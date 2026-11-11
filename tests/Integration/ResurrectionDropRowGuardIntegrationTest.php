<?php

declare(strict_types=1);

namespace {
    // The migration files register themselves with add_action() at load time;
    // the integration bootstrap does not stub it (same guarded stub other
    // migration suites use).
    if (!function_exists('add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            return true;
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * F-149 — the "resurrection-proof" drops must not destroy data.
     *
     * These migrations re-probe hourly and remove any retired table (or
     * column) that reappears. A retired object that comes back WITH DATA is
     * what a tarball rollback to older code produces; rolling forward again
     * used to delete it silently. Now an empty resurrection is still dropped,
     * but a populated one is refused, logged with its count, left unstamped
     * and re-checked hourly.
     *
     * The REAL migrations run against a REAL server.
     */
    #[Group('integration')]
    #[Group('mariadb')]
    final class ResurrectionDropRowGuardIntegrationTest extends TestCase
    {
        /** @var list<string> */
        private array $created = [];

        protected function setUp(): void
        {
            parent::setUp();
            $root = dirname(__DIR__, 2) . '/includes/database/';
            require_once $root . 'migration-runner.php';
            require_once $root . 'drop-endorsements-table.php';
            require_once $root . 'drop-user-ranks-table.php';
            require_once $root . 'drop-trust-flags-table.php';
            require_once $root . 'drop-onchain-role-boost-columns.php';

            $GLOBALS['wpdb']->clearFaultInjection();
            foreach ([
                'bcc_trust_endorsements_table_dropped',
                'bcc_trust_user_ranks_table_dropped',
                'bcc_trust_flags_table_dropped',
                'bcc_trust_onchain_role_boost_cols_dropped',
            ] as $option) {
                unset($GLOBALS['__bcc_test_options'][$option]);
            }
            foreach (array_keys($GLOBALS['__bcc_test_transients'] ?? []) as $key) {
                if (str_starts_with($key, 'bcc_trust_migration_backoff_') || str_ends_with($key, '_drop_recheck')) {
                    unset($GLOBALS['__bcc_test_transients'][$key]);
                }
            }
        }

        protected function tearDown(): void
        {
            global $wpdb;
            $wpdb->clearFaultInjection();
            foreach ($this->created as $table) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }
            $this->created = [];
            parent::tearDown();
        }

        /** @return array<string, array{string, string, string, callable-string}> */
        public static function retiredTables(): array
        {
            return [
                'endorsements' => ['bcc_trust_endorsements', 'endorsements_table', 'bcc_trust_endorsements_table_dropped', 'bcc_trust_drop_endorsements_table'],
                'user ranks'   => ['bcc_user_ranks', 'user_ranks_table', 'bcc_trust_user_ranks_table_dropped', 'bcc_trust_drop_user_ranks_table'],
                'trust flags'  => ['bcc_trust_flags', 'trust_flags_table', 'bcc_trust_flags_table_dropped', 'bcc_trust_drop_trust_flags_table'],
            ];
        }

        #[DataProvider('retiredTables')]
        public function testAResurrectedTableWithRowsIsNotDropped(string $suffix, string $migrationId, string $doneOption, string $migration): void
        {
            global $wpdb;
            $table = $this->createTable($suffix);
            $wpdb->query("INSERT INTO `{$table}` (id) VALUES (1), (2)");

            $migration();

            self::assertTrue($this->tableExists($table), 'a table that came back with rows must survive');
            self::assertSame(2, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"), 'and keep every row');
            self::assertFalse(\get_option($doneOption), 'a refusal must not be stamped done');
            self::assertNotFalse(\get_transient('bcc_trust_migration_backoff_' . $migrationId), 'a refusal re-checks hourly');

            // Anti-vacuity: once the rows are gone (an operator's decision),
            // the same migration drops the now-empty table.
            $wpdb->query("DELETE FROM `{$table}`");
            \delete_transient('bcc_trust_migration_backoff_' . $migrationId);
            $migration();
            self::assertFalse($this->tableExists($table));
            self::assertNotFalse(\get_option($doneOption));
        }

        #[DataProvider('retiredTables')]
        public function testAnEmptyResurrectedTableIsStillDropped(string $suffix, string $migrationId, string $doneOption, string $migration): void
        {
            $table = $this->createTable($suffix);

            $migration();

            self::assertFalse($this->tableExists($table));
            self::assertNotFalse(\get_option($doneOption));
        }

        #[DataProvider('retiredTables')]
        public function testAFailedRowCountIsNotTreatedAsEmpty(string $suffix, string $migrationId, string $doneOption, string $migration): void
        {
            global $wpdb;
            $table = $this->createTable($suffix);

            $wpdb->failQueriesMatching = '/^\s*SELECT COUNT\(\*\) FROM `' . preg_quote($table, '/') . '`/i';
            $migration();
            self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
            $wpdb->clearFaultInjection();

            self::assertTrue($this->tableExists($table), 'an unreadable count is unknown, not empty');
            self::assertFalse(\get_option($doneOption));
        }

        public function testRoleBoostColumnsWithDataAreNotDropped(): void
        {
            global $wpdb;
            $signals = $this->createSignals();
            $wpdb->query("INSERT INTO `{$signals}` (id, trust_boost, fraud_reduction) VALUES (1, 0.50, NULL), (2, NULL, NULL)");

            \bcc_trust_drop_onchain_role_boost_columns();

            self::assertTrue($this->columnExists($signals, 'trust_boost'), 'a populated retired column must survive');
            self::assertTrue($this->columnExists($signals, 'fraud_reduction'), 'and nothing is dropped piecemeal');
            self::assertFalse(\get_option('bcc_trust_onchain_role_boost_cols_dropped'));
            self::assertNotFalse(\get_transient('bcc_trust_migration_backoff_onchain_role_boost_cols'));

            // Anti-vacuity: zero/NULL-only columns are empty and are dropped.
            $wpdb->query("UPDATE `{$signals}` SET trust_boost = 0");
            \delete_transient('bcc_trust_migration_backoff_onchain_role_boost_cols');
            \bcc_trust_drop_onchain_role_boost_columns();
            self::assertFalse($this->columnExists($signals, 'trust_boost'));
            self::assertFalse($this->columnExists($signals, 'fraud_reduction'));
            self::assertNotFalse(\get_option('bcc_trust_onchain_role_boost_cols_dropped'));
        }

        /** #401 review L2: a failed table probe is unknown, not "absent". */
        public function testRoleBoostTableProbeFailureBacksOff(): void
        {
            global $wpdb;
            $this->createSignals();

            $wpdb->failQueriesMatching = '/SHOW TABLES LIKE/i';
            \bcc_trust_drop_onchain_role_boost_columns();
            self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
            $wpdb->clearFaultInjection();

            self::assertFalse(\get_option('bcc_trust_onchain_role_boost_cols_dropped'));
            self::assertNotFalse(\get_transient('bcc_trust_migration_backoff_onchain_role_boost_cols'), 'a failed probe must start the backoff');
        }

        // ── helpers ────────────────────────────────────────────────────────

        private function createTable(string $suffix): string
        {
            global $wpdb;
            $table = $wpdb->prefix . $suffix;
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            $wpdb->query("CREATE TABLE `{$table}` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB");
            self::assertSame('', $wpdb->last_error, "fixture: create {$table}");
            $this->created[] = $table;
            return $table;
        }

        private function createSignals(): string
        {
            global $wpdb;
            $table = $wpdb->prefix . 'bcc_onchain_signals';
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            $wpdb->query(
                "CREATE TABLE `{$table}` (
                    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                    trust_boost DECIMAL(6,2) NULL,
                    fraud_reduction DECIMAL(6,2) NULL,
                    KEY idx_trust_boost (trust_boost)
                 ) ENGINE=InnoDB"
            );
            self::assertSame('', $wpdb->last_error, 'fixture: create signals');
            $this->created[] = $table;
            return $table;
        }

        private function tableExists(string $table): bool
        {
            global $wpdb;
            return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }

        private function columnExists(string $table, string $column): bool
        {
            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
                $table,
                $column
            )) > 0;
        }
    }
}
