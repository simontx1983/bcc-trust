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
        self::visibilityChanged($actId);
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
        self::visibilityChanged($actId);
        return true;
    }

    /**
     * Signal that an activity's moderation visibility actually changed (a
     * new hide row, or a hide row removed — never an idempotent re-hide).
     *
     * Fired HERE, at the single write point, so every hide/restore path —
     * auto-hide, admin hide, admin restore, undo-of-restore — is covered
     * without each caller remembering to. Consumer:
     * ReviewVisibilityScoreSync, which recalculates the score of the page a
     * hidden/restored REVIEW counts toward (a hidden review does not count;
     * user decision). Same pattern as UserInfoRepository's
     * `bcc_user_suspension_changed`.
     */
    private static function visibilityChanged(int $actId): void
    {
        do_action('bcc_trust_activity_visibility_changed', $actId);
    }

    /**
     * Is this activity moderation-hidden?
     *
     * ⚠ F-98 — FAILS CLOSED. `$wpdb->get_var()` answers a failed query
     * with NULL, which `(int)` turned into 0, i.e. "not hidden", so a DB
     * error re-exposed the post. The failure is detected the way WPDB
     * reports it (`last_error`, the bcc-core AdvisoryLock pattern) and the
     * answer is TRUE. Per caller:
     *   - AutoHideService           → skips the hide (harmless; no write);
     *   - FeedItemReportVisibility  → refuses the report as not visible;
     *   - ModerationQueueService    → shows "hidden" for that row, and the
     *                                 undo stale-state guard refuses (both
     *                                 fail-closed moderation postures);
     *   - add()'s idempotency check → reports the insert as satisfied.
     */
    public function exists(int $actId): bool
    {
        if ($actId <= 0) {
            return false;
        }
        global $wpdb;
        $table = TableRegistry::hiddenActivities();

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE act_id = %d LIMIT 1",
            $actId
        );
        if (!self::runRead($sql)) {
            self::recordReadFailure('exists_read_failed', ['act_id' => $actId, 'db_error' => (string) $wpdb->last_error]);
            return true;
        }

        return (int) $wpdb->get_var(null) > 0;
    }

    /**
     * Full list of hidden act_ids — what the feed pipeline merges into
     * its exclusion set. Cache-backed because every feed page fans into
     * this (5 min TTL, generation-busted on every add/remove).
     *
     * ⚠ F-98 — a failed read is NEVER cached and NEVER answered with []:
     * get_col() returns [] on a DB error, and caching that for 5 minutes
     * re-exposed every moderation-hidden post in every feed. On a failed
     * read the LAST-KNOWN-GOOD list (persisted, see LAST_GOOD_OPTION) is
     * served and the next request retries. If no last-good list exists
     * (the very first read ever failed) a RepositoryException is thrown
     * rather than silently showing hidden content.
     *
     * ⚠ F-77 — the old single `LIMIT 5000` dropped the OLDEST decisions
     * once more than 5000 rows existed: those posts reappeared. The list is
     * now read in keyset batches of READ_BATCH, newest act_id first, up to
     * READ_CEILING. Reaching the ceiling is recorded loudly
     * (`ceiling_reached`). The real fix, excluding hidden rows in feed SQL
     * with NOT EXISTS instead of an id list, is queued for the feed owners.
     *
     * @return list<int>
     * @throws \BCC\Trust\Core\Exceptions\RepositoryException when the read
     *         fails and no last-known-good list exists.
     */
    public function getAllHiddenIds(): array
    {
        $genKey = self::cacheGenerationKey();
        $cached = wp_cache_get($genKey, self::CACHE_GROUP);
        if (is_array($cached)) {
            /** @var list<int> $cached */
            return $cached;
        }

        $readStartedAt = microtime(true);
        $ids = $this->readAllHiddenIds();
        if ($ids === null) {
            return self::serveLastGood();
        }

        wp_cache_set($genKey, $ids, self::CACHE_GROUP, self::CACHE_TTL);
        self::rememberLastGood($ids, $readStartedAt);
        return $ids;
    }

    /** Keyset batch size for the full hidden-id read (F-77). */
    public const READ_BATCH = 5000;

    /** Hard ceiling on the hidden-id list (F-77, 10x the old LIMIT). */
    public const READ_CEILING = 50000;

    /**
     * Non-autoload option holding the last successfully read list:
     * {ids: list<int>, read_at: float (unix, when that read STARTED), hash}.
     */
    public const LAST_GOOD_OPTION = 'bcc_hidden_ids_last_good';

    /**
     * Read every hidden act_id, newest act_id first, in keyset batches.
     * Returns null when ANY batch fails (detected via last_error — get_col
     * answers a failed query with []), so a partial list can never pass as
     * a complete one.
     *
     * @return list<int>|null
     */
    private function readAllHiddenIds(): ?array
    {
        global $wpdb;
        $table = TableRegistry::hiddenActivities();

        $ids    = [];
        $cursor = PHP_INT_MAX;
        while (count($ids) < self::READ_CEILING) {
            $sql = $wpdb->prepare(
                "SELECT act_id FROM {$table} WHERE act_id < %d ORDER BY act_id DESC LIMIT %d",
                $cursor,
                self::READ_BATCH
            );
            if (!self::runRead($sql)) {
                self::recordReadFailure('list_read_failed', ['db_error' => (string) $wpdb->last_error]);
                return null;
            }
            $rows = $wpdb->get_col(null);
            $batch = 0;
            foreach ((array) $rows as $raw) {
                $id = (int) $raw;
                if ($id > 0) {
                    $ids[]  = $id;
                    $cursor = $id;
                    $batch++;
                }
            }
            if ($batch < self::READ_BATCH) {
                return $ids; // last page
            }
        }

        // At the ceiling: the OLDEST ids beyond it are not excluded.
        \BCC\Core\Observability\DegradationMetrics::record('moderation_hidden_ids', 'ceiling_reached');
        \BCC\Core\Log\Logger::error('[HiddenActivityRepository] hidden-id ceiling reached; oldest hidden posts are not excluded', [
            'ceiling' => self::READ_CEILING,
        ]);
        return array_slice($ids, 0, self::READ_CEILING);
    }

    /**
     * Persist a successful read as the last-known-good list, only when it
     * differs from what is stored. Called ONLY after every batch passed
     * runRead(), so an empty list here is a genuinely empty table (every
     * hide restored), never a failed read standing in for one.
     *
     * ⚠ Ordering: a read is stamped with the moment it STARTED, and a write
     * only lands when that stamp is NEWER than the stored one. Two concurrent
     * requests therefore cannot put an older list over a newer one, except
     * inside the non-atomic read-then-write window (microseconds). Even
     * then the very next successful read supersedes it, and the list is
     * only ever consulted after a failure.
     *
     * @param list<int> $ids
     */
    private static function rememberLastGood(array $ids, float $readStartedAt): void
    {
        $hash   = md5(implode(',', $ids));
        $stored = get_option(self::LAST_GOOD_OPTION, null);
        if (is_array($stored)) {
            if (($stored['hash'] ?? '') === $hash) {
                return; // unchanged — no write
            }
            if ((float) ($stored['read_at'] ?? 0) >= $readStartedAt) {
                return; // a newer read already landed
            }
        }
        update_option(
            self::LAST_GOOD_OPTION,
            ['ids' => $ids, 'read_at' => $readStartedAt, 'hash' => $hash],
            false
        );
    }

    /**
     * Failed read: serve the last-known-good list (uncached, so the next
     * request retries), or throw when there is none.
     *
     * @return list<int>
     */
    private static function serveLastGood(): array
    {
        $stored = get_option(self::LAST_GOOD_OPTION, null);
        if (!is_array($stored) || !isset($stored['ids']) || !is_array($stored['ids'])) {
            \BCC\Core\Observability\DegradationMetrics::record('moderation_hidden_ids', 'no_last_good');
            throw new \BCC\Trust\Core\Exceptions\RepositoryException(
                'Hidden-activity list unreadable and no last-known-good list exists.'
            );
        }

        $ageSeconds = max(0, (int) round(microtime(true) - (float) ($stored['read_at'] ?? 0)));
        \BCC\Core\Observability\DegradationMetrics::record('moderation_hidden_ids', 'served_last_good');
        \BCC\Core\Log\Logger::error('[HiddenActivityRepository] hidden-id read failed; served last-known-good list', [
            'last_good_age_seconds' => $ageSeconds,
            'last_good_count'       => count($stored['ids']),
        ]);

        $ids = [];
        foreach ($stored['ids'] as $raw) {
            if (is_numeric($raw) && (int) $raw > 0) {
                $ids[] = (int) $raw;
            }
        }
        return $ids;
    }

    /**
     * Run a SELECT and report whether its result can be TRUSTED; the rows
     * are then read with get_col(null) / get_var(null). Same pattern as
     * bcc-core PeepSoBlockRepository::runProbe() (#69).
     *
     * ⚠⚠ `last_error` alone is NOT an exhaustive failure signal. get_col()
     * and get_var() call query() and DISCARD its return, and wpdb::query()
     * has failure paths that return false WITHOUT setting last_error (the
     * `! $this->ready` guard after a failed reconnect, and an empty result
     * from the `query` filter). Both return before flush(), so last_result
     * still holds the PREVIOUS query's rows. Trusting get_col() there
     * would read "no hidden posts" (or a stale unrelated result), cache it,
     * and overwrite the last-known-good list with it. That's F-98 again,
     * failing open on the path meant to fix it.
     *
     * ⚠ `=== false`, never `!$ok`: a SELECT returns its ROW COUNT, and a
     * legitimate zero-row result is 0.
     */
    private static function runRead(string $sql): bool
    {
        global $wpdb;
        self::clearLastError();
        $ok = $wpdb->query($sql);
        return $ok !== false && !self::lastReadFailed();
    }

    // Separate helpers (bcc-core's shape) so static analysis does not narrow
    // last_error to '' across the query() call that mutates it.
    private static function clearLastError(): void
    {
        global $wpdb;
        $wpdb->last_error = '';
    }

    private static function lastReadFailed(): bool
    {
        global $wpdb;
        return (string) $wpdb->last_error !== '';
    }

    /** @param array<string, mixed> $context */
    private static function recordReadFailure(string $event, array $context): void
    {
        \BCC\Core\Observability\DegradationMetrics::record('moderation_hidden_ids', $event);
        \BCC\Core\Log\Logger::error('[HiddenActivityRepository] read failed: ' . $event, $context);
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

    /**
     * Current cache generation — public so payload-level caches that
     * fold hidden-list state into their own keys (§5 generation-counter
     * convention) invalidate instantly when moderation hides/unhides a
     * row. Consumer: FeedRankingService's anon hot-feed payload cache.
     * Same read-or-init logic the internal list cache keys by; do NOT
     * duplicate this elsewhere — call this accessor.
     */
    public static function getGeneration(): int
    {
        $gen = wp_cache_get(self::CACHE_KEY_GEN, self::CACHE_GROUP);
        if (is_numeric($gen)) {
            // Tolerant read (NOT is_int): persistent object-cache
            // backends return integers as numeric strings on
            // cross-process reads (maybe_serialize(0) === "0"). A
            // strict is_int check here treated every cross-process
            // read as "uninitialized" and reset the generation to 0 —
            // permanently undoing every bustCache() increment, so
            // hide/unhide never invalidated downstream caches. Same
            // tolerant shape as CollectionSignalRepository::generation().
            return (int) $gen;
        }
        wp_cache_set(self::CACHE_KEY_GEN, 0, self::CACHE_GROUP);
        return 0;
    }

    private static function cacheGenerationKey(): string
    {
        return self::CACHE_KEY_LIST . ':' . self::getGeneration();
    }

    private static function bustCache(): void
    {
        wp_cache_incr(self::CACHE_KEY_GEN, 1, self::CACHE_GROUP);
    }
}
