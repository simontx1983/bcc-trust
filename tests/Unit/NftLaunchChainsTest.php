<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\NftLaunchChains;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DECISION 7, enforced in code rather than assumed.
 *
 * ── WHY AN ALLOWLIST AND NOT A CAPABILITY FLAG ──────────────────────────
 * The EVM validator works on any EVM RPC, and `NftDriverRegistry` offers the
 * EVM drivers to every EVM chain. Without a closed list, enabling
 * `bcc_supports_nft_collections` on Polygon would silently put a chain nobody
 * approved into the intake path — and on a chain with no Alchemy key it would
 * fail at metadata time, after the provider requests had already been made.
 *
 * The approved launch scope is **Ethereum and Base, both Alchemy-keyed**. This
 * list is that decision, in one place, checked BEFORE any transport.
 *
 * ⚠ It keys on `chain_id_hex` — the EIP-155 chain id — because that is the
 * chain's identity. A slug is operator-editable text: renaming a row must not
 * move it in or out of the launch scope.
 */
#[CoversClass(NftLaunchChains::class)]
final class NftLaunchChainsTest extends TestCase
{
    private static function chain(string $family, ?string $hex, string $slug = 'x'): object
    {
        return (object) [
            'id'           => 1,
            'slug'         => $slug,
            'chain_type'   => $family,
            'chain_id_hex' => $hex,
        ];
    }

    // ── The two launch chains ───────────────────────────────────────────

    public function testEthereumMainnetIsALaunchChain(): void
    {
        self::assertTrue(NftLaunchChains::isLaunchChain(self::chain('evm', '0x1', 'ethereum')));
    }

    public function testBaseIsALaunchChain(): void
    {
        self::assertTrue(NftLaunchChains::isLaunchChain(self::chain('evm', '0x2105', 'base')));
    }

    public function testTheHexComparisonIsCaseAndPaddingInsensitive(): void
    {
        // `0x2105`, `0X2105` and `0x00002105` are the same chain.
        self::assertTrue(NftLaunchChains::isLaunchChain(self::chain('evm', '0X2105')));
        self::assertTrue(NftLaunchChains::isLaunchChain(self::chain('evm', '0x00002105')));
        self::assertTrue(NftLaunchChains::isLaunchChain(self::chain('evm', '0x0001')));
    }

    // ── ⚠⚠ Everything else is out ───────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function nonLaunchEvmChains(): array
    {
        return [
            'polygon'   => ['0x89'],
            'arbitrum'  => ['0xa4b1'],
            'optimism'  => ['0xa'],
            'bsc'       => ['0x38'],
            'avalanche' => ['0xa86a'],
        ];
    }

    #[DataProvider('nonLaunchEvmChains')]
    public function testOtherEvmChainsAreNotLaunchChains(string $hex): void
    {
        self::assertFalse(NftLaunchChains::isLaunchChain(self::chain('evm', $hex)));
    }

    public function testAnEvmChainWithNoChainIdHexIsNotALaunchChain(): void
    {
        // Fail closed: an unidentifiable chain is not on the list.
        self::assertFalse(NftLaunchChains::isLaunchChain(self::chain('evm', null)));
        self::assertFalse(NftLaunchChains::isLaunchChain(self::chain('evm', '')));
        self::assertFalse(NftLaunchChains::isLaunchChain(self::chain('evm', 'not-hex')));
    }

    public function testTheSlugCannotPromoteAChainIntoLaunchScope(): void
    {
        // Polygon's chain id, renamed "ethereum". Identity wins over text.
        self::assertFalse(NftLaunchChains::isLaunchChain(self::chain('evm', '0x89', 'ethereum')));
    }

    public function testTheListIsClosedAndContainsExactlyTwoChains(): void
    {
        self::assertSame(['0x1', '0x2105'], NftLaunchChains::launchChainIds());
    }

    // ── Non-EVM families are not governed by this list ──────────────────

    public function testCosmosAndSolanaAreNotJudgedByTheEvmLaunchList(): void
    {
        // The list answers a question about EVM scope only. Cosmos and Solana
        // have their own validators and their own gates; asking this about
        // them would be a category error, so it answers false and the caller
        // must not consult it for those families.
        self::assertFalse(NftLaunchChains::isLaunchChain(self::chain('cosmos', null)));
        self::assertFalse(NftLaunchChains::isLaunchChain(self::chain('solana', null)));
    }
}
