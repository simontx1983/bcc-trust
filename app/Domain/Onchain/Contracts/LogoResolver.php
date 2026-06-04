<?php
/**
 * LogoResolver — turns a validator's on-chain identity hint into a remote
 * image URL, or null when there's no usable logo.
 *
 * One implementation per logo source:
 *   - KeybaseLogoResolver (Cosmos `description.identity` → keybase.io)
 *   - SolanaValidatorInfoResolver (Phase 2: Config-program iconUrl/keybaseUsername)
 *
 * Resolvers MUST NOT trust the returned URL blindly — they return it, and
 * ValidatorLogoService downloads it through the SSRF-hardened transport
 * (BCC\Core\Http\SafeHttpClient via ApiRetry) and re-validates the bytes.
 *
 * @package BCC\Trust\Onchain\Contracts
 */

namespace BCC\Trust\Onchain\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

interface LogoResolver
{
    /**
     * Resolve an `https://` image URL for the given identity hint, or null
     * when the identity is malformed, has no logo, or the upstream lookup
     * fails. Implementations must never throw — return null on any error.
     */
    public function resolveImageUrl(string $identity): ?string;
}
