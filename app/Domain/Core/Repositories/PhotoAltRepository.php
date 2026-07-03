<?php
/**
 * Photo Alt Repository
 *
 * CRUD for `bcc_photo_alts` — a sidecar metadata table holding the
 * author-supplied alt text for PeepSo photos. PeepSo's `peepso_photos`
 * has no native alt column, so the alt lives BCC-side and PeepSo
 * updates can't clobber it.
 *
 * Scope: this repository owns the `bcc_photo_alts` table only. Joins
 * against `peepso_photos` belong in a Service that composes this
 * Repository with PhotoRepository — never inline here.
 *
 * Pattern: mirrors WatchMetaRepository — same "third-party-row-id PK,
 * BCC-owned sidecar columns" shape. New invocations of this pattern
 * should follow WatchMetaRepository's interface conventions:
 *   - PK == third-party row id (no autoincrement)
 *   - Bulk read keyed by the parent's id list, bounded IN-clause
 *   - Idempotent upsert helper
 *   - Single-row delete helper
 *
 * Cache invalidation (per §5): every write bumps a per-photo
 * generation counter so feed body caches keyed off
 * `photo_alt_gen:{pho_id}` see fresh values on the next read.
 *
 * @package BCC\Trust\Core\Repositories
 * @since v1.5 a11y (2026-05)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type PhotoAltRow object{
 *     pho_id: numeric-string,
 *     alt_text: string
 * }
 */
final class PhotoAltRepository
{
    /** Cache group for photo-alt generation counters. */
    private const CACHE_GROUP = 'bcc_photo_alts';

    /**
     * Explicit column list for read-side projection — the feed
     * hydrator only needs (pho_id, alt_text) so we keep the row
     * narrow. Owner is fetched separately via findOwner() at write
     * time.
     */
    private const COLUMNS_READ = 'pho_id, alt_text';

    /** Hard cap on alt text length. Mirrored by the schema VARCHAR(500). */
    public const ALT_TEXT_MAX_LENGTH = 500;

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::photoAlts();
    }

    /**
     * Bulk-fetch alt text for a set of `pho_id`s. One bounded SELECT;
     * unknown ids are absent from the result map (callers default to
     * null). Used by FeedRankingService::loadPhotoBodies to attach
     * alts to a feed page in a single round-trip.
     *
     * The IN clause is hard-bounded at 500 placeholders to match the
     * canonical WatchMetaRepository::findManyByFollowIds shape — feed
     * pages cap at 20–50 items in practice, so 500 leaves abundant
     * headroom while keeping the query plan stable.
     *
     * @param list<int> $phoIds
     * @phpstan-return array<int, string> pho_id => alt_text
     */
    public function findManyByPhotoIds(array $phoIds): array
    {
        if ($phoIds === []) {
            return [];
        }

        // Filter + dedupe + bound. Mirrors PhotoRepository::findByPostIds.
        $clean = [];
        foreach ($phoIds as $id) {
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

        /** @var list<PhotoAltRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS_READ . " FROM {$this->table}
             WHERE pho_id IN ({$placeholders})
             LIMIT 500",
            ...$ids
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->pho_id] = (string) $row->alt_text;
        }
        return $map;
    }

    /**
     * Upsert the alt text for a photo. Returns true on success.
     *
     * `INSERT … ON DUPLICATE KEY UPDATE` so two-step "create then
     * change my mind" flows collapse to a single statement.
     *
     * Caller is responsible for:
     *   - Length-capping `$altText` (use ALT_TEXT_MAX_LENGTH).
     *   - HTML stripping + whitespace collapse.
     *   - AuthZ (current_user_id matches the photo owner).
     *
     * After write, the per-photo generation counter is bumped so feed
     * caches keyed off `photo_alt_gen:{pho_id}` invalidate on the
     * next read.
     */
    public function upsert(int $phoId, int $ownerId, string $altText): bool
    {
        global $wpdb;

        $sql = "INSERT INTO {$this->table} (pho_id, owner_id, alt_text, updated_at)
                VALUES (%d, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                    alt_text = VALUES(alt_text),
                    updated_at = VALUES(updated_at)";

        $result = $wpdb->query($wpdb->prepare(
            $sql,
            $phoId,
            $ownerId,
            $altText,
            current_time('mysql', true)
        ));

        if ($result === false) {
            return false;
        }

        $this->invalidateCache($phoId);
        return true;
    }

    /**
     * Delete the alt-text row for a photo. Used when the user submits
     * an empty alt to clear a previously-set value.
     *
     * @return int|false Rows deleted (0 or 1), or false on DB error.
     */
    public function delete(int $phoId): int|false
    {
        global $wpdb;

        $result = $wpdb->delete($this->table, ['pho_id' => $phoId], ['%d']);
        if ($result === false) {
            return false;
        }

        $this->invalidateCache($phoId);
        return (int) $result;
    }

    /**
     * Invalidate the per-photo alt cache (§5 generation-counter
     * pattern — bump the generation; readers key their cache by it).
     */
    private function invalidateCache(int $phoId): void
    {
        $genKey = "photo_alt_gen:{$phoId}";
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
        }
    }
}
