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
 * POLICY (owner-directed, 2026-08-06): every BCC REST response is now
 * EXCLUDED from the LiteSpeed edge cache (init() → rest_pre_dispatch →
 * noCache()). The edge cache keys REST entries by URL without varying
 * on the Origin header, so an entry primed by an Origin-less request
 * (curl, monitor, SSR) was served to browsers WITHOUT
 * Access-Control-Allow-Origin for the rest of its TTL — up to a week
 * under the prod `cache-ttl_rest` — presenting as intermittent CORS
 * failures. Correctness beats the edge win here. tag()/TTL_SECONDS are
 * retained for the purge-tag plumbing but are inert while the blanket
 * exclusion is active; revisit if a per-Origin cache-vary lands at the
 * server layer.
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
    }

    /**
     * Whether a REST route falls under the BCC edge-cache exclusion.
     * Pure predicate (unit-tested) — both BCC namespaces, prefix or
     * exact match.
     */
    public static function appliesTo(string $route): bool
    {
        foreach (['/bcc/v1', '/bcc-trust/v1'] as $ns) {
            if ($route === $ns || strncmp($route, $ns . '/', strlen($ns) + 1) === 0) {
                return true;
            }
        }
        return false;
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
