<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories\Tests;

use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\ReputationEventRepository;
use BCC\Trust\Core\Repositories\ScoreEventRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks the retention-sweep SQL for the three append-only ledgers.
 *
 * These guard against a regression that would either (a) delete rows the
 * UI/admin still need, or — most dangerous — (b) delete PENDING content
 * reports. A real $wpdb is replaced with a recorder so the exact DELETE
 * the repository builds can be asserted without a database.
 */
#[CoversClass(ContentReportRepository::class)]
#[CoversClass(ReputationEventRepository::class)]
#[CoversClass(ScoreEventRepository::class)]
final class RetentionCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        // Recorder: prepare() captures (template, args) and returns the
        // template verbatim so assertions can inspect the SQL structure;
        // query() returns a configurable affected-row count to drive the
        // batched loop.
        $wpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $affected = 0;
            /** @var list<array{sql: string, args: array<int, mixed>}> */
            public array $prepared = [];
            /** @var list<string> */
            public array $queries = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = ['sql' => $sql, 'args' => $args];
                return $sql;
            }

            public function query(string $sql): int
            {
                $this->queries[] = $sql;
                return $this->affected;
            }
        };
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }

    private static function fakeWpdb(): object
    {
        global $wpdb;
        \assert(\is_object($wpdb));
        return $wpdb;
    }

    /** Collapse whitespace so multi-line SQL can be matched by structure. */
    private static function norm(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($sql));
    }

    // ── content_reports: the load-bearing "never delete pending" guard ──────

    public function testContentReportsCleanupNeverDeletesPending(): void
    {
        (new ContentReportRepository())->cleanupResolved();

        $sql = self::norm(self::fakeWpdb()->prepared[0]['sql']);

        self::assertStringContainsString('DELETE FROM wp_bcc_content_reports', $sql);
        self::assertStringContainsString('status <> 0', $sql, 'must exclude PENDING (status 0)');
        self::assertStringContainsString('resolved_at IS NOT NULL', $sql, 'only acted-on rows');
        self::assertStringContainsString('resolved_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $sql);
        self::assertStringContainsString('LIMIT %d', $sql);

        // The inverse must NEVER appear — that would purge the admin queue.
        self::assertStringNotContainsString('status = 0', $sql);

        // Parameterized horizon (90d) + batch size (5000), never interpolated.
        self::assertSame([90, 5000], self::fakeWpdb()->prepared[0]['args']);
    }

    // ── reputation_events: UTC clock, 180-day horizon ──────────────────────

    public function testReputationEventsCleanupHorizonAndClock(): void
    {
        (new ReputationEventRepository())->cleanupOld();

        $sql = self::norm(self::fakeWpdb()->prepared[0]['sql']);

        self::assertStringContainsString('DELETE FROM wp_bcc_reputation_events', $sql);
        // created_at is written in UTC, so the cutoff must use UTC_TIMESTAMP().
        self::assertStringContainsString('created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', $sql);
        self::assertSame([180, 5000], self::fakeWpdb()->prepared[0]['args']);
    }

    // ── trust_score_events: MySQL NOW() clock, 90-day horizon ──────────────

    public function testScoreEventsCleanupHorizonAndClock(): void
    {
        (new ScoreEventRepository())->cleanupOld();

        $sql = self::norm(self::fakeWpdb()->prepared[0]['sql']);

        self::assertStringContainsString('DELETE FROM wp_bcc_trust_score_events', $sql);
        // created_at is the column's CURRENT_TIMESTAMP default, so cutoff uses NOW().
        self::assertStringContainsString('created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $sql);
        self::assertSame([90, 5000], self::fakeWpdb()->prepared[0]['args']);
    }

    // ── Batched + bounded: a full batch keeps looping, capped at 20 ────────

    public function testBatchedDeleteIsBoundedByIterationCap(): void
    {
        self::fakeWpdb()->affected = 5000; // always a full batch → keep looping

        (new ContentReportRepository())->cleanupResolved();

        self::assertCount(20, self::fakeWpdb()->queries, 'iteration ceiling caps a runaway sweep at 20');
    }

    public function testStopsAfterPartialBatch(): void
    {
        self::fakeWpdb()->affected = 10; // < batch → one pass and done

        (new ScoreEventRepository())->cleanupOld();

        self::assertCount(1, self::fakeWpdb()->queries);
    }
}
