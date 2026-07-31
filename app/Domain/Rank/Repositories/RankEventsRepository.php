<?php
/**
 * Rank Events Repository — owns the append-only Rank evidence ledger
 * `wp_bcc_trust_rank_events` (Rank Phase 4, shadow).
 *
 * Writers: RankEvidenceIngestor only. Rows are INSERT IGNORE
 * (idempotent by UNIQUE(event_uid, category)), reversed by status flip
 * (never mutated otherwise), and NEVER deleted — Rank must remain
 * reproducible from this ledger (§24.1). Mirrors ScoreEventRepository's
 * shape minus retention (score_events prunes; this ledger must not).
 *
 * @package BCC\Trust\Rank\Repositories
 * @since Rank redesign Phase 4 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type RankEventRow object{
 *     id: numeric-string,
 *     event_uid: string,
 *     subject_user_id: numeric-string,
 *     source_type: string,
 *     source_id: numeric-string,
 *     category: string,
 *     relationship_user_id: numeric-string,
 *     raw_value: numeric-string,
 *     capped_value: numeric-string,
 *     status: string,
 *     effective_at: string
 * }
 */
class RankEventsRepository
{
    /** Cache group for per-subject ledger generation counters (§5). */
    private const CACHE_GROUP = 'bcc_rank_events';

    private const COLUMNS_READ = 'id, event_uid, subject_user_id, source_type, source_id, category, relationship_user_id, raw_value, capped_value, status, effective_at';

    /** Bounded read ceiling — pre-launch subjects hold far fewer rows. */
    private const MAX_ROWS_PER_SUBJECT = 5000;

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::rankEvents();
    }

    /**
     * Append one (event, category) evidence row. INSERT IGNORE — a
     * duplicate (event_uid, category) is a successful no-op, which is
     * what makes hook re-fires harmless.
     *
     * @return bool False only on DB error.
     */
    public function append(
        string $eventUid,
        int $subjectUserId,
        string $sourceType,
        int $sourceId,
        string $category,
        int $relationshipUserId,
        float $rawValue,
        float $cappedValue
    ): bool {
        global $wpdb;

        $now = current_time('mysql', true);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table}
                (event_uid, subject_user_id, source_type, source_id, category,
                 relationship_user_id, raw_value, capped_value, status, effective_at)
             VALUES (%s, %d, %s, %d, %s, %d, %f, %f, 'active', %s)",
            $eventUid,
            $subjectUserId,
            $sourceType,
            $sourceId,
            $category,
            $relationshipUserId,
            $rawValue,
            $cappedValue,
            $now
        ));

        if ($result === false) {
            return false;
        }

        if ((int) $result > 0) {
            $this->invalidateCache($subjectUserId);
        }
        return true;
    }

    /**
     * Reverse every active row of one source event (evidence loss —
     * §14.1 step 1: it stops contributing immediately). Status flip
     * only; raw values and history stay.
     *
     * @return int Rows reversed (0 if none matched), or -1 on DB error.
     */
    public function reverseBySource(string $sourceType, int $sourceId, string $reason): int
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET status = 'reversed', reversed_at = %s, reversal_reason = %s
              WHERE source_type = %s AND source_id = %d AND status = 'active'",
            current_time('mysql', true),
            substr($reason, 0, 120),
            $sourceType,
            $sourceId
        ));

        if ($result === false) {
            return -1;
        }

        if ((int) $result > 0) {
            // Subjects affected are unknown here without a re-read; bump
            // the coarse group generation so calculator reads refresh.
            $this->invalidateCache(0);
        }
        return (int) $result;
    }

    /**
     * All ACTIVE evidence rows for one subject — the calculator's input.
     * Bounded read; pre-launch scale sits far below the ceiling and the
     * §4.4 event cap keeps per-subject growth linear in real activity.
     *
     * @return list<object>
     * @phpstan-return list<RankEventRow>
     */
    public function listActiveForSubject(int $subjectUserId): array
    {
        if ($subjectUserId <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<RankEventRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS_READ . " FROM {$this->table}
              WHERE subject_user_id = %d AND status = 'active'
              ORDER BY id ASC
              LIMIT %d",
            $subjectUserId,
            self::MAX_ROWS_PER_SUBJECT
        ));

        return $rows ?: [];
    }

    /**
     * Sum of active capped_value for one event_uid — the §4.4 event-cap
     * accounting read used at ingest (layered credit shares the cap).
     */
    public function activeCappedTotalForEvent(string $eventUid): float
    {
        global $wpdb;

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(capped_value), 0) FROM {$this->table}
              WHERE event_uid = %s AND status = 'active'",
            $eventUid
        ));
    }

    /** §5 generation-counter bump; user 0 = coarse whole-ledger bump. */
    private function invalidateCache(int $subjectUserId): void
    {
        $genKey = "rank_events_gen:{$subjectUserId}";
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
        }
    }
}
