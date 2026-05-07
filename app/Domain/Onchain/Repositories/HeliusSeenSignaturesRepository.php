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

        // 1. Delete rows older than the age TTL.
        $cutoff = gmdate('Y-m-d H:i:s', time() - $maxAgeSeconds);
        $deletedAge = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE seen_at < %s",
            $cutoff
        ));

        // 2. If still over the cap, trim oldest-first.
        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $deletedOverflow = 0;

        if ($remaining > $maxRows) {
            $excess = $remaining - $maxRows;

            // SQL: DELETE FROM ... ORDER BY seen_at ASC LIMIT %d
            // (MySQL accepts LIMIT on DELETE, which is the cheapest way
            // to trim oldest-N without a subquery).
            $deletedOverflow = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} ORDER BY seen_at ASC LIMIT %d",
                $excess
            ));

            $remaining = max(0, $remaining - $deletedOverflow);
        }

        return [
            'deleted_age'      => $deletedAge,
            'deleted_overflow' => $deletedOverflow,
            'remaining'        => $remaining,
        ];
    }

    public static function rowCount(): int
    {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}
