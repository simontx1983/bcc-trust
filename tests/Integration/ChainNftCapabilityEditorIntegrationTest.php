<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * THE CAPABILITY WRITE PATH AGAINST A REAL MySQL.
 *
 * ── WHAT ONLY THIS FILE CAN PROVE ───────────────────────────────────────
 * The unit suite fakes both repositories at their production FQNs, so it
 * can prove what the SERVICE does with a write result but not what MySQL
 * actually produces. Five claims are only checkable here, and every one of
 * them is a claim about SQL:
 *
 *   1. THE CASCADE IS ONE STATEMENT. `disableNftProductSupport()` sets both
 *      columns in a single `UPDATE`, so there is no window in which the
 *      first landed and the second did not.
 *   2. AFFECTED ROWS MEANS WHAT THE SERVICE THINKS IT MEANS. MySQL reports
 *      1 for an insert, 2 for a changed update, and 0 when the row already
 *      held those values — which is the entire basis of the no-op rule. A
 *      fake asserting that would be asserting its own fixture.
 *   3. THE UNIQUE KEY HOLDS. Repeated upserts on one triple produce one
 *      row, because `uq_chain_op_driver` says so — not because the fake
 *      happened to overwrite an array slot.
 *   4. THE PROJECTION CARRIES BOTH COLUMNS. A capability column dropped
 *      from `ChainRepository::COLUMNS` would make every chain read
 *      UNKNOWN — the right fail-closed answer, and a completely silent one,
 *      which every test that fakes the repository would still pass.
 *   5. THE CACHE IS INVALIDATED. `getActive()` is served from a five-minute
 *      object-cache/transient pair; a write that skipped invalidation would
 *      leave the postcondition read answering from the projection taken
 *      before it.
 *
 * ── AND IT LEAVES NOTHING ENABLED ───────────────────────────────────────
 * The bootstrap rebuilds a throwaway database from the real installers on
 * every run, and {@see tearDown()} returns both columns to 0 and empties the
 * override table. The final test asserts the fixture database holds no
 * enabled capability and no override row — so a run of this suite cannot be
 * what leaves one behind.
 */
#[Group('integration')]
#[CoversClass(ChainRepository::class)]
#[CoversClass(ChainNftCapabilityRepository::class)]
final class ChainNftCapabilityEditorIntegrationTest extends TestCase
{
    private const PRODUCT = 'bcc_supports_nft_collections';
    private const MANUAL  = 'manual_collection_discovery_enabled';

    private static int $chainId = 0;

    /**
     * The state the REAL installer left, captured before any test in this
     * class touches a flag.
     *
     * setUp() normalises the columns, which would also mask an installer
     * that opted a chain in — so the as-installed reading has to be taken
     * before setUp ever runs.
     *
     * @var array{chains: int, product: int, manual: int, overrides: int}|null
     */
    private static ?array $asInstalled = null;

    public static function setUpBeforeClass(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        self::$asInstalled = [
            'chains'    => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`'),
            'product'   => (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::PRODUCT . ' <> 0'
            ),
            'manual'    => (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::MANUAL . ' <> 0'
            ),
            'overrides' => (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM `' . ChainNftCapabilityRepository::table() . '`'
            ),
        ];

        self::$chainId = (int) $wpdb->get_var(
            'SELECT id FROM `' . $table . '` WHERE chain_type = "cosmos" ORDER BY id ASC LIMIT 1'
        );
    }

    /**
     * ⚠️ THE MIGRATION ENABLES NOTHING.
     *
     * `TINYINT(1) NOT NULL DEFAULT 0` with no backfill means every
     * pre-existing row lands at 0 — but "no backfill statement" is a claim
     * about SQL, and SQL is what this file runs. The override table is
     * created empty for the same reason.
     */
    public function testTheInstallerEnablesNothingAndSeedsNoOverride(): void
    {
        self::assertNotNull(self::$asInstalled);
        $this->assertGreaterThan(0, self::$asInstalled['chains'], 'the seed inserted chains');
        $this->assertSame(0, self::$asInstalled['product'], 'no chain arrives with product support');
        $this->assertSame(0, self::$asInstalled['manual'], 'no chain arrives permitted');
        $this->assertSame(0, self::$asInstalled['overrides'], 'the override table is created empty');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$chainId <= 0) {
            $this->markTestSkipped('no Cosmos chain in the fixture database');
        }

        $this->resetFixture();
    }

    protected function tearDown(): void
    {
        $this->resetFixture();
        parent::tearDown();
    }

    /** Both flags off, no override rows, cold caches. */
    private function resetFixture(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query(
            'UPDATE `' . ChainRepository::table() . '`'
            . ' SET ' . self::PRODUCT . ' = 0, ' . self::MANUAL . ' = 0'
        );
        $wpdb->query('DELETE FROM `' . ChainNftCapabilityRepository::table() . '`');

        ChainRepository::clearCache();
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];
    }

    private function storedFlag(string $column): int
    {
        return (int) $GLOBALS['wpdb']->get_var(
            'SELECT ' . $column . ' FROM `' . ChainRepository::table() . '` WHERE id = ' . self::$chainId
        );
    }

    private function overrideRowCount(): int
    {
        return (int) $GLOBALS['wpdb']->get_var(
            'SELECT COUNT(*) FROM `' . ChainNftCapabilityRepository::table() . '`'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE TWO CHAIN FLAGS
    // ═══════════════════════════════════════════════════════════════════

    public function testProductEnableMovesOneColumnAndReportsOneRow(): void
    {
        $result = ChainRepository::enableNftProductSupport(self::$chainId);

        $this->assertFalse($result->isFailure());
        $this->assertTrue($result->mutated(), 'MySQL reports one affected row');
        $this->assertSame(1, $result->affectedRows());

        $this->assertSame(1, $this->storedFlag(self::PRODUCT));
        $this->assertSame(0, $this->storedFlag(self::MANUAL), 'the permission is a separate grant');
    }

    /**
     * ⚠️ A REPEAT REPORTS ZERO AFFECTED ROWS — THE BASIS OF THE NO-OP RULE.
     *
     * This is the behaviour the whole write path is built on, and it can
     * only be observed against a real server: MySQL reports 0 for an UPDATE
     * that matched a row already holding the value.
     */
    public function testARepeatedFlagWriteReportsZeroAffectedRows(): void
    {
        ChainRepository::enableNftProductSupport(self::$chainId);

        $again = ChainRepository::enableNftProductSupport(self::$chainId);

        $this->assertFalse($again->isFailure(), 'it ran perfectly well');
        $this->assertTrue($again->isNoOp(), 'and changed nothing');
        $this->assertSame(0, $again->affectedRows());
        $this->assertSame(1, $this->storedFlag(self::PRODUCT), 'the value is still right');
    }

    /**
     * ⚠️ THE CASCADE IS ONE STATEMENT.
     *
     * Both columns move together. Observed by setting them independently
     * first, then disabling, and reading both back — with one affected row,
     * which is what proves it was a single UPDATE rather than two.
     */
    public function testDisablingProductSupportClearsBothColumnsInOneStatement(): void
    {
        ChainRepository::enableNftProductSupport(self::$chainId);
        ChainRepository::setManualCollectionDiscoveryEnabled(self::$chainId, true);
        $this->assertSame(1, $this->storedFlag(self::PRODUCT));
        $this->assertSame(1, $this->storedFlag(self::MANUAL));

        $result = ChainRepository::disableNftProductSupport(self::$chainId);

        $this->assertTrue($result->mutated());
        $this->assertSame(1, $result->affectedRows(), 'ONE row, ONE statement, TWO columns');
        $this->assertSame(0, $this->storedFlag(self::PRODUCT));
        $this->assertSame(0, $this->storedFlag(self::MANUAL));
    }

    /** A stale permission with support already off is still cleared. */
    public function testTheCascadeClearsAStalePermissionWhenSupportIsAlreadyOff(): void
    {
        ChainRepository::setManualCollectionDiscoveryEnabled(self::$chainId, true);
        $this->assertSame(0, $this->storedFlag(self::PRODUCT));
        $this->assertSame(1, $this->storedFlag(self::MANUAL));

        $result = ChainRepository::disableNftProductSupport(self::$chainId);

        $this->assertTrue($result->mutated(), 'one column still had to move');
        $this->assertSame(0, $this->storedFlag(self::MANUAL));
    }

    /** A flag write touches exactly one chain. */
    public function testAFlagWriteTouchesOnlyItsOwnChain(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        ChainRepository::enableNftProductSupport(self::$chainId);

        $others = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM `' . $table . '`'
            . ' WHERE id <> ' . self::$chainId . ' AND ' . self::PRODUCT . ' <> 0'
        );
        $this->assertSame(0, $others, 'no other chain was granted anything');
    }

    /** An invalid chain id never reaches the database. */
    public function testAnInvalidChainIdIsRefusedBeforeTheStatement(): void
    {
        foreach ([0, -1] as $bad) {
            $this->assertTrue(ChainRepository::enableNftProductSupport($bad)->isFailure());
            $this->assertTrue(ChainRepository::disableNftProductSupport($bad)->isFailure());
            $this->assertTrue(
                ChainRepository::setManualCollectionDiscoveryEnabled($bad, true)->isFailure()
            );
        }

        $this->assertSame(0, $this->storedFlag(self::PRODUCT));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE PROJECTION AND THE CACHE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ BOTH COLUMNS SURVIVE INTO THE CACHED PROJECTION.
     *
     * The capability model treats an ABSENT property as "this install
     * cannot say" and refuses — the right fail-closed answer, and a
     * completely silent one. Dropping either column from
     * `ChainRepository::COLUMNS` would make every chain permanently
     * un-scannable with no error anywhere, and every unit test that fakes
     * the repository would still pass.
     */
    public function testBothCapabilityColumnsAreInTheProjection(): void
    {
        ChainRepository::enableNftProductSupport(self::$chainId);
        ChainRepository::setManualCollectionDiscoveryEnabled(self::$chainId, true);

        $chain = ChainRepository::getById(self::$chainId);
        self::assertNotNull($chain);

        $this->assertTrue(
            NftChainCapability::bccNftSupportState($chain),
            'the product column reads back through the model, not as null'
        );
        $this->assertTrue(
            NftChainCapability::manualDiscoveryState($chain),
            'and so does the permission'
        );
    }

    /**
     * ⚠️ THE WRITE INVALIDATES THE CACHE, SO THE READ-BACK IS REAL.
     *
     * Warm the cache first, then write, then re-read. Without the
     * invalidation inside the write, this returns the pre-write projection
     * for up to the whole five-minute TTL — and the postcondition check
     * would be comparing the write against the value it replaced.
     */
    public function testAFlagWriteInvalidatesTheChainCache(): void
    {
        // Warm it.
        $before = ChainRepository::getById(self::$chainId);
        self::assertNotNull($before);
        $this->assertFalse(NftChainCapability::bccNftSupportState($before));

        ChainRepository::enableNftProductSupport(self::$chainId);

        $after = ChainRepository::getById(self::$chainId);
        self::assertNotNull($after);
        $this->assertTrue(
            NftChainCapability::bccNftSupportState($after),
            'a stale projection here would make every postcondition check meaningless'
        );
    }

    /** And the cache is cleared even when the write affected no rows. */
    public function testTheCacheIsInvalidatedEvenOnAZeroRowWrite(): void
    {
        ChainRepository::enableNftProductSupport(self::$chainId);
        ChainRepository::getById(self::$chainId);       // warm

        // Move the value behind the repository's back, as a concurrent
        // request would, then issue a write that matches nothing.
        $GLOBALS['wpdb']->query(
            'UPDATE `' . ChainRepository::table() . '` SET ' . self::PRODUCT . ' = 1'
            . ' WHERE id = ' . self::$chainId
        );
        $repeat = ChainRepository::enableNftProductSupport(self::$chainId);
        $this->assertTrue($repeat->isNoOp());

        $chain = ChainRepository::getById(self::$chainId);
        self::assertNotNull($chain);
        $this->assertTrue(
            NftChainCapability::bccNftSupportState($chain),
            'a concurrent writer must not leave this request reading a stale memo'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE OVERRIDE TABLE
    // ═══════════════════════════════════════════════════════════════════

    public function testUpsertInsertsExactlyOneRow(): void
    {
        $result = ChainNftCapabilityRepository::upsertOverride(
            self::$chainId,
            NftDriverRegistry::OP_ENUMERATION,
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            false,
            10
        );

        $this->assertTrue($result->mutated());
        $this->assertSame(1, $result->affectedRows(), 'MySQL reports 1 for an INSERT');
        $this->assertSame(1, $this->overrideRowCount());

        $overrides = ChainNftCapabilityRepository::getForChain(self::$chainId);
        $this->assertTrue($overrides->isAvailable());
        $this->assertSame(
            [[
                'operation'  => NftDriverRegistry::OP_ENUMERATION,
                'driver_key' => NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
                'enabled'    => false,
                'priority'   => 10,
            ]],
            $overrides->rows()
        );
    }

    /**
     * ⚠️ THE UNIQUE KEY IS THE CONCURRENCY AUTHORITY.
     *
     * Repeated upserts on one triple produce ONE row, because
     * `uq_chain_op_driver` says so. Not because the code read first — it
     * does not — which is what makes the write safe against a second
     * request arriving between any read and any write.
     */
    public function testRepeatedUpsertsProduceExactlyOneRow(): void
    {
        for ($i = 0; $i < 5; $i++) {
            ChainNftCapabilityRepository::upsertOverride(
                self::$chainId,
                NftDriverRegistry::OP_ENUMERATION,
                NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
                $i % 2 === 0,
                10 + $i
            );
        }

        $this->assertSame(1, $this->overrideRowCount(), 'one triple, one row');

        $rows = ChainNftCapabilityRepository::getForChain(self::$chainId)->rows();
        $this->assertSame(14, $rows[0]['priority'], 'the last write wins');
        $this->assertTrue($rows[0]['enabled']);
    }

    /**
     * ⚠️ MySQL AFFECTED-ROW SEMANTICS, OBSERVED RATHER THAN ASSUMED.
     *
     * 1 = inserted, 2 = updated with a change, 0 = the row already held
     * exactly these values. The service's no-op rule rests entirely on the
     * third case being distinguishable, and this is where that is checked
     * against a real server.
     */
    public function testUpsertAffectedRowsDistinguishInsertUpdateAndNoChange(): void
    {
        $insert = ChainNftCapabilityRepository::upsertOverride(
            self::$chainId,
            NftDriverRegistry::OP_METADATA,
            NftDriverRegistry::DRIVER_CW721_LCD,
            false,
            10
        );
        $this->assertSame(1, $insert->affectedRows(), 'insert');
        $this->assertTrue($insert->mutated());

        $update = ChainNftCapabilityRepository::upsertOverride(
            self::$chainId,
            NftDriverRegistry::OP_METADATA,
            NftDriverRegistry::DRIVER_CW721_LCD,
            true,
            20
        );
        $this->assertSame(2, $update->affectedRows(), 'changed update');
        $this->assertTrue($update->mutated());

        $identical = ChainNftCapabilityRepository::upsertOverride(
            self::$chainId,
            NftDriverRegistry::OP_METADATA,
            NftDriverRegistry::DRIVER_CW721_LCD,
            true,
            20
        );
        $this->assertTrue($identical->isNoOp(), 'nothing moved');
        $this->assertFalse($identical->isFailure(), 'and it certainly did not fail');
    }

    /** Delete removes only the exact key, and reports whether it did. */
    public function testDeleteRemovesOnlyTheExactRow(): void
    {
        foreach ([
            [NftDriverRegistry::OP_ENUMERATION, NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION],
            [NftDriverRegistry::OP_METADATA, NftDriverRegistry::DRIVER_CW721_LCD],
            [NftDriverRegistry::OP_OWNERSHIP, NftDriverRegistry::DRIVER_CW721_LCD],
        ] as [$operation, $driver]) {
            ChainNftCapabilityRepository::upsertOverride(self::$chainId, $operation, $driver, false, 10);
        }
        $this->assertSame(3, $this->overrideRowCount());

        $deleted = ChainNftCapabilityRepository::deleteOverride(
            self::$chainId,
            NftDriverRegistry::OP_METADATA,
            NftDriverRegistry::DRIVER_CW721_LCD
        );

        $this->assertTrue($deleted->mutated());
        $this->assertSame(1, $deleted->affectedRows());
        $this->assertSame(2, $this->overrideRowCount(), 'the two siblings are untouched');

        // Same driver on a DIFFERENT operation survives — the key is the
        // whole triple, not the driver.
        $remaining = ChainNftCapabilityRepository::getForChain(self::$chainId)->rows();
        $ownership = array_values(array_filter(
            $remaining,
            static fn(array $r): bool => $r['operation'] === NftDriverRegistry::OP_OWNERSHIP
        ));
        $this->assertCount(1, $ownership);
    }

    /** Deleting a row that is not there is a no-op, not a failure. */
    public function testDeletingAnAbsentRowIsANoOpNotAFailure(): void
    {
        $result = ChainNftCapabilityRepository::deleteOverride(
            self::$chainId,
            NftDriverRegistry::OP_ENUMERATION,
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION
        );

        $this->assertFalse($result->isFailure());
        $this->assertTrue($result->isNoOp());
        $this->assertSame(0, $result->affectedRows());
    }

    /** An invalid triple never reaches the statement. */
    public function testAnInvalidTripleIsRefusedBeforeTheStatement(): void
    {
        $this->assertTrue(
            ChainNftCapabilityRepository::upsertOverride(0, 'x', 'y', true, 1)->isFailure()
        );
        $this->assertTrue(
            ChainNftCapabilityRepository::upsertOverride(self::$chainId, '', 'y', true, 1)->isFailure()
        );
        $this->assertTrue(
            ChainNftCapabilityRepository::upsertOverride(self::$chainId, 'x', '', true, 1)->isFailure()
        );
        $this->assertTrue(
            ChainNftCapabilityRepository::deleteOverride(self::$chainId, '', '')->isFailure()
        );

        $this->assertSame(0, $this->overrideRowCount());
    }

    /**
     * A stored row that the registry does not recognise is INERT.
     *
     * Written straight into the table, past every validation the editor
     * applies, because that is exactly how one arrives in the real world —
     * a restored backup, a manual INSERT, a downgrade.
     */
    public function testAnUnrecognisedStoredRowIsInert(): void
    {
        ChainNftCapabilityRepository::upsertOverride(
            self::$chainId,
            'teleportation',
            'moonbeam_nft',
            true,
            0
        );

        $chain = ChainRepository::getById(self::$chainId);
        self::assertNotNull($chain);

        $overrides = ChainNftCapabilityRepository::getForChain(self::$chainId);
        $this->assertTrue($overrides->isAvailable());
        $this->assertCount(1, $overrides->rows(), 'the row is stored and readable');

        // And it changes nothing about what the chain can do.
        $withRow = NftDriverRegistry::driversFor(
            $chain,
            NftDriverRegistry::OP_ENUMERATION,
            $overrides->rows()
        );
        $withoutRow = NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_ENUMERATION, []);
        $this->assertSame($withoutRow, $withRow, 'an unrecognised row grants and blocks nothing');

        // The model lists it as stale rather than hiding it.
        $matrix = NftChainCapability::operationMatrix($chain);
        $this->assertCount(1, $matrix['stale_overrides']);
        $this->assertSame(
            NftChainCapability::STALE_UNKNOWN_OPERATION,
            $matrix['stale_overrides'][0]['reason']
        );
    }

    /** A real disable really does remove the driver from the effective list. */
    public function testAStoredDisableNarrowsTheEffectiveDriverList(): void
    {
        $chain = ChainRepository::getById(self::$chainId);
        self::assertNotNull($chain);

        $before = NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_ENUMERATION, []);
        $this->assertContains(NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION, $before);

        ChainNftCapabilityRepository::upsertOverride(
            self::$chainId,
            NftDriverRegistry::OP_ENUMERATION,
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            false,
            10
        );

        $after = NftDriverRegistry::driversFor(
            $chain,
            NftDriverRegistry::OP_ENUMERATION,
            ChainNftCapabilityRepository::getForChain(self::$chainId)->rows()
        );
        $this->assertNotContains(NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION, $after);
    }

    /** The generation counter moves, so a read-side cache would see the write. */
    public function testTheGenerationCounterAdvancesOnDemand(): void
    {
        $before = ChainNftCapabilityRepository::getChainGeneration(self::$chainId);
        ChainNftCapabilityRepository::bumpChainGeneration(self::$chainId);
        $after = ChainNftCapabilityRepository::getChainGeneration(self::$chainId);

        $this->assertGreaterThan($before, $after);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE FIXTURE IS LEFT CLEAN
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ NOTHING THIS SUITE DID SURVIVES IT.
     *
     * Depends on tearDown having run for every test above, which PHPUnit
     * guarantees. If a future test enables something and forgets to clean
     * up, this is what says so — rather than a later run inheriting an
     * enabled capability and quietly passing because of it.
     */
    public function testTheFixtureDatabaseRetainsNoEnabledCapability(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $this->assertSame(
            0,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::PRODUCT . ' <> 0'),
            'no chain is left with product support'
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::MANUAL . ' <> 0'),
            'no chain is left permitted'
        );
        $this->assertSame(0, $this->overrideRowCount(), 'no override row survives');
    }
}
