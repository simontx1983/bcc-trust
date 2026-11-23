<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit\SitemapEndpoint {

    use BCC\Trust\Core\REST\SitemapEndpoint;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * A `$wpdb` that models what ProbesReads relies on: `query()` returns the
     * row count or FALSE, sets `last_error` on failure, and leaves the rows in
     * `last_result`. Every statement is recorded.
     */
    final class SitemapWpdb
    {
        public string $prefix   = 'wp_';
        public string $users    = 'wp_users';
        public string $usermeta = 'wp_usermeta';
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';
        /** @var list<object> */
        public array $last_result = [];
        /** @var list<object> */
        public array $rows = [];
        public bool $fail = false;
        /** @var list<string> */
        public array $queries = [];

        /** @param mixed ...$args */
        public function prepare(string $query, ...$args): string
        {
            $next = 0;

            return (string) preg_replace_callback(
                '/%[ds]/',
                static function (array $m) use (&$next, $args): string {
                    $value = $args[$next++] ?? '';

                    return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
                },
                $query
            );
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $this->queries[]   = (string) preg_replace('/\s+/', ' ', $sql);
            $this->last_error  = '';
            $this->last_result = [];
            if ($this->fail) {
                $this->last_error = 'Lost connection to MySQL server during query';

                return false;
            }
            $this->last_result = $this->rows;

            return count($this->rows);
        }
    }

    /**
     * F-518: the HTTP contract of the public index.
     *
     *   - three literal routes, anonymous, with the documented defaults;
     *   - `after` / `limit` out of range → 400 `bcc_invalid_request`, and
     *     nothing is read (refused, never clamped);
     *   - throttled → 429, nothing read;
     *   - a read that cannot be trusted → 503 `bcc_unavailable`, no items,
     *     `no-store`, never a partial list;
     *   - success → `{items, next_after}`, `public, max-age=300`,
     *     `Vary: Authorization, Cookie`;
     *   - VIEWER-INDEPENDENT: a signed-in admin gets the same statements and
     *     the same answer as an anonymous caller.
     *
     * Which members, pages and groups are listed is proven on a real engine
     * in PublicSitemapIndexIntegrationTest.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class SitemapEndpointTest extends TestCase
    {
        private SitemapWpdb $wpdb;

        protected function setUp(): void
        {
            parent::setUp();
            if (!defined('ABSPATH')) {
                define('ABSPATH', sys_get_temp_dir() . '/');
            }
            if (!defined('BCC_TRUST_FRAUD_HIGH')) {
                define('BCC_TRUST_FRAUD_HIGH', 80);
            }
            require_once dirname(__DIR__) . '/Stubs/cards-search-throttle-stubs.php';
            require_once dirname(__DIR__) . '/Stubs/sitemap-endpoint-stubs.php';
            $GLOBALS['__bcc_sitemap_meta'] = [];
            \BCC\Core\Repositories\UserMetaRepository::$unreadable = [];
            // core's REAL privacy rules and open-groups read, before first use
            // (the suite would otherwise autoload the group-privacy double).
            require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/PeepSoPagePrivacy.php');
            require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/PeepSoGroupPrivacy.php');
            require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/PeepSoGroupRepository.php');
            $GLOBALS['__bcc_cst'] = ['user_id' => 0, 'throttle_allow' => true, 'throttle_calls' => [], 'routes' => []];
            $this->wpdb      = new SitemapWpdb();
            $GLOBALS['wpdb'] = $this->wpdb;
            SitemapEndpoint::register();
        }

        /** @return array<string, mixed> the route args registered for $path */
        private static function route(string $path): array
        {
            foreach ($GLOBALS['__bcc_cst']['routes'] as [$namespace, $route, $args]) {
                if ($namespace === 'bcc/v1' && $route === $path) {
                    return $args;
                }
            }
            self::fail("route {$path} not registered");
        }

        /** @param array<string, mixed> $params */
        private static function call(string $path, array $params = ['after' => '0', 'limit' => '500']): \WP_REST_Response
        {
            $callback = self::route($path)['callback'];
            self::assertIsCallable($callback);

            return $callback(new \WP_REST_Request($params));
        }

        public function testThreeAnonymousRoutesWithTheDocumentedDefaults(): void
        {
            foreach (['/sitemap/members', '/sitemap/pages', '/sitemap/groups'] as $path) {
                $args = self::route($path);
                self::assertSame('GET', $args['methods']);
                self::assertSame('__return_true', $args['permission_callback']);
                self::assertSame(0, $args['args']['after']['default']);
                self::assertSame(500, $args['args']['limit']['default']);
                self::assertSame(1000, $args['args']['limit']['maximum']);
            }
            self::assertCount(3, $GLOBALS['__bcc_cst']['routes']);
        }

        /** @return iterable<string, array{0: array<string, mixed>}> */
        public static function refusedParams(): iterable
        {
            yield 'limit 0'          => [['after' => '0', 'limit' => '0']];
            yield 'limit over 1000'  => [['after' => '0', 'limit' => '1001']];
            yield 'negative after'   => [['after' => '-1', 'limit' => '10']];
            yield 'non-numeric'      => [['after' => 'x', 'limit' => '10']];
            yield 'fractional limit' => [['after' => '0', 'limit' => '2.5']];
            // A JSON body hands the handler INTEGERS, which skip the digit check.
            yield 'integer negative after'  => [['after' => -1, 'limit' => 10]];
            yield 'integer limit 0'         => [['after' => 0, 'limit' => 0]];
            yield 'integer limit over 1000' => [['after' => 0, 'limit' => 1001]];
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('refusedParams')]
        public function testOutOfRangeParamsAre400AndReadNothing(array $params): void
        {
            foreach (['/sitemap/members', '/sitemap/pages', '/sitemap/groups'] as $path) {
                $resp = self::call($path, $params);
                self::assertSame(400, $resp->get_status(), $path);
                self::assertSame('bcc_invalid_request', $resp->get_data()['error']['code'] ?? null);
            }
            self::assertSame([], $this->wpdb->queries, 'refused, never clamped');
        }

        public function testThrottledIs429AndReadsNothing(): void
        {
            $GLOBALS['__bcc_cst']['throttle_allow'] = false;

            $resp = self::call('/sitemap/members');

            self::assertSame(429, $resp->get_status());
            self::assertSame('bcc_rate_limited', $resp->get_data()['error']['code'] ?? null);
            self::assertSame([], $this->wpdb->queries);
            self::assertSame('sitemap_index', $GLOBALS['__bcc_cst']['throttle_calls'][0][0] ?? null);
        }

        public function testAFailedReadIs503WithNoItemsOnEveryRoute(): void
        {
            $this->wpdb->fail = true;

            foreach (['/sitemap/members', '/sitemap/pages', '/sitemap/groups'] as $path) {
                $resp = self::call($path);
                self::assertSame(503, $resp->get_status(), $path);
                self::assertSame('bcc_unavailable', $resp->get_data()['error']['code'] ?? null, $path);
                self::assertArrayNotHasKey('items', $resp->get_data()['data'] ?? [], $path);
                self::assertSame('no-store', $resp->get_headers()['Cache-Control'] ?? null, $path);
            }
        }

        public function testAnEmptyAnswerIsAPublicCacheableEnvelope(): void
        {
            foreach (['/sitemap/members', '/sitemap/pages', '/sitemap/groups'] as $path) {
                $resp = self::call($path);
                self::assertSame(200, $resp->get_status(), $path);
                self::assertSame(['items' => [], 'next_after' => null], $resp->get_data()['data'] ?? null, $path);
                self::assertSame('public, max-age=300', $resp->get_headers()['Cache-Control'] ?? null, $path);
                self::assertSame('Authorization, Cookie', $resp->get_headers()['Vary'] ?? null, $path);
            }
        }

        public function testTheCursorAndLimitReachTheSql(): void
        {
            self::call('/sitemap/members', ['after' => '42', 'limit' => '7']);
            self::call('/sitemap/pages', ['after' => '43', 'limit' => '8']);

            self::assertStringContainsString('wp_users.ID > 42', $this->wpdb->queries[0]);
            self::assertStringContainsString('LIMIT 7', $this->wpdb->queries[0]);
            self::assertStringContainsString('p.ID > 43', $this->wpdb->queries[1]);
            self::assertStringContainsString('LIMIT 8', $this->wpdb->queries[1]);
        }

        /**
         * The member SQL is the ANONYMOUS rule whoever asks: public access
         * only, the three unlisted roles, the suspension clause for viewer 0,
         * and the shared listing opt-out fragment.
         */
        public function testTheMemberSqlIsTheAnonymousRule(): void
        {
            self::call('/sitemap/members');
            $sql = $this->wpdb->queries[0];

            self::assertStringContainsString("bcc_pub_acc.usr_role NOT IN ('register', 'verified', 'ban')", $sql);
            self::assertStringContainsString('bcc_pub_acc.usr_profile_acc <= 10', $sql);
            self::assertStringContainsString('INNER JOIN wp_peepso_users bcc_pub_acc', $sql);
            self::assertStringContainsString('bcc_f182.is_suspended IS NULL OR bcc_f182.is_suspended = 0 OR wp_users.ID = 0', $sql);
            self::assertStringContainsString(
                \BCC\Trust\Core\Repositories\UserSyncRepository::listingOptOutSql('wp_users.ID'),
                $sql,
                'the shared listing opt-out fragment (F-519)'
            );
        }

        public function testMembersCarryTheirCanonicalPathAndAShortScanEnds(): void
        {
            $this->wpdb->rows = [(object) ['id' => '5'], (object) ['id' => '6']];
            $GLOBALS['__bcc_sitemap_meta'] = [
                5 => ['bcc_handle' => 'good-one'],
                6 => ['bcc_handle' => 'someone@example.test'],
            ];

            $full = self::call('/sitemap/members', ['after' => '0', 'limit' => '10'])->get_data()['data'] ?? null;
            self::assertSame(['items' => [['path' => '/u/good-one']], 'next_after' => null], $full, 'the scan came back short: the end');

            $page = self::call('/sitemap/members', ['after' => '0', 'limit' => '2'])->get_data()['data'] ?? null;
            self::assertSame(6, $page['next_after'] ?? null, 'a full scan: next_after is the last id SCANNED, even when dropped');
        }

        /** ⚠ The usermeta read failing must fail the whole page, never list a member half-checked. */
        public function testAFailedMetaReadIs503NotAPartialList(): void
        {
            $this->wpdb->rows = [(object) ['id' => '5'], (object) ['id' => '6']];
            $GLOBALS['__bcc_sitemap_meta'] = [5 => ['bcc_handle' => 'good-one'], 6 => ['bcc_handle' => 'good-two']];
            \BCC\Core\Repositories\UserMetaRepository::$unreadable = [6 => true];

            $resp = self::call('/sitemap/members');

            self::assertSame(503, $resp->get_status());
            self::assertSame('bcc_unavailable', $resp->get_data()['error']['code'] ?? null);
        }

        /** P1: an unreadable SQL check is "not indexable", whatever the usermeta says. */
        public function testAProfileIsNotIndexableWhenItsCheckCannotBeRead(): void
        {
            $GLOBALS['__bcc_sitemap_meta'] = [5 => ['bcc_handle' => 'good-one']];
            $index = \BCC\Trust\Core\Plugin::instance()->publicProfileIndex();

            $this->wpdb->rows = [(object) ['id' => '5']];
            self::assertTrue($index->isIndexable(5), 'control: readable and eligible');

            $this->wpdb->fail = true;
            self::assertFalse($index->isIndexable(5));
        }

        public function testPagesMapTheirTypeAndEndWhenTheScanIsShort(): void
        {
            $this->wpdb->rows = [
                (object) ['id' => '7', 'slug' => 'val-page', 'page_type' => 'validator'],
                (object) ['id' => '8', 'slug' => 'proj-page', 'page_type' => ''],
                (object) ['id' => '9', 'slug' => 'odd-page', 'page_type' => 'mystery'],
            ];

            $full = self::call('/sitemap/pages', ['after' => '0', 'limit' => '10'])->get_data()['data'] ?? null;
            self::assertSame(
                ['items' => [['path' => '/v/val-page'], ['path' => '/p/proj-page']], 'next_after' => null],
                $full
            );

            $page = self::call('/sitemap/pages', ['after' => '0', 'limit' => '3'])->get_data()['data'] ?? null;
            self::assertSame(9, $page['next_after'] ?? null);
        }

        public function testASignedInAdminGetsExactlyTheAnonymousAnswer(): void
        {
            foreach (['/sitemap/members', '/sitemap/pages', '/sitemap/groups'] as $path) {
                $this->wpdb->queries = [];
                $GLOBALS['__bcc_cst']['user_id'] = 0;
                $anon        = self::call($path)->get_data();
                $anonQueries = $this->wpdb->queries;

                $this->wpdb->queries = [];
                $GLOBALS['__bcc_cst']['user_id'] = 1; // the site admin
                $admin        = self::call($path)->get_data();
                $adminQueries = $this->wpdb->queries;

                self::assertSame($anonQueries, $adminQueries, "{$path}: same statements");
                self::assertSame($anon['data'] ?? null, $admin['data'] ?? null, "{$path}: same answer");
            }
        }
    }
}
