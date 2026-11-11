<?php
/**
 * Vote Removal Service
 *
 * The single path for removing votes in bulk on behalf of moderation:
 * admin "clear votes" (single + bulk), ring invalidation, and account
 * deletion. Before this existed each of those callers soft-deleted votes
 * itself and remembered a different subset of the follow-up work
 * (recalc, votes_cast, caches, audit) — some none of it (F-81(b)).
 *
 * Guarantees, for every caller:
 *  - only ACTIVE votes (status = 1) are touched — dispute-removed rows
 *    (status = 2) keep their status, so a dispute-blocked voter stays
 *    blocked (F-122);
 *  - removed votes get STATUS_MODERATION_REMOVED (3), distinct from a
 *    user retraction (0): re-voting is refused and the rows survive
 *    cleanup for VoteRepository::MODERATION_RESTORE_WINDOW_DAYS (F-104);
 *  - the removal, the recalc flags and the votes_cast reset commit in ONE
 *    transaction, and every DB failure aborts it;
 *  - the audit trail records the removed vote ids, so restore() can undo
 *    exactly that action;
 *  - affected pages are recalculated now when there are few of them,
 *    otherwise left flagged for the recalculation cron; the counts
 *    returned are real, not "pages we asked for" (F-110).
 *
 * @package BCC\Trust\Core\Services\Vote
 */

namespace BCC\Trust\Core\Services\Vote;

use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\Services\VoteService;
use BCC\Trust\Core\Support\CacheManager;

if (!defined('ABSPATH')) {
    exit;
}

final class VoteRemovalService
{
    /**
     * At or below this many affected pages, recalculate synchronously so
     * the admin sees corrected scores immediately; above it, leave the
     * pages flagged for the 5-minute cron (bounded request time).
     */
    public const SYNC_RECALC_PAGE_LIMIT = 25;

    /** Rows read per keyset page while collecting the votes to remove. */
    private const READ_PAGE = 500;

    /** Vote ids per audit entry (keeps each meta payload small). */
    private const AUDIT_CHUNK = 500;

    public function __construct(
        private readonly VoteRepository     $voteRepo,
        private readonly ScoreRepository    $scoreRepo,
        private readonly UserInfoRepository $userInfoRepo,
        private readonly VoteService        $voteService,
    ) {}

    /**
     * Remove every ACTIVE vote cast by the given voters.
     *
     * @param list<int> $voterIds
     * @param string    $auditAction e.g. admin_clear_votes, admin_ring_invalidate_votes, user_deleted_votes
     * @param int       $actorId     admin performing the action (0 = system)
     * @return array{removed: int, vote_ids: list<int>, pages: int, recalculated: int, queued: int}
     * @throws \Throwable on any DB failure — nothing is removed in that case
     */
    public function removeVotesCastBy(array $voterIds, string $auditAction, int $actorId): array
    {
        $voterIds = array_values(array_unique(array_filter(array_map('intval', $voterIds), static fn (int $v): bool => $v > 0)));
        if ($voterIds === []) {
            return self::emptyResult();
        }

        /** @var array{vote_ids: list<int>, page_ids: list<int>, voter_ids: list<int>} $tx */
        $tx = TransactionManager::run(function () use ($voterIds): array {
            $refs = $this->collectByVoters($voterIds, VoteRepository::STATUS_ACTIVE);
            return $this->transition(
                $refs,
                VoteRepository::STATUS_ACTIVE,
                VoteRepository::STATUS_MODERATION_REMOVED,
                $voterIds
            );
        });

        $this->audit($auditAction, $actorId, $voterIds, $tx['vote_ids'], count($tx['page_ids']));

        return $this->afterCommit($tx['vote_ids'], $tx['page_ids']);
    }

    /**
     * Restore votes previously removed by moderation (status 3 → 1).
     *
     * Only rows still in STATUS_MODERATION_REMOVED move; anything restored,
     * purged or otherwise changed since is skipped.
     *
     * @param list<int> $voteIds ids recorded in the removal's audit entries
     * @return array{removed: int, vote_ids: list<int>, pages: int, recalculated: int, queued: int}
     *         `removed` is the count of votes RESTORED here.
     * @throws \Throwable on any DB failure — nothing is restored in that case
     */
    public function restoreModeratedVotes(array $voteIds, string $auditAction, int $actorId): array
    {
        /** @var array{vote_ids: list<int>, page_ids: list<int>, voter_ids: list<int>} $tx */
        $tx = TransactionManager::run(function () use ($voteIds): array {
            return $this->transition(
                $this->voteRepo->getVoteRefsByIdsAndStatus($voteIds, VoteRepository::STATUS_MODERATION_REMOVED),
                VoteRepository::STATUS_MODERATION_REMOVED,
                VoteRepository::STATUS_ACTIVE,
                []
            );
        });

        $this->audit($auditAction, $actorId, $tx['voter_ids'], $tx['vote_ids'], count($tx['page_ids']));

        return $this->afterCommit($tx['vote_ids'], $tx['page_ids']);
    }

    /**
     * Restore every moderation-removed vote cast by the given voters (the
     * admin "Restore moderated votes" action). Same guarantees as
     * restoreModeratedVotes(); rows already purged are simply gone.
     *
     * @param list<int> $voterIds
     * @return array{removed: int, vote_ids: list<int>, pages: int, recalculated: int, queued: int}
     *         `removed` is the count of votes RESTORED here.
     * @throws \Throwable on any DB failure — nothing is restored in that case
     */
    public function restoreModeratedVotesCastBy(array $voterIds, string $auditAction, int $actorId): array
    {
        $voterIds = array_values(array_unique(array_filter(array_map('intval', $voterIds), static fn (int $v): bool => $v > 0)));
        if ($voterIds === []) {
            return self::emptyResult();
        }

        /** @var array{vote_ids: list<int>, page_ids: list<int>, voter_ids: list<int>} $tx */
        $tx = TransactionManager::run(function () use ($voterIds): array {
            return $this->transition(
                $this->collectByVoters($voterIds, VoteRepository::STATUS_MODERATION_REMOVED),
                VoteRepository::STATUS_MODERATION_REMOVED,
                VoteRepository::STATUS_ACTIVE,
                $voterIds
            );
        });

        $this->audit($auditAction, $actorId, $voterIds, $tx['vote_ids'], count($tx['page_ids']));

        return $this->afterCommit($tx['vote_ids'], $tx['page_ids']);
    }

    /**
     * How many moderation-removed votes restoreModeratedVotesCastBy() would
     * bring back for this voter (drives the admin button label).
     *
     * @throws \Throwable on database failure
     */
    public function countRestorableFor(int $voterId): int
    {
        return $this->voteRepo->countModeratedByVoter($voterId);
    }

    /**
     * All votes in `$status` cast by the voters, keyset-paginated.
     *
     * @param list<int> $voterIds
     * @return list<object{id: int, voter_user_id: int, page_id: int}>
     */
    private function collectByVoters(array $voterIds, int $status): array
    {
        $all     = [];
        $afterId = 0;
        do {
            $refs = $this->voteRepo->getVoteRefsByVoters($voterIds, $status, $afterId, self::READ_PAGE);
            foreach ($refs as $ref) {
                $all[]   = $ref;
                $afterId = $ref->id;
            }
        } while (count($refs) === self::READ_PAGE);

        return $all;
    }

    /**
     * Inside the caller's transaction: flip `$refs` from `$from` to `$to`,
     * then work from the rows that ACTUALLY moved (re-read in `$to`), not
     * from the rows read beforehand — a vote changed by a concurrent request
     * in between is neither audited nor recalculated as if it had moved.
     * Flags the moved votes' pages and recounts votes_cast for every voter
     * involved from the live table (a vote cast mid-flight stays counted).
     *
     * @param list<object{id: int, voter_user_id: int, page_id: int}> $refs
     * @param list<int> $alsoRecountVoters voters to recount even if none of their votes moved
     * @return array{vote_ids: list<int>, page_ids: list<int>, voter_ids: list<int>}
     */
    private function transition(array $refs, int $from, int $to, array $alsoRecountVoters): array
    {
        $moved = [];
        if ($refs !== []) {
            $ids = array_map(static fn (object $r): int => $r->id, $refs);
            $this->voteRepo->transitionStatus($ids, $from, $to);
            $moved = $this->voteRepo->getVoteRefsByIdsAndStatus($ids, $to);
        }

        $voteIds = [];
        $pages   = [];
        $voters  = [];
        foreach ($alsoRecountVoters as $v) {
            $voters[$v] = $v;
        }
        foreach ($moved as $ref) {
            $voteIds[]                   = $ref->id;
            $pages[$ref->page_id]        = $ref->page_id;
            $voters[$ref->voter_user_id] = $ref->voter_user_id;
        }

        if ($pages !== []) {
            $this->scoreRepo->flagPagesForRecalculation(array_values($pages));
        }
        foreach ($voters as $voterId) {
            $this->userInfoRepo->updateVotesCast($voterId, $this->voteRepo->countByVoter($voterId));
        }

        return ['vote_ids' => $voteIds, 'page_ids' => array_values($pages), 'voter_ids' => array_values($voters)];
    }

    /**
     * Post-commit: caches, then recalculation (sync for small sets).
     *
     * @param list<int> $voteIds
     * @param list<int> $pageIds
     * @return array{removed: int, vote_ids: list<int>, pages: int, recalculated: int, queued: int}
     */
    private function afterCommit(array $voteIds, array $pageIds): array
    {
        foreach ($pageIds as $pageId) {
            CacheManager::invalidatePageCaches($pageId, null, 'moderation_vote_change');
        }

        $recalculated = 0;
        $queued       = 0;
        if (count($pageIds) <= self::SYNC_RECALC_PAGE_LIMIT) {
            foreach ($pageIds as $pageId) {
                if ($this->voteService->recalculateScoreOrRequeue($pageId)) {
                    $recalculated++;
                    // Same signal the recalculation cron fires — drives the
                    // page read-model sync.
                    do_action('bcc_trust_score_recalculated', $pageId);
                } else {
                    $queued++;
                }
            }
        } else {
            $queued = count($pageIds); // already flagged inside the transaction
        }

        return [
            'removed'      => count($voteIds),
            'vote_ids'     => $voteIds,
            'pages'        => count($pageIds),
            'recalculated' => $recalculated,
            'queued'       => $queued,
        ];
    }

    /**
     * One audit entry per AUDIT_CHUNK vote ids (at least one entry, so an
     * action that matched nothing is still witnessed).
     *
     * @param list<int> $voterIds
     * @param list<int> $voteIds
     */
    private function audit(string $action, int $actorId, array $voterIds, array $voteIds, int $pageCount): void
    {
        $chunks = $voteIds === [] ? [[]] : array_chunk($voteIds, self::AUDIT_CHUNK);
        $total  = count($chunks);

        foreach ($chunks as $i => $chunk) {
            AuditLogger::log($action, $voterIds[0] ?? 0, [
                'admin_id'       => $actorId,
                'voter_ids'      => $voterIds,
                'vote_ids'       => $chunk,
                'votes_total'    => count($voteIds),
                'pages_affected' => $pageCount,
                'chunk'          => ($i + 1) . '/' . $total,
            ], 'user');
        }
    }

    /** @return array{removed: int, vote_ids: list<int>, pages: int, recalculated: int, queued: int} */
    private static function emptyResult(): array
    {
        return ['removed' => 0, 'vote_ids' => [], 'pages' => 0, 'recalculated' => 0, 'queued' => 0];
    }
}
