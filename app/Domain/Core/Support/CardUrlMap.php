<?php
/**
 * CardUrlMap — single source of truth for card kind → URL mapping.
 *
 * ────────────────────────────────────────────────────────────────────
 *  CENTRALIZATION RULE (LOCKED — see watching Phase-2 corrections):
 *
 *    Every consumer that builds a card URL — frontend route OR API
 *    endpoint — MUST go through this class. Do not redefine the
 *    KIND_URL_PREFIX map elsewhere; do not concatenate prefixes
 *    inline in service code.
 *
 *    Current consumers:
 *      - CardViewService (links.self / links.review on card view-models)
 *      - WatchingService (links.card + actions.view on watch items)
 *      - GroupsService (links.self on the group detail response)
 *      - PushPayload (hall-post + holder-community-live push URLs)
 *      - FeedHydrationPipeline (group.link on syndicated feed posts)
 *      (the last three route their group URLs through groupUrl() rather
 *      than the inline prefixes they used pre-canonicalization.)
 *
 *    Next.js frontend routes MUST agree with the prefixes here.
 *    Divergence creates silent broken links across kinds — the bug
 *    only surfaces when a viewer navigates a path that's never
 *    been clicked from a different surface, which is exactly the
 *    kind of long-tail break that goes unnoticed.
 * ────────────────────────────────────────────────────────────────────
 *
 * Identifier rule (locked by contract):
 *   - member kind         → bcc_handle (per §B6)
 *   - validator/project/  → post_name (slug) — peepso-page CPT slug
 *     creator kinds
 *
 * The frontend prefix and the API path use the same identifier; this
 * class encapsulates that contract so callers don't reimplement it.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, Binder Phase 2 cleanup)
 */

namespace BCC\Trust\Core\Support;

use BCC\Trust\Core\ValueObjects\GroupType;

if (!defined('ABSPATH')) {
    exit;
}

final class CardUrlMap
{
    /** @var array<string, string> */
    public const KIND_URL_PREFIX = [
        'validator' => '/v/',
        'project'   => '/p/',
        'creator'   => '/c/',
        'member'    => '/u/',
    ];

    /**
     * Frontend route prefix for a group's canonical page, keyed by
     * whether the group is a Hall. Halls live under /halls/{slug}
     * (the union-hall directory); every other group kind (nft /
     * validator / system / user) canonicalizes on /communities/{slug}.
     *
     * The thin `/groups/{slug}` FE route is retired: every non-hall
     * group now resolves on the `/communities/` canonical route, and
     * this composer is the single place that prefix is emitted.
     */
    public const HALL_URL_PREFIX      = '/halls/';
    public const COMMUNITY_URL_PREFIX = '/communities/';

    /**
     * Frontend route for the entity's canonical page (Next.js).
     *
     * Identifier semantics — caller must pass the kind-canonical id:
     *   - member kind → bcc_handle
     *   - page kinds  → post_name (slug)
     *
     * Unknown kinds fall back to root prefix; safer than emitting
     * a stable wrong path, the frontend will 404 visibly.
     */
    public static function frontendUrl(string $kind, string $identifier): string
    {
        $prefix = self::KIND_URL_PREFIX[$kind] ?? '/';
        return $prefix . $identifier;
    }

    /**
     * REST endpoint for the polymorphic card view-model:
     *   GET /wp-json/bcc/v1/cards/{kind}/{identifier}
     *
     * Caller must pass the kind-canonical identifier per
     * frontendUrl()'s contract.
     */
    public static function cardApiUrl(string $kind, string $identifier): string
    {
        return '/wp-json/bcc/v1/cards/' . $kind . '/' . $identifier;
    }

    /**
     * Frontend permalink for a single post:
     *   /u/{handle}/post/{shortcode}
     *
     * The SAME centralization rule as the kind prefixes above applies
     * (LOCKED): every emitter of a post permalink — the feed hydration
     * links stage, the blog-tab emitter, any future surface — MUST call
     * this composer. Do not concatenate '/post/' inline in service code.
     *
     * Identifier semantics:
     *   - $handle    → bcc_handle (per §B6), same as the member kind
     *   - $shortCode → the 8-letter code from PostShortcodeRepository
     */
    public static function postUrl(string $handle, string $shortCode): string
    {
        return self::KIND_URL_PREFIX['member'] . $handle . '/post/' . $shortCode;
    }

    /**
     * Frontend route for a group's canonical page.
     *
     * The SAME centralization rule as the kind prefixes above applies:
     * every emitter of a group link routes through here. The former
     * inline `'/halls/'`/`'/groups/'` builders in HallsService /
     * GroupsService / CardViewService / PushPayload have all been
     * converged onto this composer, so the `/communities/` cutover is a
     * single-site change.
     *
     * Routing rule (matches §4.7.x group `type`):
     *   - hall  → /halls/{slug}
     *   - else  → /communities/{slug}   (nft / validator / system / user)
     *
     * Identifier semantics:
     *   - $type → the GroupType enum VALUE (`$ctx->type->value`) — the
     *     same string the feed `group.type` block emits.
     *   - $slug → the group's `post_name` (WP-sanitized slug).
     */
    public static function groupUrl(string $type, string $slug): string
    {
        $prefix = $type === GroupType::Hall->value
            ? self::HALL_URL_PREFIX
            : self::COMMUNITY_URL_PREFIX;
        return $prefix . self::sanitizeSlug($slug);
    }

    /**
     * Normalize a group slug for URL composition. `post_name` arrives
     * already WP-sanitized, so for real data this is a no-op; the trim +
     * leading-slash strip only guards against a caller passing a stray-
     * slashed or padded value, so we never emit `/communities//x` or a
     * trailing-space link. Pure (no WP), so groupUrl stays unit-pinnable.
     */
    private static function sanitizeSlug(string $slug): string
    {
        return ltrim(trim($slug), '/');
    }
}
