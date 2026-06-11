<?php
/**
 * Photo body hydration for the §F3 feed brain.
 *
 * Extracted verbatim from FeedRankingService (Phase 3.2 split): the
 * per-kind `photo` body loader. Also owns `resolvePhotoCaption()`,
 * which GifBodyHydrator shares — both kinds read the caption from
 * `wp_posts.post_content` with the same whitespace-collapse rule.
 *
 * @package BCC\Trust\Core\Services\Feed
 */

namespace BCC\Trust\Core\Services\Feed;

use BCC\Trust\Core\Repositories\PhotoRepository;
use BCC\Trust\Core\Repositories\PhotoAltRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class PhotoBodyHydrator
{
    public function __construct(
        private readonly PhotoRepository $photoRepo,
        private readonly PhotoAltRepository $photoAltRepo
    ) {
    }

    /**
     * Bulk-load photo bodies for v1.5 photo posts. Returns a map
     * keyed by wp_post.ID → body shape per api-contract-v1.md §3.3:
     *
     *   {
     *     caption:   string | null,   // wp_posts.post_content; null when whitespace-only
     *     photo_url: string,           // canonical full image URL
     *     alt:       string | null     // author-supplied; null when not set
     *   }
     *
     * Two bounded SELECTs: PhotoRepository::findByPostIds (peepso_photos)
     * gives us pho_id + filename; PhotoAltRepository::findManyByPhotoIds
     * (bcc_photo_alts sidecar) attaches the author-supplied alt text in a
     * single round-trip keyed by pho_id. Photos with no alt-row return
     * null and the frontend renders `<img alt="">` (decorative).
     *
     * Defensive posture: when a photo row is missing (rare race —
     * activity row landed but save_images hasn't completed), the body
     * falls back to caption-only with `photo_url: ''`. The frontend
     * gracefully omits the image when the URL is empty.
     *
     * @param list<int> $postIds
     * @return array<int, array<string, mixed>>
     */
    public function loadPhotoBodies(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $rowsByPost = $this->photoRepo->findByPostIds($postIds);

        // Collect pho_ids for the alt-text sidecar lookup. Posts without
        // a photo row (race window) contribute nothing; the alt map
        // simply won't have an entry for them.
        $phoIdsByPost = [];
        foreach ($rowsByPost as $postId => $row) {
            $phoIdsByPost[$postId] = (int) $row->pho_id;
        }
        $altsByPhoId = $this->photoAltRepo->findManyByPhotoIds(array_values($phoIdsByPost));

        $out = [];
        foreach ($postIds as $postId) {
            $caption = self::resolvePhotoCaption($postId);
            $row     = $rowsByPost[$postId] ?? null;
            $photoUrl = $row !== null ? PhotoRepository::resolvePhotoUrl($row) : '';

            $phoId = $phoIdsByPost[$postId] ?? 0;
            $alt   = $phoId > 0 ? ($altsByPhoId[$phoId] ?? null) : null;

            $out[$postId] = [
                'caption'   => $caption,
                'photo_url' => $photoUrl,
                'alt'       => $alt,
            ];
        }
        return $out;
    }

    /**
     * Read the caption for a photo post from `wp_posts.post_content`.
     * PeepSo's add_post stores the user's caption text there; the
     * writer pads with a single space when empty (see PeepSoPhotoWriter
     * docblock) so the field is never NULL on the DB side. We collapse
     * a whitespace-only post_content back to null at this layer so
     * the frontend treats photo-only posts as caption-less.
     */
    public static function resolvePhotoCaption(int $postId): ?string
    {
        if ($postId <= 0) {
            return null;
        }
        $post = get_post($postId);
        if (!($post instanceof \WP_Post)) {
            return null;
        }
        $caption = trim((string) $post->post_content);
        return $caption === '' ? null : $caption;
    }
}
