<?php
/**
 * PeepSo Page Resolver
 *
 * Resolves PeepSo page data with fallback to WordPress posts.
 * Extracted from TrustRestController::getPeepsoPageData() and
 * EndorsementService::getPeepSoPage() to eliminate duplication.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

class PeepSoPageResolver {

    /**
     * Resolve a page by ID, checking PeepSo tables first, then wp_posts.
     *
     * Returns a normalized object with consistent properties regardless of
     * source:
     *   - id       (int)    Page/post ID
     *   - title    (string) Page name or post title
     *   - owner_id (int)    Page owner or post author
     *
     * @param  int $pageId
     * @return object|null  Normalized page object, or null if not found.
     * @phpstan-return object{id: int|numeric-string, title: string, owner_id: int|numeric-string}|null
     */
    public static function resolve(int $pageId): ?object {
        $page = \BCC\Trust\Core\Repositories\PeepSoQueryRepository::resolvePage($pageId);

        if ($page) {
            return (object) [
                'id'       => $page->ID,
                'title'    => $page->name,
                'owner_id' => $page->owner_id ?? 0,
            ];
        }

        $post = get_post($pageId);
        if ($post) {
            return (object) [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'owner_id' => (int) $post->post_author,
            ];
        }

        return null;
    }

    /**
     * Get page owner ID.
     *
     * @param  int $pageId
     * @return int Owner user ID (0 if not found).
     */
    public static function getOwnerId(int $pageId): int {
        return Plugin::instance()->pageOwnerResolver()->getPageOwner($pageId);
    }
}
