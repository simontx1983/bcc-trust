<?php
/**
 * CorsHandler — the FINAL CORS authority for /bcc/v1/* and /bcc-trust/v1/*.
 *
 * ## Why "final"
 *
 * WordPress core ships its own CORS emitter, `rest_send_cors_headers()`
 * (wp-includes/rest-api.php). It is unconditional: given ANY `Origin`
 * request header it echoes that origin back verbatim as
 * `Access-Control-Allow-Origin`, plus `Access-Control-Allow-Methods` and
 * `Access-Control-Allow-Credentials: true`. There is no allowlist. Every
 * site on the internet is therefore a credentialed CORS peer of every
 * `/wp-json/` namespace unless something removes those headers again.
 *
 * Core registers it on `rest_pre_serve_request` at the default priority
 * 10, from `rest_api_default_filters()`, which is hooked on
 * `rest_api_init` (wp-includes/default-filters.php). `rest_api_init`
 * fires long AFTER plugins load, so a plugin that registers its own
 * priority-10 callback at load time is registered FIRST and therefore
 * runs FIRST — core runs second and clobbers it. The docblock this file
 * carried until 2026-08 claimed the opposite ("Runs AFTER WP's built-in
 * rest_send_cors_headers"); that claim was false and is the root cause of
 * the reflected-origin exposure this class now closes.
 *
 * The fix is priority, not removal. Core's emitter stays registered so
 * `/wp/v2/*`, Gutenberg, wp-admin and every non-BCC namespace behave
 * exactly as before. We simply run LAST for the two BCC namespaces
 * (`self::FINAL_AUTHORITY_PRIORITY`), `header_remove()` whatever core (or
 * anything else) already emitted, and re-emit from our own allowlist —
 * or emit nothing at all.
 *
 * ## Never credentialed
 *
 * `Access-Control-Allow-Credentials` is NEVER sent for BCC routes
 * (owner ruling, 2026-08). The headless chain is Bearer-JWT only — the
 * Next.js client sets `credentials: "omit"` on every cross-origin call
 * (`bcc-frontend/src/lib/api/client.ts`, `bcc-trust-client.ts`). No
 * cross-origin cookie consumer exists, so the header buys nothing and
 * costs a full-strength CSRF surface if the allowlist ever slips.
 *
 * ## Route scoping
 *
 * The BCC-namespace predicate is {@see EdgeCache::appliesTo()} — the
 * single implementation in this plugin, already unit-pinned by
 * tests/Unit/EdgeCacheRoutePredicateTest.php. It matches a namespace
 * root (`/bcc/v1`) as well as any route beneath it; the old
 * `str_contains($route, '/bcc/v1/')` test in this class missed the root
 * (WP reports the namespace index as `/bcc/v1`, no trailing slash) and
 * so left it on core's reflected headers.
 *
 * ## Allowlist
 *
 * Sourced from the `BCC_FRONTEND_ORIGIN` constant, comma-separated. When
 * undefined or empty NO CORS headers are emitted — same-origin only.
 * Two entry forms are recognised:
 *
 *   1. An exact origin, e.g. `https://bluecollarcrypto.io`. Compared
 *      against the request Origin AFTER both sides are canonicalised
 *      (scheme + host lowercased, port preserved).
 *
 *   2. A Vercel preview rule, `vercel:<project>:<team>`, e.g.
 *      `vercel:bcc-frontend:phillip-simon-s-projects`. Matches the two
 *      real Vercel preview host shapes and nothing else:
 *        - hash deploy:   `<project>-<hash>-<team>.vercel.app`
 *        - branch alias:  `<project>-git-<branch>-<team>.vercel.app`
 *      The project prefix and the `-<team>.vercel.app` suffix are exact
 *      anchors, so a lookalike host (`evil.<project>-…`,
 *      `<project>-…-<team>.vercel.app.evil.com`, a different team slug)
 *      cannot match.
 *
 * RETIRED: the `regex:` entry form. It matched case-insensitively
 * against the RAW Origin string and echoed that raw string back, so
 * `HTTPS://BCC-FRONTEND-…` was accepted and reflected verbatim; and its
 * `[a-z0-9]+` body could not span a hyphen, so no `git-*` branch preview
 * ever matched. `regex:` entries are now ignored (fail closed).
 * DEPLOYMENT NOTE: any environment whose `BCC_FRONTEND_ORIGIN` still
 * carries a `regex:` entry must migrate it to the `vercel:` form or its
 * preview deployments lose CORS.
 *
 * Configuration example (wp-config.php):
 *
 *   define('BCC_FRONTEND_ORIGIN',
 *       'https://bluecollarcrypto.io' .
 *       ',https://staging.bluecollarcrypto.io' .
 *       ',vercel:bcc-frontend:phillip-simon-s-projects'
 *   );
 *
 * ## Two entry points, one policy
 *
 *   1. `init` priority 1 — preflight OPTIONS short-circuit. Fires before
 *      WP routing so a preflight never pays for the REST bootstrap.
 *      Only engages when an `Origin` header is actually present (an
 *      Origin-less OPTIONS is not a CORS preflight and is left to WP).
 *      Allowed AND denied origins both terminate here with 204 — denied
 *      simply carries no `Access-Control-*`, which is what makes the
 *      browser refuse the real request.
 *
 *   2. `rest_pre_serve_request` at `self::FINAL_AUTHORITY_PRIORITY` —
 *      every actual BCC response.
 *
 * Both call the same {@see self::applyPolicy()}, so the two paths cannot
 * drift.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, Phase 2 hardening)
 */

namespace BCC\Trust\Core\Support;

use BCC\Trust\Infrastructure\EdgeCache;

if (!defined('ABSPATH')) {
    exit;
}

final class CorsHandler
{
    /**
     * Priority for the `rest_pre_serve_request` callback.
     *
     * Must be greater than the priority of core's `rest_send_cors_headers`
     * (10) *as registered*, and greater than anything else that might
     * emit `Access-Control-*` on that hook. PHP_INT_MAX is the only value
     * that is unconditionally last; anything smaller is a race with the
     * next plugin that picks a bigger number. Public so the pinning test
     * can assert it without reflection.
     */
    public const FINAL_AUTHORITY_PRIORITY = PHP_INT_MAX;

    /** Browser preflight cache window (seconds). 600 = 10 minutes. */
    private const PREFLIGHT_MAX_AGE = 600;

    /** Methods accepted across BCC routes. Mirrors what register_rest_route declares. */
    private const ALLOWED_METHODS = 'GET, POST, PATCH, PUT, DELETE, OPTIONS';

    /** Headers the frontend is allowed to send on a CORS request.
     *  `Authorization` is load-bearing — the entire headless chain is
     *  Bearer-JWT, so dropping it from this list logs every user out.
     *  `Sentry-Trace` + `Baggage` are injected by `@sentry/nextjs` for
     *  distributed tracing on requests matching `tracePropagationTargets`
     *  in `bcc-frontend/src/instrumentation-client.ts`. They are not
     *  "simple" CORS headers, so the browser issues a preflight and blocks
     *  the request when they are not on the allowlist — surfacing as
     *  "Failed to fetch" with no further detail. */
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, X-WP-Nonce, X-Requested-With, Sentry-Trace, Baggage';

    /** Phase 4c: let the cross-origin frontend READ the correlation id off
     *  the response (X-Request-Id, emitted by Envelope) so a frontend error
     *  can be tied back to the server logs. Exposed (readable), not allowed
     *  (inbound) — the client does not send it, avoiding a CORS preflight on
     *  otherwise-simple anonymous GETs.
     *
     *  This list is DELIBERATELY minimal — one header, because exactly one
     *  has a browser consumer (`response.headers.get("X-Request-Id")` at
     *  bcc-frontend/src/lib/api/client.ts:122 and :495). Audited 2026-08
     *  across all of bcc-frontend/src:
     *
     *    X-WP-Total / X-WP-TotalPages — 0 consumers. Emitted on two routes
     *      only (DisputeController::votes / ::mine). The frontend fetches
     *      just the first page by design; see
     *      bcc-frontend/src/lib/api/disputes-endpoints.ts:71.
     *    Link — 0 consumers. Core exposes it site-wide; no BCC route
     *      emits it.
     *
     *  ⚠ Consequence, stated plainly: those pagination totals are today
     *  UNREADABLE cross-origin. The server still SENDS them — exposure is a
     *  browser read permission, not a send switch — so same-origin, curl,
     *  SSR and server-to-server callers see them unchanged. Only browser JS
     *  is blocked.
     *
     *  ⚠ IF DISPUTE PAGINATION EVER BECOMES CLIENT-DRIVEN, add
     *  `X-WP-Total, X-WP-TotalPages` back here as a deliberate act, in the
     *  same change that introduces the reader, and update
     *  tests/Unit/CorsFinalAuthorityTest.php (D4 section) with it. Failing
     *  to do so does NOT raise an error — `headers.get("X-WP-Total")` just
     *  returns null in the browser while working perfectly in every
     *  same-origin and server-side test. This constant is the cause. */
    private const EXPOSED_HEADERS = 'X-Request-Id';

    /**
     * Every response header this class takes ownership of on a BCC route.
     * Removed unconditionally before any decision is made, so a header
     * emitted by core — or by a future third party on an earlier priority
     * — cannot survive into a BCC response.
     *
     * `Access-Control-Allow-Credentials` is on the list precisely BECAUSE
     * we never emit it: core does, and stripping is the only way to be
     * sure it is gone.
     *
     * `Access-Control-Expose-Headers` is on the list because
     * `WP_REST_Server::serve_request()` emits it unconditionally, before
     * dispatch and regardless of Origin. It is inert without an ACAO, but
     * leaving it behind means a denied BCC response still carries an
     * `Access-Control-*` header — and makes the OPTIONS and GET paths
     * disagree, since a short-circuited preflight never runs that
     * preamble. Strip it so "denied" means literally no CORS output.
     */
    private const MANAGED_HEADERS = [
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials',
        'Access-Control-Allow-Methods',
        'Access-Control-Allow-Headers',
        'Access-Control-Expose-Headers',
        'Access-Control-Max-Age',
    ];

    /** Allowlist entry prefix for a structural Vercel preview rule. */
    private const PREVIEW_ENTRY_PREFIX = 'vercel:';

    /** Allowlist entry prefix that is recognised only so it can be ignored. */
    private const RETIRED_REGEX_ENTRY_PREFIX = 'regex:';

    public static function register(): void
    {
        // Preflight OPTIONS for BCC routes — short-circuit before WP routing.
        add_action('init', [self::class, 'handlePreflight'], 1);

        // Actual REST response. LAST on the hook so core's unconditional
        // reflected headers are already emitted and can be stripped.
        add_filter(
            'rest_pre_serve_request',
            [self::class, 'sendCorsHeaders'],
            self::FINAL_AUTHORITY_PRIORITY,
            3
        );
    }

    public static function handlePreflight(): void
    {
        if (self::requestMethod() !== 'OPTIONS') {
            return;
        }

        // No Origin => not a CORS preflight. Leave WP's normal OPTIONS
        // handling (which advertises `Allow:`) alone.
        if (self::requestOrigin() === null) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '';
        if (!EdgeCache::appliesTo(self::routeFromRequestUri($uri))) {
            return;
        }

        // Allowed or denied, the answer is decided here — a denied
        // preflight terminates with 204 and NO Access-Control-* headers
        // rather than falling through to WP, where core would reflect it.
        self::applyPolicy();

        // Don't let edge / reverse-proxy caches (LiteSpeed, Cloudflare,
        // Varnish) store the preflight. They cache by URL including the
        // query string, so a single stale entry made before
        // BCC_FRONTEND_ORIGIN was configured (or before this plugin
        // loaded) would lock out every subsequent OPTIONS to that exact
        // URL with a no-CORS response. Access-Control-Max-Age is the
        // BROWSER preflight cache (different layer); this is the
        // SERVER-side cache prevention.
        header('Cache-Control: no-store, no-cache, must-revalidate');

        status_header(204);

        // Defensive: a 204 response should have an empty body. If a
        // plugin echoed during plugins_loaded (page-hit counters,
        // debug bars, sloppy auto-loaders), that output is buffered
        // and would tail the 204 status line. Browsers ignore the
        // body for preflight, but well-formed > tolerated.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        exit;
    }

    /**
     * Hooked LAST on `rest_pre_serve_request`. WP passes
     * `($served, $result, $request, $server)`; we take the first three and
     * mutate none of them — the return value is a pass-through so we never
     * short-circuit serving.
     *
     * @param  bool  $served  whether the request has already been served
     * @param  mixed $result  the WP_REST_Response being served
     * @param  mixed $request the WP_REST_Request that produced it
     * @return bool           pass-through
     */
    public static function sendCorsHeaders(bool $served, $result = null, $request = null): bool
    {
        if (!EdgeCache::appliesTo(self::routeFor($result, $request))) {
            // Not a BCC namespace: core's behaviour stands, untouched.
            return $served;
        }

        self::applyPolicy();

        return $served;
    }

    /**
     * The one CORS decision. Strip everything we own, then either emit the
     * full allowed set for a canonical allowlisted origin, or emit nothing.
     *
     * `Vary: Origin` is emitted on BOTH branches: a shared cache that
     * stored an allowed response must not replay it to a denied origin,
     * and vice versa.
     */
    private static function applyPolicy(): void
    {
        foreach (self::MANAGED_HEADERS as $name) {
            header_remove($name);
        }

        self::varyOnOrigin();

        $origin = self::resolveAllowedOrigin();
        if ($origin === null) {
            // Denied, malformed, missing, or `null`: reflect nothing.
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: ' . self::ALLOWED_METHODS);
        header('Access-Control-Allow-Headers: ' . self::ALLOWED_HEADERS);
        header('Access-Control-Expose-Headers: ' . self::EXPOSED_HEADERS);
        header('Access-Control-Max-Age: ' . self::PREFLIGHT_MAX_AGE);

        // Access-Control-Allow-Credentials is deliberately absent. See the
        // class docblock — the headless chain is Bearer-only and sets
        // `credentials: "omit"`. Do not "restore" it.
    }

    /**
     * Merge `Origin` into whatever `Vary` is already on the response
     * instead of replacing it — core appends `Vary: Origin` as a second
     * header line, and other layers may have added `Accept-Encoding` etc.
     * A single collapsed header is what caches actually want.
     */
    private static function varyOnOrigin(): void
    {
        $tokens = ['origin' => 'Origin'];

        foreach (headers_list() as $line) {
            if (stripos($line, 'Vary:') !== 0) {
                continue;
            }
            foreach (explode(',', substr($line, 5)) as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }
                $tokens[strtolower($token)] ??= $token;
            }
        }

        header('Vary: ' . implode(', ', array_values($tokens)));
    }

    /**
     * The REST route for the in-flight response. `WP_REST_Request::get_route()`
     * is the reliable source — it is populated even for 404s and for the
     * namespace index, where `WP_REST_Response::get_matched_route()` is
     * empty or root-shaped.
     *
     * @param mixed $result
     * @param mixed $request
     */
    private static function routeFor($result, $request): string
    {
        if ($request instanceof \WP_REST_Request) {
            $route = $request->get_route();
            if ($route !== '') {
                return $route;
            }
        }

        if ($result instanceof \WP_REST_Response) {
            $matched = $result->get_matched_route();
            if (is_string($matched) && $matched !== '') {
                return $matched;
            }
        }

        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '';

        return self::routeFromRequestUri($uri);
    }

    /**
     * Recover a REST route (`/bcc/v1/…`) from a raw request URI, for the
     * preflight path where no WP_REST_Request exists yet. Handles both
     * addressing forms: pretty (`/wp-json/bcc/v1/x`) and plain-permalink
     * (`/?rest_route=/bcc/v1/x`).
     *
     * Deliberately permissive about WHERE the REST prefix sits in the path
     * (sub-directory installs put it after the site path). The only cost of
     * a false positive is that an OPTIONS request to a non-REST URL that
     * happens to contain `/wp-json/bcc/v1/` answers 204 — no data crosses.
     * A false NEGATIVE, by contrast, is what left the namespace index on
     * core's reflected headers, so err permissive here.
     */
    private static function routeFromRequestUri(string $uri): string
    {
        if ($uri === '') {
            return '';
        }

        $query = wp_parse_url($uri, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            $restRoute = $params['rest_route'] ?? null;
            if (is_string($restRoute) && $restRoute !== '') {
                return self::normalizeRoute($restRoute);
            }
        }

        $path = wp_parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/';
        $at     = strpos($path, $prefix);
        if ($at === false) {
            return '';
        }

        return self::normalizeRoute(substr($path, $at + strlen($prefix) - 1));
    }

    /** Leading slash, no trailing slash — the shape WP_REST_Request uses. */
    private static function normalizeRoute(string $route): string
    {
        $route = '/' . ltrim($route, '/');

        return $route === '/' ? '/' : rtrim($route, '/');
    }

    private static function requestMethod(): string
    {
        return isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper($_SERVER['REQUEST_METHOD'])
            : '';
    }

    /** Raw `Origin` request header, or null when absent/empty. */
    private static function requestOrigin(): ?string
    {
        if (!isset($_SERVER['HTTP_ORIGIN']) || !is_string($_SERVER['HTTP_ORIGIN'])) {
            return null;
        }
        $origin = trim($_SERVER['HTTP_ORIGIN']);

        return $origin === '' ? null : $origin;
    }

    /**
     * Resolve the request Origin against the allowlist.
     *
     * Returns the CANONICAL form (scheme + host lowercased) to echo back —
     * never the raw request string. A browser always serialises an origin
     * lowercase, so canonicalising costs nothing for real traffic while
     * making it impossible to reflect attacker-chosen bytes.
     */
    private static function resolveAllowedOrigin(): ?string
    {
        $raw = self::requestOrigin();
        if ($raw === null) {
            return null;
        }

        $candidate = self::canonicalizeOrigin($raw);
        if ($candidate === null) {
            return null;
        }

        return self::isAllowlisted($candidate) ? $candidate['canonical'] : null;
    }

    /**
     * Structurally validate + canonicalise an origin string.
     *
     * Everything here is a REJECT rule; nothing is repaired. An origin has
     * exactly one legal shape — scheme, host, optional port — so any path
     * beyond `/`, any query, fragment, userinfo, control character,
     * non-ASCII byte, percent-escape or malformed host label is a forgery
     * attempt or a bug, and either way is not something to echo back.
     *
     * @return array{scheme: string, host: string, port: int|null, canonical: string}|null
     */
    private static function canonicalizeOrigin(string $raw): ?array
    {
        // `Origin: null` is what a sandboxed iframe, a file:// document or
        // a redirected cross-origin request sends. It is never allowlisted.
        if ($raw === '' || $raw === 'null' || strlen($raw) > 255) {
            return null;
        }

        // Printable ASCII only: kills CR/LF header splitting, NUL, tabs,
        // spaces, and raw-unicode homograph hosts in one test. `%` is inside
        // this range, so percent-escapes are rejected by the host charset
        // check below instead.
        if (preg_match('/[^\x21-\x7e]/', $raw) === 1) {
            return null;
        }

        $parts = wp_parse_url($raw);
        if (!is_array($parts)) {
            return null;
        }

        // Embedded credentials, query strings and fragments are not part of
        // an origin serialisation.
        if (isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        if ($path !== '' && $path !== '/') {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme'])
            ? strtolower($parts['scheme'])
            : '';
        $host = isset($parts['host']) && is_string($parts['host'])
            ? strtolower($parts['host'])
            : '';
        if ($scheme === '' || $host === '' || !self::isValidHostname($host)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        if (!self::isAcceptableScheme($scheme, $host)) {
            return null;
        }

        return [
            'scheme'    => $scheme,
            'host'      => $host,
            'port'      => $port,
            'canonical' => $scheme . '://' . $host . ($port !== null ? ':' . $port : ''),
        ];
    }

    /**
     * HTTPS everywhere, with one narrow carve-out: plain `http` on a
     * loopback host. Loopback can only ever be the developer's own machine,
     * and the entry still has to be present in `BCC_FRONTEND_ORIGIN` before
     * it matches anything — production's constant does not contain it. This
     * is what keeps the documented local setup
     * (`define('BCC_FRONTEND_ORIGIN', 'http://localhost:3000')`,
     * docs/dev-setup.md) working. Delete these two lines to make the plugin
     * HTTPS-only.
     */
    private static function isAcceptableScheme(string $scheme, string $host): bool
    {
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && ($host === 'localhost' || $host === '127.0.0.1');
    }

    /**
     * Strict DNS hostname check on an already-lowercased host. Rejects a
     * trailing dot, empty or over-long labels, leading/trailing hyphens,
     * underscores, percent-escapes, brackets and every other byte that is
     * not an LDH character.
     */
    private static function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{scheme: string, host: string, port: int|null, canonical: string} $candidate
     */
    private static function isAllowlisted(array $candidate): bool
    {
        if (!defined('BCC_FRONTEND_ORIGIN')) {
            return false;
        }
        $configured = (string) BCC_FRONTEND_ORIGIN;
        if ($configured === '') {
            return false;
        }

        foreach (explode(',', $configured) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if (str_starts_with($entry, self::PREVIEW_ENTRY_PREFIX)) {
                $spec = substr($entry, strlen(self::PREVIEW_ENTRY_PREFIX));
                if (self::matchesVercelPreview($candidate, $spec)) {
                    return true;
                }
                continue;
            }

            // RETIRED entry form — recognised only so it is skipped rather
            // than mis-parsed as an exact origin. See the class docblock.
            if (str_starts_with($entry, self::RETIRED_REGEX_ENTRY_PREFIX)) {
                continue;
            }

            $allowed = self::canonicalizeOrigin($entry);
            if ($allowed !== null && $allowed['canonical'] === $candidate['canonical']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match the two real Vercel preview host shapes for a `<project>:<team>`
     * rule. Both anchors are exact literals, so the only variable region is
     * the middle token — and that token may not contain a dot, which is what
     * stops `bcc-frontend-x.evil-<team>.vercel.app` and every other
     * extra-label trick.
     *
     * @param array{scheme: string, host: string, port: int|null, canonical: string} $candidate
     * @param string $spec `<project>:<team>`
     */
    private static function matchesVercelPreview(array $candidate, string $spec): bool
    {
        // Previews are HTTPS and never carry a port.
        if ($candidate['scheme'] !== 'https' || $candidate['port'] !== null) {
            return false;
        }

        $parts = explode(':', $spec);
        if (count($parts) !== 2) {
            return false;
        }
        [$project, $team] = $parts;

        $label = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';
        if ($project === '' || $team === ''
            || preg_match($label, $project) !== 1
            || preg_match($label, $team) !== 1) {
            return false;
        }

        $host   = $candidate['host'];
        $prefix = $project . '-';
        $suffix = '-' . $team . '.vercel.app';

        // Strict `>`: prefix and suffix must not overlap, and the middle
        // token must be at least one character.
        if (strlen($host) <= strlen($prefix) + strlen($suffix)) {
            return false;
        }
        if (!str_starts_with($host, $prefix) || !str_ends_with($host, $suffix)) {
            return false;
        }

        $middle = substr($host, strlen($prefix), strlen($host) - strlen($prefix) - strlen($suffix));

        // Hash deploy: one hyphen-free lowercase alphanumeric token.
        if (preg_match('/^[a-z0-9]{6,32}$/', $middle) === 1) {
            return true;
        }

        // Branch alias: `git-` followed by a hyphen-separated slug.
        return preg_match('/^git-[a-z0-9]+(?:-[a-z0-9]+)*$/', $middle) === 1;
    }
}
