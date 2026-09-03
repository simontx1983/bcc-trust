<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use BCC\Trust\Onchain\Support\DiscoveryReadiness;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Readiness against a REAL `wp_bcc_chains`, on real MySQL.
 *
 * ── WHAT ONLY A REAL DATABASE CAN SHOW ──────────────────────────────────
 * The unit tests drive `evaluate()` with PHP booleans. Here the product
 * switch arrives as MySQL does actually hand it back — a TINYINT read as
 * the STRING '0' or '1' — through the real repository, its real object
 * cache, and the real projection. `'0'` is truthy in PHP, so a support
 * check written as `(bool) $chain->bcc_supports_nft_collections` would
 * pass every unit test and enable every chain in production. That is the
 * class of bug this file exists to catch.
 */
#[CoversClass(DiscoveryReadiness::class)]
#[Group('integration')]
final class DiscoveryReadinessIntegrationTest extends TestCase
{
    private const SUPPORT = 'bcc_supports_nft_collections';
    private const OPT_IN  = 'cosmwasm_nft_discovery_enabled';

    protected function setUp(): void
    {
        ChainRepository::clearCache();
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];

        // A known cold state: nothing supported, nothing opted in.
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query(
            'UPDATE `' . ChainRepository::table() . '` SET '
            . self::SUPPORT . ' = 0, ' . self::OPT_IN . ' = 0'
        );
        ChainRepository::clearCache();
    }

    /** A real cosmos chain id from the shipped registry. */
    private function aCosmosChainId(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $id   = (int) $wpdb->get_var(
            'SELECT id FROM `' . ChainRepository::table() . '`
              WHERE chain_type = "cosmos" AND is_active = 1
              ORDER BY id ASC LIMIT 1'
        );

        self::assertGreaterThan(0, $id, 'the registry must ship an active cosmos chain');

        return $id;
    }

    private function setFlags(int $chainId, int $support, int $optIn): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . ChainRepository::table() . '` SET '
            . self::SUPPORT . ' = %d, ' . self::OPT_IN . ' = %d WHERE id = %d',
            $support,
            $optIn,
            $chainId
        ));
        ChainRepository::clearCache();
    }

    private function chainRow(int $chainId): object
    {
        $row = ChainRepository::getById($chainId);
        self::assertNotNull($row, 'the chain must resolve through the real repository');

        return $row;
    }

    // ── the string-'0' trap ─────────────────────────────────────────────

    /**
     * ⚠ THE READING IS ASSERTED, NOT ASSUMED.
     *
     * If MySQL/the projection ever hands back something a truthiness check
     * would misread, this test says so directly rather than leaving the
     * consequence to be discovered downstream.
     */
    public function testTheSupportColumnArrivesAsAStringAndIsReadStrictly(): void
    {
        $chainId = $this->aCosmosChainId();
        $this->setFlags($chainId, 0, 1);

        $row = $this->chainRow($chainId);
        $raw = $row->{self::SUPPORT};

        self::assertSame('0', (string) $raw, 'support off must read as "0"');

        // ⚠ THE TRAP, STATED AS AN ASSERTION RATHER THAN A COMMENT.
        // The string '0' is FALSY in PHP, but '0' arriving as an int-like
        // string is only half the story: the danger is a check written
        // against a column that reads '0' today and could read '00' or ' 0'
        // tomorrow. So the decision is asserted to refuse the REAL value,
        // not a value this test invented.
        self::assertFalse(DiscoveryReadiness::isNftDiscoverySurface($row));

        // And the positive control on the same row, so the assertion above
        // is not passing because the method refuses everything.
        $this->setFlags($chainId, 1, 1);
        self::assertTrue(DiscoveryReadiness::isNftDiscoverySurface($this->chainRow($chainId)));
    }

    // ── support gates the surface, on real rows ─────────────────────────

    public function testAnUnsupportedChainIsNotAScanSurface(): void
    {
        $chainId = $this->aCosmosChainId();
        $this->setFlags($chainId, 0, 1);

        self::assertFalse(DiscoveryReadiness::isNftDiscoverySurface($this->chainRow($chainId)));
    }

    public function testASupportedChainIsAScanSurface(): void
    {
        $chainId = $this->aCosmosChainId();
        $this->setFlags($chainId, 1, 1);

        self::assertTrue(DiscoveryReadiness::isNftDiscoverySurface($this->chainRow($chainId)));
    }

    /** Every EVM chain stays off the surface however its flags are set. */
    public function testNoEvmChainIsEverAScanSurface(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query(
            'UPDATE `' . ChainRepository::table() . '` SET ' . self::SUPPORT . ' = 1 WHERE chain_type = "evm"'
        );
        ChainRepository::clearCache();

        $evm = array_filter(
            ChainRepository::getActive(),
            static fn(object $c): bool => (string) $c->chain_type === 'evm'
        );

        self::assertNotEmpty($evm, 'the registry must ship EVM chains for this to mean anything');

        foreach ($evm as $chain) {
            self::assertFalse(
                DiscoveryReadiness::isNftDiscoverySurface($chain),
                'EVM chain ' . $chain->slug . ' must never be an NFT scan surface'
            );
        }
    }

    // ── the full decision over real rows ────────────────────────────────

    /**
     * Support off refuses with its own code, and refuses FIRST — the
     * opt-in is on and the verdict is eligible, so any other order would
     * report a different reason.
     */
    public function testSupportOffRefusesBeforeTheOptInIsConsulted(): void
    {
        $chainId = $this->aCosmosChainId();
        $this->setFlags($chainId, 0, 1);

        $reason = DiscoveryReadiness::forSummaryRow(
            $this->chainRow($chainId),
            ['chain_id' => $chainId, 'eligibility' => CosmwasmScanEligibility::ELIGIBLE, 'backfill_completed_at' => null]
        );

        self::assertSame(DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED, $reason['reason']);
        self::assertFalse($reason['eligible']);
    }

    /**
     * ⚠ AND AN ALLOWLIST CANNOT RESCUE IT.
     *
     * The canary allowlist reaches the decision as part of the per-chain
     * VERDICT, and support is asked before the verdict is even read. So a
     * chain named by the allowlist, opted in, and eligible is still
     * refused for lack of product support — proving "an allowlist can only
     * narrow" holds against real data, not just in the pure test.
     */
    public function testAnAllowlistedEligibleChainIsStillRefusedWithoutSupport(): void
    {
        $chainId = $this->aCosmosChainId();
        $this->setFlags($chainId, 0, 1);

        $reason = DiscoveryReadiness::forSummaryRow(
            $this->chainRow($chainId),
            [
                'chain_id'              => $chainId,
                'eligibility'           => CosmwasmScanEligibility::ELIGIBLE,
                'backfill_completed_at' => '2026-01-01 00:00:00',
            ]
        );

        self::assertSame(DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED, $reason['reason']);
    }

    /** With support on, the per-chain verdict is what decides. */
    public function testWithSupportOnTheVerdictDecides(): void
    {
        $chainId = $this->aCosmosChainId();
        $this->setFlags($chainId, 1, 0);

        $reason = DiscoveryReadiness::forSummaryRow(
            $this->chainRow($chainId),
            [
                'chain_id'              => $chainId,
                'eligibility'           => CosmwasmScanEligibility::NOT_OPTED_IN,
                // Completed, so INCREMENTAL — which the environment
                // switches deliberately do not gate.
                'backfill_completed_at' => '2026-01-01 00:00:00',
            ]
        );

        self::assertSame(CosmwasmScanEligibility::NOT_OPTED_IN, $reason['reason']);
    }

    // ── the shipped registry opts nothing in ────────────────────────────

    /**
     * A FRESH INSTALL SUPPORTS NO CHAIN.
     *
     * `bcc_supports_nft_collections` ships DEFAULT 0 and the installer must
     * not set it. Enabling a chain is an administrator's deliberate act, so
     * an install that arrived supporting something would be enabling NFT
     * discovery nobody asked for.
     */
    public function testAFreshInstallSupportsNoChainByDefault(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $default = (string) $wpdb->get_var(
            'SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = "' . ChainRepository::table() . '"
                AND COLUMN_NAME = "' . self::SUPPORT . '"'
        );

        self::assertSame('0', $default, 'the product switch must ship off');
    }
}
