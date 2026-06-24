<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Contracts\PageOwnerResolverInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves PeepSo page ownership by probing multiple table variants.
 *
 * PeepSo has changed its schema across versions, so we check three
 * candidate tables in priority order and fall back to WP post author.
 * Table-existence checks are cached for the duration of the request.
 */
final class PageOwnerResolver implements PageOwnerResolverInterface
{
    /** @var array<int, int> Memoized owner IDs keyed by page ID. */
    private static array $ownerCache = [];

    /**
     * Batch-resolve page owners in a single query per table variant.
     *
     * Primes the static ownerCache so subsequent getPageOwner() calls
     * return instantly without any DB hits.
     *
     * @param int[] $pageIds
     */
    public function primeOwnerCache(array $pageIds): void
    {
        $pageIds = array_values(array_filter(
            array_map('intval', $pageIds),
            fn(int $id) => !isset(self::$ownerCache[$id])
        ));

        if (empty($pageIds)) {
            return;
        }

        // Member self-pages resolve with zero DB; split them out before the
        // PeepSo batch so only real page ids hit the table-variant probes.
        $realPageIds = [];
        foreach ($pageIds as $id) {
            if (MemberSelfPageService::isSelfPage($id)) {
                self::$ownerCache[$id] = MemberSelfPageService::ownerOfSelfPage($id);
            } else {
                $realPageIds[] = $id;
            }
        }
        if (empty($realPageIds)) {
            return;
        }
        $pageIds = $realPageIds;

        // Batch-resolve via PeepSo tables (repository handles table variants).
        $resolved = \BCC\Trust\Core\Repositories\PeepSoQueryRepository::batchGetPageOwners($pageIds);
        foreach ($resolved as $pid => $uid) {
            self::$ownerCache[$pid] = $uid;
        }

        // Fallback: WP post author for anything still unresolved.
        $remaining = array_diff($pageIds, array_keys($resolved));
        if (!empty($remaining)) {
            _prime_post_caches(array_values($remaining), false, false);
            foreach ($remaining as $pid) {
                $post = get_post($pid);
                self::$ownerCache[$pid] = ($post && $post->post_author) ? (int) $post->post_author : 0;
            }
        }

        // Mark any truly missing pages as 0.
        foreach ($pageIds as $pid) {
            if (!isset(self::$ownerCache[$pid])) {
                self::$ownerCache[$pid] = 0;
            }
        }
    }

    public function getPageOwner(int $pageId): int
    {
        // Member self-pages resolve with zero DB: owner = page_id - ID_BASE.
        if (MemberSelfPageService::isSelfPage($pageId)) {
            return MemberSelfPageService::ownerOfSelfPage($pageId);
        }

        if (isset(self::$ownerCache[$pageId])) {
            return self::$ownerCache[$pageId];
        }

        // Delegate to the PeepSo query repository (probes all table variants).
        $owner = \BCC\Trust\Core\Repositories\PeepSoQueryRepository::getPageOwner($pageId);
        if ($owner !== null) {
            return self::$ownerCache[$pageId] = $owner;
        }

        // Final fallback: WordPress post author.
        $post = get_post($pageId);

        return self::$ownerCache[$pageId] = ($post && $post->post_author) ? (int) $post->post_author : 0;
    }

    public function getPageForOwner(int $userId): int
    {
        // Check scores table first (authoritative).
        $scoreRepo = \BCC\Trust\Core\Plugin::instance()->scoreRepository();
        $pageId    = $scoreRepo->getPageIdForOwner($userId);
        if ($pageId) {
            return $pageId;
        }

        // Fallback: WordPress API.
        $posts = get_posts([
            'author'         => $userId,
            'post_type'      => 'peepso-page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        return !empty($posts) ? (int) $posts[0] : 0;
    }
}
