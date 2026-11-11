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
     * F-41 / F-127 — two older migrations that could destroy data.
     *
     *  - rename-pull-to-watch: in the dual-column state it ignored the
     *    backfill result and dropped the legacy column regardless, so a
     *    failed UPDATE destroyed the legacy values.
     *  - retire-stargaze-chain: every statement's result was (int)-cast
     *    (false → 0), the chain row was deleted and the flag stamped
     *    whatever happened, and rows elsewhere keyed by the chain — gated
     *    groups above all — were orphaned.
     *
     * The REAL migration code runs against a REAL server; failure branches are
     * reached with server fault injection and every case asserts the fault
     * fired.
     */
    #[Group('integration')]
    #[Group('mariadb')]
    final class LegacyMigrationDataLossGuardsIntegrationTest extends TestCase
    {
        private const RETIRED = 'bcc_trust_stargaze_chain_retired';
        private const BACKOFF = 'bcc_trust_stargaze_retire_backoff';

        /** @var list<string> tables this suite created (dropped in tearDown) */
        private array $createdTables = [];
        private int $chainId = 0;
        /** @var list<int> */
        private array $postIds = [];

        protected function setUp(): void
        {
            parent::setUp();
            $root = dirname(__DIR__, 2) . '/includes/database/';
            require_once $root . 'rename-pull-to-watch.php';
            require_once $root . 'retire-stargaze-chain.php';

            $GLOBALS['wpdb']->clearFaultInjection();
            unset($GLOBALS['__bcc_test_options'][self::RETIRED], $GLOBALS['__bcc_test_transients'][self::BACKOFF]);
        }

        protected function tearDown(): void
        {
            global $wpdb;
            $wpdb->clearFaultInjection();
            $p = $wpdb->prefix;
            if ($this->chainId > 0) {
                foreach (['bcc_onchain_collections', 'bcc_wallet_links', 'bcc_nft_holdings'] as $suffix) {
                    $wpdb->query($wpdb->prepare("DELETE FROM `{$p}{$suffix}` WHERE chain_id = %d", $this->chainId));
                }
                $wpdb->query($wpdb->prepare("DELETE FROM `{$p}bcc_chains` WHERE id = %d", $this->chainId));
            }
            foreach ($this->postIds as $id) {
                $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d", $id));
                $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->posts}` WHERE ID = %d", $id));
            }
            foreach ($this->createdTables as $table) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }
            $this->createdTables = [];
            $this->postIds       = [];
            $this->chainId       = 0;
            parent::tearDown();
        }

        // ── F-41: rename-pull-to-watch dual-column reconcile ──────────────

        public function testAFailedBackfillKeepsTheLegacyColumn(): void
        {
            global $wpdb;
            $table = $this->dualColumnFixture();

            $wpdb->failQueriesMatching = '/^\s*UPDATE `' . preg_quote($table, '/') . '`/i';
            $changed = \bcc_trust_pw_reconcile_column($table, 'tier_at_pull', 'tier_at_watch', 'VARCHAR(20) DEFAULT NULL');
            $this->assertFaultFired();

            self::assertFalse($changed);
            self::assertTrue($this->columnExists($table, 'tier_at_pull'), 'a failed backfill must not drop the legacy column');
            self::assertSame('gold', $wpdb->get_var("SELECT tier_at_pull FROM `{$table}` WHERE id = 1"), 'and its data survives');
        }

        public function testAnUnverifiableBackfillKeepsTheLegacyColumn(): void
        {
            global $wpdb;
            $table = $this->dualColumnFixture();

            $wpdb->failQueriesMatching = '/SELECT COUNT\(\*\) FROM `' . preg_quote($table, '/') . '`/i';
            $changed = \bcc_trust_pw_reconcile_column($table, 'tier_at_pull', 'tier_at_watch', 'VARCHAR(20) DEFAULT NULL');
            $this->assertFaultFired();

            self::assertFalse($changed);
            self::assertTrue($this->columnExists($table, 'tier_at_pull'), 'unproven is not proven: keep the column');
        }

        /** Anti-vacuity: a healthy reconcile backfills, then drops. */
        public function testAHealthyReconcileBackfillsThenDrops(): void
        {
            global $wpdb;
            $table = $this->dualColumnFixture();

            self::assertTrue(\bcc_trust_pw_reconcile_column($table, 'tier_at_pull', 'tier_at_watch', 'VARCHAR(20) DEFAULT NULL'));
            self::assertFalse($this->columnExists($table, 'tier_at_pull'));
            self::assertSame('gold', $wpdb->get_var("SELECT tier_at_watch FROM `{$table}` WHERE id = 1"), 'the value moved before the drop');
            self::assertSame('silver', $wpdb->get_var("SELECT tier_at_watch FROM `{$table}` WHERE id = 2"), 'an existing new value is kept');
        }

        // ── F-41 / F-127: retire-stargaze-chain ───────────────────────────

        public function testARetirementWithNoDependentsDeletesAndStamps(): void
        {
            global $wpdb;
            $this->stargazeFixture();

            \bcc_trust_retire_stargaze_chain();

            self::assertNotFalse(\get_option(self::RETIRED));
            self::assertNull($wpdb->get_var($wpdb->prepare("SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE id = %d", $this->chainId)));
            self::assertSame(0, $this->countForChain('bcc_onchain_collections'));
            self::assertSame(0, $this->countForChain('bcc_wallet_links'));
        }

        public function testAGatedGroupOnAStargazeCollectionRefusesTheRetirement(): void
        {
            global $wpdb;
            $collectionId = $this->stargazeFixture();
            $groupId      = $this->insertPost('publish');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, '_bcc_gate_collection_id', %s)",
                $groupId,
                (string) $collectionId
            ));

            \bcc_trust_retire_stargaze_chain();

            self::assertFalse(\get_option(self::RETIRED), 'a refusal must not stamp retired');
            self::assertNotFalse(\get_transient(self::BACKOFF), 'a refusal re-checks hourly');
            self::assertSame(1, $this->countForChain('bcc_onchain_collections'), 'nothing is deleted on a refusal');
            self::assertNotNull($wpdb->get_var($wpdb->prepare("SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE id = %d", $this->chainId)));
        }

        /**
         * #425 review M1: posts that reference the CHAIN directly — a
         * chain-gated group (`_bcc_gate_chain_id`) or a Hall tagged with the
         * chain (`_bcc_chain_tag`) — must also refuse the retirement.
         *
         * @return array<string, array{string}>
         */
        public static function chainReferencingMetaKeys(): array
        {
            return [
                'chain-gated group' => ['_bcc_gate_chain_id'],
                'chain-tagged hall' => ['_bcc_chain_tag'],
            ];
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('chainReferencingMetaKeys')]
        public function testAPostReferencingTheChainRefusesTheRetirement(string $metaKey): void
        {
            global $wpdb;
            $this->stargazeFixture();
            $postId = $this->insertPost('draft'); // non-trashed, even unpublished, counts
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                $postId,
                $metaKey,
                (string) $this->chainId
            ));
            self::assertSame('', $wpdb->last_error, 'fixture: chain-reference meta');

            \bcc_trust_retire_stargaze_chain();

            self::assertFalse(\get_option(self::RETIRED), "{$metaKey} must refuse the retirement");
            self::assertNotNull($wpdb->get_var($wpdb->prepare("SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE id = %d", $this->chainId)));
        }

        /** A TRASHED post referencing the chain does not block it. */
        public function testATrashedChainReferenceDoesNotBlockTheRetirement(): void
        {
            global $wpdb;
            $this->stargazeFixture();
            $postId = $this->insertPost('trash');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, '_bcc_chain_tag', %s)",
                $postId,
                (string) $this->chainId
            ));

            \bcc_trust_retire_stargaze_chain();

            self::assertNotFalse(\get_option(self::RETIRED));
        }

        public function testAHoldingOnTheChainRefusesTheRetirement(): void
        {
            global $wpdb;
            $this->stargazeFixture();
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$wpdb->prefix}bcc_nft_holdings`
                    (wallet_link_id, chain_id, contract_address, token_id, last_seen_block, confirmed_at)
                 VALUES (999, %d, 'stars1contract', '1', 1, UTC_TIMESTAMP())",
                $this->chainId
            ));
            self::assertSame('', $wpdb->last_error, 'fixture: holding insert');

            \bcc_trust_retire_stargaze_chain();

            self::assertFalse(\get_option(self::RETIRED));
            self::assertSame(1, $this->countForChain('bcc_wallet_links'), 'the wallet link a holding hangs off survives');
        }

        /** A failed delete part-way rolls EVERYTHING back and is not stamped. */
        public function testAFailedDeleteRollsBackAndIsNotStamped(): void
        {
            global $wpdb;
            $this->stargazeFixture();

            $wpdb->failQueriesMatching = '/DELETE FROM `\S*bcc_onchain_collections`/i';
            \bcc_trust_retire_stargaze_chain();
            $this->assertFaultFired();

            self::assertFalse(\get_option(self::RETIRED), 'a failed step must not stamp retired');
            self::assertNotFalse(\get_transient(self::BACKOFF));
            self::assertSame(1, $this->countForChain('bcc_wallet_links'), 'the earlier wallet-link delete was rolled back');
            self::assertNotNull($wpdb->get_var($wpdb->prepare("SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE id = %d", $this->chainId)));
        }

        /**
         * #425 review L2: after a mid-transaction reconnect the remaining
         * statements run in AUTOCOMMIT, so the chain-row DELETE must prove its
         * own precondition. With a child row still present it deletes nothing
         * (returns 0), and the retirement treats 0 as a failure.
         */
        public function testTheChainRowIsNeverDeletedWhileAChildRowRemains(): void
        {
            global $wpdb;
            $this->stargazeFixture(); // leaves a collection + a wallet link on the chain

            $affected = \bcc_trust_stargaze_delete_chain_row_if_childless($this->chainId, [
                'bcc_user_nft_selections',
                'bcc_wallet_links',
                'bcc_onchain_collection_pieces',
                'bcc_onchain_collections',
            ]);

            self::assertSame(0, $affected, 'children remain, so the chain row must not go');
            self::assertNotNull($wpdb->get_var($wpdb->prepare("SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE id = %d", $this->chainId)));
        }

        /** Anti-vacuity: with no children left it deletes exactly the one row. */
        public function testTheChainRowIsDeletedOnceChildless(): void
        {
            global $wpdb;
            $this->stargazeFixture();
            foreach (['bcc_onchain_collections', 'bcc_wallet_links'] as $suffix) {
                $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}{$suffix}` WHERE chain_id = %d", $this->chainId));
            }

            $affected = \bcc_trust_stargaze_delete_chain_row_if_childless($this->chainId, [
                'bcc_user_nft_selections',
                'bcc_wallet_links',
                'bcc_onchain_collection_pieces',
                'bcc_onchain_collections',
            ]);

            self::assertSame(1, $affected);
        }

        /** A failed chain lookup is unknown — never "no stargaze chain, done". */
        public function testAFailedChainLookupIsNotStampedDone(): void
        {
            global $wpdb;
            $this->stargazeFixture();

            $wpdb->failQueriesMatching = '/SELECT id FROM `\S*bcc_chains` WHERE slug/i';
            \bcc_trust_retire_stargaze_chain();
            $this->assertFaultFired();

            self::assertFalse(\get_option(self::RETIRED));
        }

        // ── helpers ────────────────────────────────────────────────────────

        private function dualColumnFixture(): string
        {
            global $wpdb;
            $table = $wpdb->prefix . 'bcc_test_pw_dual';
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            $wpdb->query(
                "CREATE TABLE `{$table}` (
                    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                    tier_at_pull VARCHAR(20) DEFAULT NULL,
                    tier_at_watch VARCHAR(20) DEFAULT NULL
                 ) ENGINE=InnoDB"
            );
            $wpdb->query("INSERT INTO `{$table}` (id, tier_at_pull, tier_at_watch) VALUES (1, 'gold', NULL), (2, 'bronze', 'silver')");
            self::assertSame('', $wpdb->last_error, 'fixture: dual-column table');
            $this->createdTables[] = $table;
            return $table;
        }

        /** A stargaze chain with one collection and one wallet link; returns the collection id. */
        private function stargazeFixture(): int
        {
            global $wpdb;
            $p = $wpdb->prefix;
            $wpdb->query("DELETE FROM `{$p}bcc_chains` WHERE slug = 'stargaze'");
            $wpdb->query("INSERT INTO `{$p}bcc_chains` (slug, name, chain_type) VALUES ('stargaze', 'Stargaze', 'cosmos')");
            self::assertSame('', $wpdb->last_error, 'fixture: chain row');
            $this->chainId = (int) $wpdb->insert_id;

            // The two tables the bootstrap does not install: minimal fixtures.
            foreach (['bcc_user_nft_selections', 'bcc_onchain_collection_pieces'] as $suffix) {
                $table = $p . $suffix;
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                    $wpdb->query("CREATE TABLE `{$table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, chain_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB");
                    $this->createdTables[] = $table;
                }
            }

            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$p}bcc_onchain_collections` (contract_address, chain_id, expires_at) VALUES ('stars1contract', %d, UTC_TIMESTAMP())",
                $this->chainId
            ));
            self::assertSame('', $wpdb->last_error, 'fixture: collection');
            $collectionId = (int) $wpdb->insert_id;

            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$p}bcc_wallet_links` (user_id, post_id, wallet_address, chain_id) VALUES (1, 0, 'stars1wallet', %d)",
                $this->chainId
            ));
            self::assertSame('', $wpdb->last_error, 'fixture: wallet link');

            return $collectionId;
        }

        private function insertPost(string $status): int
        {
            global $wpdb;
            $wpdb->query($wpdb->prepare("INSERT INTO `{$wpdb->posts}` (post_status) VALUES (%s)", $status));
            self::assertSame('', $wpdb->last_error, 'fixture: post');
            $id = (int) $wpdb->insert_id;
            $this->postIds[] = $id;
            return $id;
        }

        private function countForChain(string $suffix): int
        {
            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}{$suffix}` WHERE chain_id = %d",
                $this->chainId
            ));
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

        private function assertFaultFired(): void
        {
            global $wpdb;
            self::assertGreaterThan(0, $wpdb->injectedFailures, 'the injected fault must actually have fired');
            $wpdb->clearFaultInjection();
        }
    }
}
