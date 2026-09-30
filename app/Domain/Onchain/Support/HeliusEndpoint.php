<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE ONE PLACE THAT RESOLVES THE HELIUS DAS RPC URL.
 *
 * ── WHY THIS CLASS EXISTS ───────────────────────────────────────────────
 * Same reason as {@see AlchemyEndpoint}. The Solana fetcher already knew how
 * to resolve Helius credentials into a usable URL; {@see NftProviderReadiness}
 * needs the identical answer to report whether the `das_helius` driver is
 * usable. Two hand-maintained copies of "is Helius configured?" would drift,
 * and the drift would be invisible: the panel would say configured, the
 * fetcher would return nothing.
 *
 * ── THIS CLASS BACKS `das_helius` ONLY ──────────────────────────────────
 * These constants serve the METADATA path (`getAsset`, via
 * `SolanaFetcher::fetchMetadataForMint()`). The other Solana DAS driver,
 * `das_rpc`, calls `getAssetsByOwner` through `rpcCall()` against the CHAIN
 * ROW's `rpc_url` and never reads anything here — which is exactly why the
 * two are separate drivers with separate readiness answers. Reading these
 * constants to decide whether `das_rpc` is usable is the defect that split
 * them. See {@see SolanaEndpoints}.
 *
 * Extracted verbatim from `SolanaFetcher::resolveHeliusRpcUrl()`, which
 * remains the only production consumer of the URL itself.
 *
 * ── DEFINED IS NOT CONFIGURED ───────────────────────────────────────────
 * `defined('BCC_HELIUS_API_KEY')` is NOT sufficient and must never be used
 * as the readiness test. A `define('BCC_HELIUS_API_KEY', '')` in
 * `wp-config.php` — a half-finished provisioning step, a stripped staging
 * config, a secret that failed to inject — is `defined()` and useless. Read
 * as "configured" it would flip a chain to SCANNABLE and hand the operator a
 * job that cannot make a single successful call.
 *
 * So both constants are read for a non-empty VALUE, and the empty string
 * falls through to the next candidate exactly as it does in the fetcher. A
 * key that is defined-but-empty is indistinguishable, here, from a key that
 * was never set — which is the honest answer, because neither can talk to
 * Helius.
 */
final class HeliusEndpoint
{
    /**
     * Resolve the Helius DAS-compatible RPC URL.
     *
     * Prefers an explicit `BCC_HELIUS_RPC_URL`; falls back to the canonical
     * `https://mainnet.helius-rpc.com/?api-key=…` shape built from
     * `BCC_HELIUS_API_KEY`. Returns `null` when neither yields a non-empty
     * value.
     */
    public static function resolveRpcUrl(): ?string
    {
        // Values are TRIMMED before they are judged usable. A constant
        // holding "  " or "\n" is the same class of half-finished
        // configuration as an empty one — a templated wp-config, a secret
        // injected with a stray newline, a copy-paste with trailing
        // whitespace. Untrimmed it is non-empty, reads as configured, and
        // produces a URL that cannot resolve. Trimming here also means the
        // key never carries whitespace into the outbound URL.
        if (defined('BCC_HELIUS_RPC_URL')) {
            $url = trim((string) constant('BCC_HELIUS_RPC_URL'));
            if ($url !== '') {
                return $url;
            }
        }
        if (defined('BCC_HELIUS_API_KEY')) {
            $key = trim((string) constant('BCC_HELIUS_API_KEY'));
            if ($key !== '') {
                return 'https://mainnet.helius-rpc.com/?api-key=' . rawurlencode($key);
            }
        }

        return null;
    }

    /** Is a usable (non-empty) Helius DAS endpoint configured? */
    public static function isConfigured(): bool
    {
        return self::resolveRpcUrl() !== null;
    }

    /**
     * Option key recording that a chain's endpoint answered a DAS call with
     * "method not found".
     *
     * Written by `SolanaFetcher::markDasUnsupported()` only on an OBSERVED
     * `-32601` / `-32603` from a `getAssets*` call — never speculatively.
     * Named here so the readiness derivation and the Settings page read the
     * same key instead of re-typing the prefix.
     *
     * @see \BCC\Trust\Onchain\Admin\SettingsPage
     */
    public static function dasUnsupportedOptionKey(int $chainId): string
    {
        return 'bcc_onchain_das_unsupported_' . $chainId;
    }

    // ⚠⚠⚠ `redactEndpoint()` WAS DELETED. It masked the QUERY STRING only:
    //
    //     'https://host/v2/SECRET'       -> unchanged
    //     'https://host/?api-key=SECRET' -> 'https://host/?***REDACTED***'
    //
    // Its own docblock admitted the gap, and on 2026-09-30 a live key leaked
    // through the third shape nobody had listed —
    // `AlchemyEndpoint::nftBaseFromRpcUrl()` rewriting a path key into
    // `/nft/v3/<KEY>`. That is the failure mode of allow-by-pattern redaction.
    //
    // Replacements, with the two jobs it was conflating pulled apart:
    //
    //   {@see \BCC\Trust\Onchain\Support\EndpointDescriptor::display()}
    //       deny-by-default description — scheme + host, safe to render or log
    //   {@see \BCC\Trust\Onchain\Support\EndpointDescriptor::identity()}
    //       site-keyed HMAC over the whole URL, for comparison only
    //
    // Deleted rather than deprecated: a redactor that is safe for one
    // credential shape and silently unsafe for another is worse than none,
    // because callers trust the name.
}
