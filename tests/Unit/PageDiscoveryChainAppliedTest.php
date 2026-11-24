<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Services\PageDiscoveryService;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-439 — PageDiscoveryService::query() reports whether its results
     * really reflect `chain_slug`.
     *
     * The chain filter exists on the read-model path only; the legacy
     * fallback (read model empty: fresh install, broken sync) ignores it and
     * returns the plain ranking. /onboarding/suggestions tells the operator
     * "validators on <chain> first" from this flag, so it must be true ONLY
     * when a chain was asked for AND the read-model path served it.
     *
     * The path is forced through the service's own memo (`useReadModel`);
     * the SQL runs against a recording $wpdb that finds no rows, which both
     * paths answer through their early return.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class PageDiscoveryChainAppliedTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            if (!defined('ABSPATH')) {
                define('ABSPATH', sys_get_temp_dir() . '/');
            }
            // Global function + bcc-core class stubs, in THIS process only.
            require_once dirname(__DIR__) . '/Stubs/page-discovery-chain-applied-stubs.php';
            require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/ProbesReads.php');
            require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/PeepSoPagePrivacy.php');
            $GLOBALS['wpdb']        = new ChainAppliedEmptyWpdb();
            $GLOBALS['__bcc_cache'] = [];
        }

        private static function service(bool $readModel): PageDiscoveryService
        {
            $svc = (new \ReflectionClass(PageDiscoveryService::class))->newInstanceWithoutConstructor();
            $memo = new \ReflectionProperty(PageDiscoveryService::class, 'useReadModel');
            $memo->setValue($svc, $readModel);
            return $svc;
        }

        /** @param array<string, mixed> $extra */
        private static function query(bool $readModel, array $extra = []): array
        {
            return self::service($readModel)->query(
                ['types' => ['validator'], 'sort' => 'trust', 'limit' => 4, 'page' => 1] + $extra
            );
        }

        public function testTheReadModelPathAppliesAChain(): void
        {
            self::assertTrue(self::query(true, ['chain_slug' => 'cosmos'])['chain_applied']);
            // …and the chain really reached the SQL.
            self::assertTrue((bool) array_filter(
                $GLOBALS['wpdb']->statements,
                static fn (string $sql): bool => str_contains($sql, "c_chain.slug = 'cosmos'")
            ));
        }

        public function testTheLegacyFallbackDoesNotApplyAChain(): void
        {
            self::assertFalse(self::query(false, ['chain_slug' => 'cosmos'])['chain_applied']);
            self::assertFalse((bool) array_filter(
                $GLOBALS['wpdb']->statements,
                static fn (string $sql): bool => str_contains($sql, 'cosmos')
            ));
        }

        public function testNoChainIsNeverApplied(): void
        {
            self::assertFalse(self::query(true)['chain_applied']);
            self::assertFalse(self::query(false)['chain_applied']);
        }

        public function testACacheHitReportsThePathThatFilledIt(): void
        {
            // Filled by the legacy fallback…
            self::assertFalse(self::query(false, ['chain_slug' => 'cosmos'])['chain_applied']);
            $GLOBALS['wpdb']->statements = [];
            // …then read from the cache, even by a read-model-capable service.
            self::assertFalse(self::query(true, ['chain_slug' => 'cosmos'])['chain_applied']);
            self::assertSame([], $GLOBALS['wpdb']->statements, 'served from the cache');
        }
    }

    /** Records every statement and finds nothing, on both discovery paths. */
    final class ChainAppliedEmptyWpdb
    {
        public string $prefix     = 'wp_';
        public string $posts      = 'wp_posts';
        public string $postmeta   = 'wp_postmeta';
        public string $usermeta   = 'wp_usermeta';
        public string $users      = 'wp_users';
        public string $last_error = '';

        /** @var list<string> */
        public array $statements = [];

        /** @var list<object> */
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
            $this->statements[] = $sql;
            return '0';
        }

        /** @return list<object> */
        public function get_results(string $sql, string $output = 'OBJECT'): array
        {
            $this->statements[] = $sql;
            return [];
        }

        public function query(string $sql): int
        {
            $this->statements[] = $sql;
            $this->last_error   = '';
            $this->last_result  = str_contains($sql, 'COUNT(') ? [(object) ['n' => 0]] : [];
            return count($this->last_result);
        }
    }
}
