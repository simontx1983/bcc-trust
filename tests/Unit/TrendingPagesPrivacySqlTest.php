<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories\Tests;

use BCC\Trust\Core\Repositories\ReadModelHealthRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the trending-pages privacy boundary.
 *
 * ## Why this exists
 *
 * getTrendingPages() is a page-discovery read path. Like /search and the
 * trending fallback in bcc-search, it must exclude PeepSo SECRET pages
 * (peepso_page_privacy = 2) — invisible to non-members. These are raw $wpdb
 * queries (not WP_Query), so PeepSo's posts_clauses privacy filter never runs
 * and the exclusion has to live in the SQL. Without it a secret page can win a
 * trending slot here, then get silently dropped downstream by bcc-search's
 * hydratePages() (which DOES filter privacy), so trending returns fewer than
 * LIMIT results and burns enrichment budget on rows that never render.
 *
 * The clause mirrors SearchRepository::hydratePages() exactly: a `pm_priv`
 * LEFT JOIN on the PeepSo meta plus a `NULL OR CAST(...) <> 2` exclusion.
 *
 * A real $wpdb is replaced with a recorder so the prepared template can be
 * asserted without a database. TableRegistry resolves table names via
 * $wpdb->prefix, so the recorder's prefix is all the constructor needs.
 */
#[CoversClass(ReadModelHealthRepository::class)]
final class TrendingPagesPrivacySqlTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new class {
            public string $prefix   = 'wp_';
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            /** @var list<array{sql: string, args: array<int, mixed>}> */
            public array $prepared = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = ['sql' => $sql, 'args' => $args];
                return $sql;
            }

            /** @return array<int, object> */
            public function get_results(string $sql): array
            {
                return [];
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
        $last = end($wpdb->prepared);
        return (string) preg_replace('/\s+/', ' ', trim((string) $last['sql']));
    }

    public function testTrendingExcludesSecretPages(): void
    {
        (new ReadModelHealthRepository())->getTrendingPages(12);
        $sql = self::lastSql();

        // The privacy LEFT JOIN must be present, keyed on the PeepSo meta.
        self::assertStringContainsString('peepso_page_privacy', $sql);
        // And the SECRET (2) exclusion — NULL (no meta = open) OR value <> 2.
        self::assertStringContainsString('pm_priv.meta_value IS NULL', $sql);
        self::assertStringContainsString('CAST(pm_priv.meta_value AS UNSIGNED) <> 2', $sql);
        // Sanity: still scoped to published peepso pages.
        self::assertStringContainsString("post_type = 'peepso-page'", $sql);
        self::assertStringContainsString("post_status = 'publish'", $sql);
    }

    public function testProjectionAndOrderingUnchanged(): void
    {
        (new ReadModelHealthRepository())->getTrendingPages(12);
        $sql = self::lastSql();

        // The DTO shape must not drift: columns ID, total_score, reputation_tier.
        self::assertStringContainsString('rm.page_id AS ID', $sql);
        self::assertStringContainsString('rm.trust_score AS total_score', $sql);
        self::assertStringContainsString('rm.reputation_tier', $sql);
        // Ordering + bounded limit preserved.
        self::assertStringContainsString('ORDER BY rm.trust_score DESC, rm.page_id ASC', $sql);
        self::assertStringContainsString('LIMIT %d', $sql);
    }

    public function testLimitIsBoundArg(): void
    {
        (new ReadModelHealthRepository())->getTrendingPages(12);
        global $wpdb;
        $last = end($wpdb->prepared);
        self::assertSame([12], $last['args'], 'limit must be a bound arg');
    }
}
