<?php
/**
 * Hidden Activity Repository — read/write access to bcc_hidden_activities.
 *
 * §K1 Phase C — sidecar table marking peepso_activities rows hidden by
 * BCC moderation. The feed-pipeline filter reads `getAllHiddenIds()`
 * and merges them into the per-request exclusion list; the auto-hide
 * service + admin actions write via `add()` / `remove()`.
 *
 * Cache: getAllHiddenIds() is the read-side hotspot — every feed page
 * call fans into it. We cache the full list in WP object cache for
 * 5 minutes; writes invalidate by incrementing a cache generation.
 *
 * Reason codes (V1):
 *   - autohide_threshold  — N reports crossed threshold
 *   - admin_action        — explicit admin "Hide" from the queue
 *   (free-form for later kinds; the column is just VARCHAR)
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04, §K1 Phase C)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class HiddenActivityRepository
{
    private const CACHE_GROUP    = 'bcc_trust:hidden_activities';
    private const CACHE_KEY_LIST = 'all_hidden_ids';
    private const CACHE_KEY_GEN  = 'gen';
    private const CACHE_TTL      = 300; // 5 min

    /**
     * Mark an activity hidden. Idempotent — re-hiding the same row
     * returns true without inserting a duplicate (PK collision is
     * silently absorbed).
     */
    public function add(int $actId, int $hiddenByUserId, string $reasonCode, ?int $relatedReportId = null): bool
    {
        if ($actId <= 0 || $reasonCode === '') {
            return false;
        }

        global $wpdb;
        $table = TableRegistry::hiddenActivities();

        $inserted = $wpdb->insert(
            $table,
            [
                'act_id'            => $actId,
                'hidden_at'         => current_time('mysql'),
                'hidden_by_user_id' => max(0, $hiddenByUserId),
                'reason_code'       => $reasonCode,
                'related_report_id' => $relatedReportId !== null && $relatedReportId > 0 ? $relatedReportId : null,
            ],
            ['%d', '%s', '%d', '%s', '%d']
        );

        if ($inserted === false) {
            // Most likely the PK already exists (idempotent re-hide).
            // Confirm via SELECT — anything else is a real error.
            return $this->exists($actId);
        }

        self::bustCache();
        return true;
    }

    /**
     * Remove the hidden marker — restores the activity to normal
     * surfacing. Returns true when a row was deleted (false when
     * none existed; caller can treat that as benign).
     */
    public function remove(int $actId): bool
    {
        if ($actId <= 0) {
            return false;
        }
        global $wpdb;
        $table = TableRegistry::hiddenActivities();

        $deleted = $wpdb->delete($table, ['act_id' => $actId], ['%d']);
        if ($deleted === false || $deleted === 0) {
            return false;
        }

        self::bustCache();
        return true;
    }

    public function exists(int $actId): bool
    {
        if ($actId <= 0) {
            return false;
        }
        global $wpdb;
        $table = TableRegistry::hiddenActivities();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE act_id = %d LIMIT 1",
            $actId
        )) > 0;
    }

    /**
     * Full list of hidden act_ids — what the feed pipeline merges into
     * its exclusion set. Cache-backed because every feed page fans into
     * this. Bounded LIMIT keeps the worst case bounded; if we ever
     * cross 5000 hidden rows the right move is a per-author or per-
     * recent-time scoping, not a higher LIMIT.
     *
     * @return list<int>
     */
    public function getAllHiddenIds(): array
    {
        $genKey = self::cacheGenerationKey();
        $cached = wp_cache_get($genKey, self::CACHE_GROUP);
        if (is_array($cached)) {
            /** @var list<int> $cached */
            return $cached;
        }

        global $wpdb;
        $table = TableRegistry::hiddenActivities();

        $rows = $wpdb->get_col("SELECT act_id FROM {$table} ORDER BY hidden_at DESC LIMIT 5000");

        $ids = [];
        foreach ($rows as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        wp_cache_set($genKey, $ids, self::CACHE_GROUP, self::CACHE_TTL);
        return $ids;
    }

    /**
     * Single-row lookup with full metadata — used by the admin queue
     * to render "this post is currently hidden" state on a report row.
     *
     * @return object{
     *   act_id: int|numeric-string,
     *   hidden_at: string,
     *   hidden_by_user_id: int|numeric-string,
     *   reason_code: string,
     *   related_report_id: int|numeric-string|null
     * }|null
     */
    public function findByActId(int $actId): ?object
    {
        if ($actId <= 0) {
            return null;
        }
        global $wpdb;
        $table = TableRegistry::hiddenActivities();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT act_id, hidden_at, hidden_by_user_id, reason_code, related_report_id
               FROM {$table}
              WHERE act_id = %d
              LIMIT 1",
            $actId
        ));

        if (!is_object($row)) {
            return null;
        }
        /** @var object{
         *   act_id: int|numeric-string,
         *   hidden_at: string,
         *   hidden_by_user_id: int|numeric-string,
         *   reason_code: string,
         *   related_report_id: int|numeric-string|null
         * } $row
         */
        return $row;
    }

    // ─────────────────────────────────────────────────────────────────
    // Cache invalidation — generation counter pattern (per §L6).
    // ─────────────────────────────────────────────────────────────────

    private static function cacheGenerationKey(): string
    {
        $gen = wp_cache_get(self::CACHE_KEY_GEN, self::CACHE_GROUP);
        if (!is_int($gen)) {
            $gen = 0;
            wp_cache_set(self::CACHE_KEY_GEN, $gen, self::CACHE_GROUP);
        }
        return self::CACHE_KEY_LIST . ':' . $gen;
    }

    private static function bustCache(): void
    {
        wp_cache_incr(self::CACHE_KEY_GEN, 1, self::CACHE_GROUP);
    }
}
