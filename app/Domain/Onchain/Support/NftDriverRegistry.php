<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\Repositories\ChainRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WHICH NFT DRIVERS EXIST, FOR WHICH OPERATION, ON WHICH CHAIN — OWNED BY
 * CODE, NARROWED BY THE DATABASE, NEVER THE OTHER WAY ROUND.
 *
 * ── WHY CODE OWNS THE WIRING ────────────────────────────────────────────
 * Because drivers ARE code. `talis_whitelist` targets one specific whitelist
 * contract on Injective; `cw721_lcd` speaks wasmd over LCD; `alchemy_nft`
 * needs an Alchemy URL shape. A database row saying "point Talis at Osmosis"
 * would not enable anything — it would only produce a driver key that
 * resolves to nothing, and a panel that believed it. So the set of
 * (driver, operation, chain) triples a build can actually perform is a
 * property of the build, and it is declared here.
 *
 * ── WHAT THE DATABASE MAY DO: NARROW ONLY ───────────────────────────────
 * `wp_bcc_chain_nft_capabilities` rows exist ONLY to disable or reorder what
 * this registry already offers. An absent row means "registry default". A
 * row naming a triple the registry does not offer is IGNORED — never
 * honoured, not even with `enabled = 1`.
 *
 * This is the same narrow-only property `BCC_COSMWASM_CHAIN_ALLOWLIST` has,
 * and it exists for the same fail-closed reason: configuration must be able
 * to take capability away, because taking away is always safe, and must
 * never be able to add it, because adding is a claim about code that
 * configuration is in no position to make.
 *
 * ── THERE IS NO `primary` DRIVER ────────────────────────────────────────
 * A scalar "primary driver" column was proposed and withdrawn. There is no
 * operation for which it is meaningful: enumeration is an ordered list,
 * metadata is a fallback chain, validation is per-chain-family. Ordering is
 * `priority`, per row, ascending. {@see driversFor()} always returns a LIST,
 * even when it has one element.
 *
 * ── WHAT THIS REGISTRY DELIBERATELY DOES *NOT* KNOW ─────────────────────
 * Two facts are intentionally somewhere else, because they are not
 * properties of the code:
 *
 *   - **Whether the provider is reachable/configured** — that is
 *     {@see NftProviderReadiness}, derived at read time and never stored. A
 *     registry entry means "this build can do it", not "it will work right
 *     now". `alchemy_nft` is registered on every EVM chain; whether a given
 *     chain's `rpc_url` carries an Alchemy key is a readiness question.
 *   - **Whether a Cosmos chain actually runs a wasm module** — that is
 *     MEASURED (`wp_bcc_chain_checkpoints.cw_discovery_state = 'unsupported'`,
 *     an observed HTTP 501) and lives on the checkpoint row, not on
 *     `ChainRow`. {@see NftChainCapability} folds it in as
 *     `CHAIN_UNSUPPORTED`. Re-deriving it here would be a second definition
 *     of a measured fact, and no operator action can change it anyway.
 *
 * ── THE LOAD-BEARING NEGATIVE ───────────────────────────────────────────
 * `driversFor($chain, OP_ENUMERATION)` returns `[]` for EVERY EVM chain and
 * for Solana. That is not an omission and not a configuration gap — no
 * provider offers chain-wide NFT contract enumeration on those families at
 * all. Alchemy enumerates a WALLET's contracts (`getContractsForOwner`),
 * which is a completely different question from "every collection on this
 * chain".
 *
 * Expressing that as an empty list rather than a comment is the point: the
 * registry PROVES the refusal, so `NO_ENUMERATION_DRIVER` is computed rather
 * than asserted, and no amount of Alchemy credentials can turn it into a
 * yes.
 *
 * @see NftChainCapability   the verdict that consumes this
 * @see NftProviderReadiness the per-driver runtime half
 * @see ChainSupport         UNRELATED — that class answers "does this chain
 *                           have a *fetcher transport* driver", keyed by
 *                           `chain_type`. `FetcherFactory::has_driver()` and
 *                           `FetcherInterface::supports_feature()` are that
 *                           same coarse per-transport concept. NFT drivers
 *                           here are per-(chain, operation) and ordered. The
 *                           word "driver" means different things in the two
 *                           places; they are not interchangeable.
 */
final class NftDriverRegistry
{
    // ── THE SIX OPERATIONS ──────────────────────────────────────────────
    //
    // Separated because a chain routinely supports some and not others, and
    // a single "does this chain do NFTs" flag would have to lie about at
    // least one of them. Avalanche is the standing proof: it can validate a
    // contract and count holdings on its public RPC today, while metadata
    // and wallet discovery are unavailable.

    public const OP_ENUMERATION      = 'enumeration';
    public const OP_CURATED_FEED     = 'curated_feed';
    public const OP_WALLET_DISCOVERY = 'wallet_discovery';
    public const OP_VALIDATION       = 'validation';
    public const OP_METADATA         = 'metadata';
    public const OP_OWNERSHIP        = 'ownership';

    // ── DRIVER KEYS ─────────────────────────────────────────────────────

    public const DRIVER_COSMWASM_ENUMERATION = 'cosmwasm_enumeration';
    public const DRIVER_TALIS_WHITELIST      = 'talis_whitelist';
    public const DRIVER_STARGAZE_MARKETPLACE = 'stargaze_marketplace';
    public const DRIVER_CW721_LCD            = 'cw721_lcd';
    public const DRIVER_ALCHEMY_NFT          = 'alchemy_nft';
    public const DRIVER_ALCHEMY_TRANSFERS    = 'alchemy_transfers';
    public const DRIVER_EVM_RPC              = 'evm_rpc';
    public const DRIVER_DAS                  = 'das';
    public const DRIVER_MAGICEDEN            = 'magiceden';

    /** Chain slug of the Cosmos Hub — the only chain `stargaze_marketplace` serves. */
    private const SLUG_COSMOS_HUB = 'cosmos';

    /** Chain slug of Injective — the only chain `talis_whitelist` serves. */
    private const SLUG_INJECTIVE = 'injective';

    /**
     * THE REGISTRY. Every entry is backed by code that exists on this
     * branch; nothing here is aspirational.
     *
     * ── WHY TWO ENTRIES FROM THE ARCHITECTURE PLAN ARE ABSENT ───────────
     * The plan's driver table is a TARGET-state table and lists two things
     * this build cannot do. Registering them would break the one rule this
     * class exists to enforce — a driver must never claim an operation the
     * code does not provide:
     *
     *   - `evm_rpc` is registered for OWNERSHIP only. The plan also shows
     *     VALIDATION, but the `supportsInterface(0x80ac58cd|0xd9b67a26)`
     *     `eth_call` behind it is explicitly still "to build". OWNERSHIP is
     *     real today (`EvmFetcher::count_holdings()` → `eth_call balanceOf`).
     *     Whoever builds EVM validation adds VALIDATION here in the same
     *     change, and Avalanche/BSC manual intake unblocks then — not now.
     *   - `user_request` is absent entirely; the community-request system it
     *     belongs to does not exist yet.
     *
     * `manual` is also absent, for a different reason: operator intake is
     * not one of the six operations. It is a write path into the collections
     * table, not a way of asking a chain a question.
     *
     * `priority` is the DEFAULT ordering within an operation, ascending.
     * A database row may override it; see {@see driversFor()}.
     *
     * @var array<string, array{operations: list<string>, priority: int}>
     */
    private const REGISTRY = [
        self::DRIVER_COSMWASM_ENUMERATION => [
            // CosmwasmDiscoveryWorker + CosmwasmDiscoveryService::listCodeFamilies.
            // The ONLY chain-wide enumeration driver that exists anywhere.
            'operations' => [self::OP_ENUMERATION],
            'priority'   => 10,
        ],
        self::DRIVER_TALIS_WHITELIST => [
            // EvmFetcher-independent: fetchTopCollectionsInjectiveViaTalisWhitelist.
            'operations' => [self::OP_CURATED_FEED],
            'priority'   => 10,
        ],
        self::DRIVER_STARGAZE_MARKETPLACE => [
            // StargazeMarketplaceApi::profileCollections — per-wallet, Hub only.
            'operations' => [self::OP_WALLET_DISCOVERY],
            'priority'   => 10,
        ],
        self::DRIVER_CW721_LCD => [
            // testCw721ContractInfo / fetchContractInfo / cw721Tokens.
            'operations' => [self::OP_VALIDATION, self::OP_METADATA, self::OP_OWNERSHIP],
            'priority'   => 10,
        ],
        self::DRIVER_ALCHEMY_NFT => [
            // EvmFetcher::fetch_collections + fetchContractMetadata.
            'operations' => [self::OP_WALLET_DISCOVERY, self::OP_METADATA],
            'priority'   => 10,
        ],
        self::DRIVER_ALCHEMY_TRANSFERS => [
            // alchemy_getAssetTransfers -> NftHoldingsIndexer.
            'operations' => [self::OP_OWNERSHIP],
            'priority'   => 10,
        ],
        self::DRIVER_EVM_RPC => [
            // count_holdings -> eth_call balanceOf. Works on ANY EVM RPC,
            // which is why Avalanche and BSC keep ERC-721 gating with no
            // Alchemy key at all. Ordered AFTER alchemy_transfers: both can
            // answer ownership, and the Alchemy path is richer.
            'operations' => [self::OP_OWNERSHIP],
            'priority'   => 20,
        ],
        self::DRIVER_DAS => [
            // getAssetsByOwner / getAsset.
            'operations' => [
                self::OP_WALLET_DISCOVERY,
                self::OP_VALIDATION,
                self::OP_METADATA,
                self::OP_OWNERSHIP,
            ],
            'priority'   => 10,
        ],
        self::DRIVER_MAGICEDEN => [
            // popular_collections.
            'operations' => [self::OP_CURATED_FEED],
            'priority'   => 10,
        ],
    ];

    /**
     * The six operations, in their canonical order.
     *
     * @return list<string>
     */
    public static function operations(): array
    {
        return [
            self::OP_ENUMERATION,
            self::OP_CURATED_FEED,
            self::OP_WALLET_DISCOVERY,
            self::OP_VALIDATION,
            self::OP_METADATA,
            self::OP_OWNERSHIP,
        ];
    }

    /** PURE. Is this string one of the six operations? */
    public static function isOperation(string $operation): bool
    {
        return in_array($operation, self::operations(), true);
    }

    /**
     * Every driver key this build knows about.
     *
     * @return list<string>
     */
    public static function driverKeys(): array
    {
        return array_keys(self::REGISTRY);
    }

    /** PURE. Is this a driver key this build implements? */
    public static function isDriver(string $driverKey): bool
    {
        return array_key_exists($driverKey, self::REGISTRY);
    }

    /**
     * PURE. Does this driver serve this chain, per code?
     *
     * Deliberately answers from `chain_type` and `slug` alone — the two
     * facts that are structural. Anything requiring configuration
     * (an Alchemy key, a Helius key) is a readiness question, and anything
     * measured (a chain's wasm module answering 501) belongs to the
     * checkpoint row. Mixing either in here would make "does the code
     * support this" depend on today's environment.
     *
     * @param object $chain a `ChainRow`-shaped projection
     */
    public static function driverSupportsChain(string $driverKey, object $chain): bool
    {
        $type = (string) ($chain->chain_type ?? '');
        $slug = (string) ($chain->slug ?? '');

        return match ($driverKey) {
            self::DRIVER_COSMWASM_ENUMERATION,
            self::DRIVER_CW721_LCD            => $type === 'cosmos',
            self::DRIVER_TALIS_WHITELIST      => $type === 'cosmos' && $slug === self::SLUG_INJECTIVE,
            self::DRIVER_STARGAZE_MARKETPLACE => $type === 'cosmos' && $slug === self::SLUG_COSMOS_HUB,
            self::DRIVER_ALCHEMY_NFT,
            self::DRIVER_ALCHEMY_TRANSFERS,
            self::DRIVER_EVM_RPC              => $type === 'evm',
            self::DRIVER_DAS,
            self::DRIVER_MAGICEDEN            => $type === 'solana',
            default                           => false,
        };
    }

    /**
     * PURE. The ordered driver keys this build can use for one
     * (chain, operation), after applying database narrowing.
     *
     * ── HOW OVERRIDES ARE APPLIED ───────────────────────────────────────
     * The override list is INTERSECTED with the registry, never unioned:
     *
     *   - a row for a triple the registry does not offer is DISCARDED,
     *     whatever its `enabled` value — the database cannot invent a
     *     capability;
     *   - a row with `enabled = false` REMOVES a registry default;
     *   - a row with `enabled = true` may only REORDER, via `priority`.
     *
     * Ties on priority keep registry declaration order, so the result is
     * deterministic for a given (chain, operation, override set) — a stable
     * sort matters because callers will render this list to operators.
     *
     * An unknown `$operation` returns `[]` rather than throwing: this feeds
     * a fail-closed verdict, and an empty list is already the refusal.
     *
     * @param object $chain     a `ChainRow`-shaped projection
     * @param string $operation one of the six OP_* constants
     * @param list<array{operation: string, driver_key: string, enabled: bool, priority: int}> $overrides
     *        rows from `wp_bcc_chain_nft_capabilities` for THIS chain
     * @return list<string> ordered driver keys; `[]` means "cannot"
     */
    public static function driversFor(object $chain, string $operation, array $overrides = []): array
    {
        if (!self::isOperation($operation)) {
            return [];
        }

        // Index the overrides that survive the intersection with the
        // registry. Everything else is dropped here and never consulted
        // again — this single loop is what makes the table narrow-only.
        /** @var array<string, array{enabled: bool, priority: int}> $applicable */
        $applicable = [];
        foreach ($overrides as $row) {
            if (($row['operation'] ?? '') !== $operation) {
                continue;
            }
            $key = (string) ($row['driver_key'] ?? '');
            if (!self::isDriver($key)) {
                continue;                       // DB names a driver this build lacks.
            }
            if (!in_array($operation, self::REGISTRY[$key]['operations'], true)) {
                continue;                       // DB claims an operation this driver lacks.
            }
            if (!self::driverSupportsChain($key, $chain)) {
                continue;                       // DB points a driver at the wrong chain.
            }
            $applicable[$key] = [
                'enabled'  => (bool) ($row['enabled'] ?? false),
                'priority' => (int) ($row['priority'] ?? 0),
            ];
        }

        $ordered = [];
        $seq     = 0;
        foreach (self::REGISTRY as $key => $spec) {
            $seq++;
            if (!in_array($operation, $spec['operations'], true)) {
                continue;
            }
            if (!self::driverSupportsChain($key, $chain)) {
                continue;
            }
            $priority = $spec['priority'];
            if (isset($applicable[$key])) {
                if ($applicable[$key]['enabled'] === false) {
                    continue;                   // Operator disabled a registry default.
                }
                $priority = $applicable[$key]['priority'];
            }
            $ordered[] = ['key' => $key, 'priority' => $priority, 'seq' => $seq];
        }

        usort(
            $ordered,
            static fn(array $a, array $b): int => $a['priority'] <=> $b['priority'] ?: $a['seq'] <=> $b['seq']
        );

        return array_map(static fn(array $r): string => $r['key'], $ordered);
    }

    /**
     * Convenience: the ordered enumeration drivers for a chain id.
     *
     * Resolves the chain through {@see ChainRepository::getById()} (which
     * also serves inactive chains) and returns `[]` when the chain is
     * unknown — an unresolvable chain cannot be enumerated, which is the
     * fail-closed answer.
     *
     * @param list<array{operation: string, driver_key: string, enabled: bool, priority: int}> $overrides
     * @return list<string>
     */
    public static function enumerationDriversForChainId(int $chainId, array $overrides = []): array
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return [];
        }

        return self::driversFor($chain, self::OP_ENUMERATION, $overrides);
    }
}
