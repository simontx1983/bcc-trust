<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The NFT capability migration against a REAL MySQL.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * The unit suite fakes ChainRepository at its production FQN, so it can
 * prove what the capability model does with a projection but not what the
 * DATABASE and the real repository actually produce. Four claims are only
 * checkable here:
 *
 *   1. THE MIGRATION ENABLES NOTHING. `TINYINT(1) NOT NULL DEFAULT 0` with
 *      no backfill means every pre-existing row lands at 0 — but "no
 *      backfill statement" is a claim about SQL, and SQL is what this file
 *      runs. Asserted against the registry AS INSTALLED, captured before any
 *      test in this class touches a column.
 *
 *   2. THE COLUMNS ARE IN THE CACHED PROJECTION. Their readers treat an
 *      ABSENT property as "this install cannot say" and refuse — the right
 *      fail-closed answer and a completely SILENT one. If somebody dropped
 *      either column from `ChainRepository::COLUMNS`, every chain would
 *      become permanently un-scannable and every unit test that fakes the
 *      repository would still pass.
 *
 *   3. THE SHAPE IS WHAT WAS PROMISED. Type, nullability and default read
 *      back out of INFORMATION_SCHEMA rather than being trusted to dbDelta.
 *
 *   4. THE UNIQUE KEY SURVIVED. dbDelta is fussy about index syntax and can
 *      skip one silently; without `uq_chain_op_driver` the override set
 *      would admit duplicates and stop being deterministic.
 *
 * ── WHAT THIS FILE DELIBERATELY DOES *NOT* ASSERT ───────────────────────
 * That the enabled-count is zero FOREVER. That is true only immediately
 * after introduction, and a later PR will legitimately enable chains. A
 * permanent zero-count assertion would then report correct configuration as
 * breakage. Emptiness is pinned as an observation of a FRESH INSTALL
 * (see {@see $asInstalled}); shape is pinned as the permanent invariant.
 */
#[Group('integration')]
#[CoversClass(ChainRepository::class)]
#[CoversClass(ChainNftCapabilityRepository::class)]
final class ChainNftCapabilityMigrationIntegrationTest extends TestCase
{
    private const SUPPORT_COLUMN = 'bcc_supports_nft_collections';
    private const MANUAL_COLUMN  = 'manual_collection_discovery_enabled';

    /**
     * The registry exactly as the REAL installer left it, captured before
     * any test normalises a column.
     *
     * The bootstrap builds the whole schema from scratch against a throwaway
     * database on every run, so this is a genuine fresh-install observation
     * rather than a leftover.
     *
     * @var array{total: int, support_enabled: int, manual_enabled: int, enabled_slugs: list<string>}|null
     */
    private static ?array $asInstalled = null;

    public static function setUpBeforeClass(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        self::$asInstalled = [
            'total'           => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`'),
            'support_enabled' => (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::SUPPORT_COLUMN . ' = 1'
            ),
            'manual_enabled'  => (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::MANUAL_COLUMN . ' = 1'
            ),
            'enabled_slugs'   => array_values(array_map('strval', $wpdb->get_col(
                'SELECT slug FROM `' . $table . '`
                  WHERE ' . self::SUPPORT_COLUMN . ' = 1 OR ' . self::MANUAL_COLUMN . ' = 1'
            ))),
        ];
    }

    protected function setUp(): void
    {
        ChainRepository::clearCache();
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query(
            'UPDATE `' . ChainRepository::table() . '`
                SET ' . self::SUPPORT_COLUMN . ' = 0, ' . self::MANUAL_COLUMN . ' = 0'
        );
        $wpdb->query('DELETE FROM `' . ChainNftCapabilityRepository::table() . '`');
        ChainRepository::clearCache();
    }

    // ── 1. The migration enables nothing ────────────────────────────────

    /**
     * A FRESH INSTALL GRANTS NFT CAPABILITY ON ZERO CHAINS.
     *
     * Not "few". Zero. Including Dungeon, which the architecture plan lists
     * as an intended NFT chain — intent is not data, and authoring the
     * product matrix belongs to the PR that first reads it.
     */
    public function testFreshInstallEnablesNoChain(): void
    {
        self::assertNotNull(self::$asInstalled);
        self::assertGreaterThan(0, self::$asInstalled['total'], 'the installer must seed chains at all');

        self::assertSame(0, self::$asInstalled['support_enabled'], 'no chain may ship with NFT product support on');
        self::assertSame(0, self::$asInstalled['manual_enabled'], 'no chain may ship with discovery permission on');
        self::assertSame([], self::$asInstalled['enabled_slugs']);
    }

    /** The override table ships empty — every chain runs on registry defaults. */
    public function testFreshInstallHasNoDriverOverrides(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        self::assertSame(
            0,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . ChainNftCapabilityRepository::table() . '`')
        );
    }

    /**
     * Re-running both migrations is harmless: nothing enabled, no rows lost,
     * the unique key intact, and no error raised on the way through.
     *
     * The error check reads the Logger rather than `$wpdb->last_error`.
     * `last_error` is overwritten by every subsequent query — including the
     * INFORMATION_SCHEMA probes these migrations run at the end — so
     * asserting it is empty afterwards would pass no matter what happened
     * in between. The Logger accumulates, so it actually records the run.
     */
    public function testMigrationIsIdempotent(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $chainRowsBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`');
        \BCC\Core\Log\Logger::reset();

        bcc_onchain_add_chains_nft_capability_columns();
        bcc_onchain_add_chains_nft_capability_columns();
        bcc_onchain_create_chain_nft_capabilities_table();
        bcc_onchain_create_chain_nft_capabilities_table();

        $errors = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn(array $line): bool => $line['level'] === 'error'
        ));
        self::assertSame([], $errors, 'a re-run must log no error');

        self::assertSame(
            0,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::SUPPORT_COLUMN . ' = 1')
        );
        self::assertSame(
            $chainRowsBefore,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`'),
            'a re-run must not add or drop chain rows'
        );
        self::assertSame(
            3,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                ChainNftCapabilityRepository::table(),
                'uq_chain_op_driver'
            )),
            'a re-run must leave the unique key intact'
        );
    }

    /**
     * RE-RUNNING THE MIGRATION MUST NOT REVERT AN OPERATOR'S DECISION.
     *
     * This is the reason the migration carries no "still zero"
     * postcondition. A later PR will legitimately enable a chain; the
     * migration runs on every schema-version bump forever. If it zeroed the
     * column — or failed and invited somebody to "fix" it by zeroing — every
     * deploy would silently switch the feature back off.
     */
    public function testMigrationPreservesValuesALaterPrLegitimatelySets(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $wpdb->query(
            'UPDATE `' . $table . '`
                SET ' . self::SUPPORT_COLUMN . ' = 1, ' . self::MANUAL_COLUMN . ' = 1
              WHERE slug = "cosmos"'
        );

        bcc_onchain_add_chains_nft_capability_columns();

        self::assertSame(
            '1',
            (string) $wpdb->get_var(
                'SELECT ' . self::SUPPORT_COLUMN . ' FROM `' . $table . '` WHERE slug = "cosmos"'
            ),
            'a re-run migration must leave a deliberately enabled chain enabled'
        );
        self::assertSame(
            '1',
            (string) $wpdb->get_var(
                'SELECT ' . self::MANUAL_COLUMN . ' FROM `' . $table . '` WHERE slug = "cosmos"'
            )
        );
    }

    // ── 3. The shape is what was promised ───────────────────────────────

    /**
     * @param string $column
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('capabilityColumns')]
    public function testColumnShapeIsTinyintNotNullDefaultZero(string $column): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
              LIMIT 1',
            ChainRepository::table(),
            $column
        ));

        self::assertNotNull($row, "{$column} must exist");
        self::assertSame('tinyint', strtolower((string) $row->DATA_TYPE));
        self::assertSame('NO', strtoupper((string) $row->IS_NULLABLE));
        self::assertSame(0, (int) $row->COLUMN_DEFAULT, "{$column} must default to 0");
    }

    /** @return array<string, array{0: string}> */
    public static function capabilityColumns(): array
    {
        return [
            'product support' => [self::SUPPORT_COLUMN],
            'manual permission' => [self::MANUAL_COLUMN],
        ];
    }

    // ── 4. The unique key survived dbDelta ──────────────────────────────

    public function testOverrideTableCarriesItsUniqueKey(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            ChainNftCapabilityRepository::table(),
            'uq_chain_op_driver'
        ));

        self::assertSame(3, $count, 'uq_chain_op_driver must cover all three columns');
    }

    public function testUniqueKeyActuallyRejectsADuplicateTriple(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainNftCapabilityRepository::table();
        $chain = (int) ChainRepository::resolveIdAnyState('cosmos');

        $insert = 'INSERT INTO `' . $table . '` (chain_id, operation, driver_key, enabled, priority, updated_at)
                   VALUES (%d, %s, %s, 0, 10, NOW())';

        self::assertNotFalse($wpdb->query($wpdb->prepare($insert, $chain, 'enumeration', 'cosmwasm_enumeration')));

        $wpdb->suppress_errors(true);
        $second = $wpdb->query($wpdb->prepare($insert, $chain, 'enumeration', 'cosmwasm_enumeration'));
        $wpdb->suppress_errors(false);

        self::assertFalse($second, 'the unique key must reject a duplicate (chain, operation, driver)');
    }

    // ── 2. The columns are in the cached projection ─────────────────────

    /**
     * If either column left `ChainRepository::COLUMNS`, the capability
     * readers would answer UNKNOWN for every chain — silently, forever.
     */
    public function testProjectionCarriesBothCapabilityColumns(): void
    {
        $chain = ChainRepository::getBySlug('cosmos');

        self::assertNotNull($chain);
        $vars = get_object_vars($chain);

        self::assertArrayHasKey(self::SUPPORT_COLUMN, $vars);
        self::assertArrayHasKey(self::MANUAL_COLUMN, $vars);

        // And therefore the readers give a real answer rather than "cannot say".
        self::assertFalse(NftChainCapability::bccNftSupportState($chain));
        self::assertFalse(NftChainCapability::manualDiscoveryState($chain));
    }

    /**
     * CACHE INVALIDATION EXPOSES THE CHANGED VALUE.
     *
     * `getActive()` is served from a 5-minute object-cache/transient pair. A
     * write that skipped invalidation would leave the OLD capability in
     * force for the rest of the TTL while the admin screen showed the new
     * one — the operator would have no way to tell.
     */
    public function testClearCacheExposesAChangedCapabilityValue(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $before = ChainRepository::getBySlug('cosmos');
        self::assertNotNull($before);
        self::assertFalse(NftChainCapability::manualDiscoveryState($before));

        $wpdb->query(
            'UPDATE `' . $table . '` SET ' . self::MANUAL_COLUMN . ' = 1 WHERE slug = "cosmos"'
        );

        // Still the cached projection — the write alone changes nothing here.
        $stale = ChainRepository::getBySlug('cosmos');
        self::assertNotNull($stale);
        self::assertFalse(
            NftChainCapability::manualDiscoveryState($stale),
            'without invalidation the cached projection must still show the old value'
        );

        ChainRepository::clearCache();

        $after = ChainRepository::getBySlug('cosmos');
        self::assertNotNull($after);
        self::assertTrue(
            NftChainCapability::manualDiscoveryState($after),
            'after clearCache the new value must be visible'
        );
    }

    // ── The repository read ─────────────────────────────────────────────

    public function testOverrideRepositoryReturnsRowsInPriorityOrder(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainNftCapabilityRepository::table();
        $chain = (int) ChainRepository::resolveIdAnyState('ethereum');

        $insert = 'INSERT INTO `' . $table . '` (chain_id, operation, driver_key, enabled, priority, updated_at)
                   VALUES (%d, %s, %s, %d, %d, NOW())';

        $wpdb->query($wpdb->prepare($insert, $chain, 'ownership', 'evm_rpc', 1, 5));
        $wpdb->query($wpdb->prepare($insert, $chain, 'ownership', 'alchemy_transfers', 1, 1));

        $result = ChainNftCapabilityRepository::getForChain($chain);

        self::assertTrue($result->isAvailable());
        $rows = $result->rows();

        self::assertCount(2, $rows);
        self::assertSame('alchemy_transfers', $rows[0]['driver_key']);
        self::assertSame('evm_rpc', $rows[1]['driver_key']);
        self::assertTrue($rows[0]['enabled']);
        self::assertSame(1, $rows[0]['priority']);
    }

    /**
     * An unusable chain id is UNAVAILABLE, not "no overrides".
     *
     * A chain we cannot identify is one whose operator configuration we
     * cannot claim to know — and since an absent row means "registry default
     * applies", answering "no overrides" here would grant defaults for a
     * chain that may not even exist.
     *
     * A chain id that is well-formed but simply has no rows is a different
     * matter: that read SUCCEEDED, so it is available and empty.
     */
    public function testUnusableChainIdIsUnavailableRatherThanEmpty(): void
    {
        foreach ([0, -1] as $bad) {
            $result = ChainNftCapabilityRepository::getForChain($bad);
            self::assertFalse($result->isAvailable(), "chain id {$bad} must be unavailable");
            self::assertSame(ChainNftCapabilityOverrides::REASON_INVALID_CHAIN, $result->reason());
        }

        // A syntactically valid id with no rows: the read worked.
        $absent = ChainNftCapabilityRepository::getForChain(999999);
        self::assertTrue($absent->isAvailable());
        self::assertSame([], $absent->rows());
    }

    // ── The override read fails CLOSED ──────────────────────────────────

    /**
     * THE HEADLINE REGRESSION.
     *
     * 1. an operator disables the only enumeration driver on a chain that
     *    is otherwise fully permitted and configured
     * 2. the override store then becomes unreadable
     * 3. the chain must STAY non-scannable
     * 4. registry defaults must NOT be silently restored
     *
     * Step 4 is the one that matters. Because an ABSENT row means "registry
     * default applies", a failed read reported as "no overrides" would hand
     * the disabled driver straight back — at exactly the moment the database
     * is least healthy. The table is really renamed here, so the failure is
     * a genuine unreadable read rather than a mocked one.
     */
    public function testADisabledDriverDoesNotComeBackWhenTheOverrideStoreFails(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainNftCapabilityRepository::table();
        $chain = self::fullyPermittedCosmosChain();

        // ⚠ S7: asserted through `operationMatrix()` rather than the removed
        // chain-level `forChain()`/`verdict()`. The per-operation status is
        // the stronger assertion — it NAMES the operation whose driver was
        // disabled, where the verdict only summarised one of them.
        $op = NftDriverRegistry::OP_VALIDATION;

        // 1. Operator disables the chain's only driver for that operation.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . $table . '` (chain_id, operation, driver_key, enabled, priority, updated_at)
             VALUES (%d, %s, %s, 0, 10, NOW())',
            (int) $chain->id,
            NftDriverRegistry::OP_VALIDATION,
            NftDriverRegistry::DRIVER_CW721_LCD
        ));

        self::assertSame(
            NftChainCapability::OP_DISABLED,
            NftChainCapability::operationMatrix($chain)['operations'][$op]['status'],
            'the operator disable must take effect while the store is readable'
        );

        // 2. The override store becomes unreadable.
        $wpdb->query('RENAME TABLE `' . $table . '` TO `' . $table . '_gone`');

        try {
            $matrix = NftChainCapability::operationMatrix($chain);

            // 3 + 4. A failed read is "we cannot say", not "no overrides" —
            // the fail-OPEN this test exists to prevent would report the
            // registry default and silently re-enable the disabled driver.
            self::assertFalse(
                $matrix['overrides_available'],
                'an unreadable override store must report itself unavailable'
            );
            self::assertSame(
                NftChainCapability::OP_UNKNOWN,
                $matrix['operations'][$op]['status'],
                'a failed override read must not resolve to a confident status'
            );
            self::assertNotSame(
                NftChainCapability::OP_READY,
                $matrix['operations'][$op]['status'],
                'and must never read as ready'
            );
        } finally {
            $wpdb->query('RENAME TABLE `' . $table . '_gone` TO `' . $table . '`');
        }

        // And the disable is still in force once the store returns.
        self::assertSame(
            NftChainCapability::OP_DISABLED,
            NftChainCapability::operationMatrix($chain)['operations'][$op]['status']
        );
    }

    public function testUnreadableOverrideStoreReportsUnavailableNotEmpty(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainNftCapabilityRepository::table();
        $chain = (int) ChainRepository::resolveIdAnyState('cosmos');

        $wpdb->query('RENAME TABLE `' . $table . '` TO `' . $table . '_gone`');
        try {
            $result = ChainNftCapabilityRepository::getForChain($chain);

            self::assertFalse($result->isAvailable());
            self::assertSame(ChainNftCapabilityOverrides::REASON_READ_FAILED, $result->reason());
        } finally {
            $wpdb->query('RENAME TABLE `' . $table . '_gone` TO `' . $table . '`');
        }
    }

    /** A successful read with zero rows is AVAILABLE — defaults may apply. */
    public function testZeroOverridesIsAvailableNotUnavailable(): void
    {
        $result = ChainNftCapabilityRepository::getForChain(
            (int) ChainRepository::resolveIdAnyState('cosmos')
        );

        self::assertTrue($result->isAvailable());
        self::assertSame([], $result->rows());
        self::assertNull($result->reason());
    }

    // ── A truncated override read is not a partial answer ───────────────

    /**
     * A bounded read that hits its ceiling is a SUBSET of what the operator
     * configured. Applying a subset would honour some restrictions and
     * silently drop others — so the whole set is unavailable instead.
     *
     * The concrete hazard: corrupt or hostile rows occupy the first N
     * positions while a genuine disabling row sits beyond the limit.
     */
    public function testAnOverflowingOverrideSetIsUnavailableAndNotScannable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainNftCapabilityRepository::table();
        $chain = self::fullyPermittedCosmosChain();
        $id    = (int) $chain->id;

        // 201 rows — one past the 200 ceiling. Distinct driver keys keep
        // uq_chain_op_driver satisfied.
        $values = [];
        for ($i = 0; $i <= 200; $i++) {
            $values[] = $wpdb->prepare('(%d, %s, %s, 1, %d, NOW())', $id, 'enumeration', 'filler_' . $i, $i);
        }
        $wpdb->query(
            'INSERT INTO `' . $table . '` (chain_id, operation, driver_key, enabled, priority, updated_at)
             VALUES ' . implode(',', $values)
        );

        $result = ChainNftCapabilityRepository::getForChain($id);
        self::assertFalse($result->isAvailable(), 'an overflowing set must not be applied');
        self::assertSame(ChainNftCapabilityOverrides::REASON_OVERFLOW, $result->reason());

        // ⚠ S7: via the matrix, since the chain-level verdict is gone. Every
        // operation must read UNKNOWN — an overflowing set is not a partial
        // set, so no row may resolve confidently from it.
        $matrix = NftChainCapability::operationMatrix($chain);
        self::assertFalse($matrix['overrides_available']);
        foreach ($matrix['operations'] as $operation => $row) {
            self::assertSame(
                NftChainCapability::OP_UNKNOWN,
                $row['status'],
                $operation . ' must not resolve from an overflowing override set'
            );
        }
    }

    /** Exactly at the ceiling is fine — only PAST it is truncation. */
    public function testExactlyTheCeilingIsStillAvailable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainNftCapabilityRepository::table();
        $id    = (int) ChainRepository::resolveIdAnyState('cosmos');

        $values = [];
        for ($i = 0; $i < 200; $i++) {
            $values[] = $wpdb->prepare('(%d, %s, %s, 1, %d, NOW())', $id, 'enumeration', 'filler_' . $i, $i);
        }
        $wpdb->query(
            'INSERT INTO `' . $table . '` (chain_id, operation, driver_key, enabled, priority, updated_at)
             VALUES ' . implode(',', $values)
        );

        $result = ChainNftCapabilityRepository::getForChain($id);
        self::assertTrue($result->isAvailable());
        self::assertCount(200, $result->rows());
    }

    /** A structurally broken row makes the whole set unavailable. */
    public function testAMalformedRowMakesTheSetUnavailable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainNftCapabilityRepository::table();
        $id    = (int) ChainRepository::resolveIdAnyState('cosmos');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . $table . '` (chain_id, operation, driver_key, enabled, priority, updated_at)
             VALUES (%d, %s, %s, 1, 10, NOW())',
            $id,
            '',
            'cosmwasm_enumeration'
        ));

        $result = ChainNftCapabilityRepository::getForChain($id);
        self::assertFalse($result->isAvailable());
        self::assertSame(ChainNftCapabilityOverrides::REASON_MALFORMED, $result->reason());
    }

    /**
     * A row naming a driver this build does not implement is NORMAL, not
     * malformed — an older or newer build wrote it, and the registry
     * intersection discards it harmlessly. It must not poison the set.
     */
    public function testAnUnknownDriverKeyIsNotTreatedAsMalformed(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainNftCapabilityRepository::table();
        $id    = (int) ChainRepository::resolveIdAnyState('cosmos');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . $table . '` (chain_id, operation, driver_key, enabled, priority, updated_at)
             VALUES (%d, %s, %s, 1, 10, NOW())',
            $id,
            'enumeration',
            'driver_from_a_future_build'
        ));

        $result = ChainNftCapabilityRepository::getForChain($id);
        self::assertTrue($result->isAvailable());
        self::assertCount(1, $result->rows());
    }

    // ── S5: THE STORED MEASUREMENT IS NOT READ, END TO END ──────────────

    /**
     * ⚠⚠⚠ THE PROOF THAT THE DEPENDENCY IS GONE, AGAINST A REAL ROW.
     *
     * A genuine `cw_discovery_state = 'unsupported'` is written to
     * `wp_bcc_chain_checkpoints` through the repository, and the capability
     * answer is taken before and after. They must be IDENTICAL.
     *
     * Asserted as an equality rather than against a named value on purpose:
     * it holds whatever this environment's driver readiness produces, and it
     * fails if the column is consulted again by any route — including one
     * this test did not think to name. The S4-era version asserted
     * `CHAIN_UNSUPPORTED`, so it was the exact test S5 had to invert.
     *
     * ⚠ S7 widened it. It compared one chain-level verdict; it now compares
     * EVERY per-operation status and reason, because the verdict is gone and
     * because a per-operation comparison catches a leak into any single row
     * rather than only into the summary.
     */
    public function testACosmosChainsCapabilityIsUnchangedByAStored501(): void
    {
        $chain  = self::fullyPermittedCosmosChain();
        $before = NftChainCapability::operationMatrix($chain)['operations'];

        self::markCwUnsupported((int) $chain->id);

        // Re-project: the answer must be recomputed from a fresh row, not
        // served from a cache that predates the write.
        ChainRepository::clearCache();
        $reprojected = ChainRepository::getBySlug('cosmos');
        self::assertNotNull($reprojected);

        $after = NftChainCapability::operationMatrix($reprojected)['operations'];

        self::assertSame(
            array_keys($before),
            array_keys($after),
            'the operation set itself must not change'
        );

        $compared = 0;
        foreach ($before as $operation => $row) {
            self::assertSame(
                $row['status'],
                $after[$operation]['status'],
                $operation . ' changed status because of a measurement nothing reads'
            );
            self::assertSame(
                $row['reason'],
                $after[$operation]['reason'],
                $operation . ' changed its stated reason'
            );
            $compared++;
        }

        // Anti-vacuity: an empty operation map would compare nothing at all.
        self::assertSame(5, $compared, 'five operations must have been compared');
    }

    /**
     * The measurement really was written, so the case above is not vacuous.
     *
     * Without this, a `markCwUnsupported()` that silently failed — and
     * `$wpdb->query()` returns false without throwing — would leave the
     * equality above comparing two identical no-ops and passing for the
     * wrong reason.
     */
    public function testTheStored501IsActuallyPersisted(): void
    {
        $chain = self::fullyPermittedCosmosChain();
        self::markCwUnsupported((int) $chain->id);

        $checkpoint = ChainCheckpointRepository::get((int) $chain->id);
        self::assertNotNull($checkpoint);
        self::assertSame(
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
            (string) $checkpoint->cw_discovery_state
        );
    }

    /**
     * A STALE `cw_*` VALUE ON A NON-COSMOS ROW CHANGES NOTHING.
     *
     * `cw_discovery_state` lives on `wp_bcc_chain_checkpoints`, a row shared
     * with the EVM indexer's own `state` column, so a non-Cosmos chain can
     * carry a `cw_*` value that means nothing there. While the capability
     * model read that column, a Cosmos-scoping guard existed to stop it
     * answering "this chain has no wasm module" about Ethereum — true,
     * irrelevant, and it would have MASKED the real reason.
     *
     * S5 removed the read, so the guard is no longer load-bearing. The
     * OUTCOME it protected is asserted here, and the stale value is still
     * written so a reintroduced read would fail this case.
     *
     * @param string $slug
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonCosmosChains')]
    public function testNonCosmosChainIsUnaffectedByAStaleCosmWasmState(string $slug): void
    {
        $chain  = self::fullyPermittedChain($slug);
        $before = NftChainCapability::operationMatrix($chain)['operations'];

        self::markCwUnsupported((int) $chain->id);

        ChainRepository::clearCache();
        $reprojected = ChainRepository::getBySlug($slug);
        self::assertNotNull($reprojected);
        $after = NftChainCapability::operationMatrix($reprojected)['operations'];

        // ⚠ S7: the original asserted `NO_ENUMERATION_DRIVER` — that no driver
        // could enumerate this family — and the operation is gone.
        //
        // My first replacement asserted `OP_NO_DRIVER` for `curated_feed`,
        // claiming no driver serves a curated feed on EVM or Solana. That is
        // FALSE for Solana: `magiceden` declares curated_feed there, and CI
        // caught it. There is in fact NO single operation that both families
        // structurally lack, so pinning one named refusal cannot work here.
        //
        // The property this case actually exists for is family-agnostic: a
        // stale `cw_discovery_state` on a non-Cosmos row must not influence
        // the answer AT ALL. Asserted as an equality over every operation,
        // which is both true for both families and stronger than any single
        // named status — it catches a leak into any row rather than one.
        $compared = 0;
        foreach ($before as $operation => $row) {
            self::assertSame(
                $row['status'],
                $after[$operation]['status'],
                $slug . '/' . $operation . ' changed because of an irrelevant CosmWasm measurement'
            );
            self::assertSame(
                $row['reason'],
                $after[$operation]['reason'],
                $slug . '/' . $operation . ' changed its stated reason'
            );
            $compared++;
        }

        self::assertSame(5, $compared, 'five operations must have been compared');

        // Anti-vacuity: the stale value really was written, so the equality
        // above is not comparing two identical no-ops.
        $checkpoint = ChainCheckpointRepository::get((int) $chain->id);
        self::assertNotNull($checkpoint);
        self::assertSame(
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
            (string) $checkpoint->cw_discovery_state
        );
    }

    /** @return array<string, array{0: string}> */
    public static function nonCosmosChains(): array
    {
        return ['evm' => ['ethereum'], 'solana' => ['solana']];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** A chain row with both capability columns set to 1, freshly projected. */
    private static function fullyPermittedChain(string $slug): object
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . ChainRepository::table() . '`
                SET ' . self::SUPPORT_COLUMN . ' = 1, ' . self::MANUAL_COLUMN . ' = 1
              WHERE slug = %s',
            $slug
        ));
        ChainRepository::clearCache();

        $chain = ChainRepository::getBySlug($slug);
        self::assertNotNull($chain, "chain {$slug} must exist");

        return $chain;
    }

    private static function fullyPermittedCosmosChain(): object
    {
        return self::fullyPermittedChain('cosmos');
    }

    /** Record the measured HTTP 501 on a chain's checkpoint row. */
    private static function markCwUnsupported(int $chainId): void
    {
        ChainCheckpointRepository::ensureExists($chainId);
        ChainCheckpointRepository::setCwDiscoveryState(
            $chainId,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );
    }

    /**
     * Nothing else about a chain changes because these columns exist.
     *
     * Wallet linking, holdings, validators and Halls all resolve chains
     * through the general accessors, and none of them may start behaving
     * differently.
     */
    public function testGeneralChainAccessorsAreUnaffected(): void
    {
        $active = ChainRepository::getActive();
        self::assertGreaterThan(0, count($active));

        $cosmos = ChainRepository::getBySlug('cosmos');
        self::assertNotNull($cosmos);
        self::assertSame('cosmos', $cosmos->chain_type);
        self::assertNotNull(ChainRepository::resolveId('ethereum'));
        self::assertNotNull(ChainRepository::getById((int) $cosmos->id));
    }
}
