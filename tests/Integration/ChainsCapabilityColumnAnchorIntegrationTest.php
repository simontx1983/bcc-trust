<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Where the NFT capability columns are ANCHORED, against a real engine.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * The whole defect is an `ALTER TABLE … ADD COLUMN … AFTER <x>` failing
 * because `<x>` does not exist on that install. Only a real server rejects
 * that; a double would accept any anchor, and the existing capability
 * migration test has no case that builds a table without the old anchor, so
 * the failure was invisible to a green suite.
 *
 * ── THE BUG THIS PINS ───────────────────────────────────────────────────
 * `bcc_supports_nft_collections` used to be added `AFTER
 * cosmwasm_nft_discovery_enabled`. The installer's probe gates on the column
 * being ADDED, never on the anchor existing, so on an install whose
 * `CREATE TABLE` predates the anchor the ALTER errors with *Unknown column*,
 * the error is logged, the loop `continue`s — and the column is never added.
 *
 * It then chains, because `manual_collection_discovery_enabled` is anchored on
 * the column that just failed to appear. And the consequence is silent and
 * total: `ChainRepository::COLUMNS` names both columns, so the whole chain
 * projection read fails and every chain reports UNKNOWN.
 *
 * ⚠ THIS CLASS REBUILDS `wp_bcc_chains`, which the rest of the integration
 * suite shares. `tearDown()` drops it and re-runs the real installer, which
 * recreates it AND re-seeds the 20 default chains, then clears the projection
 * cache. PHPUnit runs the suite sequentially in one process, so no other test
 * can observe the window.
 */
#[Group('integration')]
#[Group('mariadb')]
final class ChainsCapabilityColumnAnchorIntegrationTest extends TestCase
{
    private const CAPABILITY_COLUMNS = [
        'bcc_supports_nft_collections',
        'manual_collection_discovery_enabled',
    ];

    /** The per-chain scanner flag the columns used to be anchored on. */
    private const RETIRED_ANCHOR = 'cosmwasm_nft_discovery_enabled';

    private function table(): string
    {
        return ChainRepository::table();
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
     * Build a chains table as it looked BEFORE any of the NFT columns existed.
     *
     * Deliberately not a copy of today's `CREATE TABLE` minus three lines: the
     * point is a table that a genuinely older release would have produced, so
     * the installer meets the shape it actually has to cope with.
     */
    private function createOlderSchemaTable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = $this->table();

        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        $created = $wpdb->query(
            "CREATE TABLE `{$table}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                chain_type VARCHAR(20) NOT NULL,
                chain_id_hex VARCHAR(20) DEFAULT NULL,
                rpc_url VARCHAR(500) DEFAULT NULL,
                rest_url VARCHAR(500) DEFAULT NULL,
                explorer_url VARCHAR(500) DEFAULT NULL,
                native_token VARCHAR(20) DEFAULT NULL,
                decimals TINYINT UNSIGNED NOT NULL DEFAULT 6,
                bech32_prefix VARCHAR(20) DEFAULT NULL,
                icon_url VARCHAR(500) DEFAULT NULL,
                color CHAR(7) DEFAULT NULL,
                marketplace_template TEXT DEFAULT NULL,
                description TEXT DEFAULT NULL,
                is_testnet TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug),
                KEY chain_type (chain_type),
                KEY is_active (is_active)
            ) DEFAULT CHARACTER SET utf8mb4"
        );

        self::assertNotFalse($created, 'fixture: the older-schema table must be created');
        self::assertFalse(
            $this->hasColumn(self::RETIRED_ANCHOR),
            'fixture: the old anchor must be ABSENT — that is the whole point'
        );
        foreach (self::CAPABILITY_COLUMNS as $column) {
            self::assertFalse($this->hasColumn($column), 'fixture: ' . $column . ' starts absent');
        }
    }

    /**
     * Add the retired scanner column to the fixture table.
     *
     * ⚠⚠ THIS REPLACES `bcc_onchain_add_chains_cosmwasm_discovery_column()`,
     * WHICH S9b DELETED — and it is deliberately a fixture `ALTER`, not a
     * revived installer.
     *
     * The installer had to go: the schema pass runs on the SAME first request
     * as `bcc_trust_drop_scanner_schema()`, so anything that re-adds the
     * column would put it back before that request ended.
     *
     * But the SHAPE is still real and still worth testing, which is why these
     * cases were not deleted with it:
     *
     *   - every install sits in it between the S9b code landing and the
     *     migration completing, and the migration is explicitly allowed to
     *     return INCOMPLETE and retry on a later request;
     *   - a restored backup is in it by construction (the restore re-adds
     *     exactly this column, by design — see
     *     `docs/scanner-schema-drop-runbook.md` §6);
     *   - and the claim under test — that the projection reads the same with
     *     the column present and absent — is only meaningful if BOTH shapes
     *     can be built.
     *
     * Reproducing it with one line of DDL keeps the pair of cases honest
     * without keeping alive the function that would undo the drop. The
     * definition matches what the deleted installer used and what production
     * carries today.
     */
    private function addRetiredAnchorColumn(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        self::assertNotFalse(
            $wpdb->query(
                'ALTER TABLE `' . $this->table() . '` ADD COLUMN `'
                . self::RETIRED_ANCHOR . '` TINYINT(1) NOT NULL DEFAULT 0'
            ),
            'fixture: the retired column must be addable'
        );
    }

    /** One row of real-looking data, so preservation can be asserted. */
    private function seedRow(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = $this->table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (slug, name, chain_type, rest_url, native_token, decimals, is_active)
             VALUES (%s, %s, %s, %s, %s, %d, %d)",
            'anchor-fixture',
            'Anchor Fixture Chain',
            'cosmos',
            'https://rest.example.test/anchor',
            'ANCH',
            6,
            1
        ));

        self::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE slug = %s",
                'anchor-fixture'
            )),
            'fixture: the row must land'
        );
    }

    /** @return array<string, string|null> the fixture row, keyed by column */
    private function fixtureRow(): array
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = $this->table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT slug, name, chain_type, rest_url, native_token, decimals, is_active
               FROM `{$table}` WHERE slug = %s",
            'anchor-fixture'
        ));

        self::assertIsObject($row, 'the fixture row must still be readable');

        $out = [];
        foreach (get_object_vars($row) as $k => $v) {
            $out[(string) $k] = $v === null ? null : (string) $v;
        }

        return $out;
    }

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DROP TABLE IF EXISTS `' . $this->table() . '`');

        // The real installer: recreates the table AND re-seeds the defaults.
        bcc_onchain_create_chains_table();
        bcc_onchain_add_chains_nft_capability_columns();
        ChainRepository::clearCache();

        parent::tearDown();
    }

    // ══ 1. The older-schema upgrade — the case that used to fail ════════

    /**
     * ⚠⚠ THE REGRESSION. The anchor column is absent, and both capability
     * columns must still be added. Anchored on `cosmwasm_nft_discovery_enabled`
     * this ALTER raises *Unknown column* and adds nothing.
     */
    public function testTheCapabilityColumnsAreAddedWhenTheOldAnchorIsAbsent(): void
    {
        $this->createOlderSchemaTable();

        bcc_onchain_add_chains_nft_capability_columns();

        foreach (self::CAPABILITY_COLUMNS as $column) {
            self::assertTrue(
                $this->hasColumn($column),
                $column . ' must be added even with ' . self::RETIRED_ANCHOR . ' absent'
            );
        }

        // And it did not resurrect the retired anchor as a side effect.
        self::assertFalse(
            $this->hasColumn(self::RETIRED_ANCHOR),
            'this installer must not re-add the scanner flag'
        );
    }

    /** The added columns carry the intended shape, not just the name. */
    public function testTheAddedColumnsAreTinyintNotNullDefaultZero(): void
    {
        $this->createOlderSchemaTable();
        bcc_onchain_add_chains_nft_capability_columns();

        $wpdb = $GLOBALS['wpdb'];
        foreach (self::CAPABILITY_COLUMNS as $column) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT DATA_TYPE AS t, IS_NULLABLE AS n, COLUMN_DEFAULT AS d
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $this->table(),
                $column
            ));

            self::assertIsObject($row, $column . ' must exist');
            self::assertSame('tinyint', strtolower((string) $row->t), $column . ' type');
            self::assertSame('NO', (string) $row->n, $column . ' must be NOT NULL');
            self::assertSame('0', (string) $row->d, $column . ' must default to 0');
        }
    }

    // ══ 2. Existing data survives ══════════════════════════════════════

    /**
     * ⚠ AN UPGRADE THAT LOSES A ROW IS WORSE THAN ONE THAT FAILS. Every value
     * on the pre-existing row is compared field by field, not just counted.
     */
    public function testExistingRowsArePreservedAcrossTheUpgrade(): void
    {
        $this->createOlderSchemaTable();
        $this->seedRow();
        $before = $this->fixtureRow();

        bcc_onchain_add_chains_nft_capability_columns();

        self::assertSame(
            $before,
            $this->fixtureRow(),
            'the upgrade must not alter a single existing value'
        );

        // And the new columns default to 0 on the pre-existing row — "not
        // enabled" rather than NULL, which is what every reader expects.
        $wpdb = $GLOBALS['wpdb'];
        foreach (self::CAPABILITY_COLUMNS as $column) {
            self::assertSame(
                '0',
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT `{$column}` FROM `" . $this->table() . "` WHERE slug = %s",
                    'anchor-fixture'
                )),
                $column . ' must be 0 on a row that predates it'
            );
        }
    }

    // ══ 3. Idempotence ═════════════════════════════════════════════════

    /**
     * The installer runs on every schema-version bump, so a second run must be
     * a no-op rather than an error — and must not disturb a value a later PR
     * legitimately set.
     */
    public function testASecondRunChangesNothing(): void
    {
        $this->createOlderSchemaTable();
        $this->seedRow();
        bcc_onchain_add_chains_nft_capability_columns();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . $this->table() . '` SET bcc_supports_nft_collections = 1 WHERE slug = %s',
            'anchor-fixture'
        ));

        $columnsAfterFirst = $this->columns();
        $rowAfterFirst     = $this->fixtureRow();

        bcc_onchain_add_chains_nft_capability_columns();
        bcc_onchain_add_chains_nft_capability_columns();

        self::assertSame(
            $columnsAfterFirst,
            $this->columns(),
            'repeated runs must not add, remove or reorder a column'
        );
        self::assertSame($rowAfterFirst, $this->fixtureRow(), 'nor touch existing values');
        self::assertSame(
            '1',
            (string) $wpdb->get_var($wpdb->prepare(
                'SELECT bcc_supports_nft_collections FROM `' . $this->table() . '` WHERE slug = %s',
                'anchor-fixture'
            )),
            'an enabled flag a later PR set must survive the installer'
        );
    }

    // ══ 4. Fresh install ═══════════════════════════════════════════════

    /**
     * The baseline, and the anti-vacuity for everything above: on a fresh
     * install the three columns come from the base `CREATE TABLE`, so the
     * installer has nothing to add and the anchor is never consulted.
     */
    public function testAFreshInstallHasAllThreeColumnsWithoutAnyAlter(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DROP TABLE IF EXISTS `' . $this->table() . '`');

        bcc_onchain_create_chains_table();

        foreach (array_merge([self::RETIRED_ANCHOR], self::CAPABILITY_COLUMNS) as $column) {
            self::assertTrue(
                $this->hasColumn($column),
                $column . ' must come from the base CREATE TABLE on a fresh install'
            );
        }

        // Running the capability installer on top is still a no-op.
        $before = $this->columns();
        bcc_onchain_add_chains_nft_capability_columns();
        self::assertSame($before, $this->columns());
    }

    /**
     * ⚠ THE PROJECTION IS THE REASON ANY OF THIS MATTERS. `ChainRepository`
     * selects both capability columns by name, so a missing one does not
     * degrade — it fails the whole read and every chain reports UNKNOWN. After
     * the upgrade the projection must work.
     */
    public function testTheChainProjectionReadsAfterTheOlderSchemaUpgrade(): void
    {
        $this->createOlderSchemaTable();
        $this->seedRow();

        // ⚠ CAPABILITY COLUMNS FIRST, SCANNER COLUMN SECOND — deliberately the
        // opposite of the order the old anchor required. That ordering is the
        // point: after the re-anchor these two installers are INDEPENDENT, so a
        // run in either order reaches the same schema. Under the old anchor this
        // order could not work at all.
        bcc_onchain_add_chains_nft_capability_columns();
        $this->addRetiredAnchorColumn();
        ChainRepository::clearCache();
        // ⚠ WAS "names all three" UNTIL S9a. `ChainRepository::COLUMNS` now
        // names the two CAPABILITY columns and no longer names
        // `cosmwasm_nft_discovery_enabled`. Both installers still run here
        // because this case is about the ANCHOR, and the anchor question is
        // only interesting when both columns are being added.

        $wpdb = $GLOBALS['wpdb'];
        $id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM `' . $this->table() . '` WHERE slug = %s',
            'anchor-fixture'
        ));
        self::assertGreaterThan(0, $id, 'anti-vacuity: the fixture row has an id');

        $row = ChainRepository::getById($id);

        self::assertIsObject($row, 'the projection must read, not fail');
        self::assertSame('anchor-fixture', (string) $row->slug);
        self::assertSame('0', (string) $row->bcc_supports_nft_collections);
        self::assertSame('0', (string) $row->manual_collection_discovery_enabled);
    }

    // ══ 2. S9a — the projection no longer depends on the retired column ══
    //
    // These three cases are the forward-looking half, and they are the reason
    // S9 is split into two deploys. S9b will DROP
    // `cosmwasm_nft_discovery_enabled`. Dropping a column that
    // `ChainRepository::COLUMNS` still names does not degrade gracefully: the
    // whole projection read fails, this class caches an ERROR_SENTINEL, and
    // the operator-visible symptom is every chain reporting UNKNOWN with
    // nothing in the log. So the projection has to stop naming the column
    // BEFORE the column goes, and that has to be proved against a real
    // engine — `schema-drift-guard.php` compares table names and index
    // tuples only and is column-blind, so CI cannot catch this on its own.

    /**
     * ⚠⚠ THE S9b REHEARSAL. The retired column is ABSENT and the projection
     * must still read.
     *
     * Built by running ONLY the capability installer against the older
     * schema, so the table reaches exactly the shape S9b will produce: the
     * two capability columns present, `cosmwasm_nft_discovery_enabled` never
     * added. Before S9a this case could not pass.
     */
    public function testTheChainProjectionReadsWithTheRetiredScannerColumnAbsent(): void
    {
        $this->createOlderSchemaTable();
        $this->seedRow();

        // Deliberately NOT calling $this->addRetiredAnchorColumn() — this is the
        // post-S9b shape, where nothing adds that column at all.
        bcc_onchain_add_chains_nft_capability_columns();
        ChainRepository::clearCache();

        self::assertFalse(
            $this->hasColumn(self::RETIRED_ANCHOR),
            'precondition: the retired column must really be absent'
        );
        self::assertTrue(
            $this->hasColumn('bcc_supports_nft_collections'),
            'anti-vacuity: the capability columns must really be present'
        );

        $wpdb = $GLOBALS['wpdb'];
        $id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM `' . $this->table() . '` WHERE slug = %s',
            'anchor-fixture'
        ));
        self::assertGreaterThan(0, $id, 'anti-vacuity: the fixture row has an id');

        $row = ChainRepository::getById($id);

        self::assertIsObject($row, 'the projection must read with the retired column gone');
        self::assertSame('anchor-fixture', (string) $row->slug);
        self::assertSame('0', (string) $row->bcc_supports_nft_collections);
        self::assertSame('0', (string) $row->manual_collection_discovery_enabled);

        // And the row must NOT carry the retired property, because the
        // projection does not ask for it.
        self::assertFalse(
            property_exists($row, self::RETIRED_ANCHOR),
            'the projection must not surface a column it no longer selects'
        );

        // getAll() uses the same COLUMNS list and is what the admin surfaces
        // read, so it has to survive the drop too.
        self::assertNotSame([], ChainRepository::getAll(), 'getAll() must read as well');
    }

    /**
     * And with the column still PRESENT — today's production shape — the
     * projection reads identically.
     *
     * This is the other half of the pair: S9a must not have traded one
     * breakage for another. Production is on this shape right now.
     */
    public function testTheChainProjectionReadsWithTheRetiredScannerColumnPresent(): void
    {
        $this->createOlderSchemaTable();
        $this->seedRow();

        bcc_onchain_add_chains_nft_capability_columns();
        $this->addRetiredAnchorColumn();
        ChainRepository::clearCache();

        self::assertTrue(
            $this->hasColumn(self::RETIRED_ANCHOR),
            'precondition: the retired column must really be present'
        );

        $wpdb = $GLOBALS['wpdb'];
        $id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM `' . $this->table() . '` WHERE slug = %s',
            'anchor-fixture'
        ));
        $row = ChainRepository::getById($id);

        self::assertIsObject($row, 'the projection must read with the column present');
        self::assertSame('anchor-fixture', (string) $row->slug);
        self::assertFalse(
            property_exists($row, self::RETIRED_ANCHOR),
            'present-but-unselected must look exactly like absent to a caller'
        );
    }

    /**
     * The structural pin: the SELECT list must not name the retired column.
     *
     * The two cases above would both keep passing if someone re-added the
     * column to `COLUMNS` while it still existed — and S9b's drop would then
     * break production silently. This reads the constant itself.
     */
    public function testTheProjectionConstantDoesNotNameTheRetiredColumn(): void
    {
        $reflected = new \ReflectionClass(ChainRepository::class);
        $columns   = (string) $reflected->getConstant('COLUMNS');

        self::assertNotSame('', $columns, 'anti-vacuity: the constant must be readable');
        self::assertStringContainsString(
            'bcc_supports_nft_collections',
            $columns,
            'anti-vacuity: this really is the projection list'
        );
        self::assertStringNotContainsString(
            self::RETIRED_ANCHOR,
            $columns,
            'the retired scanner column must not return to the projection: S9b drops it, '
            . 'and a projection naming a dropped column fails silently behind the error sentinel'
        );
    }
}
