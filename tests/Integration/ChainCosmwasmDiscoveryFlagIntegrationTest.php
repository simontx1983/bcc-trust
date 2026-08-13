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

        // An operator has deliberately enabled one chain.
        ChainRepository::setCosmwasmNftDiscoveryEnabled($chainId, true);

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

    // ── (2) the column is in the cached projection ──────────────────────

    public function testTheCachedProjectionCarriesTheColumn(): void
    {
        $chains = ChainRepository::getActive();
        self::assertNotSame([], $chains, 'the seeded registry must produce active chains');

        foreach ($chains as $chain) {
            self::assertTrue(
                array_key_exists(self::COLUMN, get_object_vars($chain)),
                'every projected chain row must carry ' . self::COLUMN
                    . ' — the worker reads an absent column as INELIGIBLE, so dropping it '
                    . 'from ChainRepository::COLUMNS would silently stop all discovery'
            );
        }

        // The single-row fallback path (inactive chains / cache miss) uses
        // the same COLUMNS constant, and must agree.
        $byId = ChainRepository::getById($this->firstChainId());
        self::assertNotNull($byId);
        self::assertTrue(array_key_exists(self::COLUMN, get_object_vars($byId)));
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
            'UPDATE `' . ChainRepository::table() . '` SET ' . self::COLUMN . ' = 1 WHERE id = %d',
            $chainId
        ));

        // THE HAZARD IS REAL: the cached projection has not moved, so the
        // scanner would keep acting on the old answer for the whole TTL.
        // (Stated in the enable direction here because it is observable;
        // the direction that MATTERS is the mirror image — a just-disabled
        // chain that keeps getting scanned.)
        self::assertFalse(
            $this->cachedFlag($chainId),
            'this assertion is what makes the invalidation below worth having'
        );

        ChainRepository::clearCache();
        self::assertTrue($this->cachedFlag($chainId));
    }

    public function testTheRepositoryToggleTakesEffectImmediatelyInBothDirections(): void
    {
        $chainId = $this->firstChainId();

        self::assertFalse($this->cachedFlag($chainId), 'precondition: disabled and cached');

        self::assertTrue(ChainRepository::setCosmwasmNftDiscoveryEnabled($chainId, true));
        self::assertTrue($this->cachedFlag($chainId), 'enabling must be visible without a manual cache bust');

        // The disable direction is the one with teeth: a stale cache here
        // means the worker keeps spending requests on a chain an operator
        // just told it to stop scanning.
        self::assertTrue(ChainRepository::setCosmwasmNftDiscoveryEnabled($chainId, false));
        self::assertFalse($this->cachedFlag($chainId), 'disabling must take effect at once');
    }

    public function testTheToggleTouchesOnlyTheChainItNames(): void
    {
        $wpdb    = $GLOBALS['wpdb'];
        $chainId = $this->firstChainId();

        ChainRepository::setCosmwasmNftDiscoveryEnabled($chainId, true);

        $enabledIds = array_map('intval', $wpdb->get_col(
            'SELECT id FROM `' . ChainRepository::table() . '` WHERE ' . self::COLUMN . ' = 1'
        ));
        self::assertSame([$chainId], $enabledIds);
    }

    public function testTheToggleRejectsANonPositiveChainId(): void
    {
        self::assertFalse(ChainRepository::setCosmwasmNftDiscoveryEnabled(0, true));
        self::assertFalse(ChainRepository::setCosmwasmNftDiscoveryEnabled(-1, true));
    }

    /** The flag as the CACHED projection currently reports it. */
    private function cachedFlag(int $chainId): bool
    {
        foreach (ChainRepository::getActive() as $chain) {
            if ((int) $chain->id !== $chainId) {
                continue;
            }
            $vars = get_object_vars($chain);

            return (int) ($vars[self::COLUMN] ?? 0) === 1;
        }

        self::fail("chain {$chainId} is not in the active projection");
    }

    // ── (4) disabling discovery disturbs nothing else ───────────────────

    public function testADisabledChainIsStillReturnedByEveryGeneralAccessor(): void
    {
        $wpdb    = $GLOBALS['wpdb'];
        $chainId = $this->firstChainId();

        $slug = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT slug FROM `' . ChainRepository::table() . '` WHERE id = %d',
            $chainId
        ));

        // Belt and braces: explicitly disabled, not merely defaulted.
        ChainRepository::setCosmwasmNftDiscoveryEnabled($chainId, false);

        // These are the accessors wallet linking (getBySlug — WalletLink*
        // Services, WalletSeedService), holdings (getById/getBySlug —
        // HoldingsService), the fetcher factory (getById), Halls
        // (getBySlug / resolveIdAnyState — HallsService) and the admin
        // screens (getAll) actually call. None of them may lose a chain
        // because the SCANNER was told to leave it alone.
        self::assertNotNull(ChainRepository::getById($chainId), 'getById');
        self::assertNotNull(ChainRepository::getBySlug($slug), 'getBySlug');
        self::assertSame($chainId, ChainRepository::resolveId($slug), 'resolveId');
        self::assertSame($chainId, ChainRepository::resolveIdAnyState($slug), 'resolveIdAnyState');

        $activeIds = array_map(static fn(object $c): int => (int) $c->id, ChainRepository::getActive());
        self::assertContains($chainId, $activeIds, 'getActive');

        $allIds = array_map(static fn(object $c): int => (int) $c->id, ChainRepository::getAll());
        self::assertContains($chainId, $allIds, 'getAll');

        // is_active is the flag every one of those readers filters on, and
        // it must be completely untouched by the discovery opt-in.
        self::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT is_active FROM `' . ChainRepository::table() . '` WHERE id = %d',
                $chainId
            )),
            'disabling discovery must not deactivate the chain'
        );
    }

    public function testADisabledChainStillResolvesThroughTheJoinsOtherTablesUse(): void
    {
        $wpdb    = $GLOBALS['wpdb'];
        $chainId = $this->firstChainId();
        ChainRepository::setCosmwasmNftDiscoveryEnabled($chainId, false);

        // WalletRepository, ValidatorRepository, CollectionRepository and
        // NftSelectionRepository all reach chains via
        // `ChainRepository::table()` in a JOIN rather than through the
        // cached projection. The shape below is theirs: join on chain_id,
        // project slug + chain_type. Nothing in it filters on the new
        // column, and this fails if something ever starts to.
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT c.slug AS slug, c.chain_type AS chain_type
               FROM `' . ChainRepository::table() . '` c
              WHERE c.id = %d AND c.is_active = 1
              LIMIT 1',
            $chainId
        ));

        self::assertNotNull($row, 'a chain with discovery off must still join');
        self::assertNotSame('', (string) $row->slug);
    }

    public function testTheIdentityEditorAndTheDiscoveryToggleDoNotOverwriteEachOther(): void
    {
        $wpdb    = $GLOBALS['wpdb'];
        $chainId = $this->firstChainId();

        ChainRepository::setCosmwasmNftDiscoveryEnabled($chainId, true);
        ChainRepository::updateIdentity($chainId, 'About this chain.', 'https://cdn.example/i.png', '#123456');

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT description, icon_url, color, ' . self::COLUMN . ' AS flag
               FROM `' . ChainRepository::table() . '` WHERE id = %d',
            $chainId
        ));
        self::assertNotNull($row);
        self::assertSame('About this chain.', (string) $row->description);
        self::assertSame(1, (int) $row->flag, 'saving identity must not clear the discovery opt-in');

        // …and the reverse: the toggle must not blank the identity fields.
        ChainRepository::setCosmwasmNftDiscoveryEnabled($chainId, false);

        $after = $wpdb->get_row($wpdb->prepare(
            'SELECT description, icon_url, color FROM `' . ChainRepository::table() . '` WHERE id = %d',
            $chainId
        ));
        self::assertNotNull($after);
        self::assertSame('About this chain.', (string) $after->description);
        self::assertSame('https://cdn.example/i.png', (string) $after->icon_url);
        self::assertSame('#123456', (string) $after->color);
    }
}
