<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Application\Disputes;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Trust\Core\Repositories\AdjudicationClaimRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\Services\Vote\VoteJobDispatcher;

if (!defined('ABSPATH')) {
    exit;
}

final class DisputeAdjudicator implements DisputeAdjudicationInterface
{
    private VoteRepository $voteRepository;
    private ScoreRepository $scoreRepository;
    private UserInfoRepository $userInfoRepository;
    private AdjudicationClaimRepository $claimRepository;

    public function __construct(
        VoteRepository              $voteRepository,
        ScoreRepository             $scoreRepository,
        UserInfoRepository          $userInfoRepository,
        ?AdjudicationClaimRepository $claimRepository = null
    ) {
        $this->voteRepository     = $voteRepository;
        $this->scoreRepository    = $scoreRepository;
        $this->userInfoRepository = $userInfoRepository;
        $this->claimRepository    = $claimRepository ?? new AdjudicationClaimRepository();
    }

    // Claim acquire/release delegate to AdjudicationClaimRepository.
    // See class-level docblock of that repository for the design invariant
    // (claim() must be called inside the same TransactionManager::run()
    // closure as the mutation it gates).

    /**
     * Accept a dispute against a vote.
     *
     * Row-scoped: only the specific $voteId row is removed. Other
     * active votes by the same voter on the same page (different
     * categories) are preserved — they were not reviewed by the panel.
     *
     * The legacy 'all_on_page' opt-in (bcc.trust.dispute_accepted_vote_scope
     * filter) was removed in M1.3 — it was a product-level landmine
     * (invalidating votes the panel never reviewed) with no live
     * callers.
     */
    public function acceptVoteDispute(
        int $disputeId,
        int $voteId,
        int $pageId,
        int $voterId,
        int $resolvedBy
    ): bool {
        // Wrap the entire mutation in try/catch so any exception inside the
        // transaction (deadlock, FOR UPDATE timeout, DB restart) bubbles up
        // as a caller-visible failure. The idempotency claim is now INSIDE
        // the transaction, so ROLLBACK also rolls back the claim — no
        // explicit release needed on the exception path.
        try {
            $result = TransactionManager::run(function () use ($disputeId, $voteId, $pageId, $voterId): array {
                // Claim INSIDE the transaction: a committed claim means a
                // committed mutation. Two concurrent callers serialise on
                // the wp_options UNIQUE key; the loser's INSERT IGNORE
                // returns 0 and we treat the call as a no-op success. A
                // reconcile retry firing while our mutation is still
                // in-flight will BLOCK on the UNIQUE key lock until our
                // COMMIT, then see the committed row and return 0 —
                // eliminating the "claim held but mutation not yet
                // applied" window that let the dispute plugin fire
                // reporter emails prematurely.
                if (!$this->claimRepository->claim($disputeId, 'accepted')) {
                    return ['claim_acquired' => false, 'locked_votes' => []];
                }

                // Lock only the specific disputed row.
                $locked = $this->voteRepository->lockOneForUpdate($voteId);
                if (empty($locked)) {
                    return ['claim_acquired' => true, 'locked_votes' => []];
                }
                $vote = $locked[0];

                // unique_voters is a per-page counter. Only decrement it when
                // the row we're removing is this voter's LAST active vote on
                // the page — otherwise they still count as a unique voter via
                // their remaining rows. Count BEFORE the delete excludes the
                // row we're about to remove so the check is correct either
                // way (row present → count excludes it; row already gone →
                // lockOneForUpdate returned [] and we never got here).
                $remainingCount = $this->voteRepository->countActiveForVoterOnPage(
                    $voterId,
                    $pageId,
                    $voteId
                );
                $decrementUnique = ($remainingCount === 0);

                $this->scoreRepository->reverseVoteDelta(
                    (int) $vote->page_id,
                    (int) ($vote->category_id ?? 0),
                    (float) $vote->weight,
                    ((int) $vote->vote_type) === 1,
                    $decrementUnique
                );

                $this->voteRepository->deleteOneByDispute($voteId);
                $this->scoreRepository->flagForRecalculation($pageId);

                // Fraud penalty lives inside the transaction so the
                // vote removal and the voter's fraud increment either
                // both land or neither does. Previously this ran post-
                // commit — the vote was gone but a transient DB error
                // could swallow the penalty, leaving the voter
                // unpunished.
                $this->applyFraudPenalty($voterId);

                return ['claim_acquired' => true, 'locked_votes' => $locked];
            });
        } catch (\Throwable $e) {
            // Transaction rolled back — including the claim INSERT, which is
            // now inside the closure. No explicit claimRepository->release()
            // needed; future reconcile retries will find wp_options empty
            // and successfully re-claim on their next attempt.
            \BCC\Core\Log\Logger::error('[bcc-trust] acceptVoteDispute transaction failed', [
                'dispute_id' => $disputeId,
                'vote_id'    => $voteId,
                'page_id'    => $pageId,
                'voter_id'   => $voterId,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }

        // Narrow the mixed return type of TransactionManager::run at the
        // boundary via defensive coercion (no @var / assert). A non-array
        // or structurally-invalid result is treated as "claim not
        // acquired" — the same semantics as the concurrent-winner path.
        $claimAcquired = is_array($result) && !empty($result['claim_acquired']);
        $lockedVotes   = (is_array($result) && isset($result['locked_votes']) && is_array($result['locked_votes']))
            ? $result['locked_votes']
            : [];

        if (!$claimAcquired) {
            // Another concurrent caller committed its claim + mutation
            // before us. Treat as a successful no-op — the concurrent
            // caller's own post-commit code will fire the side-effects,
            // and its committed claim row now proves the mutation is on
            // disk. A reconcile retry arriving here will short-circuit to
            // success and NOT fire duplicate emails.
            return true;
        }

        if (empty($lockedVotes)) {
            // Row already gone (pre-claim removal by another path).
            // Release the committed claim so a future retry can reprocess —
            // otherwise a spurious empty-lock result permanently marks
            // the dispute adjudicated despite no mutation applying.
            $this->claimRepository->release($disputeId);
            $this->emitResolvedEvent($disputeId, $voteId, $pageId, 'accepted', $resolvedBy);
            return false;
        }

        foreach ($lockedVotes as $vote) {
            $categoryId = (int) ($vote->category_id ?? 0);
            $this->invalidateAfterVoteRemoval($voterId, $pageId, $categoryId);
        }

        $firstCategoryId = (int) ($lockedVotes[0]->category_id ?? 0);
        $scoreAfter = $this->scoreRepository->getByPageId($pageId, $firstCategoryId);

        AuditLogger::removeVote($pageId, [
            'dispute_id'      => $disputeId,
            'vote_id'         => $voteId,
            'voter_id'        => $voterId,
            'actor_id'        => $resolvedBy,
            'scope'           => 'row_only',
            'previous_vote'   => (int) $lockedVotes[0]->vote_type,
            'previous_weight' => (float) $lockedVotes[0]->weight,
            'categories'      => array_map(
                static fn($vote): int => (int) ($vote->category_id ?? 0),
                $lockedVotes
            ),
            'score_after'     => $scoreAfter ? $scoreAfter->getTotalScore() : null,
        ]);

        VoteJobDispatcher::dispatchRemoval($voterId, $pageId);

        // Synchronous authoritative recalculation so the trust score is
        // immediately correct after dispute resolution.  flagForRecalculation()
        // was set inside the transaction as a safety net — the 5-minute cron
        // will clear it even if this call fails.
        try {
            \BCC\Trust\Core\Plugin::instance()->voteService()->recalculateScore($pageId);
            \do_action('bcc_trust_score_recalculated', $pageId);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] Synchronous recalc after dispute failed', [
                'page_id' => $pageId,
                'error'   => $e->getMessage(),
            ]);
            // Flag is already set — cron will retry within 5 minutes.
        }

        $this->emitResolvedEvent($disputeId, $voteId, $pageId, 'accepted', $resolvedBy);

        return true;
    }

    public function rejectVoteDispute(
        int $disputeId,
        int $voteId,
        int $pageId,
        int $reporterId,
        int $resolvedBy,
        bool $quorumMet
    ): bool {
        // Quorum-gated reporter penalty: ONLY when the community poll
        // actually reached quorum. A day-90 Inconclusive close still
        // flips the dispute to 'rejected' (vote stands) but must NOT
        // punish the reporter for voter silence.
        //
        // Both the idempotency claim AND the penalty run inside a single
        // TransactionManager::run(): a committed claim means a committed
        // penalty. The prior layout — claim outside the tx, penalty inside —
        // let a reconcile retry arriving during a slow in-flight penalty
        // see the claim held, return true as "already done," and fire
        // reporter-result side effects before the fraud_score / reputation
        // deltas actually landed. Moving claim INSIDE closes that window.
        $applyPenalty = $quorumMet && $reporterId > 0;

        try {
            $result = TransactionManager::run(function () use ($disputeId, $reporterId, $applyPenalty): array {
                if (!$this->claimRepository->claim($disputeId, 'rejected')) {
                    return ['claim_acquired' => false];
                }

                if ($applyPenalty) {
                    $userInfoRepo = \BCC\Trust\Core\Plugin::instance()->userInfoRepository();

                    // Reputation penalty (-5 points) on the reporter's
                    // self-page (Architecture A). ensureSelfPage is idempotent
                    // and applyPenalty accumulates penalty_adjustment +
                    // recomputes total_score + reputation_tier inline. Both run
                    // inside the enclosing TransactionManager::run() so the
                    // penalty commits atomically with the idempotency claim.
                    $pageId = \BCC\Trust\Core\Plugin::instance()
                        ->memberSelfPageService()
                        ->ensureSelfPage($reporterId);
                    \BCC\Trust\Core\Plugin::instance()
                        ->scoreRepository()
                        ->applyPenalty($pageId, -5);

                    // Fraud score increment (+5, capped at 100). Delegates to
                    // UserInfoRepository::incrementFraudScore() which issues a
                    // single atomic UPDATE … SET fraud_score = LEAST(100,
                    // fraud_score + %d). Prior code was a read-modify-write
                    // that lost updates when two rejected disputes landed on
                    // the same reporter concurrently (both read 50, both
                    // wrote 55 — one +5 vanished). The atomic path uses
                    // InnoDB's per-row X-lock so concurrent callers serialise.
                    $userInfoRepo->incrementFraudScore($reporterId, 5, 'dispute_rejected');
                }

                return ['claim_acquired' => true];
            });
        } catch (\Throwable $e) {
            // Transaction rolled back — claim INSERT rolled back with it, so
            // future reconcile retries can re-claim. Returning false here is
            // critical: previously the hook-based path swallowed exceptions,
            // marking the dispute completed with no penalty applied.
            \BCC\Core\Log\Logger::error('[bcc-trust] rejectVoteDispute penalty failed', [
                'dispute_id'  => $disputeId,
                'reporter_id' => $reporterId,
                'error'       => $e->getMessage(),
            ]);
            return false;
        }

        if (!$result['claim_acquired']) {
            // Another concurrent caller committed the claim + penalty.
            // Treat as no-op success so reconcile retries do not fire
            // duplicate reporter-result side-effects.
            return true;
        }

        if ($applyPenalty) {
            AuditLogger::log('dispute_rejection_penalty', 0, [
                'reporter_id' => $reporterId,
                'dispute_id'  => $disputeId,
                'vote_id'     => $voteId,
                'page_id'     => $pageId,
                'actor_id'    => $resolvedBy,
            ], 'user', $reporterId);
        }

        $this->emitResolvedEvent($disputeId, $voteId, $pageId, 'rejected', $resolvedBy);

        return true;
    }

    private function applyFraudPenalty(int $voterId): void
    {
        $this->userInfoRepository->incrementFraudScore($voterId, 5, 'accepted_dispute');
    }

    private function invalidateAfterVoteRemoval(int $voterId, int $pageId, int $categoryId): void
    {
        $this->userInfoRepository->invalidateCache($voterId);
        $this->scoreRepository->invalidateCache($pageId, $categoryId);
        wp_cache_delete("page_vote_stats_{$pageId}", 'bcc_trust_votes');

        do_action('bcc_trust_vote_removed', $voterId, $pageId, $categoryId);
        do_action('bcc_trust_vote_changed', $voterId, $pageId, $categoryId, 'removed');
    }

    private function emitResolvedEvent(
        int $disputeId,
        int $voteId,
        int $pageId,
        string $outcome,
        int $actorId
    ): void {
        do_action('bcc.domain.dispute_resolved', [
            'dispute_id'  => $disputeId,
            'vote_id'     => $voteId,
            'page_id'     => $pageId,
            'outcome'     => $outcome,
            'actor_id'    => $actorId,
            'occurred_at' => gmdate('c'),
        ]);
    }
}
