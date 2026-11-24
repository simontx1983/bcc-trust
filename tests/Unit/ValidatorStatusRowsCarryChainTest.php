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

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Repositories\ValidatorRepository;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-584: the two validator reads behind `onchain_signals` and
     * `validator_stats` carry the chain id, so their status can be projected
     * (ValidatorStatusProjection). findCommunityStatsByIds rebuilds each row
     * from an explicit key list, so the id must survive that rebuild too: a
     * row without it reads `unknown` everywhere.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ValidatorStatusRowsCarryChainTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            if (!defined('ABSPATH')) {
                define('ABSPATH', sys_get_temp_dir() . '/');
            }
            $GLOBALS['wpdb'] = new ValidatorRowsWpdb();
        }

        public function testCommunityStatsRowsCarryTheChainIdAsAnInteger(): void
        {
            $GLOBALS['wpdb']->rows = [(object) [
                'validator_id'    => '4',
                'moniker'         => 'Validator One',
                'status'          => 'active',
                'commission_rate' => '5.00',
                'total_stake'     => '1000',
                'delegator_count' => '12',
                'native_token'    => 'atom',
                'chain_name'      => 'Cosmos Hub',
                'chain_id'        => '7',
                'page_slug'       => null,
                'page_title'      => null,
            ]];

            $rows = ValidatorRepository::findCommunityStatsByIds([4]);

            self::assertSame(7, $rows[4]->chain_id ?? null);
            self::assertContains('v.chain_id', self::selectList($GLOBALS['wpdb']->sql[0] ?? ''));
        }

        public function testTheCardSignalsReadSelectsTheChainId(): void
        {
            $GLOBALS['wpdb']->row = (object) ['status' => 'active', 'chain_id' => '7'];

            ValidatorRepository::findSignalsByPageId(9);

            self::assertContains('v.chain_id', self::selectList($GLOBALS['wpdb']->sql[0] ?? ''));
        }

        /**
         * The selected columns only: both queries also JOIN on v.chain_id,
         * so a search of the whole statement would pass without it.
         *
         * @return list<string>
         */
        private static function selectList(string $sql): array
        {
            self::assertSame(1, preg_match('/^\s*SELECT\s+(.+?)\s+FROM\s/is', $sql, $m), 'a SELECT … FROM statement');

            return array_map(static fn (string $col): string => trim($col), explode(',', $m[1]));
        }
    }

    /** Answers fixed rows and records each statement. */
    final class ValidatorRowsWpdb
    {
        public string $prefix   = 'wp_';
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';

        /** @var list<string> */
        public array $sql = [];

        /** @var list<object> */
        public array $rows = [];

        public ?object $row = null;

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            $sql = str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], $sql);
            return vsprintf($sql, $args);
        }

        /** @return list<object> */
        public function get_results(string $sql, string $output = 'OBJECT'): array
        {
            $this->sql[] = $sql;
            return $this->rows;
        }

        public function get_row(string $sql): ?object
        {
            $this->sql[] = $sql;
            return $this->row;
        }
    }
}
