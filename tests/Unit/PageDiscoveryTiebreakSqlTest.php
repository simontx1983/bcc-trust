<?php

declare(strict_types=1);

namespace BCC\Core\DB {
    // bcc-core is not autoloaded in the unit context; stub the resolver.
    if (!class_exists(__NAMESPACE__ . '\\DB', false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Repositories\PageDiscoveryRepository;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-143 — PageDiscoveryRepository pages by LIMIT/OFFSET over sort keys
     * that tie (counts, post_date, stake, the ranking expression). Tied rows
     * have no fixed order in MySQL, so pages skipped or repeated pages. Every
     * sort mode of BOTH discovery queries must end in the unique `p.ID DESC`.
     *
     * The live-server proof of the mechanism (MySQL 8 repeating rows across
     * pages without a tiebreak) is CollectionGalleryPagingIntegrationTest;
     * the shared harness wp_posts lacks the discovery columns, so this pins
     * the ORDER BY of every mode instead.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class PageDiscoveryTiebreakSqlTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            if (!defined('ABSPATH')) {
                define('ABSPATH', sys_get_temp_dir() . '/');
            }
            $GLOBALS['wpdb'] = new PageDiscoverySqlCapturingWpdb();
        }

        private static function mainSql(): string
        {
            $selects = array_values(array_filter(
                $GLOBALS['wpdb']->results,
                static fn (string $sql): bool => str_contains($sql, 'LIMIT') && str_contains($sql, 'OFFSET')
            ));
            self::assertCount(1, $selects, 'exactly one paged SELECT');
            return $selects[0];
        }

        private static function assertEndsInIdTiebreak(string $sql, string $mode): void
        {
            self::assertMatchesRegularExpression(
                '/ORDER BY .+, p\.ID DESC\s+LIMIT \d+ OFFSET \d+$/s',
                trim($sql),
                "sort '{$mode}' must end in the unique p.ID tiebreak"
            );
        }

        public function testEveryReadModelSortEndsInTheIdTiebreak(): void
        {
            foreach (['trust', '', 'endorsements', 'newest', 'followers', 'self_stake'] as $sort) {
                $GLOBALS['wpdb']->results = [];
                PageDiscoveryRepository::queryFromReadModel([], $sort, false, '', 0.0, false, 0, 12, 2, '');
                self::assertEndsInIdTiebreak(self::mainSql(), $sort);
            }
        }

        public function testEveryPostsTableSortEndsInTheIdTiebreak(): void
        {
            foreach (['trust', '', 'endorsements', 'newest', 'followers'] as $sort) {
                $GLOBALS['wpdb']->results = [];
                PageDiscoveryRepository::queryFromPostsTable([], $sort, false, '', 0.0, false, 0, 12, 2, '');
                self::assertEndsInIdTiebreak(self::mainSql(), $sort);
            }
        }
    }

    /** Records every SELECT; reports 30 matches so the paged query runs. */
    final class PageDiscoverySqlCapturingWpdb
    {
        public string $prefix   = 'wp_';
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $usermeta = 'wp_usermeta';
        public string $users    = 'wp_users';
        public string $last_error = '';

        /** @var list<string> */
        public array $results = [];

        /**
         * Real `wpdb::$last_result`, which `trustedRows()` reads (F-230).
         *
         * ⚠ DECLARED, not left to PHP: an undeclared property assigned in
         * `query()` is created dynamically, which PHP 8.2 deprecates and
         * PHP 9 makes a FATAL.
         *
         * @var list<object>
         */
        public array $last_result = [];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            $sql = str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], $sql);
            return vsprintf($sql, $args);
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function get_var(string $sql): string
        {
            return '30';
        }

        /** @return list<object> */
        public function get_results(string $sql, string $output = 'OBJECT'): array
        {
            $this->results[] = $sql;
            return [];
        }

        /**
         * ⚠ ADDED FOR F-230. The repository now reads through
         * `trustedRows()`, which calls `query()` explicitly and takes its
         * rows from `last_result`, because `wpdb::query()` can fail without
         * setting `last_error` and `get_results()` discards its return.
         *
         * Every statement is still recorded, which is what this double is
         * for; the COUNT now lands in `$results` too, and
         * {@see PageDiscoveryTiebreakSqlTest::mainSql()} already selects
         * only the statement carrying LIMIT/OFFSET, so the assertions are
         * unaffected.
         *
         * The 30 that `get_var()` used to report moves into `last_result`
         * under the `n` alias — without it the total reads as 0 and the
         * paged SELECT this test exists to inspect is never built.
         */
        public function query(string $sql): int
        {
            $this->results[]   = $sql;
            $this->last_error  = '';
            $this->last_result = str_contains($sql, 'COUNT(') ? [(object) ['n' => 30]] : [];

            return count($this->last_result);
        }
    }
}
