<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Services\UserViewService;
use BCC\Trust\Onchain\Services\NftPieceViewModelBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression net for the 2026-07-23 wallet-privacy remediation.
 *
 * BINDING RULE (docs/wallet-privacy-policy.md): a user's wallet address is
 * never disclosed to any other user — full, shortened, hashed, ENS-named, or
 * otherwise represented — and no member↔wallet or member↔holding join may be
 * reconstructable from a client-accessible surface.
 *
 * These tests exist because the previous defence was a DENYLIST that stripped
 * exactly one key (`address`) while `address_short` shipped to every viewer,
 * including anonymous, for the life of the endpoint — silently, because the
 * violation logger keyed on the same single name. So the assertions here are
 * deliberately written as ALLOWLISTS: "the output contains nothing but these
 * keys", not "the output lacks these keys". A denylist test can only fail on
 * the leak someone already imagined.
 *
 * Anonymous and authenticated-stranger are covered as distinct cases: they
 * differ only in `$viewerId` (0 vs another user's id), and both must land on
 * the empty branch.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletPrivacyEgressTest extends TestCase
{
    private const OWNER_ID    = 42;
    private const STRANGER_ID = 99;
    private const ANON_ID     = 0;

    /**
     * Synthetic, unmistakably-not-a-real-wallet fixture — asserted absent,
     * in whole and at both edges. The non-hex letters (incl. the leading
     * `0x…` and the `ZZ` suffix) guarantee the edge substrings cannot
     * appear by chance in a hex fingerprint. Never a real address.
     */
    private const ADDRESS = '0xEXAMPLEonlyNOTArealWALLETfixtureonlyZZ01';

    /** Synthetic masked-form value, likewise not a real (shortened) address. */
    private const MASKED = 'MASKED-FIXTURE-NOT-A-REAL-ADDRESS';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/wallet-privacy-stubs.php';
        $GLOBALS['__bcc_wp_logs']            = [];
        $GLOBALS['__bcc_wp_wallet_reads']    = 0;
        $GLOBALS['__bcc_wp_wallet_rows']     = [];
        $GLOBALS['__bcc_wp_user_by_addr']    = [];
        $GLOBALS['__bcc_wp_verified_counts'] = [];
    }

    private static function invoke(string $class, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    /** One verified wallet row shaped like WalletRepository::getForUser returns. */
    private static function walletRow(): object
    {
        return (object) [
            'id'             => 15,
            'wallet_address' => self::ADDRESS,
            'chain_slug'     => 'ethereum',
            'chain_name'     => 'Ethereum',
            'is_primary'     => 1,
            'verified_at'    => '2026-06-08 13:08:08',
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // resolveWallets — the primary gate
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonSelfViewerProvider(): iterable
    {
        yield 'anonymous visitor'      => [self::ANON_ID];
        yield 'authenticated stranger' => [self::STRANGER_ID];
    }

    /**
     * The load-bearing assertion: a non-owner gets an EMPTY list, whatever
     * the database holds.
     */
    #[DataProvider('nonSelfViewerProvider')]
    public function testNonSelfViewersReceiveNoWalletRecords(int $viewerId): void
    {
        $GLOBALS['__bcc_wp_wallet_rows'] = [self::walletRow()];

        $isSelf = self::OWNER_ID > 0 && self::OWNER_ID === $viewerId;
        self::assertFalse($isSelf, 'fixture sanity: viewer must not be the owner');

        $out = self::invoke(UserViewService::class, 'resolveWallets', self::OWNER_ID, $isSelf);

        self::assertSame([], $out);
    }

    /**
     * Fail-closed means SHORT-CIRCUIT, not filter-after-read. If the row were
     * fetched and then emptied, the address would still have been marshalled
     * into memory and into any future log/cache/telemetry of the read path.
     */
    public function testNonSelfViewerNeverTriggersAWalletRead(): void
    {
        $GLOBALS['__bcc_wp_wallet_rows'] = [self::walletRow()];

        self::invoke(UserViewService::class, 'resolveWallets', self::OWNER_ID, false);

        self::assertSame(0, $GLOBALS['__bcc_wp_wallet_reads']);
    }

    /** A zero/unresolved profile id is uncertain identity → empty. */
    public function testUnresolvedUserIdYieldsEmptyEvenWhenFlaggedSelf(): void
    {
        $GLOBALS['__bcc_wp_wallet_rows'] = [self::walletRow()];

        self::assertSame([], self::invoke(UserViewService::class, 'resolveWallets', 0, true));
        self::assertSame(0, $GLOBALS['__bcc_wp_wallet_reads']);
    }

    // ──────────────────────────────────────────────────────────────────
    // enforceWalletPrivacyAtEgress — the fail-closed net behind the gate
    // ──────────────────────────────────────────────────────────────────

    /**
     * Simulates a future regression upstream: something repopulates `wallets`
     * for a non-self viewer. The net must empty it REGARDLESS of which keys
     * the entries carry — including a payload with no `address` key at all,
     * which the old denylist passed through untouched.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function leakedWalletShapeProvider(): iterable
    {
        yield 'full address (old denylist caught this)' => [
            ['id' => 15, 'address' => self::ADDRESS, 'chain_slug' => 'ethereum'],
        ];
        yield 'shortened address only (old denylist MISSED this)' => [
            ['id' => 15, 'address_short' => self::MASKED, 'chain_slug' => 'ethereum'],
        ];
        yield 'a field nobody has invented yet' => [
            ['id' => 15, 'address_hashed' => 'deadbeefdeadbeef', 'ens_name' => 'welder.eth'],
        ];
        yield 'link id alone (a stable cross-user join key)' => [
            ['id' => 15],
        ];
    }

    /**
     * @param array<string, mixed> $walletEntry
     */
    #[DataProvider('leakedWalletShapeProvider')]
    public function testEgressNetEmptiesAnyNonSelfWalletBlock(array $walletEntry): void
    {
        $payload = ['id' => self::OWNER_ID, 'wallets' => [$walletEntry]];

        $out = self::invoke(UserViewService::class, 'enforceWalletPrivacyAtEgress', $payload, false);

        self::assertIsArray($out);
        self::assertSame([], $out['wallets']);
    }

    /** The owner's own account data must survive untouched. */
    public function testEgressNetLeavesOwnAccountDataIntact(): void
    {
        $entry   = ['id' => 15, 'address' => self::ADDRESS, 'address_short' => self::MASKED];
        $payload = ['id' => self::OWNER_ID, 'wallets' => [$entry]];

        $out = self::invoke(UserViewService::class, 'enforceWalletPrivacyAtEgress', $payload, true);

        self::assertIsArray($out);
        self::assertSame([$entry], $out['wallets']);
    }

    /**
     * Reporting a privacy violation must not commit one. The old logger
     * truncated to first-6 + last-4 — which IS the forbidden shortened form —
     * and wrote it next to the user id.
     */
    public function testViolationLogNeverContainsTheAddressInAnyForm(): void
    {
        $payload = [
            'id'      => self::OWNER_ID,
            'wallets' => [['id' => 15, 'address' => self::ADDRESS, 'address_short' => self::MASKED]],
        ];

        self::invoke(UserViewService::class, 'enforceWalletPrivacyAtEgress', $payload, false);

        self::assertNotSame([], $GLOBALS['__bcc_wp_logs'], 'the violation must be loud, not silent');

        $serialised = strtolower(json_encode($GLOBALS['__bcc_wp_logs'], JSON_THROW_ON_ERROR));

        // Whole address, both edges (catches a truncation regression), and
        // the masked-form value — none may appear.
        self::assertStringNotContainsString(strtolower(self::ADDRESS), $serialised);
        self::assertStringNotContainsString(strtolower(substr(self::ADDRESS, 0, 6)), $serialised);
        self::assertStringNotContainsString(strtolower(substr(self::ADDRESS, -4)), $serialised);
        self::assertStringNotContainsString(strtolower(self::MASKED), $serialised);

        // It should still be actionable: key NAMES are fine, values are not.
        self::assertStringContainsString('address', $serialised, 'key names should survive for triage');
    }

    // ──────────────────────────────────────────────────────────────────
    // NFT piece — anonymous endpoint, previously an enumerable
    // wallet→member map
    // ──────────────────────────────────────────────────────────────────

    /**
     * Allowlist assertion: the owner block carries `is_linked` and NOTHING
     * else. Written as an exact key-set comparison so any re-added field
     * fails here rather than shipping.
     */
    public function testNftOwnerBlockExposesOnlyIsLinked(): void
    {
        $GLOBALS['__bcc_wp_user_by_addr'] = [strtolower(self::ADDRESS) => self::OWNER_ID];

        $owner = self::invoke(
            NftPieceViewModelBuilder::class,
            'buildDominantOwner',
            1,
            self::ADDRESS
        );

        self::assertIsArray($owner);
        self::assertSame(['is_linked'], array_keys($owner));
        self::assertTrue($owner['is_linked']);

        $serialised = strtolower(json_encode($owner, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(strtolower(self::ADDRESS), $serialised);
        self::assertStringNotContainsString(strtolower(substr(self::ADDRESS, 0, 6)), $serialised);
    }

    /** An unlinked holder must not be distinguishable beyond the boolean. */
    public function testNftOwnerBlockForUnlinkedWalletIsAlsoBare(): void
    {
        $GLOBALS['__bcc_wp_user_by_addr'] = [];

        $owner = self::invoke(
            NftPieceViewModelBuilder::class,
            'buildDominantOwner',
            1,
            self::ADDRESS
        );

        self::assertIsArray($owner);
        self::assertSame(['is_linked'], array_keys($owner));
        self::assertFalse($owner['is_linked']);
    }

    // ──────────────────────────────────────────────────────────────────
    // Validator cards — public, and bound to the claimant's wallet
    // ──────────────────────────────────────────────────────────────────

    /**
     * `operator_address` is matched against the claimant's VERIFIED WALLET
     * (ClaimService::matchValidatorWallet), so on a claimed page it bound an
     * on-chain address to a named member. Only the derived boolean may ship.
     */
    public function testValidatorChainsExposeVerifiedBooleanNotOperatorAddress(): void
    {
        $rows = [
            (object) ['chain_slug' => 'cosmos', 'chain_name' => 'Cosmos Hub', 'operator_address' => 'cosmosvaloper1abcdef'],
            (object) ['chain_slug' => 'osmosis', 'chain_name' => 'Osmosis', 'operator_address' => ''],
        ];

        $chains = self::invoke(CardViewService::class, 'resolveChains', 'validator', 123, $rows);

        self::assertIsArray($chains);
        self::assertCount(2, $chains);

        foreach ($chains as $chain) {
            self::assertSame(['slug', 'name', 'operator_verified'], array_keys($chain));
        }

        self::assertTrue($chains[0]['operator_verified']);
        self::assertFalse($chains[1]['operator_verified'], 'empty operator address → not verified');

        $serialised = strtolower(json_encode($chains, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('cosmosvaloper', $serialised);
    }
}
