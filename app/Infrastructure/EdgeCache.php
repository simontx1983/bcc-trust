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
     * Mark the response currently being generated: bounded edge TTL plus
     * a purge tag. Must run during the request that produces the
     * cacheable response — LSCWP attaches both to the entry it stores.
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
