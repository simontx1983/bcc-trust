<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\NftDriverRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The code-owned NFT driver registry.
 *
 * ── WHAT IS BEING PINNED ────────────────────────────────────────────────
 * Two properties, and they pull in opposite directions:
 *
 *   1. The registry PROVES a refusal. `driversFor(chain, ENUMERATION)`
 *      returning `[]` for EVM and Solana is what makes
 *      `NO_ENUMERATION_DRIVER` a computed fact rather than a comment. A test
 *      that only checked the happy path would pass against a registry that
 *      had quietly grown a fake EVM enumerator.
 *
 *   2. The DATABASE MAY NARROW BUT NEVER GRANT. Every override is
 *      intersected with the registry. The dangerous direction is a row with
 *      `enabled = 1` for a triple the code does not implement — so those are
 *      asserted to change NOTHING, from several angles: unknown driver,
 *      known driver on the wrong chain, known driver claiming an operation
 *      it does not perform.
 *
 * The failure mode these exist for is a future refactor that "simplifies"
 * the intersection into a union — which would look like a bug fix ("the
 * operator enabled it and it didn't work") and would silently turn
 * configuration into a capability claim.
 */
#[CoversClass(NftDriverRegistry::class)]
final class NftDriverRegistryTest extends TestCase
{
    /** A `ChainRow`-shaped projection, as ChainRepository would return. */
    private static function chain(string $type, string $slug, int $id = 1): object
    {
        return (object) [
            'id'         => (string) $id,
            'slug'       => $slug,
            'chain_type' => $type,
        ];
    }

    // ── The load-bearing negative ───────────────────────────────────────

    /**
     * ⚠⚠⚠ NOTHING IN THIS BUILD ENUMERATES A CHAIN, UNDER ANY SPELLING.
     *
     * Three cases stood here until S7: that no EVM chain had an enumeration
     * driver, that Solana had none, and that Cosmos was the only family that
     * did. They expressed the negative as an EMPTY DRIVER LIST on a surviving
     * `enumeration` operation — the registry proving the refusal rather than
     * asserting it.
     *
     * S7 removed the operation and its only driver, so the negative is now
     * expressed by the operation not existing at all. That is strictly
     * stronger: an empty list can be filled by adding a driver, whereas a
     * missing operation cannot be satisfied by any registry row, any override
     * row, or any credential.
     *
     * This replaces all three, and it checks the LITERALS as well as the
     * constants — the constants are gone, so a reintroduction would most
     * likely arrive as a hand-written string.
     */
    public function testNoOperationOrDriverEnumeratesAChain(): void
    {
        self::assertNotContains('enumeration', NftDriverRegistry::operations());
        self::assertCount(5, NftDriverRegistry::operations(), 'five operations remain');

        // No driver, on any chain family, declares it.
        foreach ([
            self::chain('cosmos', 'cosmos'),
            self::chain('cosmos', 'injective'),
            self::chain('evm', 'ethereum'),
            self::chain('solana', 'solana'),
        ] as $chain) {
            self::assertSame(
                [],
                NftDriverRegistry::driversFor($chain, 'enumeration', []),
                'an unknown operation must resolve to no drivers'
            );
        }

        // And the removed driver key is unknown even when a row names it.
        self::assertSame(
            [],
            NftDriverRegistry::driversFor(self::chain('cosmos', 'cosmos'), 'enumeration', [[
                'operation'  => 'enumeration',
                'driver_key' => 'cosmwasm_enumeration',
                'enabled'    => true,
                'priority'   => 1,
            ]]),
            'a database row must not be able to resurrect a removed operation'
        );
    }

    // ── Chain targeting ─────────────────────────────────────────────────

    public function testTalisIsInjectiveOnlyAndNoCosmosChainHasWalletDiscovery(): void
    {
        $injective = self::chain('cosmos', 'injective');
        $hub       = self::chain('cosmos', 'cosmos');

        self::assertSame(
            [NftDriverRegistry::DRIVER_TALIS_WHITELIST],
            NftDriverRegistry::driversFor($injective, NftDriverRegistry::OP_CURATED_FEED, [])
        );
        self::assertSame(
            [],
            NftDriverRegistry::driversFor($hub, NftDriverRegistry::OP_CURATED_FEED, []),
            'the Cosmos Hub has no curated feed'
        );

        // ⚠ PR 7.12: the Hub's ONLY wallet-discovery driver was the
        // Stargaze marketplace indexer, which answered "which collections
        // does this wallet hold" by sending the member's address to an
        // undocumented third party. It is gone, and nothing replaced it —
        // wasmd has no owner→contracts index, so the honest answer is that
        // NO Cosmos chain can enumerate a wallet's collections.
        self::assertSame(
            [],
            NftDriverRegistry::driversFor($hub, NftDriverRegistry::OP_WALLET_DISCOVERY, []),
            'the Cosmos Hub must have no wallet-discovery driver'
        );
        self::assertSame(
            [],
            NftDriverRegistry::driversFor($injective, NftDriverRegistry::OP_WALLET_DISCOVERY, []),
            'Injective has no per-wallet owner index'
        );
    }

    /** The removed driver key must not come back under any spelling. */
    public function testNoDriverKeyNamesTheRemovedMarketplace(): void
    {
        $keys = NftDriverRegistry::driverKeys();
        self::assertNotSame([], $keys, 'denominator: the registry must be populated');

        foreach ($keys as $key) {
            self::assertStringNotContainsString('stargaze', strtolower((string) $key));
            self::assertStringNotContainsString('marketplace', strtolower((string) $key));
        }
    }

    /**
     * `evm_rpc` carries OWNERSHIP and NOT validation.
     *
     * The architecture plan's target-state table shows it doing both, but
     * the `supportsInterface` eth_call behind EVM validation is not built on
     * this branch. Registering an operation whose code does not exist is the
     * one thing this registry may never do — a caller would resolve a driver
     * key that executes nothing. Whoever builds it registers it then.
     */
    public function testEvmRpcProvidesOwnershipButNotValidationYet(): void
    {
        $chain = self::chain('evm', 'avalanche');

        self::assertContains(
            NftDriverRegistry::DRIVER_EVM_RPC,
            NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_OWNERSHIP, [])
        );
        // ⚠ PREMISE CHANGED BY PR E. This used to assert an EMPTY validation
        // driver list, with the note "EVM validation is not implemented on
        // this branch and must not be claimed". PR E implements it:
        // `EvmContractProbe` proves the token standard with ERC-165
        // `supportsInterface` over `eth_call`, which any EVM RPC answers — so
        // `evm_rpc` now legitimately claims OP_VALIDATION, and the registry
        // would be under-claiming if it did not.
        self::assertContains(
            NftDriverRegistry::DRIVER_EVM_RPC,
            NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_VALIDATION, []),
            'PR E builds EVM validation on supportsInterface, so the registry must claim it'
        );

        // ⚠ Still NOT claimed: metadata. Name, symbol, image and supply come
        // from Alchemy (DECISION 7), so a keyless EVM chain can prove the
        // standard and still not complete an intake. Claiming metadata here
        // would tell an operator a keyless chain is fully supported.
        self::assertNotContains(
            NftDriverRegistry::DRIVER_EVM_RPC,
            NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_METADATA, []),
            'evm_rpc proves the standard; it does not supply metadata'
        );
    }

    public function testOwnershipOnEvmIsOrderedAlchemyThenPlainRpc(): void
    {
        self::assertSame(
            [NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS, NftDriverRegistry::DRIVER_EVM_RPC],
            NftDriverRegistry::driversFor(self::chain('evm', 'ethereum'), NftDriverRegistry::OP_OWNERSHIP, [])
        );
    }

    // ── The database can narrow ─────────────────────────────────────────

    public function testAnOverrideCanDisableARegistryDefault(): void
    {
        $chain = self::chain('cosmos', 'cosmos');

        self::assertSame(
            [],
            NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_VALIDATION, [[
                'operation'  => NftDriverRegistry::OP_VALIDATION,
                'driver_key' => NftDriverRegistry::DRIVER_CW721_LCD,
                'enabled'    => false,
                'priority'   => 10,
            ]])
        );
    }

    public function testAnOverrideCanReorderButNotAdd(): void
    {
        $chain = self::chain('evm', 'ethereum');

        self::assertSame(
            [NftDriverRegistry::DRIVER_EVM_RPC, NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS],
            NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_OWNERSHIP, [[
                'operation'  => NftDriverRegistry::OP_OWNERSHIP,
                'driver_key' => NftDriverRegistry::DRIVER_EVM_RPC,
                'enabled'    => true,
                'priority'   => 1,
            ]])
        );
    }

    // ── The database can NEVER grant ────────────────────────────────────

    /**
     * THE CENTRAL INVARIANT, from three angles. Each row below is `enabled`
     * and would grant a capability if the intersection were ever loosened
     * into a union.
     *
     * @param array{operation: string, driver_key: string, enabled: bool, priority: int} $row
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenGrants')]
    public function testDatabaseCannotEnableAnOperationTheCodeDoesNotProvide(
        string $chainType,
        string $chainSlug,
        string $operation,
        array $row
    ): void {
        $chain = self::chain($chainType, $chainSlug);

        $withoutOverride = NftDriverRegistry::driversFor($chain, $operation, []);
        $withOverride    = NftDriverRegistry::driversFor($chain, $operation, [$row]);

        self::assertSame(
            $withoutOverride,
            $withOverride,
            'an enabled row for a triple the registry does not offer must change nothing'
        );
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> */
    public static function forbiddenGrants(): array
    {
        return [
            // The nightmare row: somebody tries to switch on EVM chain-wide
            // enumeration by inserting a record for a driver that has never
            // existed. It must stay [].
            'invented enumeration driver on EVM' => [
                'evm', 'ethereum', NftDriverRegistry::OP_VALIDATION,
                ['operation' => NftDriverRegistry::OP_VALIDATION, 'driver_key' => 'evm_enumeration', 'enabled' => true, 'priority' => 1],
            ],
            // A REAL driver, but one that cannot enumerate anything.
            'real driver claiming enumeration on EVM' => [
                'evm', 'ethereum', NftDriverRegistry::OP_VALIDATION,
                ['operation' => NftDriverRegistry::OP_VALIDATION, 'driver_key' => NftDriverRegistry::DRIVER_ALCHEMY_NFT, 'enabled' => true, 'priority' => 1],
            ],
            // A real Cosmos enumerator, pointed at a chain it does not serve.
            'cosmos enumerator aimed at Solana' => [
                'solana', 'solana', NftDriverRegistry::OP_VALIDATION,
                ['operation' => NftDriverRegistry::OP_VALIDATION, 'driver_key' => NftDriverRegistry::DRIVER_CW721_LCD, 'enabled' => true, 'priority' => 1],
            ],
            // A real driver on the right chain, claiming an operation it
            // does not perform — the EVM validation that PR 8 will build.
            'evm_rpc claiming validation before it is built' => [
                'evm', 'avalanche', NftDriverRegistry::OP_VALIDATION,
                ['operation' => NftDriverRegistry::OP_VALIDATION, 'driver_key' => NftDriverRegistry::DRIVER_EVM_RPC, 'enabled' => true, 'priority' => 1],
            ],
            // Talis is Injective-only; a row cannot re-aim it.
            'talis aimed at Osmosis' => [
                'cosmos', 'osmosis', NftDriverRegistry::OP_CURATED_FEED,
                ['operation' => NftDriverRegistry::OP_CURATED_FEED, 'driver_key' => NftDriverRegistry::DRIVER_TALIS_WHITELIST, 'enabled' => true, 'priority' => 1],
            ],
        ];
    }

    // ── Unknown input fails closed ──────────────────────────────────────

    public function testUnknownOperationYieldsNoDrivers(): void
    {
        $chain = self::chain('cosmos', 'cosmos');

        foreach (['', 'ENUMERATION', 'enumerate', 'intake', 'primary'] as $bogus) {
            self::assertSame(
                [],
                NftDriverRegistry::driversFor($chain, $bogus, []),
                "operation '{$bogus}' must not resolve"
            );
        }
    }

    public function testUnknownChainTypeYieldsNoDriversForAnyOperation(): void
    {
        $chain = self::chain('polkadot', 'polkadot');

        foreach (NftDriverRegistry::operations() as $operation) {
            self::assertSame([], NftDriverRegistry::driversFor($chain, $operation, []));
        }
    }

    /**
     * A chain projection missing `chain_type` entirely — what a partially
     * populated row or a trimmed projection looks like. Must not match any
     * driver.
     */
    public function testChainProjectionWithoutTypeMatchesNothing(): void
    {
        $chain = (object) ['id' => '1', 'slug' => 'mystery'];

        foreach (NftDriverRegistry::operations() as $operation) {
            self::assertSame([], NftDriverRegistry::driversFor($chain, $operation, []));
        }
    }

    // ── Shape of the registry itself ────────────────────────────────────

    /**
     * ⚠ SIX until S7 removed `enumeration`, which led this list. The order of
     * the remaining five is unchanged on purpose: a consumer that indexed by
     * name is unaffected, and one that indexed by position was already wrong.
     */
    public function testThereAreExactlyFiveOperations(): void
    {
        self::assertSame(
            ['curated_feed', 'wallet_discovery', 'validation', 'metadata', 'ownership'],
            NftDriverRegistry::operations()
        );
    }

    /**
     * `user_request` and `manual` are deliberately absent — the first
     * belongs to a system that does not exist yet, the second is a write
     * path rather than one of the six operations.
     */
    public function testIntakeDriversAreNotRegistered(): void
    {
        self::assertFalse(NftDriverRegistry::isDriver('user_request'));
        self::assertFalse(NftDriverRegistry::isDriver('manual'));
    }

    public function testEveryRegisteredDriverDeclaresOnlyRealOperations(): void
    {
        foreach (NftDriverRegistry::driverKeys() as $key) {
            self::assertTrue(NftDriverRegistry::isDriver($key));
        }
    }
}
