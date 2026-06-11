<?php
/**
 * GIF body hydration for the §F3 feed brain.
 *
 * Extracted verbatim from FeedRankingService (Phase 3.2 split): the
 * per-kind `gif` body composer. The layer-2 status→gif promotion pass
 * (GifRepository batch read) stays in FeedRankingService::hydrateBodies
 * — it is cross-kind orchestration; this class only composes the body
 * shape from the URL map already in hand. Caption resolution is shared
 * with photos via PhotoBodyHydrator::resolvePhotoCaption().
 *
 * @package BCC\Trust\Core\Services\Feed
 */

namespace BCC\Trust\Core\Services\Feed;

if (!defined('ABSPATH')) {
    exit;
}

final class GifBodyHydrator
{
    /**
     * Bulk-build GIF bodies for v1.5 GIF posts. Caller has already
     * batch-fetched the giphy URLs via GifRepository in the layer-2
     * promotion pass, so this is just a per-post composition step:
     * read the wp_post for the caption, pair with the URL.
     *
     * Body shape (api-contract-v1.md §3.3.10):
     *
     *   {
     *     caption:  string | null,    // wp_posts.post_content; null when whitespace-only
     *     gif_url:  string,            // Giphy CDN URL
     *     provider: 'giphy'            // forward-stable for future Tenor / sticker providers
     *   }
     *
     * @param list<int> $postIds
     * @param array<int, string> $gifUrlByExtId
     * @return array<int, array<string, mixed>>
     */
    public function loadGifBodies(array $postIds, array $gifUrlByExtId): array
    {
        if ($postIds === []) {
            return [];
        }

        $out = [];
        foreach ($postIds as $postId) {
            $url = $gifUrlByExtId[$postId] ?? '';
            if ($url === '') {
                continue;
            }
            $out[$postId] = [
                'caption'  => PhotoBodyHydrator::resolvePhotoCaption($postId),
                'gif_url'  => $url,
                'provider' => 'giphy',
            ];
        }
        return $out;
    }
}
