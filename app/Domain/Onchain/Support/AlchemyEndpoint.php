<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE ONE PLACE THAT KNOWS WHAT AN ALCHEMY RPC URL LOOKS LIKE.
 *
 * ── WHY THIS CLASS EXISTS ───────────────────────────────────────────────
 * Two different questions depend on the same single fact:
 *
 *   1. "Can this fetcher build an Alchemy NFT v3 base URL?"
 *      — {@see \BCC\Trust\Onchain\Fetchers\EvmFetcher::fetch_collections()}
 *   2. "Is the Alchemy-backed NFT driver actually usable on this chain?"
 *      — {@see NftProviderReadiness::isReady()}
 *
 * Before this class, (1) lived in a private method on `EvmFetcher` and (2)
 * did not exist yet. Answering (2) by re-typing the regex somewhere else is
 * the exact failure mode {@see CosmwasmScanEligibility} was created to end:
 * two definitions of one predicate, maintained by hand, written to agree and
 * drifting anyway. A capability panel that said "Alchemy configured" while
 * the fetcher returned `[]` for the same chain would be worse than no panel,
 * because it would be believed.
 *
 * So the regex lives here exactly once and both callers ask this class.
 *
 * ── WHY THE REGEX IS STRICT ─────────────────────────────────────────────
 * It anchors on `*.g.alchemy.com` and requires a non-empty key segment.
 * That strictness is a SECURITY property, not fussiness: the key segment is
 * interpolated into an outbound URL, so a loose pattern would leak the API
 * key to whatever host the `rpc_url` column happened to name. Preserved
 * byte-for-byte from the original `EvmFetcher::alchemyNftBaseFromRpcUrl()`.
 *
 * ── WHAT "NOT CONFIGURED" COVERS ────────────────────────────────────────
 * Two real, distinct production situations both land on `null`, and both
 * are correct:
 *
 *   - **Seeded-but-keyless.** `schema-chains.php` seeds Ethereum, Polygon,
 *     Arbitrum, Optimism and Base with `https://{net}.g.alchemy.com/v2/` —
 *     no key. That trailing-empty form fails the `([A-Za-z0-9_-]+)` segment
 *     and is therefore NOT configured. The seeded URLs are templates an
 *     operator must finish, never working endpoints.
 *   - **Non-Alchemy host.** Avalanche (`api.avax.network`) and BSC
 *     (`bsc-dataseed.binance.org`) never match at all.
 *
 * Neither is a defect and neither is a transient. They are configuration
 * states an operator can fix, which is why they surface as
 * `PROVIDER_UNAVAILABLE` and never as a chain- or code-level incapability.
 */
final class AlchemyEndpoint
{
    /**
     * Derive the Alchemy NFT API v3 base URL from a chain's JSON-RPC URL.
     *
     * Maps `https://{network}.g.alchemy.com/v2/{key}` to
     *      `https://{network}.g.alchemy.com/nft/v3/{key}`.
     *
     * PURE. Returns `null` for any URL that is not a recognisable Alchemy
     * v2 endpoint.
     */
    public static function nftBaseFromRpcUrl(string $rpcUrl): ?string
    {
        if ($rpcUrl === '') {
            return null;
        }
        if (!preg_match('~^(https://[a-z0-9.-]+\.g\.alchemy\.com)/v2/([A-Za-z0-9_-]+)/?$~', $rpcUrl, $m)) {
            return null;
        }

        return $m[1] . '/nft/v3/' . $m[2];
    }

    /**
     * PURE. Is this `rpc_url` a usable, keyed Alchemy endpoint?
     *
     * Defined in terms of {@see nftBaseFromRpcUrl()} rather than a second
     * regex, so "readiness says yes" and "the fetcher can build a URL" can
     * never disagree — they are the same computation.
     */
    public static function isConfigured(?string $rpcUrl): bool
    {
        return self::nftBaseFromRpcUrl((string) ($rpcUrl ?? '')) !== null;
    }
}
