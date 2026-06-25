<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded LRU for Helius webhook replay protection (V2 Phase 1b).
 *
 * Helius does not provide HMAC + does not include in-payload timestamps
 * (verified spike 2, May 2026); idempotency must be enforced
 * client-side via the transaction signature.
 *
 * Operational bounds:
 *   - max 10 000 rows
 *   - rows aged > 1 h are deleted by `bcc_helius_dedupe_sweep` cron
 *   - cron also trims oldest-first to keep row count <= cap
 *   - post-sweep size persisted to `bcc_helius_dedupe_size` option;
 *     dashboard alarms when count > 12 000 (same overdue-detector
 *     pattern as CronService::admin_notices)
 *
 * @phpstan-type SeenRow object{signature: string, seen_at: string}
 */
final class HeliusSeenSignaturesRepository
{
    public const MAX_ROWS         = 10000;
    public const ALARM_THRESHOLD  = 12000;
    public const MAX_AGE_SECONDS  = 3600;

    /** Bounded age-delete: rows per batch + max batches per sweep (Phase 8). */
    private const CLEANUP_BATCH_SIZE      = 5000;
    private const CLEANUP_MAX_ITERATIONS  = 20;

    /**
     * Sweep-derived row count, persisted so admin reads + the next
     * sweep don't have to re-COUNT(*) the whole table. Updated only
     * by the sweep cron (every 5 min) — a write here on every markSeen
     * would defeat the purpose. Drift between sweeps is bounded by the
     * insertion rate over 5 minutes; the alarm threshold (12k vs 10k cap)
     * absorbs that drift.
     */
    private const SIZE_OPTION = 'bcc_helius_dedupe_size';

    public static function table(): string
    {
        return DB::table('helius_seen_signatures');
    }

    /**
     * Atomically record a signature as seen.
     *
     * Returns true when newly inserted (caller should process the
     * payload), false when already present (caller should skip — replay).
     */
    public static function markSeen(string $signature): bool
    {
        if ($signature === '' || mb_strlen($signature) > 96) {
            return false;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        // INSERT IGNORE returns 1 on insert, 0 when the unique key
        // (signature) already exists. $wpdb->query returns affected rows.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (signature, seen_at) VALUES (%s, %s)",
            $signature,
            $now
        ));

        return is_int($result) && $result === 1;
    }

    /**
     * Sweep old + overflow rows. Called by `bcc_helius_dedupe_sweep`
     * cron every 5 minutes.
     *
     * @return array{deleted_age: int, deleted_overflow: int, remaining: int}
     */
    public static function sweep(int $maxAgeSeconds = self::MAX_AGE_SECONDS, int $maxRows = self::MAX_ROWS): array
    {
        $maxAgeSeconds = max(60, $maxAgeSeconds);
        $maxRows       = max(100, $maxRows);

        global $wpdb;
        $table = self::table();

        // 1. Delete rows older than the age TTL — in bounded batches. An
        //    unbounded `DELETE … WHERE seen_at < cutoff` would, if this 5-min
        //    cron stalls for hours, accumulate a large backlog and then take
        //    ONE long table-lock that blocks the indexer's dedup inserts.
        //    Batched (5000/iter, capped) so each lock is short; a backlog that
        //    exceeds the per-sweep cap is finished by the next sweep.
        $cutoff = gmdate('Y-m-d H:i:s', time() - $maxAgeSeconds);
        $deletedAge = 0;
        for ($i = 0; $i < self::CLEANUP_MAX_ITERATIONS; $i++) {
            $n = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE seen_at < %s LIMIT %d",
                $cutoff,
                self::CLEANUP_BATCH_SIZE
            ));
            $deletedAge += $n;
            if ($n < self::CLEANUP_BATCH_SIZE) {
                break;
            }
        }

        // 2. Compute remaining without a re-COUNT(*) when the cached
        // size is fresh. Cache miss falls back to a true COUNT(*).
        $cachedBefore = (int) get_option(self::SIZE_OPTION, -1);
        $remaining = $cachedBefore >= 0
            ? max(0, $cachedBefore - $deletedAge)
            : (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $deletedOverflow = 0;

        if ($remaining > $maxRows) {
            // 3. Re-ground to a real COUNT(*) BEFORE any destructive
            // overflow trim. The cached size ($bcc_helius_dedupe_size) is
            // a fast estimate that can drift high between sweeps; trusting
            // it to size the overflow DELETE let a drifted-high cache evict
            // still-in-window (< maxAgeSeconds) signatures — reopening the
            // replay window for any evicted sig. Pay the COUNT(*) ONLY when
            // the cached estimate claims we're over the cap (rare), so the
            // common non-overflow path stays count-free.
            $actual = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

            if ($actual > $maxRows) {
                $excess = $actual - $maxRows;

                // SQL: DELETE FROM ... ORDER BY seen_at ASC LIMIT %d
                // (MySQL accepts LIMIT on DELETE, which is the cheapest way
                // to trim oldest-N without a subquery).
                //
                // Residual tradeoff (intentional, NOT the bug being fixed):
                // on a GENUINE >maxRows in-window burst this cap still
                // evicts oldest-first rows that are still in the replay
                // window. Those are the rows closest to aging out, so the
                // residual replay risk is minimal — this is a deliberate
                // size safety-valve. We do NOT add a `WHERE seen_at < cutoff`
                // guard here: step 1 already removed every out-of-window
                // row, so such a guard would match nothing and make the cap
                // un-enforceable. Re-grounding to COUNT(*) is the actual
                // correctness fix — it stops cache drift from ever driving
                // eviction.
                $deletedOverflow = (int) $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} ORDER BY seen_at ASC LIMIT %d",
                    $excess
                ));

                $remaining = max(0, $actual - $deletedOverflow);
            } else {
                // Cache was drifted high; the table is actually within cap.
                // Correct $remaining to the real count, trim nothing.
                $remaining = $actual;
            }
        }

        update_option(self::SIZE_OPTION, $remaining, false);

        return [
            'deleted_age'      => $deletedAge,
            'deleted_overflow' => $deletedOverflow,
            'remaining'        => $remaining,
        ];
    }

    public static function rowCount(): int
    {
        $cached = (int) get_option(self::SIZE_OPTION, -1);
        if ($cached >= 0) {
            return $cached;
        }

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table());
        update_option(self::SIZE_OPTION, $count, false);
        return $count;
    }
}
