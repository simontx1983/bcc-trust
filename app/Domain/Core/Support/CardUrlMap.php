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
}
