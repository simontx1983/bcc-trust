<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Disputes\Services\DisputeScheduler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-38 — the daily dispute backstop must not starve behind open-poll disputes.
 *
 * reconcileReviewingDispute() skips a dispute whose poll is still OPEN, so it
 * stays 'reviewing'. The old candidate query was
 *   WHERE status = 'reviewing' AND created_at <= cutoff ORDER BY created_at LIMIT 50
 * with no cursor: with 50+ open-poll disputes at the front of the set, every
 * run re-selected the same 50 and a stuck missing/closed-poll dispute behind
 * them was never reconciled.
 *
 * The REAL query runs against a REAL server; only the reconcile step is
 * replaced by a recorder (it is the poll engine's job, not this seam's).
 */
#[Group('integration')]
#[Group('mariadb')]
final class DisputeBackstopCursorIntegrationTest extends TestCase
{
    private const LIMIT  = 50;
    private const CUTOFF = '2026-01-01 00:00:00';

    private string $disputes = '';

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->clearFaultInjection();
        $this->disputes = $wpdb->prefix . 'bcc_disputes';

        $wpdb->query("DROP TABLE IF EXISTS `{$this->disputes}`");
        // Only the columns the backstop query reads, plus its real index.
        $wpdb->query(
            "CREATE TABLE `{$this->disputes}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vote_id BIGINT UNSIGNED NOT NULL,
                page_id BIGINT UNSIGNED NOT NULL,
                voter_id BIGINT UNSIGNED NOT NULL,
                reporter_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'reviewing',
                created_at DATETIME NOT NULL,
                INDEX idx_status_created (status, created_at)
             ) ENGINE=InnoDB"
        );
        self::assertSame('', $wpdb->last_error, 'fixture table must be creatable');
        unset($GLOBALS['__bcc_test_options'][DisputeScheduler::BACKSTOP_CURSOR_OPTION]);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        $wpdb->query("DROP TABLE IF EXISTS `{$this->disputes}`");
        unset($GLOBALS['__bcc_test_options'][DisputeScheduler::BACKSTOP_CURSOR_OPTION]);
        parent::tearDown();
    }

    /** @return list<int> ids passed to reconcile, in order */
    private function runOnce(): array
    {
        $seen = [];
        DisputeScheduler::runBackstopBatch(
            self::CUTOFF,
            self::LIMIT,
            static function (int $id) use (&$seen): void {
                $seen[] = $id; // Recorder: an open-poll dispute stays 'reviewing'.
            }
        );
        return $seen;
    }

    private function insertDispute(string $createdAt, string $status = 'reviewing'): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$this->disputes}` (vote_id, page_id, voter_id, reporter_id, status, created_at)
             VALUES (1, 1, 1, 1, %s, %s)",
            $status,
            $createdAt
        ));
        // The harness wpdb does not refresh insert_id after a raw query().
        return (int) $wpdb->get_var("SELECT MAX(id) FROM `{$this->disputes}`");
    }

    public function testAStuckDisputeBehindFiftyOpenPollDisputesIsReachedOnTheNextRun(): void
    {
        // 60 older disputes whose polls stay open (never leave 'reviewing'),
        // then the stuck one, created last.
        for ($i = 0; $i < 60; $i++) {
            $this->insertDispute(gmdate('Y-m-d H:i:s', 1748736000 + $i * 60));
        }
        $stuck = $this->insertDispute('2025-07-01 00:00:00');

        $first  = $this->runOnce();
        $second = $this->runOnce();

        self::assertCount(self::LIMIT, $first);
        self::assertNotContains($stuck, $first);
        self::assertContains($stuck, $second, 'the stuck dispute must be reconciled on the next run, not starved');
        self::assertSame([], array_intersect($first, $second), 'the second run must not re-select the first batch');
    }

    public function testTheCursorWrapsAfterTheTailSoEveryDisputeIsRevisited(): void
    {
        $ids = [];
        for ($i = 0; $i < 70; $i++) {
            $ids[] = $this->insertDispute(gmdate('Y-m-d H:i:s', 1748739600 + $i * 60));
        }

        $runs = [$this->runOnce(), $this->runOnce(), $this->runOnce()];

        self::assertSame(array_slice($ids, 0, 50), $runs[0]);
        self::assertSame(array_slice($ids, 50), $runs[1], 'the short batch covers the tail');
        self::assertSame(array_slice($ids, 0, 50), $runs[2], 'after the tail the cursor wraps to the start');
    }

    public function testOnlyReviewingDisputesAtOrBeforeTheCutoffAreSelected(): void
    {
        $old      = $this->insertDispute('2025-06-01 00:00:00');
        $resolved = $this->insertDispute('2025-06-01 00:01:00', 'accepted');
        $recent   = $this->insertDispute('2026-06-01 00:00:00');

        $seen = $this->runOnce();

        self::assertSame([$old], $seen);
        self::assertNotContains($resolved, $seen);
        self::assertNotContains($recent, $seen);
    }

    public function testADisputeWhoseReconcileAlwaysThrowsDoesNotStallTheCursor(): void
    {
        // 60 disputes; the FIRST one's reconcile always throws (a poison row).
        $ids = [];
        for ($i = 0; $i < 60; $i++) {
            $ids[] = $this->insertDispute(gmdate('Y-m-d H:i:s', 1748743200 + $i * 60));
        }
        $poison = $ids[0];

        $runs = [];
        for ($r = 0; $r < 2; $r++) {
            $seen = [];
            DisputeScheduler::runBackstopBatch(
                self::CUTOFF,
                self::LIMIT,
                static function (int $id) use (&$seen, $poison): void {
                    $seen[] = $id;
                    if ($id === $poison) {
                        throw new \RuntimeException('reconcile always fails for this dispute');
                    }
                }
            );
            $runs[] = $seen;
        }

        self::assertSame(array_slice($ids, 0, 50), $runs[0], 'the batch continues past the throwing dispute');
        self::assertSame(array_slice($ids, 50), $runs[1], 'the next run moves past it instead of re-selecting it first');
    }

    public function testAFailedCandidateReadStopsTheSweepAndKeepsTheCursor(): void
    {
        global $wpdb;
        for ($i = 0; $i < 60; $i++) {
            $this->insertDispute(gmdate('Y-m-d H:i:s', 1748746800 + $i * 60));
        }
        $this->runOnce(); // cursor now mid-set
        $cursorBefore = (int) get_option(DisputeScheduler::BACKSTOP_CURSOR_OPTION, 0);
        self::assertGreaterThan(0, $cursorBefore);

        $wpdb->failQueriesMatching = '/FROM `?' . preg_quote($this->disputes, '/') . '`?\s+WHERE status = \'reviewing\'/';
        $reconciled = [];
        try {
            DisputeScheduler::runBackstopBatch(
                self::CUTOFF,
                self::LIMIT,
                static function (int $id) use (&$reconciled): void {
                    $reconciled[] = $id;
                }
            );
            self::fail('a failed candidate read must not read as "no candidates"');
        } catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure $e) {
            self::assertSame('listReviewingCreatedBefore', $e->repositoryMethod());
        }

        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have hit the candidate read');
        self::assertSame([], $reconciled);
        self::assertSame(
            $cursorBefore,
            (int) get_option(DisputeScheduler::BACKSTOP_CURSOR_OPTION, 0),
            'a DB error must not wrap or move the cursor'
        );
    }

    public function testAnEmptySetLeavesAZeroCursorUntouched(): void
    {
        self::assertSame([], $this->runOnce());
        self::assertSame(0, (int) get_option(DisputeScheduler::BACKSTOP_CURSOR_OPTION, 0));
    }
}
