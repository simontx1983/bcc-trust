<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Application\ScoreContributorService;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\TrustScoreService;
use BCC\Trust\Core\Services\VoteService;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A FAILED read inside recalculateScore() must leave the stored score alone.
 *
 * recalculateScore() reads the page's score rows (lockAllForPage, FOR UPDATE)
 * and its vote aggregates (getVoteAggregatesForPage), then SAVES a score
 * rebuilt from them. Both reads used to turn a SQL error into an empty
 * answer:
 *
 *  - the aggregate into an all-zero row, so the recalc wrote positive =
 *    negative = 0 and vote_count = 0 over the real score, and cleared the
 *    recalc flag that would have repaired it;
 *  - the lock read into "no score rows", so the recalc REPLACEd the row
 *    rebuilt from defaults (bonuses and penalty zeroed) without holding the
 *    lock it believed it held.
 *
 * Both now fail closed (RepositoryReadFailure): the transaction rolls back
 * and the page stays flagged.
 *
 * Fixture: the stored positive_score is 2.1 while the page's one active vote
 * would recompute it to 0.5, so a recalc that RAN cannot pass the "stays 2.1"
 * assertions, and the control test shows it does run without the fault.
 */
#[Group('integration')]
#[CoversClass(VoteRepository::class)]
#[CoversClass(ScoreRepository::class)]
#[CoversClass(VoteService::class)]
#[CoversClass(ScoreContributorService::class)]
final class RecalculationReadFailureIntegrationTest extends TestCase
{
    private const OWNER_ID     = 9841;
    private const STORED       = 2.1;
    private const CONTRIBUTION = 1.25;
    private const VOTE_WEIGHT  = 0.5;

    /** The aggregate SELECT (and nothing else) in getVoteAggregatesForPage(). */
    private const AGGREGATE_READ = '/^SELECT\s+COALESCE\(SUM\(CASE WHEN d\.vote_type > 0/';

    /** The score-row lock read in lockAllForPage(). */
    private const LOCK_READ = '/^SELECT page_id, category_id FROM `?[^ `]*bcc_trust_page_scores`? WHERE page_id = \d+ FOR UPDATE$/';

    private int $pageId;

    protected function setUp(): void
    {
        $this->pageId = MemberSelfPageService::ID_BASE + self::OWNER_ID;

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        foreach (['bcc_trust_votes', 'bcc_trust_page_scores', 'bcc_trust_page_scores_velocity'] as $t) {
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . $t . '`');
        }
        $wpdb->query('TRUNCATE TABLE `wp_users`');
        $GLOBALS['__bcc_test_object_cache'] = [];
        Logger::reset();

        $ok = $wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id'              => $this->pageId,
            'category_id'          => 0,
            'page_owner_id'        => self::OWNER_ID,
            'total_score'          => round(TrustScoreService::compute(self::STORED, 0.0, 0.0, self::CONTRIBUTION, 0.0, 0.0), 2),
            'positive_score'       => self::STORED,
            'negative_score'       => 0.0,
            'contribution_bonus'   => self::CONTRIBUTION,
            'vote_count'           => 1,
            'unique_voters'        => 1,
            'recalculate_required' => 1,
        ]);
        self::assertNotFalse($ok, 'score fixture insert failed: ' . $wpdb->last_error);

        $ok = $wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => 11,
            'page_id'       => $this->pageId,
            'vote_type'     => 1,
            'weight'        => self::VOTE_WEIGHT,
            'vested_weight' => self::VOTE_WEIGHT,
            'status'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'vote fixture insert failed: ' . $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
    }

    private function service(): VoteService
    {
        $ref = new \ReflectionClass(VoteService::class);
        /** @var VoteService $svc */
        $svc = $ref->newInstanceWithoutConstructor();
        foreach ([
            'voteRepo'        => new VoteRepository(),
            'scoreRepo'       => new ScoreRepository(),
            'attestationRepo' => new AttestationRepository(),
        ] as $prop => $value) {
            $ref->getProperty($prop)->setValue($svc, $value);
        }
        return $svc;
    }

    /** @return array<string, mixed> */
    private function scoreRow(): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            'SELECT positive_score, negative_score, vote_count, contribution_bonus, onchain_bonus, recalculate_required
               FROM `' . $wpdb->prefix . 'bcc_trust_page_scores` WHERE page_id = %d AND category_id = 0',
            $this->pageId
        ));
        self::assertIsObject($row, 'score row must exist: ' . $wpdb->last_error);
        return (array) $row;
    }

    /** The stored score is exactly the fixture, untouched by the failed recalc. */
    private function assertScoreUntouched(): void
    {
        $row = $this->scoreRow();
        self::assertEqualsWithDelta(self::STORED, (float) $row['positive_score'], 0.0001, 'a failed read was saved as the page\'s score');
        self::assertSame(1, (int) $row['vote_count']);
        self::assertEqualsWithDelta(self::CONTRIBUTION, (float) $row['contribution_bonus'], 0.0001, 'the bonus was rebuilt from defaults');
        self::assertSame(1, (int) $row['recalculate_required'], 'the flag must stay set so the cron retries');
    }

    /** Arm $pattern, run $fn, disarm; returns how many queries were failed. */
    private function withFault(string $pattern, callable $fn): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->failQueriesMatching = $pattern;
        try {
            $fn();
        } finally {
            $hits = $wpdb->injectedFailures;
            $wpdb->clearFaultInjection();
        }
        return $hits;
    }

    public function testControlASuccessfulRecalcRewritesTheScoreFromTheVotes(): void
    {
        $this->service()->recalculateScore($this->pageId, true);

        $row = $this->scoreRow();
        self::assertEqualsWithDelta(self::VOTE_WEIGHT, (float) $row['positive_score'], 0.01, 'precondition: a recalc that RUNS moves 2.1 to the votes\' 0.5');
        self::assertEqualsWithDelta(self::CONTRIBUTION, (float) $row['contribution_bonus'], 0.0001);
    }

    public function testAFailedAggregateReadKeepsTheStoredScore(): void
    {
        $threw = null;
        $hits  = $this->withFault(self::AGGREGATE_READ, function () use (&$threw): void {
            try {
                $this->service()->recalculateScore($this->pageId, true);
            } catch (RepositoryReadFailure $e) {
                $threw = $e;
            }
        });

        self::assertSame(1, $hits, 'precondition: the aggregate read was the one failed');
        self::assertInstanceOf(RepositoryReadFailure::class, $threw, 'a failed aggregate read must abort the recalc');
        $this->assertScoreUntouched();
    }

    public function testAFailedLockReadKeepsTheStoredScoreAndBonuses(): void
    {
        $threw = null;
        $hits  = $this->withFault(self::LOCK_READ, function () use (&$threw): void {
            try {
                $this->service()->recalculateScore($this->pageId, true);
            } catch (RepositoryReadFailure $e) {
                $threw = $e;
            }
        });

        self::assertSame(1, $hits, 'precondition: the lock read was the one failed');
        self::assertInstanceOf(RepositoryReadFailure::class, $threw, 'a failed lock read must abort the recalc');
        $this->assertScoreUntouched();
    }

    public function testTheRequeueWrapperReportsFailureAndLeavesThePageFlagged(): void
    {
        $ok = null;
        $this->withFault(self::AGGREGATE_READ, function () use (&$ok): void {
            $ok = $this->service()->recalculateScoreOrRequeue($this->pageId);
        });

        self::assertFalse($ok, 'a recalc that did not run must not report success');
        $this->assertScoreUntouched();

        $errors = array_values(array_filter(
            Logger::$lines,
            static fn (array $l): bool => $l['level'] === 'error'
                && $l['message'] === '[bcc-trust] Score recalculation failed'
        ));
        self::assertCount(1, $errors);
        self::assertSame(RepositoryReadFailure::class, $errors[0]['context']['type']);
    }

    public function testABonusWriteWhoseLockReadFailsWritesNothing(): void
    {
        // Before: the lock read's result was ignored, so a failed FOR UPDATE
        // let the bonus write run WITHOUT the row lock. Now it throws and the
        // transaction rolls back (the same way applyBonus's other failure
        // paths already end: TransactionManager turns them into a throw).
        $threw = null;
        $hits  = $this->withFault(self::LOCK_READ, function () use (&$threw): void {
            try {
                (new ScoreContributorService(new ScoreRepository()))->applyBonus($this->pageId, 'onchain', 3.0);
            } catch (RepositoryReadFailure $e) {
                $threw = $e;
            }
        });

        self::assertSame(1, $hits, 'precondition: the lock read was the one failed');
        self::assertInstanceOf(RepositoryReadFailure::class, $threw, 'the bonus write must abort, not run without the row lock');
        self::assertEqualsWithDelta(0.0, (float) $this->scoreRow()['onchain_bonus'], 0.0001);
    }
}
