<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WHICH ENDPOINT A SOLANA CALL ACTUALLY GOES TO — resolved once, for both the
 * fetcher that calls it and the capability model that describes it.
 *
 * ── WHY THIS EXISTS: SOLANA DAS IS TWO ENDPOINTS, NOT ONE ───────────────
 * "DAS" reads like a single capability. In this build it is served by two
 * completely separate endpoint paths, chosen per method:
 *
 *   getAssetsByOwner   -> SolanaFetcher::rpcCall() -> the CHAIN ROW's rpc_url
 *       used by fetch_collections (WALLET_DISCOVERY),
 *       count_holdings and list_holdings (OWNERSHIP)
 *
 *   getAsset           -> fetchMetadataForMint() -> the HELIUS constants
 *       used by NftEnrichmentService and NftPieceViewModelBuilder (METADATA)
 *
 * Treating those as one readiness answer produced exactly the defect this
 * capability model exists to prevent:
 *
 *   1. BCC_HELIUS_API_KEY is configured
 *   2. the Solana chain row still carries the public RPC
 *   3. readiness reports "das ready"
 *   4. wallet discovery calls the PUBLIC RPC
 *   5. getAssetsByOwner fails — that endpoint has no DAS
 *
 * A panel that says ready while the worker cannot run is worse than no
 * panel, because it is believed. So the two paths are two drivers
 * ({@see NftDriverRegistry::DRIVER_DAS_RPC} and
 * {@see NftDriverRegistry::DRIVER_DAS_HELIUS}) with two readiness answers,
 * and the endpoint each one describes is resolved HERE so the description
 * and the call cannot diverge.
 *
 * ── THIS CLASS CHANGES NO ROUTING ───────────────────────────────────────
 * Both methods reproduce what the fetcher already did, byte for byte. If
 * production should consolidate Solana onto Helius, that is a behavioural
 * change and belongs in its own PR — not smuggled into a scaffold by way of
 * making a capability model look tidier.
 */
final class SolanaEndpoints
{
    /**
     * The public Solana mainnet endpoint the fetcher falls back to.
     *
     * It is a full node, not an indexer: it does not implement the DAS
     * methods. That is a documented property of the endpoint rather than a
     * guess, which is why {@see rpcSupportsDas()} can answer for this one
     * URL without having to ask it first.
     */
    public const PUBLIC_MAINNET_RPC = 'https://api.mainnet-beta.solana.com';

    /**
     * The endpoint `SolanaFetcher::rpcCall()` will POST to — the chain row's
     * `rpc_url`, falling back to the public default.
     *
     * The fallback matters and is easy to miss: a chain row with a NULL
     * `rpc_url` still makes calls, to the default. A readiness check that
     * looked at the raw column would be describing a different endpoint from
     * the one being used.
     *
     * @param object $chain a `ChainRow`-shaped projection
     */
    public static function rpcEndpoint(object $chain): string
    {
        $configured = trim((string) ($chain->rpc_url ?? ''));

        return $configured !== '' ? $configured : self::PUBLIC_MAINNET_RPC;
    }

    /**
     * PURE-ish. Can the endpoint `rpcCall()` uses plausibly serve DAS?
     *
     * Only ONE endpoint can be answered without asking: the public default,
     * which is known not to implement DAS. Every other endpoint is unknown
     * until a call is made — and an unknown endpoint is allowed through,
     * because refusing it would be a permanent deadlock (the first call is
     * what produces the evidence). If it turns out not to serve DAS, the
     * `-32601` observation is recorded against that endpoint and
     * {@see NftProviderReadiness} refuses from then on.
     *
     * This is deliberately NOT a list of known-good providers. A slug or
     * host allowlist would go stale the moment an operator used a provider
     * nobody had added to it, and it would be the same
     * hard-coded-list antipattern the tab-visibility rules already reject.
     */
    public static function rpcSupportsDas(object $chain): bool
    {
        return self::rpcEndpoint($chain) !== self::PUBLIC_MAINNET_RPC;
    }

    /**
     * The endpoint `SolanaFetcher::fetchMetadataForMint()` will POST to, or
     * `null` when no Helius credential is configured.
     *
     * Separate from {@see rpcEndpoint()} on purpose: metadata deliberately
     * ignores the chain row, because the chain's `rpc_url` is the public
     * endpoint by default and `getAsset` needs a DAS provider.
     */
    public static function metadataEndpoint(): ?string
    {
        return HeliusEndpoint::resolveRpcUrl();
    }
}
