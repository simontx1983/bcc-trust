<?php
/**
 * FrontendOrigin — the single parser for BCC_FRONTEND_ORIGIN.
 *
 * The constant is a comma-separated allowlist whose entries are either:
 *   - an exact origin string (scheme + host + optional port), or
 *   - a regex pattern prefixed with "regex:", matched case-insensitively
 *     against a request Origin. Used for Vercel preview URLs, e.g.
 *     regex:^https://bcc-frontend-[a-z0-9]+-team\.vercel\.app$
 *
 * Before this class the constant was parsed in four places, and only one
 * of them understood the "regex:" prefix:
 *
 *   CorsHandler::resolveAllowedOrigin()         stripped it   (correct)
 *   JwtToken::audienceAllowlist()               did not
 *   FrontendRedirect::firstOrigin()             did not
 *   PolkadotSignatureVerifier::routeUrl()       did not (bcc-core)
 *
 * A "regex:" entry in first position therefore leaked a raw pattern into
 * places that need a usable URL: the JWT `aud` claim, password-reset and
 * email-verification links, and the internal wallet-verify callout.
 *
 * The distinction this class enforces:
 *   - Anything that needs a USABLE ORIGIN (a URL base, an audience) must
 *     use exactOrigins()/canonical() — regex entries can never serve.
 *   - Only Origin-header MATCHING may consider regex entries, via
 *     matches().
 *
 * @package BCC\Trust\Core\Support
 * @since   2026-08-19 (domain cutover hardening)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class FrontendOrigin
{
    private const REGEX_PREFIX = 'regex:';

    /**
     * Raw, trimmed, non-empty entries in declaration order.
     *
     * @return list<string>
     */
    private static function entries(): array
    {
        if (!defined('BCC_FRONTEND_ORIGIN')) {
            return [];
        }
        $raw = (string) constant('BCC_FRONTEND_ORIGIN');
        if ($raw === '') {
            return [];
        }

        $entries = array_map('trim', explode(',', $raw));

        return array_values(array_filter($entries, static fn(string $e): bool => $e !== ''));
    }

    /**
     * Exact origins only — every entry that is NOT a regex pattern,
     * in declaration order.
     *
     * This is the allowlist for anything requiring a real origin: the
     * JWT `aud` claim and its verification set, redirect bases, and
     * outbound internal calls.
     *
     * @return list<string>
     */
    public static function exactOrigins(): array
    {
        $exact = [];
        foreach (self::entries() as $entry) {
            if (!self::isPattern($entry)) {
                $exact[] = $entry;
            }
        }

        return $exact;
    }

    /**
     * Regex patterns with the "regex:" prefix stripped, in declaration
     * order. Only Origin-header matching should consult these.
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        $patterns = [];
        foreach (self::entries() as $entry) {
            if (self::isPattern($entry)) {
                $patterns[] = substr($entry, strlen(self::REGEX_PREFIX));
            }
        }

        return $patterns;
    }

    /**
     * The canonical frontend origin — the FIRST EXACT entry.
     *
     * Deliberately skips leading regex entries rather than taking
     * position [0] blindly: a pattern is not a usable URL, and callers
     * of this method are all building one.
     */
    public static function canonical(): ?string
    {
        return self::exactOrigins()[0] ?? null;
    }

    /**
     * Resolve a request Origin against the allowlist.
     *
     * Exact matches are tried first so the fast path always wins; regex
     * entries are evaluated only on miss. A malformed pattern is silently
     * skipped (never matches) so a typo cannot break the whole allowlist.
     *
     * @return string|null The matched origin to echo back, or null.
     */
    public static function match(string $requestOrigin): ?string
    {
        $requestOrigin = trim($requestOrigin);
        if ($requestOrigin === '') {
            return null;
        }

        if (in_array($requestOrigin, self::exactOrigins(), true)) {
            return $requestOrigin;
        }

        foreach (self::patterns() as $pattern) {
            if (@preg_match('#' . $pattern . '#i', $requestOrigin) === 1) {
                return $requestOrigin;
            }
        }

        return null;
    }

    /** True when the allowlist is unset or empty (CORS/aud disabled). */
    public static function isConfigured(): bool
    {
        return self::entries() !== [];
    }

    private static function isPattern(string $entry): bool
    {
        return str_starts_with($entry, self::REGEX_PREFIX);
    }
}
