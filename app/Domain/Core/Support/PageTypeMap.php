<?php
/**
 * PageTypeMap — single source of truth for the
 * card_kind ↔ _bcc_page_type meta-value bidirectional mapping.
 *
 * Used by:
 *   - CardViewService (forward: kind → page_type, validating cards/:type/:id route)
 *   - BinderService   (reverse: page_type → kind, resolving followed users to validator/project/creator)
 *
 * The two directions intentionally don't include 'member' — that's
 * the wp_users-backed kind, not a peepso-page-backed kind. Callers
 * default to 'member' when a user has no recognized peepso-page.
 *
 * V1 mapping rationale:
 *   - validator → validator (1:1; PeepSo CPT slug matches the contract)
 *   - project   → builder   (legacy CPT slug from blue-collar-crypto-peepso-integration)
 *   - creator   → nft       (legacy CPT slug)
 *
 * Adding a new card_kind requires updating BOTH directions in lockstep
 * — a one-direction-only entry would create a silent contract drift.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, Cards Phase 2)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PageTypeMap
{
    /**
     * Card-kind → _bcc_page_type meta value.
     * Used by CardViewService to validate that a route's `:type`
     * segment matches the page's actual stored type.
     *
     * @var array<string, string>
     */
    public const KIND_TO_PAGE_TYPE = [
        'validator' => 'validator',
        'project'   => 'builder',
        'creator'   => 'nft',
    ];

    /**
     * _bcc_page_type meta value → card_kind.
     * Used by BinderService to resolve a followed user's owned
     * peepso-page into the right card_kind label. Unknown values
     * (or no page at all) collapse to the 'member' default.
     *
     * @var array<string, string>
     */
    public const PAGE_TYPE_TO_KIND = [
        'validator' => 'validator',
        'builder'   => 'project',
        'nft'       => 'creator',
    ];

    /**
     * Map a stored _bcc_page_type to a card_kind, returning null when
     * the page_type is unrecognized so callers can fall back to 'member'.
     */
    public static function kindForPageType(string $pageType): ?string
    {
        return self::PAGE_TYPE_TO_KIND[$pageType] ?? null;
    }
}
