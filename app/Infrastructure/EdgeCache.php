<?php

/**
 * Bridge to the LiteSpeed edge cache via LSCWP's public API actions.
 *
 * The anonymous REST tier is edge-cached by LiteSpeed under LSCWP's
 * `cache-ttl_rest` server config — NOT the `Cache-Control` header the
 * endpoints emit, which only governs browsers. Responses that must stay
 * lifecycle-correct therefore (a) pin their own edge TTL and tag
 * themselves while being generated, and (b) get purged by tag when the
 * underlying lifecycle event fires (user delete, suspend/unsuspend).
 *
 * Every method is a plain do_action against hooks LSCWP registers in
 * src/api.cls.php — a silent no-op wherever LSCWP is not active (Local
 * by Flywheel, CI, non-LiteSpeed hosts), so callers never need to guard.
 * Tag strings are passed raw; LSCWP applies its own blog prefix on both
 * the tag-add and the purge side, so they always match.
 *
 * POLICY (owner-directed, 2026-08-06; widened 2026-08-19): EVERY REST
 * response is EXCLUDED from the LiteSpeed edge cache (init() →
 * rest_pre_dispatch → noCache()). The edge cache keys REST entries by URL
 * without varying on the Origin header, so an entry primed by an
 * Origin-less request (curl, monitor, SSR) was served to browsers WITHOUT
 * Access-Control-Allow-Origin for the rest of its TTL — up to a week
 * under the prod `cache-ttl_rest` — presenting as intermittent CORS
 * failures. Correctness beats the edge win here. tag()/TTL_SECONDS are
 * retained for the purge-tag plumbing but are inert while the blanket
 * exclusion is active; revisit if a per-Origin cache-vary lands at the
 * server layer.
 *
 * 2026-08-19 widening: the exclusion originally covered only `/bcc/v1`
 * and `/bcc-trust/v1`. That was too narrow — WordPress core attaches
 * `Access-Control-Allow-Origin` to EVERY REST response, so core routes
 * carry the same Origin-variant payload. Reproduced on production: a
 * request to `/wp-json/` sent with one Origin came back holding a
 * STORED `Access-Control-Allow-Origin` naming a different origin. One
 * caller's CORS grant was being replayed to another. The exclusion now
 * covers every REST route, and `hardenSharedCaching()` adds `Vary:
 * Origin` plus a `public`→`private` downgrade for non-LiteSpeed shared
 * caches. Ordinary (non-REST) page caching is untouched.
 *
 * @package BCC\Trust\Infrastructure
 */

declare(strict_types=1);

namespace BCC\Trust\Infrastructure;

if (!defined('ABSPATH')) {
    exit;
}

final class EdgeCache
{
    /** Tag attached to every member-directory (`/members`) response. */
    public const TAG_MEMBERS = 'bcc_members';

    /**
     * Edge TTL for tagged responses, matching the `max-age=15` the list
     * endpoints already declare to browsers. Bounds worst-case staleness
     * for anything a purge hook misses.
     */
    private const TTL_SECONDS = 15;

    /**
     * Register the blanket REST exclusion. rest_pre_dispatch runs for
     * every REST request before the handler; the filter passes $result
     * through untouched — its only job is flagging LSCWP while the
     * response is being generated.
     */
    public static function init(): void
    {
        add_filter('rest_pre_dispatch', static function ($result, $server, $request) {
            if ($request instanceof \WP_REST_Request && self::appliesTo($request->get_route())) {
                self::noCache();
            }
            return $result;
        }, 10, 3);

        // Defence in depth for shared caches that are NOT LiteSpeed (the
        // Hostinger CDN layer, any future reverse proxy). Runs last so it
        // sees whatever Cache-Control the endpoint chose.
        add_filter('rest_post_dispatch', [self::class, 'hardenSharedCaching'], PHP_INT_MAX, 3);
    }

    /**
     * Whether a REST route falls under the edge-cache exclusion.
     *
     * EVERY REST route, not just the BCC namespaces. Narrowing this to
     * `/bcc/v1` + `/bcc-trust/v1` was the bug: WordPress core's
     * `rest_send_cors_headers` attaches `Access-Control-Allow-Origin` to
     * EVERY REST response, so core routes are exactly as Origin-variant
     * as BCC ones — but they stayed cacheable. Reproduced 2026-08-19 on
     * production: a request to `/wp-json/` carrying one Origin was served
     * a stored `Access-Control-Allow-Origin` belonging to a DIFFERENT
     * origin, i.e. one caller's CORS grant replayed to another.
     *
     * Kept as a predicate rather than inlined `true` so the contract stays
     * unit-testable and any future re-narrowing has to delete a test.
     *
     * Non-REST pages never reach here — `rest_pre_dispatch` is REST-only —
     * so ordinary page caching is untouched.
     */
    public static function appliesTo(string $route): bool
    {
        unset($route);

        return true;
    }

    /**
     * Make REST responses unstorable by SHARED caches without destroying
     * the per-browser caching the endpoints deliberately declare.
     *
     * `Vary: Origin` is merged, never clobbered — a response that already
     * varies on something else keeps it. Vary alone is not sufficient
     * (the live replay happened despite it), which is why the LiteSpeed
     * `no-cache` control above is the primary mechanism; this is the
     * belt to that pair of braces.
     *
     * When the request carried an `Origin`, the response is Origin-variant
     * and must not sit in a shared cache. Rather than forcing `no-store`
     * — which would throw away the intentional `max-age` /
     * `stale-while-revalidate` on the feed and list endpoints — `public`
     * is downgraded to `private`. Shared caches must not store it;
     * browsers still cache it per-user for the same TTL.
     *
     * @param  mixed $response
     * @param  mixed $server
     * @param  mixed $request
     * @return mixed
     */
    public static function hardenSharedCaching($response, $server, $request)
    {
        unset($server);

        if (!$response instanceof \WP_REST_Response) {
            return $response;
        }

        $headers = $response->get_headers();

        $vary = isset($headers['Vary']) ? (string) $headers['Vary'] : '';
        if ($vary === '') {
            $response->header('Vary', 'Origin');
        } elseif (!preg_match('/(^|,)\s*origin\s*(,|$)/i', $vary)) {
            $response->header('Vary', $vary . ', Origin');
        }

        $hasOrigin = $request instanceof \WP_REST_Request
            && is_string($request->get_header('origin'))
            && $request->get_header('origin') !== '';

        if (!$hasOrigin) {
            return $response;
        }

        $cacheControl = isset($headers['Cache-Control']) ? (string) $headers['Cache-Control'] : '';
        if ($cacheControl === '') {
            $response->header('Cache-Control', 'private, no-store');

            return $response;
        }
        if (stripos($cacheControl, 'private') !== false || stripos($cacheControl, 'no-store') !== false) {
            return $response;
        }

        $downgraded = preg_replace('/\bpublic\b/i', 'private', $cacheControl, 1);
        $response->header(
            'Cache-Control',
            is_string($downgraded) && $downgraded !== $cacheControl
                ? $downgraded
                : 'private, ' . $cacheControl
        );

        return $response;
    }

    /**
     * Flag the in-flight response as never-edge-cacheable. Beats any
     * TTL set elsewhere (LSCWP treats no-cache as terminal for the
     * request).
     */
    public static function noCache(): void
    {
        do_action('litespeed_control_set_nocache', 'bcc REST excluded from edge cache (Origin-variant CORS hazard)');
    }

    /**
     * Purge the ENTIRE LiteSpeed cache for this site. Deliberately
     * blunt: used by the one-shot rollout migration so week-old REST
     * entries (cached pre-exclusion, possibly without CORS headers)
     * die at deploy instead of aging out.
     */
    public static function purgeAll(): void
    {
        do_action('litespeed_purge_all');
    }

    /**
     * Mark the response currently being generated: bounded edge TTL plus
     * a purge tag. Must run during the request that produces the
     * cacheable response — LSCWP attaches both to the entry it stores.
     * (Inert while the blanket REST exclusion above is active.)
     */
    public static function tag(string $tag): void
    {
        do_action('litespeed_control_set_ttl', self::TTL_SECONDS);
        do_action('litespeed_tag_add', $tag);
    }

    /** Purge every edge entry carrying the tag. */
    public static function purge(string $tag): void
    {
        do_action('litespeed_purge', $tag);
    }

    /**
     * Purge a single origin URL from the edge. Best-effort: LSCWP keys
     * URL purges on the exact request URL, so query-string variants of
     * the same route are only caught by their TTL.
     */
    public static function purgeUrl(string $url): void
    {
        do_action('litespeed_purge_url', $url);
    }
}
