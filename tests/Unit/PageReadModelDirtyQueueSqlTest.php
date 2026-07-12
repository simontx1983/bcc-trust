<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories\Tests;

use BCC\Trust\Core\Repositories\PageReadModelRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the read-model dirty-queue clock convention.
 *
 * The queue is single-clock (MySQL session tz via NOW(6)): enqueue, the
 * nowSnapshot cleanup cutoff, quarantine, and the fetch gate must all agree,
 * or quarantine backoff breaks. Two regressions this catches:
 *   - fetchDirtyPages losing its `created_at <= NOW(6)` gate → future-dated
 *     quarantined rows get re-synced every tick (backoff never happens).
 *   - quarantineDirtyPage reverting to UTC_TIMESTAMP() → the delay is skewed
 *     by the session offset on a non-UTC MySQL.
 *
 * TableRegistry::dirtyQueue() resolves via $wpdb->prefix, so the recorder's
 * prefix is all that's needed.
 */
#[CoversClass(PageReadModelRepository::class)]
final class PageReadModelDirtyQueueSqlTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            /** @var list<string> */
            public array $prepared = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = $sql;
                return $sql;
            }

            /** @return array<int, object> */
            public function get_results(string $sql): array
            {
                return [];
            }

            public function query(string $sql): int
            {
                return 0;
            }
        };
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }

    private static function lastSql(): string
    {
        global $wpdb;
        return (string) preg_replace('/\s+/', ' ', trim((string) end($wpdb->prepared)));
    }

    public function testFetchDirtyPagesGatesOnDueRows(): void
    {
        PageReadModelRepository::fetchDirtyPages(10);
        $sql = self::lastSql();

        self::assertStringContainsString('created_at <= NOW(6)', $sql, 'future-dated quarantined rows must be gated out');
        self::assertStringContainsString('ORDER BY created_at ASC', $sql);
    }

    public function testQuarantineUsesSessionClockNotUtc(): void
    {
        PageReadModelRepository::quarantineDirtyPage(555, 2);
        $sql = self::lastSql();

        self::assertStringContainsString('DATE_ADD(NOW(6), INTERVAL', $sql);
        // Regression guard: must not reintroduce the UTC/local clock split.
        self::assertStringNotContainsString('UTC_TIMESTAMP', $sql);
    }
}
