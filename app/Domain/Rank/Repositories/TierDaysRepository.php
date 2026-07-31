<?php
/**
 * Tier Days Repository
 *
 * Owns `bcc_trust_tier_days` — one row per (user, UTC day) with the
 * member's resolved Trust Tier ordinal. Written by TierSnapshotService
 * only; a missing row is a non-qualifying day (fail-safe strict — a gap
 * can delay a promotion, never grant one).
 *
 * Also owns the daily-snapshot advisory lock and the wp_users id cursor
 * the sweep pages with (§1: all $wpdb stays in repositories).
 *
 * @package BCC\Trust\Rank\Repositories
 * @since Rank redesign Phase 1 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

class TierDaysRepository
{
    private const SNAPSHOT_LOCK = 'bcc_rank_tier_snapshot';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::tierDays();
    }

    /**
     * Record one user's tier for a day. INSERT IGNORE — the first write
     * for a (user, day) wins and is never rewritten (snapshot semantics).
     *
     * @return bool False only on DB error.
     */
    public function recordDay(int $userId, string $day, int $tierOrd): bool
    {
        if ($userId <= 0) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table} (user_id, day, tier_ord) VALUES (%d, %s, %d)",
            $userId,
            $day,
            $tierOrd
        ));

        return $result !== false;
    }

    /**
     * Bulk form for the daily sweep — one bounded multi-VALUES
     * INSERT IGNORE per batch (batch size capped by the sweep at 200).
     *
     * @param array<int, int> $userIdToOrd user_id => tier_ord
     * @return bool False only on DB error.
     */
    public function recordDays(array $userIdToOrd, string $day): bool
    {
        if ($userIdToOrd === []) {
            return true;
        }

        global $wpdb;

        $placeholders = [];
        $values       = [];
        foreach ($userIdToOrd as $userId => $tierOrd) {
            if ((int) $userId <= 0) {
                continue;
            }
            $placeholders[] = '(%d, %s, %d)';
            $values[]       = (int) $userId;
            $values[]       = $day;
            $values[]       = (int) $tierOrd;
        }
        if ($placeholders === []) {
            return true;
        }

        $sql = "INSERT IGNORE INTO {$this->table} (user_id, day, tier_ord) VALUES "
             . implode(', ', $placeholders);

        return $wpdb->query($wpdb->prepare($sql, ...$values)) !== false;
    }

    /**
     * Days within [sinceDay, today] at or above the given tier ordinal —
     * the §13.1 window count (45-of-60 / 120-of-180). Bounded aggregate
     * on the PK prefix; consumed by the Phase 5 promotion engine.
     */
    public function countQualifyingDays(int $userId, string $sinceDay, int $minTierOrd): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE user_id = %d AND day >= %s AND tier_ord >= %d",
            $userId,
            $sinceDay,
            $minTierOrd
        ));
    }

    /**
     * Retention purge — delete one bounded batch of rows older than
     * $beforeDay. Returns rows deleted (0 = drained) or false on error;
     * the sweep loops within its time budget.
     */
    public function purgeOlderThan(string $beforeDay, int $limit): int|false
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE day < %s LIMIT %d",
            $beforeDay,
            max(1, $limit)
        ));

        return $result === false ? false : (int) $result;
    }

    /**
     * Page of user ids after the cursor — the sweep's iteration source.
     * Every registered user gets a tier-day row (a member without a
     * self-page score row resolves to the default Neutral tier).
     *
     * @return list<int>
     */
    public function listUserIdsAfter(int $cursor, int $limit): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
            max(0, $cursor),
            max(1, $limit)
        ));

        return array_values(array_map('intval', $ids ?: []));
    }

    /**
     * Non-blocking advisory lock serialising the daily snapshot across
     * PHP processes (DisputeRepository lock pattern).
     */
    public function acquireSnapshotLock(): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::SNAPSHOT_LOCK)
        ) === 1;
    }

    public function releaseSnapshotLock(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::SNAPSHOT_LOCK));
    }
}
