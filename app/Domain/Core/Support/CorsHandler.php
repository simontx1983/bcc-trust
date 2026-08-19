<?php
/**
 * CorsHandler — CORS for /bcc/v1/* (and /bcc-trust/v1/*) REST routes.
 *
 * Allowlist sourced from the BCC_FRONTEND_ORIGIN constant. Comma-
 * separated for multi-environment (e.g. staging + prod). When the
 * constant is undefined or empty, NO CORS headers are emitted —
 * effectively same-origin only. That keeps the backend safe by
 * default; opting in is explicit.
 *
 * Origin matching supports two modes per entry:
 *   - Exact string (scheme + host + optional port) — the default.
 *   - Regex pattern prefixed with "regex:" — matched case-insensitively
 *     against the request Origin. Use for Vercel preview URLs whose
 *     per-deployment hash changes on every push.
 *
 * Two hook points:
 *
 *   1. `init` priority 1 — preflight OPTIONS short-circuit. Fires
 *      BEFORE WP routing so the OPTIONS request never reaches the
 *      REST stack. Returns 204 + CORS headers + exits.
 *
 *   2. `rest_pre_serve_request` — actual response. Runs AFTER WP's
 *      built-in `rest_send_cors_headers` (which sends a baseline
 *      `Access-Control-Allow-Origin: <origin>` without credentials).
 *      Our handler re-emits with `Access-Control-Allow-Credentials: true`
 *      for the BCC routes. PHP's `header()` defaults to replace=true,
 *      so the second emission wins.
 *
 * Browsers reject duplicate `Access-Control-Allow-*` values, so
 * relying on header replacement (not addition) is critical here.
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
    /** Browser preflight cache window (seconds). 600 = 10 minutes. */
    private const PREFLIGHT_MAX_AGE = 600;

    /** Methods accepted across BCC routes. Mirrors what register_rest_route declares. */
    private const ALLOWED_METHODS = 'GET, POST, PATCH, PUT, DELETE, OPTIONS';

    /** Headers the frontend is allowed to send on a credentialed CORS request.
     *  `Sentry-Trace` + `Baggage` are injected by `@sentry/nextjs` for distributed
     *  tracing on requests matching `tracePropagationTargets` in
     *  `bcc-frontend/src/instrumentation-client.ts`. They are not "simple" CORS
     *  headers, so the browser issues a preflight and blocks the request when
     *  they are not on the allowlist — surfacing as "Failed to fetch" with no
     *  further detail. */
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, X-WP-Nonce, X-Requested-With, Sentry-Trace, Baggage';

    public static function register(): void
    {
        // Preflight OPTIONS for /bcc/v1/* — short-circuit before WP routing.
        add_action('init', [self::class, 'handlePreflight'], 1);

        // Actual REST response — override WP's default (no-credentials)
        // CORS headers with our credentialed allowlist for BCC routes.
        add_filter('rest_pre_serve_request', [self::class, 'sendCorsHeaders'], 10, 2);
    }

    public static function handlePreflight(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS') {
            return;
        }

        $route = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (!self::isBccRoute($route)) {
            return;
        }

        $origin = self::resolveAllowedOrigin();
        if ($origin === null) {
            // Origin not allowlisted → fall through; WP returns 404
            // (no OPTIONS handler) which is what we want for unauthorized origins.
            return;
        }

        self::emitHeaders($origin);

        // Don't let edge / reverse-proxy caches (LiteSpeed, Cloudflare,
        // Varnish) store the preflight. They cache by URL including the
        // query string, so a single stale entry made before BCC_FRONTEND_ORIGIN
        // was configured (or before this plugin loaded) would lock out every
        // subsequent OPTIONS to that exact URL with a no-CORS response.
        // Access-Control-Max-Age above is the BROWSER preflight cache
        // (different layer); this is the SERVER-side cache prevention.
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
     * Hooked on `rest_pre_serve_request`. Receives 2 args from WP:
     * the served-flag and the WP_REST_Response. We don't mutate either —
     * we just use the route from the response to gate header emission.
     *
     * @param  bool                   $served whether the request has already been served
     * @param  \WP_HTTP_Response|null $result the response object (carries the route)
     * @return bool                           pass-through (we never short-circuit serving)
     */
    public static function sendCorsHeaders(bool $served, $result = null): bool
    {
        $route = '';
        if ($result instanceof \WP_REST_Response) {
            $matched = $result->get_matched_route();
            if (is_string($matched) && $matched !== '') {
                $route = $matched;
            }
        }
        if ($route === '') {
            $route = (string) ($_SERVER['REQUEST_URI'] ?? '');
        }

        if (!self::isBccRoute($route)) {
            return $served;
        }

        $origin = self::resolveAllowedOrigin();
        if ($origin !== null) {
            self::emitHeaders($origin);
        }

        return $served;
    }

    private static function isBccRoute(string $route): bool
    {
        return str_contains($route, '/bcc/v1/') || str_contains($route, '/bcc-trust/v1/');
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

        $requestOrigin = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])
            ? trim($_SERVER['HTTP_ORIGIN'])
            : '';
        if ($requestOrigin === '') {
            return null;
        }

        // Exact-then-regex resolution lives in FrontendOrigin so CORS and
        // the JWT/redirect consumers can never disagree about what the
        // allowlist means — the divergence this class alone got right.
        return FrontendOrigin::match($requestOrigin);
    }

    private static function emitHeaders(string $origin): void
    {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: ' . self::ALLOWED_METHODS);
        header('Access-Control-Allow-Headers: ' . self::ALLOWED_HEADERS);
        header('Access-Control-Allow-Credentials: true');
        // Phase 4c: let the cross-origin frontend READ the correlation id off
        // the response (X-Request-Id, emitted by Envelope) so a frontend error
        // can be tied back to the server logs. Exposed (readable), not allowed
        // (inbound) — the client does not send it, avoiding a CORS preflight on
        // otherwise-simple anonymous GETs.
        header('Access-Control-Expose-Headers: X-Request-Id');
        header('Access-Control-Max-Age: ' . self::PREFLIGHT_MAX_AGE);
        header('Vary: Origin');
    }
}
