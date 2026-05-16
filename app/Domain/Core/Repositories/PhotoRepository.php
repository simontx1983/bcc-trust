<?php
/**
 * PhotoRepository — read-side access to peepso_photos for the v1.5
 * photo body hydrator.
 *
 * BCC writes route through bcc-core's PeepSoPhotoWriter (single-graph
 * rule, mirrors PeepSoReactionRepository's read-only pattern). This
 * repo strictly reads.
 *
 * Backing table: {prefix}peepso_photos
 *   pho_id, pho_post_id, pho_owner_id, pho_album_id, pho_module_id,
 *   pho_file_name, pho_orig_name, pho_filesystem_name, pho_size,
 *   pho_ext, pho_thumbs (JSON), pho_token (S3), pho_stored (bool)
 *
 * For V1 we surface the full image URL only — no thumbnail variant.
 * The feed page caps at 20 items so per-card image bandwidth stays
 * bounded; introducing thumbnail-variant logic now would be premature
 * (a thumbnail micro-optimization that adds complexity for no real
 * win). When the cap goes up, lift `pho_thumbs` JSON parsing into
 * this repo and surface a `thumbnail_url` on the body.
 *
 * S3 mode: when `pho_stored = 1` and `pho_token` is set, PeepSo
 * stores the photo on Amazon S3. V1 BCC does not handle S3 URL
 * construction; sites running PeepSo's S3 photo storage will see
 * the photo URL fall back to the local-storage convention, which
 * may 404 on S3-only deployments. Documented as a Phase 2 follow-up
 * — most BCC installs run local storage today.
 *
 * @package BCC\Trust\Core\Repositories
 * @since v1.5 (2026-05, Phase 1b photo composer)
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type PhotoRow object{
 *   pho_id: int|numeric-string,
 *   pho_post_id: int|numeric-string,
 *   pho_owner_id: int|numeric-string,
 *   pho_filesystem_name: string,
 *   pho_ext: string|null,
 *   pho_token: string|null,
 *   pho_stored: int|numeric-string|null
 * }
 */
final class PhotoRepository
{
    private const TABLE_SUFFIX = 'peepso_photos';

    private const COLUMNS = 'pho_id, pho_post_id, pho_owner_id, pho_filesystem_name, pho_ext, pho_token, pho_stored';

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Batch-load photo rows for a list of parent wp_post IDs. One
     * SELECT per feed page; bounded IN(...) with at most page-size
     * placeholders (~20 typical, 50 max).
     *
     * Returns a map keyed by `pho_post_id` (the parent's wp_post.ID,
     * which is what FeedItem.external_id carries for photo kinds) →
     * the first PhotoRow for that post. Multi-photo posts return only
     * the first row in V1 (single-photo composer), matching the
     * Phase 1b composer's single-photo constraint.
     *
     * Empty input returns []. Missing entries in the result mean "no
     * photo row found for this post" — caller should fall through to
     * an empty body or a safe placeholder.
     *
     * @param list<int> $postIds
     * @return array<int, object>
     * @phpstan-return array<int, PhotoRow>
     */
    public function findByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        // Filter + dedupe + bound the IN clause.
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

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = 'SELECT ' . self::COLUMNS . '
                  FROM ' . self::table() . '
                 WHERE pho_post_id IN (' . $placeholders . ')
                 ORDER BY pho_id ASC';

        /** @phpstan-var list<PhotoRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$ids));
        if (!is_array($rows)) {
            return [];
        }

        // Index by pho_post_id, first-row-wins (multi-photo posts
        // surface only their cover image in V1).
        $out = [];
        foreach ($rows as $r) {
            $postId = (int) $r->pho_post_id;
            if ($postId > 0 && !isset($out[$postId])) {
                $out[$postId] = $r;
            }
        }
        return $out;
    }

    /**
     * Batch-load all photos in a single album, newest first. Bounded
     * by `$limit` (defaults to 200 — comfortable headroom for a single
     * album view without unbounded scans). Owner filter is a
     * defense-in-depth check so a stale album_id from a different user
     * can't leak rows.
     *
     * Returns photos in `pho_id DESC` order — PeepSo's stored timeline
     * (no `pho_created` on this table, the ID is the only stable
     * ordering signal).
     *
     * @return list<object>
     * @phpstan-return list<PhotoRow>
     */
    public function findByAlbumId(int $albumId, int $ownerId, int $limit = 200): array
    {
        if ($albumId <= 0 || $ownerId <= 0) {
            return [];
        }

        $limit = max(1, min(500, $limit));

        global $wpdb;
        $sql = 'SELECT ' . self::COLUMNS . '
                  FROM ' . self::table() . '
                 WHERE pho_album_id = %d
                   AND pho_owner_id = %d
                 ORDER BY pho_id DESC
                 LIMIT %d';

        /** @phpstan-var list<PhotoRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare($sql, $albumId, $ownerId, $limit));
        return is_array($rows) ? $rows : [];
    }

    /**
     * Resolve the owner user id for a photo by its `pho_id`. Returns
     * null when the photo doesn't exist (deleted, never uploaded, or
     * unknown id). Used by the §3.3.9 alt-text write endpoint to
     * verify `current_user_id() === pho_owner_id` before storing the
     * author-supplied alt — single PK lookup, no JOIN.
     *
     * Note: this is a read against PeepSo's own table, mirroring the
     * "BCC reads peepso_photos, never writes" rule documented in the
     * file-level docblock above.
     */
    public function findOwnerByPhotoId(int $phoId): ?int
    {
        if ($phoId <= 0) {
            return null;
        }

        global $wpdb;
        $owner = $wpdb->get_var($wpdb->prepare(
            'SELECT pho_owner_id FROM ' . self::table() . ' WHERE pho_id = %d LIMIT 1',
            $phoId
        ));

        if ($owner === null || $owner === '') {
            return null;
        }
        return (int) $owner;
    }

    /**
     * Resolve the public URL for a photo row.
     *
     * Local-storage convention: `<peepso_uri>users/<owner>/photos/<filename>`.
     * Mirrors PeepSoPhotosModel::get_photo_thumbs_url's URL pattern
     * (without the /thumbs/ segment). Returns empty string when the
     * photo is S3-only (`pho_stored = 1` with `pho_token`) — V1 does
     * not construct S3 URLs; the caller surfaces "" and the frontend
     * gracefully omits the image.
     *
     * @param object $row
     * @phpstan-param PhotoRow $row
     */
    public static function resolvePhotoUrl(object $row): string
    {
        $stored = (int) ($row->pho_stored ?? 0);
        $token  = (string) ($row->pho_token ?? '');
        if ($stored === 1 && $token !== '') {
            // S3 mode — V1 does not resolve. Phase 2 will read PeepSo
            // option `photos_aws_s3_*` config and construct the
            // signed URL or CloudFront prefix.
            return '';
        }

        $owner    = (int) ($row->pho_owner_id ?? 0);
        $filename = (string) ($row->pho_filesystem_name ?? '');
        if ($owner <= 0 || $filename === '') {
            return '';
        }

        if (!class_exists('PeepSo')) {
            return '';
        }

        $base = (string) \PeepSo::get_peepso_uri();
        if ($base === '') {
            return '';
        }

        return $base . 'users/' . $owner . '/photos/' . $filename;
    }
}
