<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `wp_bcc_chains.cosmwasm_nft_discovery_enabled` against a REAL MySQL.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * The unit suite fakes ChainRepository at its production FQN, so it can
 * prove what the WORKER does with a projection but not what the DATABASE
 * and the real repository actually produce. Three of this feature's
 * claims are only checkable here:
 *
 *   1. THE MIGRATION ENABLES NOTHING. A `TINYINT(1) NOT NULL DEFAULT 0`
 *      with no backfill statement means every pre-existing row lands at 0
 *      — but "no backfill statement" is a claim about SQL, and SQL is
 *      what this file runs.
 *   2. THE COLUMN IS IN THE CACHED PROJECTION. The worker's eligibility
 *      read treats an ABSENT column as ineligible, which is the right
 *      fail-closed answer and also completely silent. If someone dropped
 *      the column from ChainRepository::COLUMNS, discovery would stop
 *      everywhere and every test that fakes the repository would still
 *      pass.
 *   3. THE TOGGLE INVALIDATES THE CACHE. getActive() is served from a
 *      5-minute object-cache/transient pair. A write that skipped
 *      invalidation would leave a just-DISABLED chain being scanned for
 *      the rest of the TTL — and the admin screen would show the new
 *      value the whole time, so the operator would have no way to tell.
 *
 * It also pins the other half of the bargain: turning discovery off for a
 * chain must not disturb anything else that chain is used for. Wallet
 * linking, holdings, validators and Halls all resolve chains through the
 * general accessors, and none of them may start missing a chain because
 * the scanner was told to leave it alone.
 */
#[Group('integration')]
#[CoversClass(ChainRepository::class)]
final class ChainCosmwasmDiscoveryFlagIntegrationTest extends TestCase
{
    private const COLUMN = 'cosmwasm_nft_discovery_enabled';

    /**
     * A RETAINED projected flag, used where a case needs to observe the
     * cached projection rather than the retired column.
     *
     * ⚠ S9a removed `cosmwasm_nft_discovery_enabled` from
     * `ChainRepository::COLUMNS`, so it is no longer observable through the
     * projection at all. The cache-invalidation property that case (3) pins
     * is about `ChainRepository::clearCache()` and is entirely unaffected by
     * which column it watches — it just needs one that is actually projected.
     * `bcc_supports_nft_collections` is retained, is a tinyint flag
     * defaulting to 0, and stays in COLUMNS by explicit design note.
     */
    private const OBSERVABLE_COLUMN = 'bcc_supports_nft_collections';

    /**
     * The registry exactly as the REAL installer left it, captured before
     * any test in this class normalises the column.
     *
     * setUp() resets the flag so each test starts from a known state,
     * which would also mask an installer that opted a chain in — so the
     * as-installed reading has to be taken before setUp ever runs. The
     * bootstrap builds the whole schema from scratch against a throwaway
     * database on every run, so this is a genuine fresh-install
     * observation, not a leftover.
     *
     * @var array{total: int, enabled: int, cosmos_enabled: list<string>}|null
     */
    private static ?array $asInstalled = null;

    public static function setUpBeforeClass(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        self::$asInstalled = [
            'total'   => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`'),
            'enabled' => (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::COLUMN . ' = 1'
            ),
            'cosmos_enabled' => array_values(array_map('strval', $wpdb->get_col(
                'SELECT slug FROM `' . $table . '`
                  WHERE chain_type = "cosmos" AND ' . self::COLUMN . ' = 1'
            ))),
        ];
    }

    protected function setUp(): void
    {
        // Every test starts from a cold cache and the shipped column state.
        ChainRepository::clearCache();
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query(
            'UPDATE `' . ChainRepository::table() . '` SET ' . self::COLUMN . ' = 0'
        );
        // ⚠ S9a ALSO RESETS THE OBSERVABLE FLAG. Case (3) watches
        // `bcc_supports_nft_collections` through the cached projection now,
        // and this suite shares `wp_bcc_chains` with tests that deliberately
        // permit a chain. Without this, that case's "precondition: disabled"
        // would depend on execution order — which is exactly the kind of
        // flake that gets a real failure dismissed later.
        $wpdb->query(
            'UPDATE `' . ChainRepository::table() . '` SET ' . self::OBSERVABLE_COLUMN . ' = 0'
        );
        ChainRepository::clearCache();
    }

    /** @return array<string, mixed>|null the INFORMATION_SCHEMA row */
    private function columnDefinition(): ?array
    {
        $wpdb = $GLOBALS['wpdb'];

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = %s
                AND COLUMN_NAME  = %s
              LIMIT 1',
            ChainRepository::table(),
            self::COLUMN
        ));

        return $row === null ? null : (array) $row;
    }

    private function firstChainId(): int
    {
        $wpdb = $GLOBALS['wpdb'];

        return (int) $wpdb->get_var(
            'SELECT id FROM `' . ChainRepository::table() . '` ORDER BY id ASC LIMIT 1'
        );
    }

    // ── (1) the migration enables nothing ───────────────────────────────

    public function testTheColumnIsANotNullTinyintDefaultingToZero(): void
    {
        $definition = $this->columnDefinition();

        self::assertNotNull($definition, 'the installer must create the column');
        self::assertSame('tinyint(1)', strtolower((string) $definition['COLUMN_TYPE']));
        self::assertSame('NO', (string) $definition['IS_NULLABLE']);
        self::assertSame('0', (string) $definition['COLUMN_DEFAULT']);
    }

    public function testEveryInstalledChainShipsDisabled(): void
    {
        $installed = self::$asInstalled;
        self::assertNotNull($installed);

        // A count of zero enabled chains is also what an EMPTY table gives,
        // so the population is asserted first — otherwise this test would
        // keep passing if the seed loop stopped inserting anything.
        self::assertGreaterThan(0, $installed['total'], 'the seed must have produced chains to check');
        self::assertSame(0, $installed['enabled'], 'installing must not opt any chain in to discovery');

        // Named, not merely counted: the cosmos chains are the ones this
        // flag governs, and they are the population a regression here would
        // silently start scanning.
        self::assertSame([], $installed['cosmos_enabled']);
    }

    public function testTheMigrationIsIdempotentAndNeverFlipsAnything(): void
    {
        $wpdb    = $GLOBALS['wpdb'];
        $chainId = $this->firstChainId();

        // An operator had deliberately enabled one chain.
        //
        // ⚠ PLANTED WITH A DIRECT WRITE, NOT A SETTER (S8). This used to call
        // `ChainRepository::setCosmwasmNftDiscoveryEnabled()`, which S8
        // deleted along with the scanner that was its only caller. The
        // guarantee under test is NOT the setter — it is that re-running the
        // ALTER never flips a value it finds, which still matters for every
        // install carrying a non-default row until S9 drops the column.
        // Planting the row directly is what keeps that testable.
        $planted = $wpdb->update(
            ChainRepository::table(),
            [self::COLUMN => 1],
            ['id' => $chainId],
            ['%d'],
            ['%d']
        );
        self::assertSame(1, $planted, 'precondition: the non-default value must actually be planted');

        bcc_onchain_add_chains_cosmwasm_discovery_column();
        bcc_onchain_add_chains_cosmwasm_discovery_column();

        self::assertSame('', (string) $wpdb->last_error, 're-running the ALTER must not error');

        $enabledIds = array_map('intval', $wpdb->get_col(
            'SELECT id FROM `' . ChainRepository::table() . '` WHERE ' . self::COLUMN . ' = 1'
        ));
        self::assertSame([$chainId], $enabledIds, 'a re-run must neither enable nor disable anything');
    }

    public function testTheMigrationIsWiredIntoTheSchemaInstaller(): void
    {
        // A migration nobody calls fails SILENTLY AND PERMANENTLY here:
        // existing installs would never gain the column, the worker would
        // read every chain as ineligible (correctly — an absent column is
        // "no", by design), and the symptom would be "the scanner does
        // nothing", which is also exactly what a correctly-configured
        // fresh install looks like.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');

        $start = strpos($source, 'function bcc_onchain_ensure_schema()');
        self::assertNotFalse($start, 'the schema installer entry point must exist');

        $body = substr($source, $start, 4000);
        self::assertStringContainsString(
            'bcc_onchain_add_chains_cosmwasm_discovery_column();',
            $body,
            'the ALTER must run from the same installer the other chain migrations run from'
        );
    }

    public function testMigratingAPreExistingInstallLandsEveryRowAtZero(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        // Reproduce the pre-migration shape: the column does not exist.
        $wpdb->query('ALTER TABLE `' . $table . '` DROP COLUMN ' . self::COLUMN);
        self::assertNull($this->columnDefinition(), 'precondition: the column is gone');

        $rowsBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`');

        bcc_onchain_add_chains_cosmwasm_discovery_column();

        self::assertNotNull($this->columnDefinition(), 'the migration must add the column back');
        self::assertSame(
            $rowsBefore,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`'),
            'the migration must not add or remove chains'
        );
        self::assertSame(
            0,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . self::COLUMN . ' = 1'),
            'an existing install must land with discovery enabled on ZERO chains'
        );
    }

    // ── (2) the column is NOT in the cached projection (S9a) ────────────

    /**
     * ⚠⚠ THIS CASE WAS INVERTED IN S9a, AND THE OLD FORM WAS A DELIBERATE
     * PIN AGAINST EXACTLY THIS CHANGE — so the inversion needs its reason
     * on the record.
     *
     * It asserted that every projected chain row CARRIED
     * `cosmwasm_nft_discovery_enabled`, with the message: "the worker reads
     * an absent column as INELIGIBLE, so dropping it from
     * ChainRepository::COLUMNS would silently stop all discovery".
     *
     * That reasoning was correct and is now void: **there is no worker.** S8
     * deleted `CosmwasmDiscoveryWorker`, and a comment-stripped sweep over
     * `app/` and `includes/` finds the column named in exactly one
     * executable place — `schema-chains.php`, the installer that ADDS it,
     * which S9a retains on purpose. Nothing reads it.
     *
     * So the property worth pinning is the opposite one, and it is what makes
     * S9b's drop safe: the projection must NOT name a column it no longer
     * needs, because a `SELECT` naming a dropped column fails the whole read
     * and `ChainRepository` caches an error sentinel.
     *
     * ⚠ The COLUMN itself is still on the table — case (1) above still
     * asserts the installer adds it. Only the projection stopped asking.
     */
    public function testTheCachedProjectionNoLongerCarriesTheRetiredColumn(): void
    {
        $chains = ChainRepository::getActive();
        self::assertNotSame([], $chains, 'the seeded registry must produce active chains');

        foreach ($chains as $chain) {
            $vars = get_object_vars($chain);

            self::assertArrayNotHasKey(
                self::COLUMN,
                $vars,
                self::COLUMN . ' is back in the projection — S9b drops that column, and a '
                    . 'projection naming a dropped column fails silently behind the error sentinel'
            );

            // Anti-vacuity: the row must be a real projection, not an empty
            // object that would satisfy the assertion above for free.
            self::assertArrayHasKey('slug', $vars, 'the projected row must be populated');
            self::assertArrayHasKey(
                self::OBSERVABLE_COLUMN,
                $vars,
                'the RETAINED capability flag must still be projected'
            );
        }

        // The single-row fallback path (inactive chains / cache miss) uses
        // the same COLUMNS constant, and must agree.
        $byId = ChainRepository::getById($this->firstChainId());
        self::assertNotNull($byId);
        $vars = get_object_vars($byId);
        self::assertArrayNotHasKey(self::COLUMN, $vars);
        self::assertArrayHasKey(self::OBSERVABLE_COLUMN, $vars);

        // And the column really is still ON THE TABLE — S9a drops nothing.
        self::assertNotNull(
            $this->columnDefinition(),
            'S9a must not have dropped the column; that is S9b, behind a backup'
        );
    }

    // ── (3) the toggle invalidates the cache ────────────────────────────

    public function testAWriteThatSkipsInvalidationKeepsServingTheStaleAnswer(): void
    {
        $wpdb    = $GLOBALS['wpdb'];
        $chainId = $this->firstChainId();

        // Warm the cache with the shipped state.
        self::assertFalse($this->cachedFlag($chainId), 'precondition: disabled');

        // A write that goes straight to SQL — i.e. what a future admin
        // screen or wp-cli one-liner would do if it did not go through the
        // repository.
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . ChainRepository::table() . '` SET ' . self::OBSERVABLE_COLUMN . ' = 1 WHERE id = %d',
            $chainId
        ));

        // THE HAZARD IS REAL: the cached projection has not moved, so every
        // reader keeps acting on the old answer for the whole TTL.
        //
        // ⚠ The original note said "the scanner would keep acting on the old
        // answer". There is no scanner — S8 deleted it. The hazard survives
        // it unchanged, because the flag now watched here
        // (`bcc_supports_nft_collections`) gates whether a chain can take a
        // collection at all, and `NftChainCapability` reads it through this
        // same cached projection. A stale "permitted" answer is the one that
        // matters, exactly as a stale "scan me" answer used to be.
        self::assertFalse(
            $this->cachedFlag($chainId),
            'this assertion is what makes the invalidation below worth having'
        );

        ChainRepository::clearCache();
        self::assertTrue($this->cachedFlag($chainId));
    }






    /**
     * Every column of one chain row, keyed by column name.
     *
     * Explicit projection built from INFORMATION_SCHEMA rather than
     * `SELECT *`: §2 is about repositories, but an assertion that names
     * its columns is also the one that notices when the set of columns
     * changes. Names are filtered to `[A-Za-z0-9_]` before interpolation
     * — they come from the schema, not from input, and they still get
     * checked.
     *
     * @return array<string, string|null>
     */
    private function rowSnapshot(int $chainId): array
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $columns = [];
        foreach ($wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              ORDER BY ORDINAL_POSITION',
            $table
        )) as $column) {
            $name = (string) $column;
            if (preg_match('/^[A-Za-z0-9_]+$/', $name) === 1) {
                $columns[] = '`' . $name . '`';
            }
        }
        if ($columns === []) {
            return [];
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . implode(', ', $columns) . ' FROM `' . $table . '` WHERE id = %d LIMIT 1',
            $chainId
        ));
        if ($row === null) {
            return [];
        }

        $out = [];
        foreach (get_object_vars($row) as $key => $value) {
            $out[(string) $key] = $value === null ? null : (string) $value;
        }

        return $out;
    }

    /** The flag as the CACHED projection currently reports it. */
    private function cachedFlag(int $chainId): bool
    {
        foreach (ChainRepository::getActive() as $chain) {
            if ((int) $chain->id !== $chainId) {
                continue;
            }
            $vars = get_object_vars($chain);

            // ⚠ READS THE RETAINED FLAG SINCE S9a. This read `self::COLUMN`,
            // which the projection no longer carries, so it would silently
            // return 0 for every chain and the invalidation case below would
            // pass for the wrong reason in one direction and fail in the
            // other. The property under test is `clearCache()`, not which
            // flag is watched.
            return (int) ($vars[self::OBSERVABLE_COLUMN] ?? 0) === 1;
        }

        self::fail("chain {$chainId} is not in the active projection");
    }

    // ── (4) disabling discovery disturbs nothing else ───────────────────



}
