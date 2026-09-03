<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * PR 7 — the collection-metadata migration against a REAL MySQL.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * Every claim is about what the SERVER does. Whether `image_url = ''`
 * matches a row that `IS NULL` does not, whether an `UPDATE` scoped by
 * `IS NOT NULL` matches nothing on a second run, and whether an added column
 * really carries `NOT NULL DEFAULT 'none'` are facts about MySQL. A double
 * would agree with whatever the code believes.
 *
 * ⚠ The empty-image normalization is the whole reason 119 production Cosmos
 * rows are unfillable: `COALESCE(image_url, %s)` replaces NULL and leaves
 * `''` untouched. A test that seeded only NULLs would pass against code that
 * fixes nothing.
 */
final class CollectionCommunityMetadataMigrationTest extends TestCase
{
    private const CHAIN = 8801;

    private function table(): string
    {
        return bcc_onchain_collections_table();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->query('DELETE FROM `' . $this->table() . '` WHERE chain_id = ' . self::CHAIN);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->query('DELETE FROM `' . $this->table() . '` WHERE chain_id = ' . self::CHAIN);
        parent::tearDown();
    }

    private function seed(string $contract, ?string $imageUrl, ?string $floorCurrency = null, ?string $floorPrice = null): int
    {
        $t = $this->table();
        $image = $imageUrl === null ? 'NULL' : "'" . $imageUrl . "'";
        $cur   = $floorCurrency === null ? 'NULL' : "'" . $floorCurrency . "'";
        $price = $floorPrice === null ? 'NULL' : $floorPrice;

        $GLOBALS['wpdb']->query(
            "INSERT INTO `{$t}` (contract_address, chain_id, collection_name, image_url, floor_currency, floor_price, expires_at)
             VALUES ('{$contract}', " . self::CHAIN . ", 'Seeded', {$image}, {$cur}, {$price}, UTC_TIMESTAMP())"
        );

        return (int) $GLOBALS['wpdb']->insert_id;
    }

    /**
     * ⚠ The integration double is `get_row(string $sql): ?object` — SQL only,
     * objects out. There is no `ARRAY_A` in this harness.
     */
    private function row(int $id): object
    {
        $t   = $this->table();
        $row = $GLOBALS['wpdb']->get_row("SELECT * FROM `{$t}` WHERE id = {$id}");
        self::assertIsObject($row, 'seeded row must exist');

        return $row;
    }

    // ── columns ─────────────────────────────────────────────────────────

    public function testEveryDeclaredColumnExists(): void
    {
        $t = $this->table();

        foreach (array_keys(bcc_onchain_community_metadata_columns()) as $column) {
            $n = (int) $GLOBALS['wpdb']->get_var(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$column}'"
            );
            self::assertSame(1, $n, "{$column} must exist after the migration");
        }
    }

    public function testTheApprovalStateColumnIsNotNullAndDefaultsToNone(): void
    {
        $t = $this->table();
        $meta = $GLOBALS['wpdb']->get_row(
            "SELECT IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'
                AND COLUMN_NAME = 'chain_description_state'"
        );

        self::assertIsObject($meta);
        self::assertSame('NO', $meta->IS_NULLABLE);
        // ⚠ information_schema returns the literal string for a string default.
        self::assertSame('none', $meta->COLUMN_DEFAULT);
    }

    public function testANewRowStartsWithNoImportedDescription(): void
    {
        $id  = $this->seed('0xnodesc', null);
        $row = $this->row($id);

        self::assertNull($row->chain_description);
        self::assertSame('none', $row->chain_description_state);
        self::assertNull($row->chain_description_source);
        self::assertNull($row->collection_symbol);
        self::assertNull($row->marketplace_url);
    }

    public function testTotalSupplyWasNotWidened(): void
    {
        $t = $this->table();
        $meta = $GLOBALS['wpdb']->get_row(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = 'total_supply'"
        );

        self::assertIsObject($meta);
        self::assertStringContainsString('unsigned', strtolower((string) $meta->COLUMN_TYPE));
        self::assertStringStartsWith('int', strtolower((string) $meta->COLUMN_TYPE));
    }

    // ── empty-image normalization ───────────────────────────────────────

    public function testEmptyImageStringBecomesSqlNull(): void
    {
        $empty = $this->seed('0xempty', '');
        self::assertSame('', $this->row($empty)->image_url, 'precondition: the production shape');

        bcc_onchain_normalize_empty_collection_images();

        self::assertNull($this->row($empty)->image_url);
    }

    public function testNormalizationLeavesRealImagesAndExistingNullsAlone(): void
    {
        $real  = $this->seed('0xreal', 'https://example.test/a.png');
        $null  = $this->seed('0xnull', null);
        $empty = $this->seed('0xempty2', '');

        bcc_onchain_normalize_empty_collection_images();

        self::assertSame('https://example.test/a.png', $this->row($real)->image_url, 'a real URL is not touched');
        self::assertNull($this->row($null)->image_url);
        self::assertNull($this->row($empty)->image_url);
    }

    public function testNormalizationScopeIsExactlyTheEmptyStrings(): void
    {
        $this->seed('0xa', '');
        $this->seed('0xb', '');
        $this->seed('0xc', 'https://example.test/c.png');
        $this->seed('0xd', null);

        $t = $this->table();
        $before = (int) $GLOBALS['wpdb']->get_var(
            "SELECT COUNT(*) FROM `{$t}` WHERE chain_id = " . self::CHAIN . " AND image_url = ''"
        );
        self::assertSame(2, $before);

        // Scope is asserted on THIS chain's rows rather than the global return
        // value, so a parallel fixture elsewhere cannot make the count lie.
        bcc_onchain_normalize_empty_collection_images();

        $after = (int) $GLOBALS['wpdb']->get_var(
            "SELECT COUNT(*) FROM `{$t}` WHERE chain_id = " . self::CHAIN . " AND image_url = ''"
        );
        self::assertSame(0, $after);

        $stillReal = (int) $GLOBALS['wpdb']->get_var(
            "SELECT COUNT(*) FROM `{$t}` WHERE chain_id = " . self::CHAIN . " AND image_url = 'https://example.test/c.png'"
        );
        self::assertSame(1, $stillReal, 'exactly the empty strings, nothing else');
    }

    public function testNormalizationIsIdempotent(): void
    {
        $this->seed('0xidem', '');

        bcc_onchain_normalize_empty_collection_images();
        $t = $this->table();
        $snapshot = (string) $GLOBALS['wpdb']->get_var(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|', id, COALESCE(image_url,'<null>')) ORDER BY id), '')
               FROM `{$t}` WHERE chain_id = " . self::CHAIN
        );

        // A second run must find nothing to do — that is what makes it
        // idempotent rather than merely repeatable.
        bcc_onchain_normalize_empty_collection_images();

        $after = (string) $GLOBALS['wpdb']->get_var(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|', id, COALESCE(image_url,'<null>')) ORDER BY id), '')
               FROM `{$t}` WHERE chain_id = " . self::CHAIN
        );
        self::assertSame($snapshot, $after);
    }

    // ── retired market values ───────────────────────────────────────────

    public function testHardcodedSolanaFloorCurrencyIsNulledOut(): void
    {
        // ⚠ The exact production shape: a hardcoded 'SOL' beside a NULL price.
        // It never described a market — it described a chain, and it made an
        // empty column look populated.
        $id = $this->seed('0xsol', null, 'SOL', null);
        self::assertSame('SOL', $this->row($id)->floor_currency, 'precondition');

        bcc_onchain_null_out_retired_market_values();

        self::assertNull($this->row($id)->floor_currency);
    }

    public function testStoredFloorPriceIsNulledOut(): void
    {
        $id = $this->seed('0xprice', null, 'ETH', '1.25000000');

        bcc_onchain_null_out_retired_market_values();

        $row = $this->row($id);
        self::assertNull($row->floor_price);
        self::assertNull($row->floor_currency);
    }

    public function testMarketRetirementLeavesCommunityDataUntouched(): void
    {
        $id = $this->seed('0xkeep', 'https://example.test/keep.png', 'SOL', '9.5');
        $t  = $this->table();
        $GLOBALS['wpdb']->query(
            "UPDATE `{$t}` SET collection_name = 'Keep Me', total_supply = 777, is_verified = 1 WHERE id = {$id}"
        );

        bcc_onchain_null_out_retired_market_values();

        $row = $this->row($id);
        self::assertSame('Keep Me', $row->collection_name, 'identity is not market data');
        self::assertSame('777', $row->total_supply, 'collection SIZE is allowed and must survive');
        self::assertSame('1', $row->is_verified, 'verification must not be touched by a metadata migration');
        self::assertSame('https://example.test/keep.png', $row->image_url);
        self::assertNull($row->floor_price);
    }

    public function testMarketRetirementIsIdempotent(): void
    {
        $this->seed('0xidem2', null, 'SOL', '3.5');

        $first = bcc_onchain_null_out_retired_market_values();
        self::assertIsArray($first);

        $second = bcc_onchain_null_out_retired_market_values();

        foreach (['floor_price', 'floor_currency', 'total_volume', 'listed_percentage', 'royalty_percentage'] as $c) {
            self::assertSame(0, $second[$c] ?? null, "{$c}: a second run must change zero rows");
        }
    }

    // ── the completion gate ─────────────────────────────────────────────

    public function testTheVerifierReportsComplete(): void
    {
        self::assertTrue(
            bcc_onchain_verify_collections_community_metadata(),
            'the schema-version stamp gate must see a complete migration'
        );
    }

    /**
     * ⚠ The verifier must report INCOMPLETE when a column is genuinely absent.
     *
     * Asserting only "returns true on a migrated table" is satisfied by a
     * verifier that returns true unconditionally — a mutation control that
     * removed its check survived exactly that way. The stamp gate is what
     * stops a migration ever being retried, so a verifier that cannot say
     * "no" is worse than none.
     */
    public function testTheVerifierReportsIncompleteWhenAColumnIsMissing(): void
    {
        $t = $this->table();

        // Drop one column, assert the gate refuses, then put it back. Scoped
        // to a column PR 7 added, so a failure here cannot damage pre-existing
        // data — and the re-add is in a finally so an assertion failure still
        // restores the schema.
        $GLOBALS['wpdb']->query("ALTER TABLE `{$t}` DROP COLUMN collection_symbol");

        try {
            self::assertFalse(
                bcc_onchain_verify_collections_community_metadata(),
                'a missing column must NOT be reported as a complete migration'
            );
        } finally {
            $GLOBALS['wpdb']->query("ALTER TABLE `{$t}` ADD COLUMN collection_symbol VARCHAR(32) DEFAULT NULL");
        }

        self::assertTrue(
            bcc_onchain_verify_collections_community_metadata(),
            'and complete again once the column is back'
        );
    }

    public function testRunningTheWholeMigrationAgainChangesNothing(): void
    {
        $t = $this->table();
        $this->seed('0xrerun', '', 'SOL', '2.0');

        bcc_onchain_add_collections_community_metadata();
        $snapshot = (string) $GLOBALS['wpdb']->get_var(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|', id,
                COALESCE(image_url,'<null>'), COALESCE(floor_currency,'<null>'),
                COALESCE(floor_price,'<null>'), chain_description_state) ORDER BY id), '')
               FROM `{$t}` WHERE chain_id = " . self::CHAIN
        );

        bcc_onchain_add_collections_community_metadata();

        $after = (string) $GLOBALS['wpdb']->get_var(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|', id,
                COALESCE(image_url,'<null>'), COALESCE(floor_currency,'<null>'),
                COALESCE(floor_price,'<null>'), chain_description_state) ORDER BY id), '')
               FROM `{$t}` WHERE chain_id = " . self::CHAIN
        );

        self::assertSame($snapshot, $after, 'the full migration is safe to rerun');
        self::assertTrue(bcc_onchain_verify_collections_community_metadata());
    }
}
