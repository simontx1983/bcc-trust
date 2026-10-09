<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * S9b — the destructive drop, against a real engine, in both directions.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * The migration's whole job is DDL. A double would accept any `ALTER` and
 * report success; only a real server rejects dropping a column an index
 * still spans, re-probes `INFORMATION_SCHEMA`, and shows whether a resumed
 * run actually converges.
 *
 * And `scripts/schema-drift-guard.php` compares table names and index
 * tuples with no column-level parity at all, so for the eight columns this
 * suite is the only automated check that exists in either direction.
 *
 * ── WHAT IS DELIBERATELY COVERED ────────────────────────────────────────
 *   1. Populated cleanup — rows present, not an empty-table no-op.
 *   2. The INDEX, explicitly. `KEY idx_cw_discovery (cw_discovery_state,
 *      cw_last_discovery_at)` spans two retired columns, and stripping the
 *      columns while keeping it is invalid DDL — `ERROR 1072: Key column
 *      'cw_discovery_state' doesn't exist in table`, which is exactly how
 *      the restore rehearsal failed before the backup artefact was fixed.
 *   3. Repeat execution — a second and third run are COMPLETE and change
 *      nothing.
 *   4. Partial failure / recovery — four half-applied states converge,
 *      because "it is idempotent" is a claim about every intermediate
 *      state and not just the first one.
 *   5. Retained projections after cleanup — the readers S9a decoupled still
 *      read, and the retained WRITERS still write.
 *   6. Preservation — unrelated columns, indexes and rows on the two
 *      surviving parent tables are untouched.
 *   7. Nothing re-adds what was dropped on the next schema pass.
 *
 * ⚠⚠ THIS CLASS MUTATES `wp_bcc_chain_checkpoints` AND `wp_bcc_chains`,
 * which the whole integration suite shares. `setUp()` builds the PRE-drop
 * shape (the retired columns, the index, the three tables, all populated);
 * `tearDown()` drops both parents and re-runs the REAL installers, which is
 * the discipline `ChainsCapabilityColumnAnchorIntegrationTest` uses and the
 * discipline whose absence let S9a leak a flag into a sibling suite.
 * Re-running `bcc_onchain_create_chains_table()` also re-seeds the default
 * chains. PHPUnit runs sequentially in one process, so no other test can
 * observe the window.
 *
 * @see \bcc_trust_drop_scanner_schema()
 * @see docs/scanner-schema-drop-runbook.md
 */
#[Group('integration')]
#[Group('mariadb')]
final class ScannerSchemaDropIntegrationTest extends TestCase
{
    /** @var list<string> */
    private const CK_COLUMNS = [
        'cw_discovery_state',
        'cw_code_cursor',
        'cw_max_code_id',
        'cw_backfill_completed_at',
        'cw_last_discovery_at',
        'cw_metadata_refreshed_at',
        'cw_last_error',
    ];

    private const CK_INDEX = 'idx_cw_discovery';

    private const CHAIN_COLUMN = 'cosmwasm_nft_discovery_enabled';

    /** @var list<string> */
    private const DROPPED_TABLES = [
        'bcc_cosmwasm_code_families',
        'bcc_cosmwasm_contracts',
        'bcc_discovery_runs',
    ];

    /**
     * Columns on the two parents that must SURVIVE. Re-asserted after every
     * cleanup, because a migration that dropped one of these and still
     * reported success is the failure mode with no symptom until a
     * projection reads — and then the symptom is every chain silently
     * reporting UNKNOWN out of a poisoned cache.
     *
     * @var array<string, list<string>>
     */
    private const MUST_SURVIVE = [
        'bcc_chain_checkpoints' => ['chain_id', 'last_processed_block', 'head_block', 'state',
                                    'cu_used_today', 'cu_budget_reset_at', 'last_run_at',
                                    'last_error', 'block_progression_history'],
        'bcc_chains'            => ['id', 'slug', 'name', 'chain_type', 'is_active',
                                    'bcc_supports_nft_collections', 'manual_collection_discovery_enabled'],
    ];

    /** The chain whose checkpoint this suite writes retired values into. */
    private const FIXTURE_CHAIN_ID = 8;

    private function prefix(): string
    {
        return $GLOBALS['wpdb']->prefix;
    }

    /** @return list<string> */
    private function columns(string $bare): array
    {
        $wpdb = $GLOBALS['wpdb'];

        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              ORDER BY ORDINAL_POSITION',
            $this->prefix() . $bare
        ));

        return array_map(static fn (object $r): string => (string) $r->c, $rows ?: []);
    }

    /** @return list<string> distinct index names, PRIMARY included */
    private function indexes(string $bare): array
    {
        $wpdb = $GLOBALS['wpdb'];

        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT DISTINCT INDEX_NAME AS i FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              ORDER BY INDEX_NAME',
            $this->prefix() . $bare
        ));

        return array_map(static fn (object $r): string => (string) $r->i, $rows ?: []);
    }

    private function tableExists(string $bare): bool
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = $this->prefix() . $bare;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Build the PRE-drop shape and populate it.
     *
     * The three tables get a reduced but faithful shape — the migration only
     * ever `DROP TABLE`s them, so what matters is that they exist and hold
     * rows, which is what makes the cleanup case populated rather than an
     * empty-table no-op.
     */
    private function buildPreDropShape(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $p    = $this->prefix();

        // ── the seven columns, then the index that spans two of them ─────
        $present = $this->columns('bcc_chain_checkpoints');
        foreach (self::CK_COLUMNS as $column) {
            if (in_array($column, $present, true)) {
                continue;
            }
            $type = match ($column) {
                'cw_discovery_state' => "VARCHAR(20) NOT NULL DEFAULT 'idle'",
                'cw_code_cursor'     => 'VARCHAR(255) DEFAULT NULL',
                'cw_max_code_id'     => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'cw_last_error'      => 'VARCHAR(255) DEFAULT NULL',
                default              => 'DATETIME DEFAULT NULL',
            };
            $wpdb->query("ALTER TABLE `{$p}bcc_chain_checkpoints` ADD COLUMN `{$column}` {$type}");
        }
        if (!in_array(self::CK_INDEX, $this->indexes('bcc_chain_checkpoints'), true)) {
            $wpdb->query(
                "ALTER TABLE `{$p}bcc_chain_checkpoints`
                 ADD KEY `" . self::CK_INDEX . "` (`cw_discovery_state`, `cw_last_discovery_at`)"
            );
        }
        if (!in_array(self::CHAIN_COLUMN, $this->columns('bcc_chains'), true)) {
            $wpdb->query(
                "ALTER TABLE `{$p}bcc_chains`
                 ADD COLUMN `" . self::CHAIN_COLUMN . "` TINYINT(1) NOT NULL DEFAULT 0"
            );
        }

        // ── the three tables, populated ──────────────────────────────────
        $wpdb->query("DROP TABLE IF EXISTS `{$p}bcc_cosmwasm_code_families`");
        $wpdb->query(
            "CREATE TABLE `{$p}bcc_cosmwasm_code_families` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                chain_id BIGINT UNSIGNED NOT NULL,
                code_id BIGINT UNSIGNED NOT NULL,
                classification VARCHAR(24) NOT NULL DEFAULT 'inconclusive',
                PRIMARY KEY (id),
                UNIQUE KEY uq_chain_code (chain_id, code_id)
            )"
        );
        $wpdb->query("DROP TABLE IF EXISTS `{$p}bcc_cosmwasm_contracts`");
        $wpdb->query(
            "CREATE TABLE `{$p}bcc_cosmwasm_contracts` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                chain_id BIGINT UNSIGNED NOT NULL,
                contract_address VARCHAR(128) NOT NULL,
                PRIMARY KEY (id)
            )"
        );
        $wpdb->query("DROP TABLE IF EXISTS `{$p}bcc_discovery_runs`");
        $wpdb->query(
            "CREATE TABLE `{$p}bcc_discovery_runs` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                chain_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                active_marker TINYINT(1) DEFAULT NULL,
                PRIMARY KEY (id)
            )"
        );

        for ($i = 1; $i <= 4; $i++) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$p}bcc_cosmwasm_code_families` (chain_id, code_id, classification)
                 VALUES (%d, %d, %s)",
                self::FIXTURE_CHAIN_ID,
                $i,
                'confirmed_cw721'
            ));
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$p}bcc_cosmwasm_contracts` (chain_id, contract_address)
                 VALUES (%d, %s)",
                self::FIXTURE_CHAIN_ID,
                'cosmos1contract' . $i
            ));
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$p}bcc_discovery_runs` (chain_id, status) VALUES (%d, %s)",
            self::FIXTURE_CHAIN_ID,
            'succeeded'
        ));

        // ── populate the retired COLUMNS too, so the drop destroys values ─
        ChainCheckpointRepository::ensureExists(self::FIXTURE_CHAIN_ID);
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$p}bcc_chain_checkpoints`
                SET cw_discovery_state   = 'backfilled',
                    cw_max_code_id       = 181,
                    cw_last_discovery_at = '2026-08-19 17:29:32'
              WHERE chain_id = %d",
            self::FIXTURE_CHAIN_ID
        ));
        $wpdb->query(
            "UPDATE `{$p}bcc_chains` SET `" . self::CHAIN_COLUMN . "` = 1
              WHERE id = (SELECT MIN(id) FROM (SELECT id FROM `{$p}bcc_chains`) AS t)"
        );

        ChainRepository::clearCache();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('BCC_TRUST_MIGRATION_COMPLETE')) {
            define('BCC_TRUST_MIGRATION_COMPLETE', 'complete');
        }
        if (!defined('BCC_TRUST_MIGRATION_INCOMPLETE')) {
            define('BCC_TRUST_MIGRATION_INCOMPLETE', 'incomplete');
        }
        require_once dirname(__DIR__, 2) . '/includes/database/migration-runner.php';
        require_once dirname(__DIR__, 2) . '/includes/database/drop-scanner-schema.php';

        // Fault injection is used by the partial-failure cases below. Reset it
        // here as well as in tearDown so a case that throws part-way cannot
        // leave a fault armed for a sibling suite.
        $GLOBALS['wpdb']->clearFaultInjection();

        $this->buildPreDropShape();
    }

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $p    = $this->prefix();

        $wpdb->clearFaultInjection();

        foreach (self::DROPPED_TABLES as $bare) {
            $wpdb->query("DROP TABLE IF EXISTS `{$p}{$bare}`");
        }

        // Restore the two shared parents to the POST-S9b shape — the shape
        // every other suite now expects — by dropping them and re-running the
        // REAL installers rather than this test's own idea of them. The
        // chains installer re-seeds the default chains on the way back.
        $wpdb->query("DROP TABLE IF EXISTS `{$p}bcc_chain_checkpoints`");
        bcc_onchain_create_chain_checkpoints_table();

        $wpdb->query("DROP TABLE IF EXISTS `{$p}bcc_chains`");
        bcc_onchain_create_chains_table();
        bcc_onchain_add_chains_nft_capability_columns();
        ChainRepository::clearCache();

        parent::tearDown();
    }

    // ══ 0. The denominator ══════════════════════════════════════════════

    public function testThePreDropShapeIsReallyBuiltAndPopulated(): void
    {
        $ck = $this->columns('bcc_chain_checkpoints');
        foreach (self::CK_COLUMNS as $c) {
            self::assertContains($c, $ck, "{$c} must be present before the drop");
        }
        self::assertContains(self::CK_INDEX, $this->indexes('bcc_chain_checkpoints'));
        self::assertContains(self::CHAIN_COLUMN, $this->columns('bcc_chains'));

        $wpdb = $GLOBALS['wpdb'];
        $p    = $this->prefix();
        foreach (self::DROPPED_TABLES as $bare) {
            self::assertTrue($this->tableExists($bare), "{$bare} must exist before the drop");
        }
        self::assertSame(4, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_cosmwasm_code_families`"));
        self::assertSame(4, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_cosmwasm_contracts`"));
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_discovery_runs`"));

        self::assertSame(
            'backfilled',
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT cw_discovery_state FROM `{$p}bcc_chain_checkpoints` WHERE chain_id = %d",
                self::FIXTURE_CHAIN_ID
            )),
            'the retired column must hold a real value, so the drop destroys something'
        );
        self::assertSame(
            1,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_chains` WHERE `" . self::CHAIN_COLUMN . "` = 1"),
            'and the retired flag must be set on exactly one chain'
        );
    }

    // ══ 1. Populated cleanup ════════════════════════════════════════════

    public function testAPopulatedSchemaIsCleanedUpCompletely(): void
    {
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());

        foreach (self::DROPPED_TABLES as $bare) {
            self::assertFalse($this->tableExists($bare), "{$bare} must be gone");
        }
        $ck = $this->columns('bcc_chain_checkpoints');
        foreach (self::CK_COLUMNS as $c) {
            self::assertNotContains($c, $ck, "{$c} must be gone");
        }
        self::assertNotContains(self::CK_INDEX, $this->indexes('bcc_chain_checkpoints'));
        self::assertNotContains(self::CHAIN_COLUMN, $this->columns('bcc_chains'));
    }

    // ══ 2. The index, explicitly ════════════════════════════════════════

    /**
     * ⚠ The migration hardcodes no index NAME. It derives the set from
     * INFORMATION_SCHEMA, so an install carrying a differently-named index
     * over the same columns is handled too — and this case proves the
     * derived path actually fires rather than matching one known string.
     */
    public function testAnIndexOverTheRetiredColumnsIsDroppedUnderAnyName(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $p    = $this->prefix();

        $wpdb->query("ALTER TABLE `{$p}bcc_chain_checkpoints` ADD KEY `some_other_cw_idx` (`cw_max_code_id`)");
        self::assertContains('some_other_cw_idx', $this->indexes('bcc_chain_checkpoints'));

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());

        $after = $this->indexes('bcc_chain_checkpoints');
        self::assertNotContains(self::CK_INDEX, $after);
        self::assertNotContains('some_other_cw_idx', $after, 'a differently-named index must go too');
        self::assertContains('PRIMARY', $after, 'and the primary key must survive');
    }

    // ══ 3. Repeat execution ═════════════════════════════════════════════

    public function testRepeatExecutionIsCompleteAndChangesNothing(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());

        $ckBefore   = $this->columns('bcc_chain_checkpoints');
        $chBefore   = $this->columns('bcc_chains');
        $ixBefore   = $this->indexes('bcc_chain_checkpoints');
        $slugsBefore = $wpdb->get_col('SELECT slug FROM `' . ChainRepository::table() . '` ORDER BY id');

        self::assertNotSame([], $slugsBefore, 'anti-vacuity: there must be chain rows to leave alone');

        self::assertSame(
            BCC_TRUST_MIGRATION_COMPLETE,
            bcc_trust_drop_scanner_schema(),
            'a second run must report COMPLETE, not INCOMPLETE'
        );
        self::assertSame(
            BCC_TRUST_MIGRATION_COMPLETE,
            bcc_trust_drop_scanner_schema(),
            'and a third — the runner may call it again before the done_option lands'
        );

        self::assertSame($ckBefore, $this->columns('bcc_chain_checkpoints'));
        self::assertSame($chBefore, $this->columns('bcc_chains'));
        self::assertSame($ixBefore, $this->indexes('bcc_chain_checkpoints'));
        self::assertSame(
            $slugsBefore,
            $wpdb->get_col('SELECT slug FROM `' . ChainRepository::table() . '` ORDER BY id'),
            'repeat runs must not touch chain rows'
        );
    }

    /**
     * And a run that finds NOTHING to do is COMPLETE rather than INCOMPLETE.
     *
     * This is the state a fresh install is in from the start, and the state
     * every install reaches permanently after the first successful run.
     */
    public function testAnInstallWithNothingToDoIsComplete(): void
    {
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());
    }

    // ══ 4. Partial failure / recovery ═══════════════════════════════════

    /**
     * ⚠ THE RECOVERY CASE. A fatal between statements leaves a half-applied
     * schema, and the migration's per-step existence probes are what make
     * that a resumable state rather than a repair job.
     *
     * Four stopping points at once, because the idempotence claim is about
     * every intermediate state, not just the first one.
     */
    public function testItResumesFromAPartiallyAppliedState(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $p    = $this->prefix();

        // Stop 1: the index is gone, every column still present.
        $wpdb->query("ALTER TABLE `{$p}bcc_chain_checkpoints` DROP INDEX `" . self::CK_INDEX . "`");
        self::assertNotContains(self::CK_INDEX, $this->indexes('bcc_chain_checkpoints'));

        // Stop 2: three of the seven columns are gone.
        foreach (['cw_code_cursor', 'cw_metadata_refreshed_at', 'cw_last_error'] as $c) {
            $wpdb->query("ALTER TABLE `{$p}bcc_chain_checkpoints` DROP COLUMN `{$c}`");
        }
        // Stop 3: one of the three tables is gone.
        $wpdb->query("DROP TABLE IF EXISTS `{$p}bcc_discovery_runs`");
        // Stop 4: the chains flag is already gone.
        $wpdb->query("ALTER TABLE `{$p}bcc_chains` DROP COLUMN `" . self::CHAIN_COLUMN . "`");

        // Anti-vacuity: there must still be real work left for the resumed run.
        self::assertContains('cw_discovery_state', $this->columns('bcc_chain_checkpoints'));
        self::assertTrue($this->tableExists('bcc_cosmwasm_contracts'));

        self::assertSame(
            BCC_TRUST_MIGRATION_COMPLETE,
            bcc_trust_drop_scanner_schema(),
            'a half-applied schema must converge, not fail'
        );

        $ck = $this->columns('bcc_chain_checkpoints');
        foreach (self::CK_COLUMNS as $c) {
            self::assertNotContains($c, $ck);
        }
        foreach (self::DROPPED_TABLES as $bare) {
            self::assertFalse($this->tableExists($bare));
        }
        self::assertNotContains(self::CHAIN_COLUMN, $this->columns('bcc_chains'));
        self::assertNotContains(self::CK_INDEX, $this->indexes('bcc_chain_checkpoints'));
    }

    // ══ 5. Retained projections after cleanup ═══════════════════════════

    /**
     * The readers S9a decoupled must still read once the columns are
     * PHYSICALLY gone — which is the entire reason S9 was split in two.
     *
     * Note the distinction S9a's own suites pin: there, "retired column
     * absent" means absent from the returned PROJECTION while the physical
     * column is still present on the table. Here the column is physically
     * gone as well, and the projection must be identical either way.
     */
    public function testRetainedProjectionsStillReadAfterCleanup(): void
    {
        $activeBefore = count(ChainRepository::getActive());
        $allBefore    = count(ChainRepository::getAll());
        self::assertGreaterThan(0, $allBefore, 'anti-vacuity: there must be chains to project');

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());
        ChainRepository::clearCache();

        $chains = ChainRepository::getAll();
        self::assertCount($allBefore, $chains, 'the chain projection must read with the column dropped');

        $first = $chains[0];
        self::assertTrue(property_exists($first, 'slug'));
        self::assertTrue(property_exists($first, 'bcc_supports_nft_collections'));
        self::assertTrue(property_exists($first, 'manual_collection_discovery_enabled'));
        self::assertFalse(
            property_exists($first, self::CHAIN_COLUMN),
            'and must not surface the dropped column'
        );

        self::assertCount($activeBefore, ChainRepository::getActive(), 'getActive() must read too');
        self::assertNotNull(
            ChainRepository::getById((int) $first->id),
            'the single-row read must not fall through to the error sentinel'
        );

        $checkpoints = ChainCheckpointRepository::getAll();
        self::assertNotSame([], $checkpoints, 'the checkpoint listing must read');
        $ck = $checkpoints[0];
        foreach (self::MUST_SURVIVE['bcc_chain_checkpoints'] as $k) {
            self::assertTrue(property_exists($ck, $k), "{$k} must survive in the projection");
        }
        foreach (self::CK_COLUMNS as $c) {
            self::assertFalse(property_exists($ck, $c), "{$c} must not be surfaced");
        }
        self::assertIsObject(ChainCheckpointRepository::get((int) $ck->chain_id));

        // And the retained WRITERS, which an UPDATE naming a dropped column
        // would have broken just as surely as a SELECT.
        ChainCheckpointRepository::recordSuccess((int) $ck->chain_id, 4321, 4400);
        $after = ChainCheckpointRepository::get((int) $ck->chain_id);
        self::assertIsObject($after);
        self::assertSame(4321, (int) $after->last_processed_block);
        self::assertGreaterThanOrEqual(
            7,
            ChainCheckpointRepository::addCuUsage((int) $ck->chain_id, 7),
            'the CU budget writer must still work'
        );
        self::assertTrue(
            ChainCheckpointRepository::setState((int) $ck->chain_id, 'idle'),
            'the state writer must still work'
        );
    }

    // ══ 6. Preservation ════════════════════════════════════════════════

    /**
     * Unrelated columns, indexes and ROWS on the two surviving parents are
     * untouched. This catches a mistake in the migration itself — a DROP
     * aimed one column over would otherwise look like a clean completion.
     */
    public function testUnrelatedColumnsIndexesAndRowsArePreserved(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $chainRowsBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . ChainRepository::table() . '`');
        $ckRowsBefore    = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . ChainCheckpointRepository::table() . '`');
        $chainIdxBefore  = $this->indexes('bcc_chains');
        $slugsBefore     = $wpdb->get_col('SELECT slug FROM `' . ChainRepository::table() . '` ORDER BY id');
        $namesBefore     = $wpdb->get_col('SELECT name FROM `' . ChainRepository::table() . '` ORDER BY id');
        $blocksBefore    = $wpdb->get_col(
            'SELECT last_processed_block FROM `' . ChainCheckpointRepository::table() . '` ORDER BY chain_id'
        );

        self::assertNotSame([], $slugsBefore, 'anti-vacuity: there must be chain rows to preserve');
        self::assertNotSame([], $blocksBefore, 'anti-vacuity: there must be checkpoint rows to preserve');

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());

        self::assertSame(
            $chainRowsBefore,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . ChainRepository::table() . '`'),
            'no chain row may be lost'
        );
        self::assertSame(
            $ckRowsBefore,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . ChainCheckpointRepository::table() . '`'),
            'no checkpoint row may be lost'
        );
        self::assertSame(
            $slugsBefore,
            $wpdb->get_col('SELECT slug FROM `' . ChainRepository::table() . '` ORDER BY id'),
            'chain VALUES must be untouched'
        );
        self::assertSame(
            $namesBefore,
            $wpdb->get_col('SELECT name FROM `' . ChainRepository::table() . '` ORDER BY id')
        );
        self::assertSame(
            $blocksBefore,
            $wpdb->get_col('SELECT last_processed_block FROM `' . ChainCheckpointRepository::table() . '` ORDER BY chain_id'),
            'checkpoint VALUES must be untouched'
        );
        self::assertSame(
            $chainIdxBefore,
            $this->indexes('bcc_chains'),
            'chains indexes must be untouched — only the checkpoints index spans a retired column'
        );

        foreach (self::MUST_SURVIVE as $bare => $required) {
            $present = $this->columns($bare);
            foreach ($required as $c) {
                self::assertContains($c, $present, "{$bare}.{$c} must survive the drop");
            }
        }
    }

    // ══ 7. Leftover options, and the control that must survive ══════════

    /**
     * The sweep is hygiene, not a gate — the migration returns COMPLETE
     * whether or not an option row was there. So the assertions with teeth
     * are the CONTROLS, and one of them is a near-miss that a first draft of
     * this migration really did get wrong.
     *
     * ⚠⚠ `bcc_trust_cw721_scan_options_cleaned` IS ANOTHER MIGRATION'S
     * COMPLETION MARKER. The first draft swept `bcc_trust_cw721_scan_%`,
     * which matches it — so this migration would have deleted
     * `cleanup_cw721_scan_options_v1`'s done_option and made it run again.
     * That prefix is now gone from the drop targets (that migration owns it
     * and sweeps all three transient spellings properly), and this case is
     * what keeps it gone.
     */
    public function testTheOptionSweepRemovesOnlyItsOwnPrefix(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $doomed = [
            'bcc_cosmos_endpoint_authz_17',
            'bcc_cosmos_endpoint_authz_8',
        ];
        $controls = [
            // Another migration's done_option, which the first draft ate.
            'bcc_trust_cw721_scan_options_cleaned',
            // The options that migration genuinely owns, in all three of the
            // spellings transients actually use.
            'bcc_trust_cw721_scan_17',
            '_transient_bcc_trust_cw721_code_ids_17',
            '_transient_timeout_bcc_trust_cw721_code_ids_17',
            // A near-miss on this migration's OWN prefix.
            'bcc_cosmos_endpoint_review_17',
            // And this migration's own completion marker, which it must not
            // sweep either.
            'bcc_trust_scanner_schema_dropped_probe',
        ];

        foreach (array_merge($doomed, $controls) as $name) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                $name,
                'x'
            ));
        }
        foreach ($doomed as $name) {
            self::assertSame(
                1,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
                    $name
                )),
                "anti-vacuity: {$name} must be present before the sweep"
            );
        }

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());

        foreach ($doomed as $name) {
            self::assertSame(
                0,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
                    $name
                )),
                "{$name} must be swept"
            );
        }
        foreach ($controls as $name) {
            self::assertSame(
                1,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
                    $name
                )),
                "{$name} is NOT this migration's to delete and must survive"
            );
        }

        foreach ($controls as $name) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name));
        }
    }

    /**
     * And the drop targets must not name a prefix another migration owns.
     *
     * A sweep is only safe if its predicate is exclusive, and the cheapest
     * way to keep it exclusive is to assert it against the registry rather
     * than to trust a comment.
     */
    public function testTheDropTargetsDoNotClaimAnotherMigrationsOptions(): void
    {
        $targets = bcc_trust_scanner_schema_drop_targets();

        self::assertSame(
            ['bcc_cosmos_endpoint_authz_'],
            $targets['option_prefixes'],
            'the cw721 scan options belong to cleanup_cw721_scan_options_v1, which '
            . 'already sweeps them and whose done_option starts with that prefix'
        );

        // No prefix may match any done_option in the migration registry.
        $registry = bcc_trust_pending_migrations();
        self::assertNotSame([], $registry, 'anti-vacuity: the registry must be readable');

        foreach ($registry as $migration) {
            $doneOption = (string) ($migration['done_option'] ?? '');
            self::assertNotSame('', $doneOption);
            foreach ($targets['option_prefixes'] as $prefix) {
                self::assertStringStartsNotWith(
                    $prefix,
                    $doneOption,
                    "sweeping '{$prefix}%' would delete the completion marker "
                    . "'{$doneOption}' and make that migration run again"
                );
            }
        }
    }

    // ══ 8. The installer that would undo the drop ══════════════════════

    /**
     * ⚠ `bcc_onchain_add_chains_cosmwasm_discovery_column()` re-added the
     * chains flag on every schema pass — and the schema pass runs on the SAME
     * first request as this migration, because removing the three
     * `schema-*.php` files moves `BCC_TRUST_SCHEMA_VERSION` from
     * `1a0bf150b1` to `db054e2c71` and that fires dbDelta. If the installer
     * still existed the column would be back before the request ended.
     */
    public function testNothingReAddsTheDroppedColumnOrTables(): void
    {
        self::assertFalse(
            function_exists('bcc_onchain_add_chains_cosmwasm_discovery_column'),
            'the installer that re-added the chains flag must be deleted, not merely unused'
        );
        foreach ([
            'bcc_onchain_create_cosmwasm_code_families_table',
            'bcc_onchain_create_cosmwasm_contracts_table',
            'bcc_onchain_create_discovery_runs_table',
        ] as $fn) {
            self::assertFalse(function_exists($fn), "{$fn}() must be deleted");
        }

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());

        // Re-running the real installers for the surviving parents must not
        // bring any of it back. This is the dbDelta pass, in miniature.
        bcc_onchain_create_chain_checkpoints_table();
        bcc_onchain_create_chains_table();
        bcc_onchain_add_chains_nft_capability_columns();

        $ck = $this->columns('bcc_chain_checkpoints');
        foreach (self::CK_COLUMNS as $c) {
            self::assertNotContains($c, $ck, "{$c} came back from a schema pass");
        }
        self::assertNotContains(
            self::CHAIN_COLUMN,
            $this->columns('bcc_chains'),
            'the chains flag came back from a schema pass'
        );
        self::assertNotContains(self::CK_INDEX, $this->indexes('bcc_chain_checkpoints'));
        foreach (self::DROPPED_TABLES as $bare) {
            self::assertFalse($this->tableExists($bare), "{$bare} came back from a schema pass");
        }

        // And the retained capability columns are still installed correctly
        // by that same pass — the anchor fix (#280) must hold post-drop.
        $chains = $this->columns('bcc_chains');
        self::assertContains('bcc_supports_nft_collections', $chains);
        self::assertContains('manual_collection_discovery_enabled', $chains);
    }

    // ══ 4b. REAL faults, not just simulated half-applied states ════════

    /**
     * ⚠ A DATABASE FAULT PART-WAY THROUGH MUST REPORT INCOMPLETE — and then
     * the retry must finish the job.
     *
     * The previous case builds a half-applied schema by hand, which proves
     * the resumed run converges but not that a genuine failure is DETECTED.
     * This one injects a real fault into one specific `DROP COLUMN`, so the
     * migration takes its own error branch, and then clears it so the same
     * function call completes. That is the whole contract the runner relies
     * on: INCOMPLETE leaves the `done_option` unwritten and the migration is
     * attempted again on the next request.
     */
    public function testADatabaseFaultWhileDroppingAColumnIsIncompleteAndThenRecovers(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $wpdb->failQueriesMatching = '/DROP COLUMN `cw_code_cursor`/';

        self::assertSame(
            BCC_TRUST_MIGRATION_INCOMPLETE,
            bcc_trust_drop_scanner_schema(),
            'a failing ALTER must leave the migration pending, never claim completion'
        );
        self::assertGreaterThanOrEqual(1, $wpdb->injectedFailures, 'anti-vacuity: the fault must have fired');
        $wpdb->clearFaultInjection();

        // Half-applied, and specifically: the index went first (so the
        // remaining columns are now droppable), the failing column is still
        // there, and NOTHING downstream of the failure ran.
        self::assertNotContains(self::CK_INDEX, $this->indexes('bcc_chain_checkpoints'));
        self::assertContains(
            'cw_code_cursor',
            $this->columns('bcc_chain_checkpoints'),
            'the column whose ALTER failed must still be present'
        );
        foreach (self::DROPPED_TABLES as $bare) {
            self::assertTrue(
                $this->tableExists($bare),
                "{$bare} must NOT have been dropped — table drops come after the column that failed"
            );
        }

        // The retry, which is literally the next request calling the same
        // function again.
        self::assertSame(
            BCC_TRUST_MIGRATION_COMPLETE,
            bcc_trust_drop_scanner_schema(),
            'the retry must converge'
        );

        $ck = $this->columns('bcc_chain_checkpoints');
        foreach (self::CK_COLUMNS as $c) {
            self::assertNotContains($c, $ck);
        }
        foreach (self::DROPPED_TABLES as $bare) {
            self::assertFalse($this->tableExists($bare));
        }
        self::assertNotContains(self::CHAIN_COLUMN, $this->columns('bcc_chains'));
    }

    /**
     * ⚠⚠ AN UNREADABLE PROBE IS **UNVERIFIED**, NOT "ABSENT".
     *
     * This is the single most dangerous confusion available to this
     * migration. `bcc_trust_scanner_schema_columns_present()` returns null
     * when the `INFORMATION_SCHEMA` read fails, and a caller that treated
     * null as "no columns to drop" would sail past every step, find nothing
     * to do, re-probe (also unreadable), and report COMPLETE — stamping the
     * `done_option` on a database it never looked at. The rows would survive
     * that particular run, but the migration would never be attempted again.
     *
     * So: INCOMPLETE, and not one byte touched.
     */
    public function testAnUnreadableProbeIsUnverifiedAndNothingIsDropped(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $p    = $this->prefix();

        $rowsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_cosmwasm_contracts`");
        self::assertSame(4, $rowsBefore, 'anti-vacuity: there must be rows at stake');

        $wpdb->failQueriesMatching = '/INFORMATION_SCHEMA\.COLUMNS/';

        self::assertSame(
            BCC_TRUST_MIGRATION_INCOMPLETE,
            bcc_trust_drop_scanner_schema(),
            'an unreadable column probe must stop the migration'
        );
        self::assertGreaterThanOrEqual(1, $wpdb->injectedFailures, 'anti-vacuity: the fault must have fired');

        // Cleared BEFORE asserting, because the assertions below read
        // INFORMATION_SCHEMA themselves and would otherwise see the fault.
        $wpdb->clearFaultInjection();

        $ck = $this->columns('bcc_chain_checkpoints');
        foreach (self::CK_COLUMNS as $c) {
            self::assertContains($c, $ck, "{$c} must be untouched after an unverified probe");
        }
        self::assertContains(self::CK_INDEX, $this->indexes('bcc_chain_checkpoints'));
        self::assertContains(self::CHAIN_COLUMN, $this->columns('bcc_chains'));
        foreach (self::DROPPED_TABLES as $bare) {
            self::assertTrue($this->tableExists($bare), "{$bare} must be untouched");
        }
        self::assertSame(
            $rowsBefore,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_cosmwasm_contracts`"),
            'and every row must still be there'
        );

        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());
    }

    /**
     * The same fail-closed rule on the way OUT: if the postcondition re-probe
     * cannot be read, the migration does not get to call itself complete.
     *
     * `$wpdb->query()` returning success is proof the statement did not
     * error, not proof the schema moved — so the re-probe is the actual
     * evidence, and an unreadable re-probe is no evidence.
     */
    public function testAnUnreadablePostconditionProbeAlsoRefusesToClaimCompletion(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        // Let the whole drop run, then fail only the STATISTICS re-probe.
        // `bcc_trust_scanner_schema_indexes_over()` is called twice: once in
        // the inventory and once as a postcondition, so failing the SECOND
        // call is what this needs — hence the fault is armed after the first
        // successful run has already removed everything.
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());

        $wpdb->failQueriesMatching = '/INFORMATION_SCHEMA\.STATISTICS/';
        self::assertSame(
            BCC_TRUST_MIGRATION_INCOMPLETE,
            bcc_trust_drop_scanner_schema(),
            'an unreadable index probe must not be read as "no indexes remain"'
        );
        self::assertGreaterThanOrEqual(1, $wpdb->injectedFailures);
        $wpdb->clearFaultInjection();

        // And it is still a no-op on the already-clean schema, so the
        // refusal costs nothing but a retry.
        self::assertSame(BCC_TRUST_MIGRATION_COMPLETE, bcc_trust_drop_scanner_schema());
    }

}
