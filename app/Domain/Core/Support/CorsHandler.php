<?php
/**
 * CorsHandler — the FINAL CORS authority for EVERY WordPress REST route.
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
 * Three entry forms are recognised:
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
 *   3. A regular-expression rule, `regex:<pattern>`. The escape hatch for
 *      an origin shape the two structural forms cannot express. Prefer
 *      form 2 for Vercel — it needs no pattern review. See
 *      {@see self::compileRegexRule()} for the full rule set; the short
 *      version is that the pattern is ALWAYS wrapped in `^(?:…)$`, always
 *      matched case-SENSITIVELY, and always matched against the
 *      canonical origin rather than the raw header.
 *
 * ## The `regex:` form, and the two defects it used to have
 *
 * This form was withdrawn in the 2026-08 hardening pass and reinstated
 * immediately afterwards (owner ruling: keep it, a rule may be needed
 * later). Reinstated does NOT mean restored — the original had two live
 * defects, and the current implementation exists to make both
 * unrepresentable:
 *
 *   1. It matched with `/i` against the RAW `Origin` bytes and then echoed
 *      those raw bytes back, so `HTTPS://BCC-FRONTEND-…VERCEL.APP` was
 *      allowed and reflected verbatim. Now the origin must first survive
 *      {@see self::canonicalizeOrigin()} — the same structural gate every
 *      other entry form goes through, which is the only thing standing
 *      between the allowlist and a path, query, fragment, userinfo, port,
 *      control character or percent-escape. The pattern then matches the
 *      CANONICAL, lowercased origin, and the canonical form is what is
 *      echoed. A `regex:` entry is therefore not a way around any reject
 *      rule; it can only ever select from among origins that already
 *      passed. `/i` is gone: the subject is lowercase by construction, so
 *      the flag could only ever widen the surface.
 *
 *   2. Its `[a-z0-9]+` body could not span a hyphen, so no `git-*` branch
 *      preview ever matched. That is a config-authoring problem rather
 *      than a code one; form 2 now covers previews structurally, which is
 *      why it is the recommended answer.
 *
 * ⚠ A `regex:` pattern MAY NOT CONTAIN A COMMA. The allowlist splits on
 * commas before anything else looks at an entry, so a counted quantifier
 * like `[a-z0-9]{6,32}` is torn into `regex:…[a-z0-9]{6` and `32}…`. Both
 * halves then fail closed — the first will not compile, the second is not
 * a valid origin — so the symptom is a rule that silently never matches,
 * not a security hole. Use `[a-z0-9][a-z0-9]*` or form 2 instead.
 *
 * ⚠ A `regex:` entry MUST NOT BE FIRST. Three other consumers of
 * `BCC_FRONTEND_ORIGIN` read the first entry as the canonical frontend
 * origin and would build links out of the rule string. Append, never
 * prepend. Same constraint as the `vercel:` form; see
 * docs/domain-cutover-runbook.md.
 *
 * ⚠⚠ AND "not first" IS NOT SUFFICIENT. A pattern that BEGINS with `//`
 * is refused outright ({@see self::compileRegexRule()}), because of a
 * neighbouring consumer rather than anything CORS does:
 *
 *   - {@see FrontendRedirect::allowedHosts()} runs `wp_parse_url()` over
 *     EVERY entry and keeps whatever `host` falls out. `regex:` parses as
 *     a URL scheme, so in `regex://evil.com` the `//` reads as the start
 *     of an authority and yields the host `evil.com` — which lands on the
 *     allowlist {@see FrontendRedirect::validateReturnTo()} uses to
 *     approve OAuth `return_to` targets (GitHubController, XController).
 *     That is an open redirect and a token-leak path. The pattern itself
 *     is inert for CORS (no canonical origin starts with `//`), so the
 *     damage would be entirely in the other consumer and no CORS test
 *     would ever see it. Refusing the shape here is the cheap half of the
 *     fix; it costs nothing, since such a pattern can never match.
 *   - Only a LEADING `//` does this. `regex:^https://…` and
 *     `regex:https://…` both parse to no host at all, because
 *     `wp_parse_url` only reads an authority when `//` directly follows
 *     the scheme colon. The ordinary anchored pattern is therefore safe.
 *   - {@see JwtToken::audienceAllowlist()} adds every entry verbatim to
 *     the acceptable `aud` set. Inert — an attacker cannot sign a token —
 *     but it means a rule string is a valid audience, which is noise in
 *     any audit of that list.
 *
 * Both of those parsers predate the prefixed entry forms and neither
 * strips a prefix. Unifying all four readers behind one parser is the
 * real fix; it is tracked in docs/domain-cutover-runbook.md and is NOT
 * done here.
 *
 * Configuration example (wp-config.php):
 *
 *   define('BCC_FRONTEND_ORIGIN',
 *       'https://bluecollarcrypto.io' .
 *       ',https://staging.bluecollarcrypto.io' .
 *       ',vercel:bcc-frontend:phillip-simon-s-projects' .
 *       ',regex:https://bcc-frontend-[a-z0-9-]+\.internal\.example\.net'
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

    /**
     * Allowlist entry prefix for a hardened regular-expression rule.
     *
     * Supported again as of 2026-08 (owner ruling), on the terms set out in
     * {@see self::compileRegexRule()}. It is the LAST resort — the exact and
     * `vercel:` forms need no pattern review, so reach for those first.
     */
    private const REGEX_ENTRY_PREFIX = 'regex:';

    /**
     * PCRE delimiter for `regex:` rules, and therefore a byte a pattern may
     * not contain.
     *
     * `/` is unusable — every origin pattern contains `https://`, and forcing
     * operators to write `https:\/\/` is exactly the kind of papercut that
     * produces a pattern nobody re-reads. `#` cannot appear in a canonical
     * origin either (a fragment is a reject rule in
     * {@see self::canonicalizeOrigin()}), so nothing legitimate needs it and
     * refusing it outright is free.
     */
    private const REGEX_DELIMITER = '#';

    /**
     * Hard cap on a `regex:` pattern, in bytes.
     *
     * Half of the backtracking bound. The other half is the 255-byte cap
     * `canonicalizeOrigin()` already puts on the SUBJECT. Measured at both
     * caps, the nastiest classic ReDoS shapes (`(a|a)+$`, `(\w|\w\w)+$`)
     * exhaust `pcre.backtrack_limit` in ~3.5 ms and return `false`, which
     * {@see self::tryMatch()} turns into a denial — so the worst case is a
     * few wasted milliseconds and a closed door, not a hung worker.
     *
     * 200 is ~3× the longest realistic rule (the documented Vercel-shaped
     * pattern is 70 bytes).
     */
    private const MAX_REGEX_PATTERN_LENGTH = 200;

    /**
     * Negative canary. Every configured pattern is probed against it, and any
     * pattern that MATCHES it is discarded.
     *
     * This rejects total catch-alls — `regex:.*`, `regex:.+`, `regex:[\s\S]*`
     * — which are never a deliberate allowlist and are the single most likely
     * way this entry form gets misused (someone pastes `.*` while debugging
     * CORS and it ships). `.invalid` is reserved by RFC 2606 and can never be
     * registered, so no honest rule has any reason to match it.
     *
     * ⚠ Be precise about what this does NOT do. It catches TOTALITY, not
     * BREADTH. `regex:^https://.*\.vercel\.app$` does not match the canary
     * and is accepted, even though it hands CORS to every Vercel deployment
     * on the internet. That remains operator rope, deliberately: a pattern
     * broad enough to be dangerous but narrow enough to look intentional
     * cannot be told apart from a legitimate one by any local check. Pattern
     * review at config time is the control for breadth; this is only a
     * backstop for the degenerate case.
     */
    private const REGEX_TOTALITY_CANARY = 'https://cors-totality-canary.invalid';

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

    /** Guards against double registration if register() is called twice. */
    private static bool $registered = false;

    public static function register(): void
    {
        // Idempotent: two registrations would emit duplicate CORS headers,
        // which is itself a spec violation.
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // Preflight OPTIONS — short-circuit before WP routing.
        add_action('init', [self::class, 'handlePreflight'], 1);

        // Take core OUT of the CORS business entirely, rather than racing
        // it and cleaning up afterwards. Core's version reflects ANY
        // Origin with `Access-Control-Allow-Credentials: true` on EVERY
        // REST route — including `/wp-json/` and `/wp/v2/*`, which no BCC
        // handler used to touch. Verified on production 2026-08-19:
        // `evil.example.com` was echoed back with credentials.
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
    public static function removeCoreCorsHeaders(): bool
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
        if (!self::ownsRoute(self::routeFor($result, $request))) {
            return $served;
        }

        self::applyPolicy();

        return $served;
    }

    /**
     * Which REST routes this handler is the CORS authority for: ALL of them.
     *
     * This used to delegate to `EdgeCache::appliesTo()`, which at the time
     * meant "the two BCC namespaces". That was wrong twice over:
     *
     *   1. Scope. Core's `rest_send_cors_headers` reflected ANY Origin with
     *      credentials on EVERY REST route, so leaving `/wp-json/` and
     *      `/wp/v2/*` to "core's behaviour" left the hole open on exactly
     *      the routes no BCC code guarded. Verified on production
     *      2026-08-19: `evil.example.com` was echoed back from
     *      `/wp-json/wp/v2/types`.
     *   2. Coupling. Borrowing a CACHE predicate to decide a SECURITY
     *      boundary means a future narrowing of the cache exclusion would
     *      silently re-open CORS. The two answers coincide today; they are
     *      not the same question, so they no longer share a predicate.
     *
     * No non-REST guard is needed: this is only ever reached from
     * `rest_pre_serve_request`, which WordPress fires solely while serving
     * a REST request. Ordinary page requests never enter here — including
     * the `?rest_route=` form, which IS a REST request and is normalised
     * upstream by `routeFromRequestUri()`.
     *
     * Kept as a named predicate rather than deleting the branch so the
     * ownership claim stays greppable and testable.
     */
    private static function ownsRoute(string $route): bool
    {
        unset($route);

        return true;
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
     * Test the CANONICAL candidate against every configured entry.
     *
     * ⚠ Structural invariant, load-bearing for the `regex:` form: the only
     * caller is {@see self::resolveAllowedOrigin()}, which has already run
     * {@see self::canonicalizeOrigin()} and bailed on null. Every `$candidate`
     * reaching here has therefore survived the full reject list, and
     * `$candidate['canonical']` is lowercased and normalised. No entry form —
     * least of all a pattern out of configuration — may be given the raw
     * header instead. Route a new entry form through `$candidate`, never
     * through `self::requestOrigin()`.
     *
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

            if (str_starts_with($entry, self::REGEX_ENTRY_PREFIX)) {
                $pattern = substr($entry, strlen(self::REGEX_ENTRY_PREFIX));
                if (self::matchesRegexRule($candidate, $pattern)) {
                    return true;
                }
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

    /**
     * Match a `regex:<pattern>` rule.
     *
     * The subject is `$candidate['canonical']` and nothing else. That string
     * is lowercase, has no path/query/fragment/userinfo, has a validated LDH
     * host and a validated port, and is byte-identical to what
     * {@see self::applyPolicy()} will echo in `Access-Control-Allow-Origin`
     * if this returns true. Matching what we echo, rather than what the
     * caller sent, is the property that makes reflecting attacker-chosen
     * bytes structurally impossible — it was the original defect in this
     * entry form and must not be reintroduced.
     *
     * @param array{scheme: string, host: string, port: int|null, canonical: string} $candidate
     */
    private static function matchesRegexRule(array $candidate, string $pattern): bool
    {
        $regex = self::compileRegexRule($pattern);
        if ($regex === null) {
            return false;
        }

        return self::tryMatch($regex, $candidate['canonical']) === true;
    }

    /**
     * Validate a configured pattern and wrap it into the PCRE we will
     * actually run, or return null to discard the entry.
     *
     * Everything here fails CLOSED: a rejected entry is simply skipped, so a
     * bad pattern costs its own rule and cannot widen any other. In
     * particular a non-compiling pattern never makes the allowlist
     * permissive — the remaining entries are evaluated exactly as before.
     *
     * ## Always anchored, never anchor-CHECKED
     *
     * The pattern is unconditionally wrapped in `^(?:…)$` (plus the `D`
     * modifier, so `$` cannot match before a trailing newline). It is NOT
     * inspected for `^` and `$` and rejected when they are missing.
     *
     * That is a deliberate choice between the two options, and the reason is
     * that an anchor-PRESENCE test is not an anchor GUARANTEE. Consider:
     *
     *     regex:^https://bluecollarcrypto\.io|evil
     *
     * It contains a `^`, so a presence check waves it through — and then it
     * matches `https://evil.com`, because alternation binds looser than the
     * anchor and the second branch is a bare substring. That is a live
     * false-allow produced by a rule that LOOKS anchored. Wrapping instead of
     * checking makes the whole class unrepresentable: whatever the operator
     * wrote becomes one alternation group that must consume the entire
     * subject. The non-capturing group is what makes it safe for alternation
     * — `^https://a|https://b$` without the group is not anchored on either
     * branch.
     *
     * Wrapping is also idempotent for the patterns people actually write: the
     * documented, already-anchored Vercel-shaped rule matches identically
     * before and after, because `^` and `$` are zero-width assertions that
     * re-assert harmlessly at the positions the wrapper pins them to.
     *
     * The cost of wrapping over rejecting is that an operator who writes an
     * unanchored substring rule gets a rule that matches nothing rather than
     * a rule that is refused. Both are fail-closed; wrapping additionally
     * does the right thing for the operator who wrote a bare alternation, and
     * never has to explain a rejection whose cause is a regex subtlety.
     *
     * ## Case-sensitive
     *
     * No `/i`. The subject is lowercased by `canonicalizeOrigin()`, so `i`
     * cannot make a legitimate rule match anything extra — it can only widen
     * the rule to accept uppercase forms of hosts the operator did not think
     * about. The original implementation used `/i` against the raw header and
     * that is precisely how `HTTPS://BCC-FRONTEND-…` got allowed.
     *
     * @return string|null the delimited PCRE, or null when the entry is unusable
     */
    private static function compileRegexRule(string $pattern): ?string
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_REGEX_PATTERN_LENGTH) {
            return null;
        }

        // Printable ASCII only — the same charset canonicalizeOrigin() demands
        // of the subject. A pattern carrying a control character, a NUL or a
        // non-ASCII byte cannot match a canonical origin anyway, so refusing
        // it loses nothing and keeps the two charsets in step.
        if (preg_match('/[^\x21-\x7e]/', $pattern) === 1) {
            return null;
        }

        if (str_contains($pattern, self::REGEX_DELIMITER)) {
            return null;
        }

        // A pattern starting with `//` makes `regex://evil.com` parse as a
        // URL with the authority `evil.com`, which FrontendRedirect then
        // trusts as an OAuth return-URL host. Inert here — no canonical
        // origin begins with `//`, so the rule could never match anyway —
        // which is exactly why refusing it is free. See the class docblock.
        if (str_starts_with($pattern, '//')) {
            return null;
        }

        $regex = self::REGEX_DELIMITER . '^(?:' . $pattern . ')$' . self::REGEX_DELIMITER . 'D';

        // One probe, two guarantees. `null` means PCRE refused the pattern
        // outright (a compile error, e.g. an unbalanced bracket) — discard it.
        // `true` means the pattern matches a host that must never be allowed,
        // i.e. it is a total catch-all — discard that too. Only a pattern that
        // compiles AND correctly declines the canary is handed back.
        return self::tryMatch($regex, self::REGEX_TOTALITY_CANARY) === false ? $regex : null;
    }

    /**
     * `preg_match` with every failure mode collapsed into a single null.
     *
     * A `regex:` pattern is configuration, so it can be malformed, and its
     * subject is attacker-influenced, so matching can blow the backtrack
     * limit. PHP reports the first as an `E_WARNING` plus a `false` return
     * and the second as a `false` return with `preg_last_error()` set; both
     * must fail closed.
     *
     * The local error handler is not decoration. Without it a single
     * mistyped entry emits a warning into the site's error log on EVERY
     * request that carries an `Origin` — a log flood that is far more likely
     * to take a site down than the misconfiguration itself. `@` is not
     * sufficient: a custom handler installed by a debug plugin (or by
     * PHPUnit) is invoked regardless of the suppression operator.
     *
     * @return bool|null true = matched, false = did not match, null = PCRE refused
     */
    private static function tryMatch(string $regex, string $subject): ?bool
    {
        $refused = false;

        set_error_handler(static function (int $errno, string $errstr) use (&$refused): bool {
            unset($errno, $errstr);
            $refused = true;

            return true;
        });

        try {
            $result = preg_match($regex, $subject);
        } finally {
            restore_error_handler();
        }

        if ($refused || $result === false || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        return $result === 1;
    }
}
