<?php
/**
 * Login Days Repository
 *
 * Owns `bcc_trust_login_days` — one row per (user, UTC day) with at
 * least one explicit successful authentication event. Written only by
 * RankLoginListener; there is no request-touch writer by design (owner
 * correction 2 — background API traffic must never count as a login).
 *
 * Reads feed the Rank time-credit (DISTINCT months), the inactivity /
 * decay clock (MAX(day)), and DormancyDetector's 60-day display rule.
 *
 * Pattern: PhotoAltRepository — bounded queries, explicit columns, §5
 * generation-counter invalidation for the per-user read cache.
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

class LoginDaysRepository
{
    /** Cache group for per-user login-day generation counters. */
    private const CACHE_GROUP = 'bcc_rank_login_days';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::loginDays();
    }

    /**
     * Record an explicit login for the given UTC day. INSERT IGNORE —
     * idempotent by PK(user_id, day): the day's first auth event wins,
     * same-day duplicates (2FA completing a password login, several
     * logins in one day) are harmless no-ops.
     *
     * @return bool False only on DB error (a duplicate is success).
     */
    public function recordLogin(int $userId, string $source, string $day): bool
    {
        if ($userId <= 0) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table} (user_id, day, source, created_at)
             VALUES (%d, %s, %s, %s)",
            $userId,
            $day,
            $source,
            current_time('mysql', true)
        ));

        if ($result === false) {
            return false;
        }

        if ((int) $result > 0) {
            $this->invalidateCache($userId);
        }
        return true;
    }

    /**
     * Most recent explicit-login day ('Y-m-d'), or null if the user has
     * never logged in since the table shipped. The inactivity/decay
     * clock and DormancyDetector both key off this value.
     */
    public function lastLoginDay(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        global $wpdb;

        $day = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(day) FROM {$this->table} WHERE user_id = %d",
            $userId
        ));

        return is_string($day) && $day !== '' ? $day : null;
    }

    /**
     * Number of distinct calendar months containing at least one
     * explicit login (§12.1 time credit input — the cap is applied by
     * the calculator from config, not here). Bounded aggregate on the
     * PK prefix.
     */
    public function distinctMonthCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT DATE_FORMAT(day, '%%Y-%%m'))
               FROM {$this->table}
              WHERE user_id = %d",
            $userId
        ));
    }

    /**
     * §5 generation-counter bump — future read caches key off
     * `login_days_gen:{user_id}`.
     */
    private function invalidateCache(int $userId): void
    {
        $genKey = "login_days_gen:{$userId}";
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
        }
    }
}
