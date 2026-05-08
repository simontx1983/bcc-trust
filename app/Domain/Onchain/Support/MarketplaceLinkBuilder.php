<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Marketplace-link template interpolator (V2 Phase 6 / §H1).
 *
 * Builds the per-piece marketplace URL from a per-chain template
 * stored in `bcc_onchain_chains.marketplace_template`. Templates
 * carry `{contract}` and `{token_id}` placeholders; this helper
 * substitutes them and validates the result is a structurally-sound
 * http(s) URL with a host.
 *
 * Why no URL-encode: the supported chains use canonical address
 * forms that are already URL-safe (EVM `0x[a-f0-9]{40}`, Solana
 * base58, Cosmos bech32). Token IDs are stored as decimal strings
 * (EVM) or arbitrary CW-721 strings — the latter are
 * caller-controlled but for V2 Phase 6 we trust upstream marketplaces
 * to handle the round-trip. If a future chain breaks this assumption
 * the per-chain solution is to either pre-encode in the template or
 * extend this helper with a chain-aware overload.
 *
 * @since V2 Phase 6 (§H1)
 */
final class MarketplaceLinkBuilder
{
    /**
     * Interpolate a marketplace template. Returns null when:
     *   - template is empty or whitespace-only
     *   - the resulting URL is not http(s)://
     *   - the resulting URL has no host component
     *
     * Missing placeholders are left alone — `{contract}` without a
     * matching value would propagate into the URL, which would fail
     * the validity check below. Solana templates intentionally omit
     * `{token_id}` (Solana mints don't have a separate token_id);
     * that's fine because the surviving template just doesn't
     * reference it.
     */
    public static function build(string $template, string $contract, string $tokenId): ?string
    {
        $tpl = trim($template);
        if ($tpl === '') {
            return null;
        }

        $url = strtr($tpl, [
            '{contract}' => $contract,
            '{token_id}' => $tokenId,
        ]);

        if ($url === '') {
            return null;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = $parts['host'] ?? '';
        if (!is_string($host) || $host === '') {
            return null;
        }

        return $url;
    }
}
