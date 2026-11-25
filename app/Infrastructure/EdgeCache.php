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
 * POLICY 2 (2026-08-20): a CREDENTIALED request is excluded on every
 * route, not just the BCC namespaces — see
 * {@see EdgeCache::isCredentialed()}. Route scoping alone left a gap,
 * because BearerAuth authenticates globally while LSCWP decides
 * "logged in" from cookies, and this frontend carries JWTs instead.
 *
 * @package BCC\Trust\Infrastructure
 */

declare(strict_types=1);

namespace BCC\Trust\Infrastructure;

use BCC\Trust\Core\Support\BearerAuth;

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
            if ($request instanceof \WP_REST_Request && self::shouldExclude($request->get_route())) {
                self::noCache();
            }
            return $result;
        }, 10, 3);
    }

    /**
     * Whether the in-flight REST response must be kept out of the edge
     * cache — either because of WHERE it is (a BCC route) or because of
     * WHO it is for (a credentialed request).
     */
    public static function shouldExclude(string $route): bool
    {
        return self::appliesTo($route) || self::isCredentialed();
    }

    /**
     * Whether this request carries credentials, and so must never be
     * stored by a cache that does not key on them.
     *
     * ## Why route-scoping alone was not enough
     *
     * `appliesTo()` covers the two BCC namespaces, but
     * {@see BearerAuth} hooks `determine_current_user` GLOBALLY (priority
     * 30) — so a JWT authenticates on *any* route, including PeepSo's.
     * Verified on production: `/wp-json/peepso/v1/post` returns 200 and
     * LiteSpeed marks it `public,max-age=...`, while `/wp/v2/*` is
     * `no-cache` because WP core sends its own nocache headers.
     *
     * The trap is that LSCWP decides "is this visitor logged in?" from
     * COOKIES. This frontend authenticates with `Authorization: Bearer`
     * and sets no cookie, so a fully authenticated request looks
     * anonymous to the cache: its personalized response gets stored and
     * replayed to whoever asks next. Cookie sessions were never exposed
     * — LSCWP already excludes those — which is exactly why the gap was
     * invisible.
     *
     * `is_user_logged_in()` is checked too, as belt-and-braces for any
     * auth path that resolves a user without an Authorization header.
     * It is guarded by `function_exists` so the predicate stays callable
     * outside WordPress (unit tests, CLI).
     *
     * Anonymous traffic is untouched: both checks are false, so the
     * anonymous edge tier keeps caching exactly as before.
     */
    public static function isCredentialed(): bool
    {
        if (BearerAuth::hasCredential()) {
            return true;
        }

        return function_exists('is_user_logged_in') && is_user_logged_in();
    }

    /**
     * Whether a REST route falls under the BCC edge-cache exclusion.
     * Pure predicate (unit-tested) — both BCC namespaces, prefix or
     * exact match, case-INSENSITIVE (F-337): WordPress dispatches
     * /BCC/v1/… to the bcc/v1 handler, so it must be excluded too.
     */
    public static function appliesTo(string $route): bool
    {
        $route = strtolower($route);
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

    /**
     * The privacy keys a cached MEMBER LISTING actually renders.
     *
     * ⚠ This list is the whole point of the feature: an anonymous
     * member list can sit in the edge cache for its TTL, so a member who
     * changes one of these would keep seeing the old value served to
     * everyone else until it expired. A key belongs here when it changes
     * something a member CARD renders, or whether the member appears in
     * the list at all — and NOT merely because it is a privacy setting.
     *
     *   show_about_on_profile   the card's bio (as of F-341's card gate)
     *   real_name_hidden        the card's name
     *   follower_count_hidden   the card's Watchers count
     *   watching_hidden         the card's Watching count
     *   reviews_hidden          the card's review counts
     *   disputes_hidden         the card's dispute counts
     *   discovery_optout        whether the member is listed at all
     *
     * ⚠ DELIBERATELY EXCLUDED, so the exclusions are a decision rather
     * than an oversight: `email_hidden` and `delegations_hidden`, and the
     * verifications / operator / blogs / groups show-flags. Those change
     * the PROFILE payload, which is not edge-cached per member listing.
     * Adding them would purge the whole members tag for a change no
     * listing renders.
     *
     * @var list<string>
     */
    public const MEMBER_LISTING_KEYS = [
        'show_about_on_profile',
        'real_name_hidden',
        'follower_count_hidden',
        'watching_hidden',
        'reviews_hidden',
        'disputes_hidden',
        'discovery_optout',
    ];

    /**
     * Purge the members tag when any of $keys is one a listing renders.
     *
     * Returns whether it purged, so a caller (and a test) can tell a
     * decision not to purge from a purge that did nothing.
     *
     * ⚠ THE CALLER PASSES ONLY KEYS THAT ACTUALLY CHANGED. This function
     * cannot tell a real change from a settings form resending the same
     * value, and purging on every save would drop the members tag every
     * time anybody touched any preference.
     *
     * @param list<string> $keys
     */
    public static function purgeMembersForKeys(array $keys): bool
    {
        foreach ($keys as $key) {
            if (in_array((string) $key, self::MEMBER_LISTING_KEYS, true)) {
                self::purge(self::TAG_MEMBERS);

                return true;
            }
        }

        return false;
    }

    /**
     * Subscribe the members-tag purge to the two places a listing-visible
     * field is written.
     *
     * Both actions carry ONLY the keys that actually changed:
     *
     *   `bcc_privacy_settings_changed`      §K2 flags, via
     *                                       PrivacySettings::writePartial;
     *   `bcc_member_listing_fields_changed` the fields the prefs endpoint
     *                                       writes directly, outside
     *                                       writePartial (today the About
     *                                       show-flag and the listing
     *                                       opt-out).
     *
     * Two actions rather than one because the two write paths are real and
     * separate; ONE key set and ONE decision, so no key is covered twice
     * and none is missed.
     *
     * Registered here rather than inline in Plugin so a test can register
     * and fire them — the pattern FeedRankingService::registerIdentityHooks
     * already uses.
     */
    public static function registerMemberListingHooks(): void
    {
        $purge = static function ($userId, $keys): void {
            unset($userId);
            if (is_array($keys)) {
                /** @var list<string> $keys */
                self::purgeMembersForKeys(array_values($keys));
            }
        };

        add_action('bcc_privacy_settings_changed', $purge, 30, 2);
        add_action('bcc_member_listing_fields_changed', $purge, 30, 2);
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
