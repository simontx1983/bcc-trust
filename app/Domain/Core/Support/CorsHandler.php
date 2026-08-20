<?php
/**
 * CorsHandler — the single CORS authority for every WordPress REST route.
 *
 * Allowlist sourced from the BCC_FRONTEND_ORIGIN constant, parsed in
 * exactly one place: {@see FrontendOrigin}. This class never re-splits,
 * re-prefixes or re-canonicalises the constant — it asks FrontendOrigin
 * whether an Origin matches and echoes what comes back.
 *
 * ## The bug this closes
 *
 * WordPress core registers `rest_send_cors_headers()` on
 * `rest_pre_serve_request` at priority 10, from inside
 * `rest_api_default_filters()` (wp-includes/rest-api.php:252, hooked to
 * `rest_api_init` at 10). That callback reflects ANY `Origin` back as
 * `Access-Control-Allow-Origin` with `Access-Control-Allow-Credentials:
 * true`, against no allowlist, on EVERY REST route.
 *
 * This class used to guard only `/bcc/v1/*` and `/bcc-trust/v1/*` and to
 * run at the same priority 10 — but registered at plugin-load time, i.e.
 * BEFORE core, so core ran second and won. Verified on production
 * 2026-08-19: `https://evil.example.com` was echoed back from
 * `/wp-json/wp/v2/types` with credentials.
 *
 * Three changes close it, and all three are load-bearing:
 *
 *   1. Core's emitter is REMOVED (`removeCoreCorsHeaders()`), rather than
 *      raced and cleaned up after.
 *   2. Ownership is EVERY REST route, not the two BCC namespaces.
 *   3. This class runs LAST on `rest_pre_serve_request`
 *      (`FINAL_AUTHORITY_PRIORITY`), stripping every header it owns before
 *      deciding, so a third-party plugin cannot re-add an unsafe one.
 *
 * ## No credentials, ever
 *
 * `Access-Control-Allow-Credentials` is deliberately never emitted. The
 * headless chain is Bearer-only: every WordPress-bound request from the
 * frontend sets `credentials: "omit"` (bcc-frontend/src/lib/api/client.ts
 * :100,486). The `credentials: "include"` calls in that file target
 * same-origin `/api/auth/*` Next.js routes and never reach WordPress. The
 * header is on {@see self::MANAGED_HEADERS} precisely BECAUSE we never
 * send it — core does, and stripping is the only way to be sure it is gone.
 *
 * ## Hook points
 *
 *   1. `init` priority 1 — preflight OPTIONS short-circuit, before WP
 *      routing, so the OPTIONS never reaches the REST stack. Only for
 *      requests that carry an `Origin` AND resolve to a REST route:
 *      an Origin-less OPTIONS is not a CORS preflight and is left to WP's
 *      `Allow:` handling, and an ordinary page request resolves to no
 *      route at all.
 *   2. `rest_api_init` priority 11 — core-emitter removal. Must be 11:
 *      core adds the filter at 10 on the same hook, so removing any
 *      earlier is a silent no-op.
 *   3. `rest_pre_serve_request` at PHP_INT_MAX — the response headers.
 *
 * Browsers reject duplicate `Access-Control-Allow-*` values, so the
 * strip-then-emit order in {@see self::applyPolicy()} is critical.
 *
 * Configuration example (wp-config.php):
 *
 *   define('BCC_FRONTEND_ORIGIN',
 *       'https://bluecollarcrypto.io' .
 *       ',regex:^https://bcc-frontend-[a-z0-9]+-phillip-simon-s-projects\.vercel\.app$'
 *   );
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, Phase 2 hardening)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class CorsHandler
{
    /**
     * Priority for the response-header callback on `rest_pre_serve_request`.
     *
     * Last, deliberately. Core's emitter is already gone by then, but a
     * third-party plugin registering on the same hook is not — and running
     * after it is what makes {@see self::MANAGED_HEADERS} stripping mean
     * something.
     */
    public const FINAL_AUTHORITY_PRIORITY = PHP_INT_MAX;

    /**
     * Priority at which the core-CORS removal runs on `rest_api_init`.
     *
     * WordPress registers `rest_send_cors_headers` from inside
     * `rest_api_default_filters()`, which is hooked to `rest_api_init` at
     * priority 10 (wp-includes/default-filters.php:532). The removal must
     * therefore run AFTER that — 11 is the first priority that qualifies.
     * Removing earlier is a silent no-op: the filter does not exist yet,
     * `remove_filter()` returns false, and core re-adds it moments later.
     */
    public const CORE_CORS_REMOVAL_PRIORITY = 11;

    /**
     * Priority at which WordPress core registers `rest_send_cors_headers`
     * on `rest_pre_serve_request` — `add_filter()`'s default, i.e. 10
     * (wp-includes/rest-api.php:252). `remove_filter()` matches on
     * (hook, callback, priority), so this value must be exact: passing
     * any other priority removes nothing and returns false.
     */
    public const CORE_CORS_PRIORITY = 10;

    /** Browser preflight cache window (seconds). 600 = 10 minutes. */
    private const PREFLIGHT_MAX_AGE = 600;

    /** Methods accepted across BCC routes. Mirrors what register_rest_route declares. */
    private const ALLOWED_METHODS = 'GET, POST, PATCH, PUT, DELETE, OPTIONS';

    /** Headers the frontend is allowed to send on a cross-origin request.
     *  `Authorization` is the whole point — the chain is Bearer-only.
     *  `Sentry-Trace` + `Baggage` are injected by `@sentry/nextjs` for
     *  distributed tracing on requests matching `tracePropagationTargets` in
     *  `bcc-frontend/src/instrumentation-client.ts`. They are not "simple" CORS
     *  headers, so the browser issues a preflight and blocks the request when
     *  they are not on the allowlist — surfacing as "Failed to fetch" with no
     *  further detail. */
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, X-WP-Nonce, X-Requested-With, Sentry-Trace, Baggage';

    /** Phase 4c: let the cross-origin frontend READ the correlation id off the
     *  response (X-Request-Id, emitted by Envelope) so a frontend error can be
     *  tied back to the server logs. Exposed (readable), not allowed (inbound)
     *  — the client does not send it, avoiding a CORS preflight on otherwise-
     *  simple anonymous GETs. */
    private const EXPOSED_HEADERS = 'X-Request-Id';

    /**
     * Every response header this class takes ownership of on a REST route.
     * Removed unconditionally before any decision is made, so a header
     * emitted by core — or by a third party on an earlier priority —
     * cannot survive into the response.
     *
     * `Access-Control-Expose-Headers` is on the list because
     * `WP_REST_Server::serve_request()` emits it unconditionally, before
     * dispatch and regardless of Origin. It is inert without an ACAO, but
     * leaving it behind means a denied response still carries an
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

    /** Guards against double registration — two registrations would emit
     *  duplicate CORS headers, which is itself a spec violation. */
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // Preflight OPTIONS — short-circuit before WP routing.
        add_action('init', [self::class, 'handlePreflight'], 1);

        // Take core OUT of the CORS business entirely.
        add_action(
            'rest_api_init',
            [self::class, 'removeCoreCorsHeaders'],
            self::CORE_CORS_REMOVAL_PRIORITY
        );

        // Actual REST response. LAST on the hook, so anything another
        // plugin emits is still stripped even though core no longer does.
        add_filter(
            'rest_pre_serve_request',
            [self::class, 'sendCorsHeaders'],
            self::FINAL_AUTHORITY_PRIORITY,
            3
        );
    }

    /**
     * `rest_api_init` callback. An action callback must return nothing, so
     * the removal's result — which is worth asserting on — lives on
     * {@see self::detachCoreCorsEmitter()} instead of being swallowed here.
     */
    public static function removeCoreCorsHeaders(): void
    {
        self::detachCoreCorsEmitter();
    }

    /**
     * Detach WordPress core's REST CORS emitter.
     *
     * Deliberately narrow: this removes exactly one callback from exactly
     * one hook at exactly one priority. `rest_handle_options_request` and
     * every other core REST filter are left alone — only the header
     * emitter goes.
     *
     * @return bool True when the callback was present and removed. False
     *              means core's filter was not registered at the expected
     *              priority, which is a contract change worth failing a
     *              test over.
     */
    public static function detachCoreCorsEmitter(): bool
    {
        return remove_filter(
            'rest_pre_serve_request',
            'rest_send_cors_headers',
            self::CORE_CORS_PRIORITY
        );
    }

    /** Test seam: forget that register() ran. */
    public static function resetRegistrationForTests(): void
    {
        self::$registered = false;
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
        if (!self::ownsRoute(self::routeFromRequestUri($uri))) {
            return;
        }

        // Allowed or denied, the answer is decided here — a denied
        // preflight terminates with 204 and NO Access-Control-* headers
        // rather than falling through to WP.
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
        if (!self::ownsRoute(self::routeFor($result, $request))) {
            return $served;
        }

        self::applyPolicy();

        return $served;
    }

    /**
     * Which REST routes this handler is the CORS authority for: ALL of them.
     *
     * This used to be `isBccRoute()` — a substring test for the two BCC
     * namespaces. That was wrong twice over:
     *
     *   1. Scope. Core's `rest_send_cors_headers` reflected ANY Origin with
     *      credentials on EVERY REST route, so leaving `/wp-json/` and
     *      `/wp/v2/*` to "core's behaviour" left the hole open on exactly
     *      the routes no BCC code guarded. Verified on production
     *      2026-08-19: `evil.example.com` was echoed back from
     *      `/wp-json/wp/v2/types`.
     *   2. Coupling. An earlier draft of this fix delegated the gate to
     *      `EdgeCache::appliesTo()`, i.e. let a CACHE policy decide a
     *      SECURITY boundary. A future narrowing of the cache exclusion
     *      would then silently re-open CORS. The two answers coincide
     *      today; they are not the same question, so they do not share a
     *      predicate. This one is deliberately standalone.
     *
     * The empty string is the one thing out of scope, and it means "no REST
     * route could be resolved from this request". On `rest_pre_serve_request`
     * that never happens — `WP_REST_Request::get_route()` is populated even
     * for 404s and for the index. On the `init` preflight hook it is how an
     * ordinary page request looks, which is what keeps non-REST OPTIONS
     * traffic on WordPress's own handling.
     */
    private static function ownsRoute(string $route): bool
    {
        return $route !== '';
    }

    /**
     * The one CORS decision. Strip everything we own, then either emit the
     * full allowed set for an allowlisted origin, or emit nothing.
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
     * (`/?rest_route=/bcc/v1/x`). Returns `''` when the URI is not a REST
     * request at all.
     *
     * Deliberately permissive about WHERE the REST prefix sits in the path
     * (sub-directory installs put it after the site path). The only cost of
     * a false positive is that an OPTIONS request to a non-REST URL that
     * happens to contain `/wp-json/` answers 204 — no data crosses. A false
     * NEGATIVE, by contrast, is what left the namespace index on core's
     * reflected headers, so err permissive here.
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
     * Resolve the request's Origin against the BCC_FRONTEND_ORIGIN
     * allowlist. Returns the matched origin to echo back, or null
     * when no match (or no allowlist configured).
     *
     * The allowlist grammar (exact origins vs `regex:` patterns) and
     * the exact-then-regex resolution order are owned by
     * {@see FrontendOrigin} — the single parser of the constant. Do not
     * re-implement the split here: CORS is the one consumer that may
     * consider regex entries at all, and keeping the rules in one place
     * is what stops it drifting from the JWT-audience and redirect
     * consumers, which must only ever see concrete origins.
     */
    private static function resolveAllowedOrigin(): ?string
    {
        if (!FrontendOrigin::isConfigured()) {
            return null;
        }

        $requestOrigin = self::requestOrigin();
        if ($requestOrigin === null) {
            return null;
        }

        // Exact-then-regex resolution lives in FrontendOrigin so CORS and
        // the JWT/redirect consumers can never disagree about what the
        // allowlist means — the divergence this class alone got right.
        return FrontendOrigin::match($requestOrigin);
    }
}
