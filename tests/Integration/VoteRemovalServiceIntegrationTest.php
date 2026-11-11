<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Exceptions\VoteEligibilityException;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\Vote\VoteRemovalService;
use BCC\Trust\Core\Services\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * VoteRemovalService — the single bulk vote-removal path (F-81(b), F-104,
 * F-110, F-122), against real MySQL.
 *
 * What each case pins:
 *  - only ACTIVE votes move, to STATUS_MODERATION_REMOVED (3); a
 *    dispute-removed row (2) and a retraction (0) keep their status — the
 *    old bulk soft-deletes rewrote every status to 0 (F-122);
 *  - the affected page is recalculated (the old account-deletion and bulk
 *    clear paths never recalculated → phantom scores, F-81(b));
 *  - votes_cast is reset; the audit entry carries the removed vote ids;
 *  - a moderation-removed voter cannot re-vote, and restore() undoes
 *    exactly the recorded ids (F-104);
 *  - cleanupOldDeleted() keeps status 3 for the 90-day restore window;
 *  - a DB failure mid-removal rolls the WHOLE removal back.
 *
 * Pages are member self-pages so owner resolution needs no wp_posts/PeepSo.
 *
 * ── Why separate processes
 * The post-commit step fires do_action(), which the shared bootstrap does
 * not define, and AuditLogger needs an actor function. Both are declared
 * here per child process, so they can never shadow a sibling suite's own
 * guarded stub.
 */
#[Group('integration')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(VoteRemovalService::class)]
final class VoteRemovalServiceIntegrationTest extends TestCase
{
    private const RING_VOTER  = 9701;
    private const OTHER_VOTER = 9702;
    private const OWNER_A     = 9711;
    private const OWNER_B     = 9712;

    private int $pageA;
    private int $pageB;

    protected function setUp(): void
    {
        if (!function_exists('do_action')) {
            eval('function do_action(string $hook, ...$args): void {}');
        }
        if (!function_exists('BCC\\Trust\\Core\\Security\\get_current_user_id')) {
            eval('namespace BCC\\Trust\\Core\\Security; function get_current_user_id(): int { return 0; }');
        }

        $this->pageA = MemberSelfPageService::ID_BASE + self::OWNER_A;
        $this->pageB = MemberSelfPageService::ID_BASE + self::OWNER_B;

        $wpdb = $GLOBALS['wpdb'];
        foreach (['bcc_trust_votes', 'bcc_trust_page_scores', 'bcc_trust_user_info'] as $t) {
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . $t . '`');
        }
        $wpdb->query('DELETE FROM `' . TableRegistry::activity() . "` WHERE action LIKE 'itest\\_%'");
        $GLOBALS['__bcc_test_object_cache'] = [];

        foreach ([$this->pageA => self::OWNER_A, $this->pageB => self::OWNER_B] as $page => $owner) {
            self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
                'page_id'       => $page,
                'category_id'   => 0,
                'page_owner_id' => $owner,
            ]), $wpdb->last_error);
        }
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_user_info', [
            'user_id'      => self::RING_VOTER,
            'user_login'   => 'ring',
            'user_email'   => 'ring@example.test',
            'display_name' => 'Ring',
            'votes_cast'   => 3,
        ]), $wpdb->last_error);
    }

    private function vote(int $voterId, int $pageId, int $status, int $categoryId = 0): int
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => $voterId,
            'page_id'       => $pageId,
            'category_id'   => $categoryId,
            'vote_type'     => 1,
            'weight'        => 2.0,
            'vested_weight' => 2.0,
            'status'        => $status,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function voteStatus(int $voteId): int
    {
        return (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            'SELECT status FROM `' . $GLOBALS['wpdb']->prefix . 'bcc_trust_votes` WHERE id = %d',
            $voteId
        ));
    }

    private function scoreVoteCount(int $pageId): int
    {
        return (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            'SELECT vote_count FROM `' . $GLOBALS['wpdb']->prefix . 'bcc_trust_page_scores` WHERE page_id = %d AND category_id = 0',
            $pageId
        ));
    }

    private function votesCast(int $userId): int
    {
        return (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            'SELECT votes_cast FROM `' . $GLOBALS['wpdb']->prefix . 'bcc_trust_user_info` WHERE user_id = %d',
            $userId
        ));
    }

    private function voteService(): VoteService
    {
        $ref = new \ReflectionClass(VoteService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        foreach ([
            'voteRepo'        => new VoteRepository(),
            'scoreRepo'       => new ScoreRepository(),
            'attestationRepo' => new AttestationRepository(),
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($svc, $value);
        }
        return $svc;
    }

    private function service(?ScoreRepository $scoreRepo = null, ?VoteRepository $voteRepo = null): VoteRemovalService
    {
        return new VoteRemovalService(
            $voteRepo ?? new VoteRepository(),
            $scoreRepo ?? new ScoreRepository(),
            new UserInfoRepository(),
            $this->voteService()
        );
    }

    /** @return list<array<string, mixed>> */
    private function auditMetas(string $action): array
    {
        $rows = $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare(
            'SELECT meta FROM `' . TableRegistry::activity() . '` WHERE action = %s ORDER BY id',
            $action
        ));
        return array_map(static fn ($m): array => (array) json_decode((string) $m, true), $rows);
    }

    public function testOnlyActiveVotesMoveAndDisputeRemovedRowsKeepTheirStatus(): void
    {
        $activeA   = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $activeB   = $this->vote(self::RING_VOTER, $this->pageB, VoteRepository::STATUS_ACTIVE);
        $disputed  = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_DISPUTE_REMOVED, 7);
        $retracted = $this->vote(self::RING_VOTER, $this->pageB, VoteRepository::STATUS_REMOVED, 7);
        $bystander = $this->vote(self::OTHER_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);

        $result = $this->service()->removeVotesCastBy([self::RING_VOTER], 'itest_clear', 1);

        self::assertSame(2, $result['removed']);
        self::assertSame([$activeA, $activeB], $result['vote_ids']);
        self::assertSame(VoteRepository::STATUS_MODERATION_REMOVED, $this->voteStatus($activeA));
        self::assertSame(VoteRepository::STATUS_MODERATION_REMOVED, $this->voteStatus($activeB));
        self::assertSame(VoteRepository::STATUS_DISPUTE_REMOVED, $this->voteStatus($disputed), 'F-122: a dispute removal must survive');
        self::assertSame(VoteRepository::STATUS_REMOVED, $this->voteStatus($retracted));
        self::assertSame(VoteRepository::STATUS_ACTIVE, $this->voteStatus($bystander), 'other voters are untouched');
    }

    public function testAffectedPagesAreRecalculatedAndVotesCastIsReset(): void
    {
        $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $this->vote(self::OTHER_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $this->vote(self::RING_VOTER, $this->pageB, VoteRepository::STATUS_ACTIVE);
        $svc = $this->voteService();
        $svc->recalculateScore($this->pageA);
        $svc->recalculateScore($this->pageB);
        self::assertSame(2, $this->scoreVoteCount($this->pageA), 'precondition');
        self::assertSame(1, $this->scoreVoteCount($this->pageB), 'precondition');

        $result = $this->service()->removeVotesCastBy([self::RING_VOTER], 'itest_clear', 1);

        self::assertSame(2, $result['pages']);
        self::assertSame(2, $result['recalculated'], 'small sets are recalculated synchronously');
        self::assertSame(1, $this->scoreVoteCount($this->pageA), 'only the bystander vote remains');
        self::assertSame(0, $this->scoreVoteCount($this->pageB), 'F-81(b): no phantom score after removal');
        self::assertSame(0, $this->votesCast(self::RING_VOTER));
    }

    public function testTheAuditTrailRecordsTheRemovedVoteIds(): void
    {
        $a = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $b = $this->vote(self::RING_VOTER, $this->pageB, VoteRepository::STATUS_ACTIVE);

        $this->service()->removeVotesCastBy([self::RING_VOTER], 'itest_ring', 42);

        $metas = $this->auditMetas('itest_ring');
        self::assertCount(1, $metas);
        self::assertSame([$a, $b], $metas[0]['vote_ids']);
        self::assertSame([self::RING_VOTER], $metas[0]['voter_ids']);
        self::assertSame(42, $metas[0]['admin_id']);
    }

    public function testAModerationRemovedVoterCannotReVote(): void
    {
        $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $this->service()->removeVotesCastBy([self::RING_VOTER], 'itest_clear', 1);

        $this->expectException(VoteEligibilityException::class);
        $this->expectExceptionMessage('removed by moderation');

        TransactionManager::run(function (): void {
            (new VoteRepository())->upsertRaw([
                'voter_user_id' => self::RING_VOTER,
                'page_id'       => $this->pageA,
                'category_id'   => 0,
                'vote_type'     => 1,
                'weight'        => 2.0,
                'vested_weight' => 2.0,
            ]);
        });
    }

    public function testRestoreUndoesExactlyTheRecordedIds(): void
    {
        $a        = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $b        = $this->vote(self::RING_VOTER, $this->pageB, VoteRepository::STATUS_ACTIVE);
        $disputed = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_DISPUTE_REMOVED, 7);
        $removed  = $this->service()->removeVotesCastBy([self::RING_VOTER], 'itest_clear', 1);

        // Restore only page A's vote, and try to sneak the dispute row in.
        $restored = $this->service()->restoreModeratedVotes([$a, $disputed], 'itest_restore', 1);

        self::assertSame([$a], $restored['vote_ids']);
        self::assertSame(VoteRepository::STATUS_ACTIVE, $this->voteStatus($a));
        self::assertSame(VoteRepository::STATUS_MODERATION_REMOVED, $this->voteStatus($b));
        self::assertSame(VoteRepository::STATUS_DISPUTE_REMOVED, $this->voteStatus($disputed), 'restore never revives a dispute removal');
        self::assertSame(1, $this->scoreVoteCount($this->pageA), 'the restored vote counts again');
        self::assertSame(1, $this->votesCast(self::RING_VOTER), 'votes_cast recounted');
        self::assertSame([$a, $b], $removed['vote_ids']);
    }

    public function testCleanupKeepsModerationRemovalsForTheRestoreWindow(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = $wpdb->prefix . 'bcc_trust_votes';
        $young = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_MODERATION_REMOVED);
        $old   = $this->vote(self::RING_VOTER, $this->pageB, VoteRepository::STATUS_MODERATION_REMOVED);
        $disp  = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_DISPUTE_REMOVED, 7);

        $age = static function (int $id, int $days) use ($wpdb, $table): void {
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) WHERE id = %d",
                $days,
                $id
            ));
        };
        $window = VoteRepository::MODERATION_RESTORE_WINDOW_DAYS;
        $age($young, $window - 1);
        $age($old, $window + 1);
        $age($disp, $window + 400);

        (new VoteRepository())->cleanupOldDeleted();

        self::assertSame(VoteRepository::STATUS_MODERATION_REMOVED, $this->voteStatus($young), 'inside the window: still restorable');
        self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE id = %d", $old)), 'past the window: purged');
        self::assertSame(VoteRepository::STATUS_DISPUTE_REMOVED, $this->voteStatus($disp), 'dispute removals are never purged by this sweep');
    }

    public function testADbFailureMidRemovalRemovesNothing(): void
    {
        $a = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);

        $failingScores = new class extends ScoreRepository {
            public function flagPagesForRecalculation(array $pageIds): void
            {
                throw new RepositoryException('simulated flag failure');
            }
        };

        try {
            $this->service($failingScores)->removeVotesCastBy([self::RING_VOTER], 'itest_fail', 1);
            self::fail('a failed removal must surface, not report success');
        } catch (RepositoryException $e) {
            self::assertSame('simulated flag failure', $e->getMessage());
        }

        self::assertSame(VoteRepository::STATUS_ACTIVE, $this->voteStatus($a), 'the status flip must roll back with the failed flag');
        self::assertSame(3, $this->votesCast(self::RING_VOTER));
        self::assertSame([], $this->auditMetas('itest_fail'), 'no audit entry claims a removal that did not happen');
    }

    public function testPerVoterRestoreBringsBackOnlyThatVotersModeratedVotes(): void
    {
        $mine      = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $mineB     = $this->vote(self::RING_VOTER, $this->pageB, VoteRepository::STATUS_ACTIVE);
        $disputed  = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_DISPUTE_REMOVED, 7);
        $theirs    = $this->vote(self::OTHER_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $this->service()->removeVotesCastBy([self::RING_VOTER, self::OTHER_VOTER], 'itest_ring', 1);
        self::assertSame(2, $this->service()->countRestorableFor(self::RING_VOTER), 'the button count matches what restore will move');

        $restored = $this->service()->restoreModeratedVotesCastBy([self::RING_VOTER], 'itest_restore_voter', 7);

        self::assertSame([$mine, $mineB], $restored['vote_ids']);
        self::assertSame(VoteRepository::STATUS_ACTIVE, $this->voteStatus($mine));
        self::assertSame(VoteRepository::STATUS_ACTIVE, $this->voteStatus($mineB));
        self::assertSame(VoteRepository::STATUS_DISPUTE_REMOVED, $this->voteStatus($disputed), 'never revives a dispute removal');
        self::assertSame(VoteRepository::STATUS_MODERATION_REMOVED, $this->voteStatus($theirs), 'another voter stays removed');
        self::assertSame(2, $this->votesCast(self::RING_VOTER));
        self::assertSame(1, $this->scoreVoteCount($this->pageA), 'page A counts the restored vote again');
        self::assertSame(1, $this->scoreVoteCount($this->pageB));
        self::assertSame(0, $this->service()->countRestorableFor(self::RING_VOTER));
        self::assertSame([$mine, $mineB], $this->auditMetas('itest_restore_voter')[0]['vote_ids']);
    }

    /**
     * #428 review L1 + L2: a vote changed by a concurrent request between the
     * read and the flip must be neither audited as removed nor zeroed out of
     * votes_cast. Modelled by a repository whose flip skips one id.
     */
    public function testOnlyVotesThatActuallyMovedAreAuditedAndVotesCastIsRecounted(): void
    {
        $moves = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $stays = $this->vote(self::RING_VOTER, $this->pageB, VoteRepository::STATUS_ACTIVE);

        $racing = new class ($stays) extends VoteRepository {
            public function __construct(private readonly int $skipId)
            {
                parent::__construct();
            }

            public function transitionStatus(array $voteIds, int $fromStatus, int $toStatus): int
            {
                return parent::transitionStatus(
                    array_values(array_filter($voteIds, fn (int $id): bool => $id !== $this->skipId)),
                    $fromStatus,
                    $toStatus
                );
            }
        };

        $result = $this->service(null, $racing)->removeVotesCastBy([self::RING_VOTER], 'itest_race', 1);

        self::assertSame([$moves], $result['vote_ids'], 'L2: report only what moved');
        self::assertSame([$moves], $this->auditMetas('itest_race')[0]['vote_ids'], 'L2: audit only what moved');
        self::assertSame(1, $result['pages'], 'only the moved vote\'s page is touched');
        self::assertSame(VoteRepository::STATUS_ACTIVE, $this->voteStatus($stays));
        self::assertSame(1, $this->votesCast(self::RING_VOTER), 'L1: the still-active vote stays counted (was hard-set to 0)');
    }

    /**
     * #428 review L3 (the bcc-core #69 failure class): when wpdb::prepare()
     * yields no statement, the read must fail loudly — never "no votes".
     */
    public function testAnEmptyPreparedStatementFailsInsteadOfReadingNothing(): void
    {
        $a    = $this->vote(self::RING_VOTER, $this->pageA, VoteRepository::STATUS_ACTIVE);
        $real = $GLOBALS['wpdb'];

        $GLOBALS['wpdb'] = new class ($real) {
            public function __construct(private object $inner) {}
            public function prepare(string $query, ...$args): string { return ''; }
            public function __get(string $name): mixed { return $this->inner->$name; }
            public function __set(string $name, mixed $value): void { $this->inner->$name = $value; }
            /** @param array<mixed> $args */
            public function __call(string $name, array $args): mixed { return $this->inner->$name(...$args); }
        };

        try {
            (new VoteRepository())->getVoteRefsByVoters([self::RING_VOTER], VoteRepository::STATUS_ACTIVE, 0, 10);
            self::fail('an empty prepared statement must throw');
        } catch (RepositoryException $e) {
            self::assertStringContainsString('prepare() produced no statement', $e->getMessage());
        } finally {
            $GLOBALS['wpdb'] = $real;
        }

        self::assertSame(VoteRepository::STATUS_ACTIVE, $this->voteStatus($a));
    }
}
