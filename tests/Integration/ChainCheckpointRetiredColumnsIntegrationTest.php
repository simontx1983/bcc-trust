<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The checkpoint projection survives the S9b column drop — proved on a real
 * engine, in BOTH directions.
 *
 * ── WHY THIS EXISTS, AND WHY IT CANNOT BE A UNIT TEST ───────────────────
 * S9b will `ALTER TABLE … DROP COLUMN` the seven `cw_*` columns. Dropping a
 * column that `ChainCheckpointRepository::COLUMNS` still names does not
 * degrade gracefully — the `SELECT` itself fails. A double would happily
 * return whatever shape it was told to; only a real server rejects a query
 * naming a column that is not there.
 *
 * And the consequence is quiet. `get()` and `getAll()` run through the
 * read guard, so a failed read becomes "unavailable" rather than an
 * exception, and the EVM indexer, the circuit breaker and the CU budget all
 * read this table. A silent unavailable there looks like an idle chain.
 *
 * ⚠ `schema-drift-guard.php` CANNOT CATCH THIS. It compares table names and
 * index tuples and has no column-level parity at all, so the `cw_*` drop is
 * invisible to it in both directions. These cases are the check.
 *
 * ── THE TWO STEPS, AND WHY THE ORDER IS THE SAFETY ARGUMENT ─────────────
 * S9a removed the seven `cw_*` columns from the SELECT list and deleted the
 * twelve `cw_*` writers that went callerless when S8 removed the scanner.
 * ⚠ S9a's "retired column absent" meant absent from the RETURNED
 * PROJECTION ONLY: it left the columns on the table, the `schema-*.php`
 * installers intact and the five `CW_STATE_*` constants in place, so it was
 * a pure, reversible code change with no data at stake.
 *
 * S9b is the second step and the destructive one. It removed the columns and
 * `idx_cw_discovery` from `CREATE TABLE`, deleted the three sibling
 * `schema-*.php` files and the `CW_STATE_*` constants, and added
 * `bcc_trust_drop_scanner_schema()` to drop all of it from existing
 * installs.
 *
 * Doing those in the other order is the failure this file exists to prevent:
 * a `SELECT` naming a dropped column fails the WHOLE read, `ChainRepository`
 * and this repository's read guard turn that into "unavailable", and the
 * operator-visible symptom is every chain idle with nothing in the log.
 *
 * So the pair of cases below is still the whole point — columns present and
 * columns absent must behave identically — except that since S9b the
 * PRESENT shape is the one that has to be constructed. See
 * `addRetiredColumns()`.
 * ⚠ THIS CLASS MUTATES `wp_bcc_chain_checkpoints`, which the rest of the
 * integration suite shares. `tearDown()` drops the table and re-runs the
 * real installer. PHPUnit runs this suite sequentially in one process, so no
 * other test can observe the window.
 */
#[Group('integration')]
#[Group('mariadb')]
final class ChainCheckpointRetiredColumnsIntegrationTest extends TestCase
{
    /**
     * The seven columns S9b drops.
     *
     * @var list<string>
     */
    private const RETIRED_COLUMNS = [
        'cw_discovery_state',
        'cw_code_cursor',
        'cw_max_code_id',
        'cw_backfill_completed_at',
        'cw_last_discovery_at',
        'cw_metadata_refreshed_at',
        'cw_last_error',
    ];

    /**
     * The composite index S9b drops, and the reason the drop has an ORDER.
     *
     * `KEY idx_cw_discovery (cw_discovery_state, cw_last_discovery_at)` spans
     * two of the columns above, so it has to go before either of them.
     * `schema-drift-guard.php` DOES compare index tuples — but only for
     * indexes on tables it still finds declared, and it is column-blind, so
     * it would not have told anyone which column was missing.
     */
    private const RETIRED_INDEX = 'idx_cw_discovery';

    /**
     * Columns the retained readers and writers genuinely need. Shared with
     * the EVM indexer's progress tracking, the breaker and the CU budget.
     *
     * @var list<string>
     */
    private const RETAINED_COLUMNS = [
        'chain_id',
        'last_processed_block',
        'head_block',
        'state',
        'cu_used_today',
        'cu_budget_reset_at',
        'last_run_at',
        'last_error',
        'block_progression_history',
    ];

    private const CHAIN_ID = 424242;

    private function table(): string
    {
        return ChainCheckpointRepository::table();
    }

    /** @return list<string> every column on the table, in ordinal order */
    private function columns(): array
    {
        $wpdb = $GLOBALS['wpdb'];

        /** @var list<object> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              ORDER BY ORDINAL_POSITION',
            $this->table()
        ));

        return array_map(static fn (object $r): string => (string) $r->c, $rows);
    }

    private function hasColumn(string $column): bool
    {
        return in_array($column, $this->columns(), true);
    }

    /**
     * Simulate S9b: drop the seven retired columns from the live table.
     *
     * One statement per column rather than one combined ALTER, because that
     * is what a migration that must tolerate a partially-dropped table looks
     * like, and because a combined statement hides which column failed.
     */
    /**
     * Rebuild the PRE-S9b shape: the seven columns and the index over two of
     * them.
     *
     * ⚠⚠ THIS USED TO BE THE SHIPPED SHAPE, SO IT USED TO NEED NO HELPER.
     * `bcc_onchain_create_chain_checkpoints_table()` created the columns and
     * S9a left them alone; §1 below just read them. S9b removed them from
     * `CREATE TABLE`, so the "columns present" case now has to construct its
     * own subject — and it must, because that shape is still real:
     *
     *   - an install sits in it between the S9b code deploy and the
     *     migration completing (which may legitimately take several
     *     requests — the migration returns INCOMPLETE and retries), and
     *   - a restored backup is in it by construction.
     *
     * Deliberately NOT by reviving an installer. The schema pass runs on the
     * same first request as `bcc_trust_drop_scanner_schema()`, so any
     * installer that re-added these columns would undo the drop within that
     * request. A fixture `ALTER` cannot: it exists only here.
     *
     * ⚠ THE INDEX IS PART OF THE SHAPE, not decoration. `idx_cw_discovery`
     * spans `cw_discovery_state` and `cw_last_discovery_at`, which is why
     * `dropRetiredColumns()` below has to remove it FIRST — dropping either
     * column while it exists fails with `ERROR 1072`. That is the exact
     * failure the first S9b restore rehearsal hit.
     */
    private function addRetiredColumns(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = $this->table();

        foreach (self::RETIRED_COLUMNS as $column) {
            if ($this->hasColumn($column)) {
                continue;
            }
            $type = match ($column) {
                'cw_discovery_state' => "VARCHAR(20) NOT NULL DEFAULT 'idle'",
                'cw_code_cursor'     => 'VARCHAR(255) DEFAULT NULL',
                'cw_max_code_id'     => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'cw_last_error'      => 'VARCHAR(255) DEFAULT NULL',
                default              => 'DATETIME DEFAULT NULL',
            };
            self::assertNotFalse(
                $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$type}"),
                "fixture: {$column} must be addable"
            );
        }

        if (!$this->hasIndex(self::RETIRED_INDEX)) {
            self::assertNotFalse(
                $wpdb->query(
                    "ALTER TABLE `{$table}` ADD KEY `" . self::RETIRED_INDEX
                    . '` (`cw_discovery_state`, `cw_last_discovery_at`)'
                ),
                'fixture: the composite index must be addable'
            );
        }
    }

    private function hasIndex(string $index): bool
    {
        $wpdb = $GLOBALS['wpdb'];

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $this->table(),
            $index
        )) > 0;
    }

    private function dropRetiredColumns(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = $this->table();

        // ⚠ THE INDEX FIRST. It spans two of the columns below, so dropping
        // either of them while it exists fails with `ERROR 1072: Key column
        // 'cw_discovery_state' doesn't exist in table`. The real migration
        // drops it first for the same reason.
        if ($this->hasIndex(self::RETIRED_INDEX)) {
            $wpdb->query('ALTER TABLE `' . $table . '` DROP INDEX `' . self::RETIRED_INDEX . '`');
        }

        foreach (self::RETIRED_COLUMNS as $column) {
            if (!$this->hasColumn($column)) {
                continue;
            }
            $wpdb->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }

    private function seedRow(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
    }

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DROP TABLE IF EXISTS `' . $this->table() . '`');

        // The real installer, so the restored table is the shipped shape
        // rather than this test's idea of it.
        bcc_onchain_create_chain_checkpoints_table();

        parent::tearDown();
    }

    // ══ 0. The denominator ══════════════════════════════════════════════

    public function testTheShippedTableCarriesBothSets(): void
    {
        $columns = $this->columns();

        self::assertNotSame([], $columns, 'anti-vacuity: the table must exist and be readable');

        foreach (self::RETAINED_COLUMNS as $c) {
            self::assertContains($c, $columns, "retained column {$c} must be on the shipped table");
        }
        // ⚠⚠ INVERTED IN S9b, AND THE OLD FORM IS WORTH KEEPING ON THE
        // RECORD because it was the pin that made the two-deploy split
        // enforceable. It read:
        //
        //     assertContains($c, $columns,
        //         "S9a must NOT have dropped {$c} — the drop is S9b, behind a backup")
        //
        // That was exactly right at the time: S9a's whole claim was that the
        // columns stayed on the table while the PROJECTION stopped naming
        // them, and this case is what stopped the two steps being collapsed
        // into one. S9b is that second step, so the shipped table no longer
        // carries them and the assertion flips.
        foreach (self::RETIRED_COLUMNS as $c) {
            self::assertNotContains(
                $c,
                $columns,
                "S9b removed {$c} from CREATE TABLE; a fresh install must not have it"
            );
        }
        self::assertFalse(
            $this->hasIndex(self::RETIRED_INDEX),
            'and the index over two of them must be gone from CREATE TABLE too'
        );
    }

    // ══ 1. Today's shape — the columns are present ══════════════════════

    public function testTheProjectionReadsWithTheRetiredColumnsPresent(): void
    {
        // Since S9b the installer no longer creates these, so the shape has
        // to be built. See addRetiredColumns() for why it is still worth
        // testing and why it is a fixture rather than a revived installer.
        $this->addRetiredColumns();
        $this->seedRow();

        self::assertTrue(
            $this->hasColumn('cw_discovery_state'),
            'precondition: this case is about the columns being PRESENT'
        );
        self::assertTrue(
            $this->hasIndex(self::RETIRED_INDEX),
            'precondition: and about the index being present with them'
        );

        $row = ChainCheckpointRepository::get(self::CHAIN_ID);

        self::assertIsObject($row, 'the projection must read');
        self::assertSame(self::CHAIN_ID, (int) $row->chain_id);

        // Present-but-unselected must be indistinguishable from absent.
        foreach (self::RETIRED_COLUMNS as $c) {
            self::assertFalse(
                property_exists($row, $c),
                "the projection must not surface {$c}: S9a removed it from the SELECT list"
            );
        }
        foreach (self::RETAINED_COLUMNS as $c) {
            self::assertTrue(property_exists($row, $c), "the projection must still surface {$c}");
        }
    }

    // ══ 2. The S9b rehearsal — the columns are gone ═════════════════════

    /**
     * ⚠⚠ THE CASE THAT MATTERS. Drop the seven columns, then read.
     *
     * Before S9a this could not pass: `COLUMNS` named all seven, so the
     * `SELECT` referenced columns that no longer existed and the read failed.
     */
    public function testTheProjectionReadsWithTheRetiredColumnsAbsent(): void
    {
        $this->seedRow();
        // ⚠ ADD, THEN DROP. Since S9b the installer no longer creates these
        // columns, so a bare dropRetiredColumns() would be a NO-OP and this
        // case would pass without ever exercising a drop. Building the
        // pre-S9b shape first keeps the transition real.
        $this->addRetiredColumns();
        $this->dropRetiredColumns();

        foreach (self::RETIRED_COLUMNS as $c) {
            self::assertFalse($this->hasColumn($c), "precondition: {$c} must really be gone");
        }
        self::assertTrue(
            $this->hasColumn('last_processed_block'),
            'anti-vacuity: the retained columns must still be there'
        );

        $row = ChainCheckpointRepository::get(self::CHAIN_ID);

        self::assertIsObject($row, 'get() must read with the retired columns dropped');
        self::assertSame(self::CHAIN_ID, (int) $row->chain_id);
        foreach (self::RETAINED_COLUMNS as $c) {
            self::assertTrue(property_exists($row, $c), "{$c} must survive the drop");
        }

        $all = ChainCheckpointRepository::getAll();
        self::assertIsArray($all);
        self::assertNotSame([], $all, 'getAll() must read with the retired columns dropped');
    }

    /**
     * And every retained WRITER still works with the columns gone.
     *
     * The readers are only half of it: `recordSuccess()`, `recordFailure()`,
     * `setState()` and `addCuUsage()` are what the EVM indexer, the breaker
     * and the CU budget actually call on every tick, and an `UPDATE` naming a
     * dropped column fails exactly as a `SELECT` does.
     */
    public function testTheRetainedWritersWorkWithTheRetiredColumnsAbsent(): void
    {
        $this->seedRow();
        // ⚠ ADD, THEN DROP. Since S9b the installer no longer creates these
        // columns, so a bare dropRetiredColumns() would be a NO-OP and this
        // case would pass without ever exercising a drop. Building the
        // pre-S9b shape first keeps the transition real.
        $this->addRetiredColumns();
        $this->dropRetiredColumns();

        // ensureExists() on a fresh id, with the columns already gone: the
        // INSERT must not name them either.
        $second = self::CHAIN_ID + 1;
        ChainCheckpointRepository::ensureExists($second);
        self::assertIsObject(
            ChainCheckpointRepository::get($second),
            'ensureExists() must insert without naming a dropped column'
        );

        ChainCheckpointRepository::recordSuccess(self::CHAIN_ID, 1000, 1200);
        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertIsObject($row);
        self::assertSame(1000, (int) $row->last_processed_block, 'recordSuccess() must persist');
        self::assertSame(1200, (int) $row->head_block);

        self::assertTrue(
            ChainCheckpointRepository::setState(self::CHAIN_ID, ChainCheckpointRepository::STATE_DEGRADED),
            'setState() must succeed'
        );
        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertIsObject($row);
        self::assertSame(ChainCheckpointRepository::STATE_DEGRADED, (string) $row->state);

        ChainCheckpointRepository::recordFailure(
            self::CHAIN_ID,
            ChainCheckpointRepository::STATE_BREAKER_OPEN,
            'simulated provider fault'
        );
        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertIsObject($row);
        self::assertSame(ChainCheckpointRepository::STATE_BREAKER_OPEN, (string) $row->state);
        self::assertStringContainsString('simulated provider fault', (string) $row->last_error);

        $used = ChainCheckpointRepository::addCuUsage(self::CHAIN_ID, 120);
        self::assertGreaterThanOrEqual(120, $used, 'addCuUsage() must accumulate');
        self::assertSame(
            0,
            ChainCheckpointRepository::cuRemainingForToday(self::CHAIN_ID, 10),
            'the CU budget must still compute from the retained columns'
        );
    }

    /**
     * The progression history — the one retained column with structure — is
     * still written and still decodes after the drop.
     */
    public function testTheProgressionHistorySurvivesTheDrop(): void
    {
        $this->seedRow();
        // ⚠ ADD, THEN DROP. Since S9b the installer no longer creates these
        // columns, so a bare dropRetiredColumns() would be a NO-OP and this
        // case would pass without ever exercising a drop. Building the
        // pre-S9b shape first keeps the transition real.
        $this->addRetiredColumns();
        $this->dropRetiredColumns();

        ChainCheckpointRepository::recordSuccess(self::CHAIN_ID, 10, 20);
        ChainCheckpointRepository::recordSuccess(self::CHAIN_ID, 30, 40);

        $row = ChainCheckpointRepository::get(self::CHAIN_ID);
        self::assertIsObject($row);

        $history = ChainCheckpointRepository::decodeProgressionHistory(
            $row->block_progression_history ?? null
        );

        self::assertNotSame([], $history, 'the history must decode to something');
        self::assertLessThanOrEqual(
            ChainCheckpointRepository::MAX_PROGRESSION_ENTRIES,
            count($history),
            'and must stay bounded'
        );
    }

    // ══ 3. The structural pin ═══════════════════════════════════════════

    /**
     * The SELECT list must name none of the seven.
     *
     * Every case above would still pass if someone re-added a `cw_*` column
     * to `COLUMNS` while the column existed — and S9b's drop would then break
     * the read. This reads the constant itself, so the regression is caught
     * before the drop rather than by it.
     */
    public function testTheProjectionConstantNamesNoRetiredColumn(): void
    {
        $reflected = new \ReflectionClass(ChainCheckpointRepository::class);
        $columns   = (string) $reflected->getConstant('COLUMNS');

        self::assertNotSame('', $columns, 'anti-vacuity: the constant must be readable');
        self::assertStringContainsString(
            'block_progression_history',
            $columns,
            'anti-vacuity: this really is the projection list'
        );

        foreach (self::RETIRED_COLUMNS as $c) {
            self::assertStringNotContainsString(
                $c,
                $columns,
                "{$c} must not return to the projection: S9b drops it, and a SELECT naming a "
                . 'dropped column fails the whole read behind the guard'
            );
        }
    }
}
