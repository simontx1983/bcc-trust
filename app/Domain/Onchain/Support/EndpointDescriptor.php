<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The only sanctioned way to DESCRIBE a provider endpoint, and — separately —
 * to COMPARE one.
 *
 * ── ⚠⚠⚠ WHY DENY-BY-DEFAULT, AND WHY IT IS NOT NEGOTIABLE ───────────────
 * The predecessor, `HeliusEndpoint::redactEndpoint()`, masked the QUERY STRING
 * and nothing else:
 *
 *     'https://host/v2/SECRET'       -> 'https://host/v2/SECRET'   // untouched
 *     'https://host/?api-key=SECRET' -> 'https://host/?***REDACTED***'
 *
 * That is ALLOW-BY-PATTERN redaction: it enumerates the credential shapes its
 * author thought of. Alchemy embeds the key in the PATH, and
 * {@see AlchemyEndpoint::nftBaseFromRpcUrl()} rewrites that path into
 * `/nft/v3/<KEY>` — a third shape on nobody's list. On 2026-09-30 a diagnostic
 * printed exactly that rewritten form and leaked a live key, which had to be
 * rotated.
 *
 * The lesson generalises past URLs: a redactor that asks "is this one of the
 * secrets I know about?" fails silently the first time the answer is a shape it
 * has never seen, and URL rewriting MANUFACTURES new shapes. So this class never
 * asks. {@see display()} keeps scheme and host and throws everything else away
 * without inspecting it — userinfo, port, path, query, fragment. A new provider
 * URL layout cannot leak through it, because nothing is pattern-matched.
 *
 * ── ⚠⚠ DESCRIPTION AND IDENTITY ARE DIFFERENT PROBLEMS ──────────────────
 * `NftProviderReadiness::dasMarkApplies()` used to decide "does this stored
 * DAS-unsupported mark still describe the endpoint we now use?" by comparing two
 * redactions. Under a scheme+host-only description that comparison COLLAPSES:
 * two different credentials, or two different paths, on one host describe
 * identically. A stale mark would then attach to a rotated endpoint and keep a
 * perfectly good driver disabled — or, in the other direction, a real negative
 * signal would be dropped.
 *
 * Hardening the description therefore REQUIRES splitting identity out of it:
 *
 *   {@see display()}   lossy on purpose, safe to render, safe to log
 *   {@see identity()}  collision-resistant over the WHOLE url, site-keyed,
 *                      opaque, and NEVER rendered or logged
 *
 * ── THREAT MODEL FOR identity() ─────────────────────────────────────────
 * The identity is persisted in a `wp_option` that an administrator can read and
 * that appears in every database backup and export. What must not be possible is
 * recovering the endpoint — and therefore the credential — from that stored
 * value.
 *
 * A plain `sha256(url)` would not achieve that. The URL space is tiny and highly
 * structured: an attacker holding the option knows the host set, knows the path
 * template (`/v2/<key>`), and needs only to grind candidate keys. An unsalted
 * digest is also equal across every site that uses the same endpoint, so one
 * confirmed pairing anywhere breaks it everywhere.
 *
 * So identity is `hash_hmac('sha256', normalised_url, K)` where K is derived
 * from this site's own WordPress salts via {@see identityKey()}. Consequences:
 *
 *   - offline grinding needs K, which is not in the option or the database;
 *   - the same endpoint on two sites yields different identities, so nothing
 *     is comparable across installs;
 *   - rotating salts invalidates stored identities, which fails SAFE — a mark
 *     that no longer matches is treated as absent, re-enabling a driver that
 *     will re-mark itself if the endpoint really is unsupported.
 *
 * It is a comparison token, not an authenticator: it is never accepted from a
 * request, so there is no forgery surface to defend.
 *
 * ⚠ It is NOT reversible and NOT a password hash. It does not need to be
 * slow — the input is not a user secret being verified, and a per-request KDF
 * would buy nothing against an attacker who already lacks K.
 */
final class EndpointDescriptor
{
    /** No endpoint is configured at all. */
    public const UNCONFIGURED = '(unconfigured)';

    /** Something is configured, but it is not a URL we can describe safely. */
    public const UNRECOGNISED = '(unrecognised endpoint)';

    /**
     * A display-safe description: `scheme://host`, and nothing else, ever.
     *
     * ⚠ On anything unparseable this returns a BOUNDED PLACEHOLDER and never
     * echoes the input. Falling back to the raw string is exactly how a
     * malformed-but-credentialed value would leak — a URL too broken to parse is
     * still perfectly capable of containing a key.
     */
    public static function display(?string $url): string
    {
        $raw = trim((string) ($url ?? ''));
        if ($raw === '') {
            return self::UNCONFIGURED;
        }

        $parts = self::parts($raw);
        if ($parts === null) {
            return self::UNRECOGNISED;
        }

        return $parts['scheme'] . '://' . $parts['host'];
    }

    /** The hostname alone, for diagnostics that name a provider. '' when unusable. */
    public static function host(?string $url): string
    {
        $parts = self::parts(trim((string) ($url ?? '')));

        return $parts === null ? '' : $parts['host'];
    }

    /** Is an endpoint configured AND describable? A boolean, nothing more. */
    public static function isConfigured(?string $url): bool
    {
        return self::parts(trim((string) ($url ?? ''))) !== null;
    }

    /**
     * An opaque, site-keyed identity for COMPARISON ONLY.
     *
     * ⚠⚠⚠ NEVER RENDER, LOG, RETURN OVER REST OR PUT IN AN EXCEPTION. It is a
     * persisted comparison token; treating it as a display value would undo the
     * split this class exists to make. `scripts/endpoint-exposure-guard.php`
     * fails the build if it reaches an output path.
     *
     * Returns '' for input with no describable endpoint, so an unusable value
     * can never be mistaken for a match: '' is compared with `hash_equals()`
     * against a real identity and loses.
     */
    public static function identity(?string $url): string
    {
        $raw = trim((string) ($url ?? ''));
        if ($raw === '') {
            return '';
        }

        $parts = self::parts($raw);
        if ($parts === null) {
            return '';
        }

        return hash_hmac('sha256', self::normalise($parts, $raw), self::identityKey());
    }

    /** Do two endpoints denote the same thing? Constant-time, either may be ''. */
    public static function sameEndpoint(?string $a, ?string $b): bool
    {
        $ia = self::identity($a);
        $ib = self::identity($b);
        if ($ia === '' || $ib === '') {
            return false;
        }

        return hash_equals($ia, $ib);
    }

    /**
     * Canonical form fed to the HMAC.
     *
     * Equivalent-but-differently-written endpoints must agree, or a mark would
     * stop applying after a cosmetic change and a genuine negative signal would
     * be dropped. So the host is lowercased and a default port is dropped.
     *
     * ⚠ The path, query and fragment are included VERBATIM and are NOT
     * normalised. Two endpoints differing only in credential must never collide,
     * and any "tidying" of those components risks exactly that.
     *
     * @param array{scheme: string, host: string, port: int|null} $parts from {@see parts()}
     */
    private static function normalise(array $parts, string $raw): string
    {
        $out = $parts['scheme'] . '://' . $parts['host'];

        $port = $parts['port'];
        $default = ['http' => 80, 'https' => 443];
        if ($port !== null && ($default[$parts['scheme']] ?? null) !== $port) {
            $out .= ':' . $port;
        }

        $path = (string) (parse_url($raw, PHP_URL_PATH) ?? '');
        $out .= rtrim($path, '/');

        $query = parse_url($raw, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $out .= '?' . $query;
        }
        $frag = parse_url($raw, PHP_URL_FRAGMENT);
        if (is_string($frag) && $frag !== '') {
            $out .= '#' . $frag;
        }

        return $out;
    }

    /**
     * Scheme + host + port, or null when there is nothing safely describable.
     *
     * @return array{scheme: string, host: string, port: int|null}|null
     */
    private static function parts(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        // ⚠ Control characters can split a log line or an HTTP header. Refuse
        // rather than sanitise — a value containing them is not a URL we trust.
        if (preg_match('~[\x00-\x1F\x7F]~', $raw) === 1) {
            return null;
        }

        $p = parse_url($raw);
        if (!is_array($p)) {
            return null;
        }

        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host   = strtolower((string) ($p['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return null;
        }
        // Only network schemes get described. `javascript:` and `data:` have no
        // host anyway, but an allowlist keeps a future scheme from arriving with
        // its payload in a component we do not expect.
        if (!in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
            return null;
        }
        if (preg_match('~^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$~', $host) !== 1) {
            return null;
        }

        $port = isset($p['port']) ? (int) $p['port'] : null;

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    /**
     * The HMAC key: this site's own salts, never a constant in the repository.
     *
     * `wp_salt()` is defined per install in `wp-config.php`. Using it means the
     * key is not in version control, not in the option being protected, and not
     * shared between installs.
     *
     * ⚠ The fallback exists so a CLI or test context without WordPress salts
     * still produces a STABLE key rather than a random one — a random key per
     * request would make every stored identity un-matchable and silently
     * re-enable every marked driver. It is deliberately not a secret, and it is
     * only ever reached where no salts exist.
     */
    private static function identityKey(): string
    {
        if (function_exists('wp_salt')) {
            $salt = (string) \wp_salt('bcc_onchain_endpoint_identity');
            if (trim($salt) !== '') {
                return $salt;
            }
        }
        foreach (['AUTH_SALT', 'AUTH_KEY', 'BCC_ENCRYPTION_KEY'] as $c) {
            if (defined($c) && is_string(constant($c)) && trim((string) constant($c)) !== '') {
                return 'bcc_onchain_endpoint_identity|' . (string) constant($c);
            }
        }

        return 'bcc_onchain_endpoint_identity|no-site-salt-available';
    }
}
