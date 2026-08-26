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
     * NO EVM CHAIN CAN BE ENUMERATED. Not one of the seven, on any
     * configuration. No provider sells chain-wide NFT contract enumeration
     * on EVM; Alchemy enumerates a WALLET's contracts, which is a different
     * question that lives under WALLET_DISCOVERY.
     */
    public function testNoEvmChainHasAnEnumerationDriver(): void
    {
        foreach (['ethereum', 'base', 'polygon', 'arbitrum', 'optimism', 'avalanche', 'bsc'] as $slug) {
            self::assertSame(
                [],
                NftDriverRegistry::driversFor(self::chain('evm', $slug), NftDriverRegistry::OP_ENUMERATION),
                "EVM chain {$slug} must have no enumeration driver"
            );
        }
    }

    public function testSolanaHasNoEnumerationDriver(): void
    {
        self::assertSame(
            [],
            NftDriverRegistry::driversFor(self::chain('solana', 'solana'), NftDriverRegistry::OP_ENUMERATION)
        );
    }

    public function testCosmosIsTheOnlyFamilyWithAnEnumerationDriver(): void
    {
        self::assertSame(
            [NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION],
            NftDriverRegistry::driversFor(self::chain('cosmos', 'cosmos'), NftDriverRegistry::OP_ENUMERATION)
        );
    }

    // ── Chain targeting ─────────────────────────────────────────────────

    public function testTalisIsInjectiveOnlyAndStargazeIsHubOnly(): void
    {
        $injective = self::chain('cosmos', 'injective');
        $hub       = self::chain('cosmos', 'cosmos');

        self::assertSame(
            [NftDriverRegistry::DRIVER_TALIS_WHITELIST],
            NftDriverRegistry::driversFor($injective, NftDriverRegistry::OP_CURATED_FEED)
        );
        self::assertSame(
            [],
            NftDriverRegistry::driversFor($hub, NftDriverRegistry::OP_CURATED_FEED),
            'the Cosmos Hub has no curated feed'
        );

        self::assertSame(
            [NftDriverRegistry::DRIVER_STARGAZE_MARKETPLACE],
            NftDriverRegistry::driversFor($hub, NftDriverRegistry::OP_WALLET_DISCOVERY)
        );
        self::assertSame(
            [],
            NftDriverRegistry::driversFor($injective, NftDriverRegistry::OP_WALLET_DISCOVERY),
            'Injective has no per-wallet owner index'
        );
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
            NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_OWNERSHIP)
        );
        self::assertSame(
            [],
            NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_VALIDATION),
            'EVM validation is not implemented on this branch and must not be claimed'
        );
    }

    public function testOwnershipOnEvmIsOrderedAlchemyThenPlainRpc(): void
    {
        self::assertSame(
            [NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS, NftDriverRegistry::DRIVER_EVM_RPC],
            NftDriverRegistry::driversFor(self::chain('evm', 'ethereum'), NftDriverRegistry::OP_OWNERSHIP)
        );
    }

    // ── The database can narrow ─────────────────────────────────────────

    public function testAnOverrideCanDisableARegistryDefault(): void
    {
        $chain = self::chain('cosmos', 'cosmos');

        self::assertSame(
            [],
            NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_ENUMERATION, [[
                'operation'  => NftDriverRegistry::OP_ENUMERATION,
                'driver_key' => NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
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

        $withoutOverride = NftDriverRegistry::driversFor($chain, $operation);
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
                'evm', 'ethereum', NftDriverRegistry::OP_ENUMERATION,
                ['operation' => NftDriverRegistry::OP_ENUMERATION, 'driver_key' => 'evm_enumeration', 'enabled' => true, 'priority' => 1],
            ],
            // A REAL driver, but one that cannot enumerate anything.
            'real driver claiming enumeration on EVM' => [
                'evm', 'ethereum', NftDriverRegistry::OP_ENUMERATION,
                ['operation' => NftDriverRegistry::OP_ENUMERATION, 'driver_key' => NftDriverRegistry::DRIVER_ALCHEMY_NFT, 'enabled' => true, 'priority' => 1],
            ],
            // A real Cosmos enumerator, pointed at a chain it does not serve.
            'cosmos enumerator aimed at Solana' => [
                'solana', 'solana', NftDriverRegistry::OP_ENUMERATION,
                ['operation' => NftDriverRegistry::OP_ENUMERATION, 'driver_key' => NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION, 'enabled' => true, 'priority' => 1],
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
                NftDriverRegistry::driversFor($chain, $bogus),
                "operation '{$bogus}' must not resolve"
            );
        }
    }

    public function testUnknownChainTypeYieldsNoDriversForAnyOperation(): void
    {
        $chain = self::chain('polkadot', 'polkadot');

        foreach (NftDriverRegistry::operations() as $operation) {
            self::assertSame([], NftDriverRegistry::driversFor($chain, $operation));
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
            self::assertSame([], NftDriverRegistry::driversFor($chain, $operation));
        }
    }

    // ── Shape of the registry itself ────────────────────────────────────

    public function testThereAreExactlySixOperations(): void
    {
        self::assertSame(
            ['enumeration', 'curated_feed', 'wallet_discovery', 'validation', 'metadata', 'ownership'],
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
