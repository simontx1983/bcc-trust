<?php
/**
 * FrontendRedirect — headless-Next.js return-URL handling for OAuth flows.
 *
 * Two responsibilities:
 *
 *   1. Whitelist the BCC_FRONTEND_ORIGIN host(s) for wp_safe_redirect via
 *      the `allowed_redirect_hosts` filter. Without this, OAuth callbacks
 *      that try to land back on Next.js fall through to home_url('/')
 *      because wp_safe_redirect strips off-host targets.
 *
 *   2. Validate a caller-supplied `return_to` string against the same
 *      allowlist. Used by /x/auth and /github/auth to record where the
 *      user wants to land after the OAuth callback.
 *
 * BCC_FRONTEND_ORIGIN is a comma-separated list (mirrors CorsHandler /
 * JwtToken). Each entry must be a fully-qualified scheme+host (no path),
 * e.g. `https://app.bcc.example,https://staging.bcc.example`.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class FrontendRedirect
{
    /**
     * Wire the `allowed_redirect_hosts` filter so wp_safe_redirect()
     * accepts the configured frontend origin(s). Idempotent — safe to
     * call from any boot path.
     */
    public static function register(): void
    {
        add_filter('allowed_redirect_hosts', [self::class, 'extendAllowedHosts']);
    }

    /**
     * Filter callback. WP passes the current allowlist (a list of host
     * strings, no scheme). We append every host parsed out of
     * BCC_FRONTEND_ORIGIN that isn't already present.
     *
     * @param  array<int, string> $hosts
     * @return array<int, string>
     */
    public static function extendAllowedHosts(array $hosts): array
    {
        foreach (self::allowedHosts() as $host) {
            if (!in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }
        return $hosts;
    }

    /**
     * Validate a caller-supplied return URL against the BCC_FRONTEND_ORIGIN
     * allowlist. Returns the URL unchanged on match, null on reject.
     *
     * Rules:
     *   - Must be a fully qualified URL (scheme + host).
     *   - Scheme must be http or https.
     *   - Host (with port) must match an entry parsed from BCC_FRONTEND_ORIGIN.
     */
    public static function validateReturnTo(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        if (!in_array($host, self::allowedHosts(), true)) {
            return null;
        }
        return $url;
    }

    /**
     * Build a default return URL on the first configured frontend origin
     * with a trailing path. Falls back to home_url() (legacy WP-side
     * surface) when BCC_FRONTEND_ORIGIN is undefined.
     */
    public static function defaultReturn(string $path): string
    {
        $origin = self::firstOrigin();
        if ($origin === null) {
            return home_url($path);
        }
        $relative = $path === '' || $path[0] !== '/' ? '/' . $path : $path;
        return rtrim($origin, '/') . $relative;
    }

    /**
     * Extract the first frontend origin (scheme + host[:port]) from
     * BCC_FRONTEND_ORIGIN, or null when unconfigured. Used as the
     * default redirect base.
     */
    public static function firstOrigin(): ?string
    {
        // First EXACT entry — never a `regex:` preview pattern, which is
        // not a usable URL base. Callers build redirect targets and email
        // links from this.
        return FrontendOrigin::canonical();
    }

    /**
     * Hosts (host[:port], lowercase) parsed from BCC_FRONTEND_ORIGIN.
     *
     * @return array<int, string>
     */
    private static function allowedHosts(): array
    {
        $hosts = [];
        // Exact origins only: wp_parse_url() on a `regex:^https://…`
        // entry yields a nonsense host that could never match a real
        // redirect target anyway.
        foreach (FrontendOrigin::exactOrigins() as $entry) {
            $parts = wp_parse_url($entry);
            if (!is_array($parts) || empty($parts['host'])) {
                continue;
            }
            $host = strtolower((string) $parts['host']);
            if (isset($parts['port'])) {
                $host .= ':' . $parts['port'];
            }
            $hosts[] = $host;
        }
        return $hosts;
    }
}
