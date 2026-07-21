<?php
/**
 * GifRepository — read-side access to PeepSo's `peepso_giphy`
 * post_meta for the v1.5 GIF body hydrator.
 *
 * BCC writes route through bcc-core's PeepSoGifWriter (single-graph
 * rule, mirrors PhotoRepository's read-only pattern). This repo
 * strictly reads.
 *
 * Backing storage: `wp_postmeta` rows where `meta_key = 'peepso_giphy'`
 * and `meta_value = <giphy URL>`. PeepSo's `PeepSoGiphy::after_add_post`
 * (peepso/classes/giphy.php:409) writes this meta after a successful
 * `add_post` with `$_POST['type']==='giphy'`.
 *
 * Bulk fetch path: prime the post-meta cache once for the whole feed
 * page via `update_meta_cache('post', ...)`, then read each meta with
 * `get_post_meta` (which hits the cache). This mirrors how
 * `FeedRankingService::hydrateBodies` already primes the cache for
 * BCC sidecar lookups.
 *
 * The GIF body shape (api-contract-v1.md §3.3.10) is constructed in
 * the service layer — this repo just returns the URL strings.
 *
 * @package BCC\Trust\Core\Repositories
 * @since v1.5 (2026-05, Phase 1c GIF picker)
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class GifRepository
{
    /**
     * Post-meta key PeepSo uses for stored GIF URLs. Mirrors
     * `PeepSoGiphy::POST_META_KEY_GIPHY` (peepso/classes/giphy.php:7).
     * Constant-mirroring instead of `\PeepSoGiphy::POST_META_KEY_GIPHY`
     * because BCC code shouldn't hard-fail when PeepSo is deactivated
     * — when the class doesn't exist this repo simply returns empty
     * results.
     *
     * Public so the admin-queue post_kind filter
     * (ContentReportRepository) discriminates status vs gif against
     * the same key instead of re-hardcoding the string.
     */
    public const POST_META_KEY = 'peepso_giphy';

    /**
     * Batch-load Giphy URLs for a list of parent wp_post IDs. Primes
     * the post-meta cache once, then one `get_post_meta` per id (cache
     * hit, no DB round-trip per id).
     *
     * Returns a map keyed by `post_id` → giphy URL string. Posts
     * without a `peepso_giphy` meta are omitted (the caller treats
     * absence as "not a GIF post").
     *
     * Empty input returns []. Invalid (zero/negative) ids are filtered.
     *
     * @param list<int> $postIds
     * @return array<int, string>
     */
    public function findByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        // Filter + dedupe positive ids.
        $clean = [];
        foreach ($postIds as $id) {
            $iid = (int) $id;
            if ($iid > 0) {
                $clean[$iid] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $ids = array_keys($clean);

        // One round-trip primes the post-meta cache for every id;
        // get_post_meta() then hits the cache.
        update_meta_cache('post', $ids);

        $out = [];
        foreach ($ids as $id) {
            $value = get_post_meta($id, self::POST_META_KEY, true);
            if (is_string($value) && $value !== '') {
                $out[$id] = $value;
            }
        }
        return $out;
    }
}
