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

    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * F-150 — the standalone `init` migrations must not stamp "done" when a
     * statement failed.
     *
     * Each one used to ignore every `$wpdb->query()` result and write its
     * done-option unconditionally, so a failed FK drop, DROP or ALTER was
     * never retried. They now fail closed — no stamp — and back off for an
     * hour (bcc_trust_migration_step_failed), so a permanently failing
     * statement is retried hourly rather than on every request.
     *
     * Every case runs the REAL migration against a REAL server and reaches
     * the failure branch with the shim's server fault injection, asserting
     * the fault actually fired. Each case then proves the same migration
     * DOES complete and stamp once the fault is gone (anti-vacuity), and that
     * the backoff really suppresses an immediate retry.
     */
    #[Group('integration')]
    #[Group('mariadb')]
    final class InitMigrationFailureStampIntegrationTest extends TestCase
    {
        /** @var list<string> fixture tables this suite created, dropped in tearDown */
        private array $created = [];

        protected function setUp(): void
        {
            parent::setUp();
            $root = dirname(__DIR__, 2) . '/includes/database/';
            require_once $root . 'migration-runner.php';
            require_once $root . 'drop-legacy-orphans.php';
            require_once $root . 'drop-legacy-indexes.php';
            require_once $root . 'drop-endorsement-bonus.php';
            require_once $root . 'drop-onchain-role-boost-columns.php';
            require_once $root . 'peepso-reaction-indexes.php';

            $GLOBALS['wpdb']->clearFaultInjection();
            foreach ([
                'bcc_trust_legacy_orphans_dropped',
                'bcc_trust_legacy_indexes_trimmed_v2',
                'bcc_trust_endorsement_bonus_dropped',
                'bcc_trust_onchain_role_boost_cols_dropped',
                'bcc_peepso_reaction_indexes_v1',
            ] as $option) {
                unset($GLOBALS['__bcc_test_options'][$option]);
            }
            foreach (array_keys($GLOBALS['__bcc_test_transients'] ?? []) as $key) {
                if (str_starts_with($key, 'bcc_trust_migration_backoff_')
                    || $key === 'bcc_trust_onchain_role_boost_drop_recheck'
                ) {
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
            // Leave page_scores exactly as the bootstrap made it.
            if ($this->columnExists($wpdb->prefix . 'bcc_trust_page_scores', 'endorsement_bonus')) {
                $wpdb->query("ALTER TABLE `{$wpdb->prefix}bcc_trust_page_scores` DROP COLUMN `endorsement_bonus`");
            }
            parent::tearDown();
        }

        public function testLegacyOrphansAreNotStampedWhenADropFails(): void
        {
            global $wpdb;
            $orphan = $this->createTable('bcc_user_locals', 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY');

            $wpdb->failQueriesMatching = '/^\s*DROP TABLE IF EXISTS \S*bcc_user_locals/i';
            \bcc_trust_drop_legacy_orphans();
            $this->assertFaultFired();

            self::assertFalse(\get_option('bcc_trust_legacy_orphans_dropped'), 'a failed DROP must not be stamped done');
            $this->assertBackoffSet('legacy_orphans');
            $this->assertBackoffSuppressesRetry('bcc_trust_drop_legacy_orphans', 'bcc_trust_legacy_orphans_dropped');

            $this->clearBackoff('legacy_orphans');
            \bcc_trust_drop_legacy_orphans();
            self::assertNotFalse(\get_option('bcc_trust_legacy_orphans_dropped'), 'a clean run completes and stamps');
            self::assertFalse($this->tableExists($orphan));
        }

        public function testLegacyIndexesAreNotStampedWhenADropFails(): void
        {
            global $wpdb;
            $reports = $this->createTable(
                'bcc_user_reports',
                'id BIGINT UNSIGNED NOT NULL PRIMARY KEY, reporter_id BIGINT UNSIGNED NOT NULL, '
                . 'reported_id BIGINT UNSIGNED NOT NULL, UNIQUE KEY uq_reporter_reported (reporter_id, reported_id)'
            );

            $wpdb->failQueriesMatching = '/DROP INDEX `uq_reporter_reported`/i';
            \bcc_trust_drop_legacy_indexes();
            $this->assertFaultFired();

            self::assertFalse(\get_option('bcc_trust_legacy_indexes_trimmed_v2'));
            $this->assertBackoffSet('legacy_indexes_v2');
            $this->assertBackoffSuppressesRetry('bcc_trust_drop_legacy_indexes', 'bcc_trust_legacy_indexes_trimmed_v2');

            $this->clearBackoff('legacy_indexes_v2');
            \bcc_trust_drop_legacy_indexes();
            self::assertNotFalse(\get_option('bcc_trust_legacy_indexes_trimmed_v2'));
            self::assertFalse($this->indexExists($reports, 'uq_reporter_reported'));
        }

        public function testEndorsementBonusIsNotStampedWhenTheColumnDropFails(): void
        {
            global $wpdb;
            $scores = $wpdb->prefix . 'bcc_trust_page_scores';
            $wpdb->query("ALTER TABLE `{$scores}` ADD COLUMN `endorsement_bonus` DECIMAL(10,2) NOT NULL DEFAULT 0");
            self::assertSame('', $wpdb->last_error, 'fixture: column add');

            $wpdb->failQueriesMatching = '/DROP COLUMN `endorsement_bonus`/i';
            \bcc_trust_drop_endorsement_bonus();
            $this->assertFaultFired();

            self::assertFalse(\get_option('bcc_trust_endorsement_bonus_dropped'));
            $this->assertBackoffSet('endorsement_bonus');
            $this->assertBackoffSuppressesRetry('bcc_trust_drop_endorsement_bonus', 'bcc_trust_endorsement_bonus_dropped');

            $this->clearBackoff('endorsement_bonus');
            \bcc_trust_drop_endorsement_bonus();
            self::assertNotFalse(\get_option('bcc_trust_endorsement_bonus_dropped'));
            self::assertFalse($this->columnExists($scores, 'endorsement_bonus'));
        }

        public function testRoleBoostColumnsAreNotStampedWhenTheIndexDropFails(): void
        {
            global $wpdb;
            $signals = $this->createTable(
                'bcc_onchain_signals',
                'id BIGINT UNSIGNED NOT NULL PRIMARY KEY, trust_boost DECIMAL(6,2) NULL, '
                . 'fraud_reduction DECIMAL(6,2) NULL, KEY idx_trust_boost (trust_boost)'
            );

            $wpdb->failQueriesMatching = '/DROP INDEX `idx_trust_boost`/i';
            \bcc_trust_drop_onchain_role_boost_columns();
            $this->assertFaultFired();

            self::assertFalse(\get_option('bcc_trust_onchain_role_boost_cols_dropped'));
            self::assertTrue($this->columnExists($signals, 'trust_boost'), 'the column cannot go while its index remains');
            $this->assertBackoffSet('onchain_role_boost_cols');

            $this->clearBackoff('onchain_role_boost_cols');
            \bcc_trust_drop_onchain_role_boost_columns();
            self::assertNotFalse(\get_option('bcc_trust_onchain_role_boost_cols_dropped'));
            self::assertFalse($this->columnExists($signals, 'trust_boost'));
            self::assertFalse($this->columnExists($signals, 'fraud_reduction'));
        }

        /**
         * The hourly re-verify pass used to log "dropped retired … columns"
         * even when nothing was there to drop, burying the one line that
         * matters (a resurrected column actually removed).
         */
        public function testRoleBoostReverifyLogsNothingWhenThereIsNothingToDrop(): void
        {
            $this->createTable('bcc_onchain_signals', 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY');
            if (!property_exists(\BCC\Core\Log\Logger::class, 'lines')) {
                self::markTestSkipped('the integration Logger stub is not the one in play');
            }
            \BCC\Core\Log\Logger::reset();

            \bcc_trust_drop_onchain_role_boost_columns();

            self::assertNotFalse(\get_option('bcc_trust_onchain_role_boost_cols_dropped'));
            $dropped = array_filter(
                \BCC\Core\Log\Logger::$lines,
                static fn(array $l): bool => str_contains($l['message'], 'dropped retired onchain role-boost columns')
            );
            self::assertSame([], array_values($dropped), 'a pass that dropped nothing must not claim it did');
        }

        public function testReactionIndexesAreNotStampedWhenACreateFails(): void
        {
            global $wpdb;
            $reactions = $this->createTable(
                'peepso_reactions',
                'reaction_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, reaction_user_id BIGINT UNSIGNED NOT NULL, '
                . 'reaction_type INT NOT NULL, reaction_act_id BIGINT UNSIGNED NOT NULL, reaction_timestamp DATETIME NULL'
            );
            $activities = $this->createTable(
                'peepso_activities',
                'act_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, act_owner_id BIGINT UNSIGNED NOT NULL'
            );

            $wpdb->failQueriesMatching = '/CREATE INDEX `idx_bcc_activities_owner`/i';
            \bcc_trust_create_peepso_reaction_indexes();
            $this->assertFaultFired();

            self::assertFalse(\get_option('bcc_peepso_reaction_indexes_v1'), 'a failed CREATE INDEX must not be stamped done');
            self::assertTrue($this->indexExists($reactions, 'idx_bcc_reactions_user_type'), 'independent indexes are still attempted');
            $this->assertBackoffSet('peepso_reaction_indexes_v1');
            $this->assertBackoffSuppressesRetry('bcc_trust_create_peepso_reaction_indexes', 'bcc_peepso_reaction_indexes_v1');

            $this->clearBackoff('peepso_reaction_indexes_v1');
            \bcc_trust_create_peepso_reaction_indexes();
            self::assertSame('1', \get_option('bcc_peepso_reaction_indexes_v1'));
            self::assertTrue($this->indexExists($activities, 'idx_bcc_activities_owner'));
        }

        // ── helpers ────────────────────────────────────────────────────────

        private function createTable(string $suffix, string $columns): string
        {
            global $wpdb;
            $table = $wpdb->prefix . $suffix;
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            $wpdb->query("CREATE TABLE `{$table}` ({$columns}) ENGINE=InnoDB");
            self::assertSame('', $wpdb->last_error, "fixture: create {$table}");
            $this->created[] = $table;
            return $table;
        }

        private function assertFaultFired(): void
        {
            global $wpdb;
            self::assertGreaterThan(0, $wpdb->injectedFailures, 'the injected fault must actually have fired');
            $wpdb->clearFaultInjection();
        }

        private function assertBackoffSet(string $migrationId): void
        {
            self::assertNotFalse(
                \get_transient('bcc_trust_migration_backoff_' . $migrationId),
                'a failed step must start the one-hour retry backoff'
            );
        }

        /** With the backoff live and NO fault, an immediate retry still does nothing. */
        private function assertBackoffSuppressesRetry(callable $migration, string $doneOption): void
        {
            $migration();
            self::assertFalse(\get_option($doneOption), 'the backoff must suppress an immediate retry');
        }

        private function clearBackoff(string $migrationId): void
        {
            \delete_transient('bcc_trust_migration_backoff_' . $migrationId);
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

        private function indexExists(string $table, string $index): bool
        {
            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
                $table,
                $index
            )) > 0;
        }
    }
}
