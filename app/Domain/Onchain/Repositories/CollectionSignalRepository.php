<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-user collection stances (waitlist / spam) — see
 * includes/database/schema-collection-signals.php for the why.
 *
 * One stance per (user, chain, contract), switchable: setting a new
 * stance replaces the old one (ON DUPLICATE KEY), clearing deletes the
 * row. `notified_at` stamps the waitlist go-live bell; switching stance
 * resets it so a user who flips spam→waitlist can still be notified.
 *
 * @phpstan-type SignalCountRow object{
 *     chain_id: string,
 *     contract_address: string,
 *     waitlist_count: string,
 *     spam_count: string
 * }
 */
final class CollectionSignalRepository
{
    public const STANCE_WAITLIST = 'waitlist';
    public const STANCE_SPAM     = 'spam';

    public const STANCES = [self::STANCE_WAITLIST, self::STANCE_SPAM];

    // No COLUMNS const: no reader selects full rows — every query
    // names its explicit column list inline (§2 satisfied per-query).

    /** Cache group for the §5 generation counter. */
    private const CACHE_GROUP    = 'bcc_collection_signals';
    private const GENERATION_KEY = 'gen_signals';

    public static function table(): string
    {
        return DB::table('collection_signals');
    }

    /**
     * Set (or switch) the user's stance on a collection. Returns false
     * on invalid input or DB failure.
     */
    public static function setStance(int $userId, int $chainId, string $contract, string $stance): bool
    {
        $contract = strtolower(trim($contract));
        if ($userId <= 0 || $chainId <= 0 || $contract === '' || !in_array($stance, self::STANCES, true)) {
            return false;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        // Switching stance resets notified_at: a spam→waitlist flip must
        // be eligible for the go-live bell again.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (user_id, chain_id, contract_address, stance, notified_at, created_at, updated_at)
             VALUES (%d, %d, %s, %s, NULL, %s, %s)
             ON DUPLICATE KEY UPDATE
                stance      = VALUES(stance),
                notified_at = IF(stance = VALUES(stance), notified_at, NULL),
                updated_at  = VALUES(updated_at)",
            $userId,
            $chainId,
            $contract,
            $stance,
            $now,
            $now
        ));

        if ($result === false) {
            return false;
        }

        self::bumpGeneration();
        return true;
    }

    /** Remove the user's stance on a collection (back to neutral). */
    public static function clearStance(int $userId, int $chainId, string $contract): bool
    {
        $contract = strtolower(trim($contract));
        if ($userId <= 0 || $chainId <= 0 || $contract === '') {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE user_id = %d AND chain_id = %d AND contract_address = %s",
            $userId,
            $chainId,
            $contract
        ));

        if ($result === false) {
            return false;
        }
        if ((int) $result > 0) {
            self::bumpGeneration();
        }
        return true;
    }

    /**
     * The viewer's stances for a bounded contract set on one chain:
     * `strtolower(contract) => stance`. Contracts with no stance are
     * absent.
     *
     * @param list<string> $contracts
     * @return array<string, string>
     */
    public static function getStancesForUser(int $userId, int $chainId, array $contracts): array
    {
        if ($userId <= 0 || $chainId <= 0 || $contracts === []) {
            return [];
        }
        $contracts = array_values(array_unique(array_map('strtolower', $contracts)));

        global $wpdb;
        $table = self::table();
        $map   = [];

        foreach (array_chunk($contracts, 100) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%s'));
            /** @var list<object{contract_address: string, stance: string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT contract_address, stance
                 FROM {$table}
                 WHERE user_id = %d
                   AND chain_id = %d
                   AND contract_address IN ({$ph})",
                array_merge([$userId, $chainId], $chunk)
            ));
            foreach ($rows ?: [] as $row) {
                $map[strtolower((string) $row->contract_address)] = (string) $row->stance;
            }
        }

        return $map;
    }

    /**
     * Waitlist + spam tallies per collection, chain-wide (or one chain).
     * Powers the demand-ranked Verify Collections queue and the
     * soft-hide threshold. Keyed by generation so admin renders after a
     * stance write see fresh counts.
     *
     * @return list<SignalCountRow>
     */
    public static function countsByCollection(?int $chainId = null, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));

        $cacheKey = sprintf('signal_counts_%s_%d_g%d', $chainId ?? 'all', $limit, self::getGeneration());
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if (is_array($cached)) {
            /** @var list<SignalCountRow> $cached */
            return $cached;
        }

        global $wpdb;
        $table = self::table();

        $where  = '';
        $params = [self::STANCE_WAITLIST, self::STANCE_SPAM];
        if ($chainId !== null && $chainId > 0) {
            $where    = 'WHERE chain_id = %d';
            $params[] = $chainId;
        }
        $params[] = $limit;

        /** @var list<SignalCountRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT chain_id, contract_address,
                    SUM(stance = %s) AS waitlist_count,
                    SUM(stance = %s) AS spam_count
             FROM {$table}
             {$where}
             GROUP BY chain_id, contract_address
             ORDER BY waitlist_count DESC
             LIMIT %d",
            $params
        ));

        $rows = $rows ?: [];
        wp_cache_set($cacheKey, $rows, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS);
        return $rows;
    }

    /**
     * Waitlisted users for one collection who have NOT been notified
     * yet — the go-live bell audience.
     *
     * @return list<int>
     */
    public static function waitlistUserIdsPending(int $chainId, string $contract, int $limit = 500): array
    {
        $contract = strtolower(trim($contract));
        if ($chainId <= 0 || $contract === '') {
            return [];
        }
        $limit = max(1, min(2000, $limit));

        global $wpdb;
        $table = self::table();

        /** @var list<string>|null $ids */
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id
             FROM {$table}
             WHERE chain_id = %d
               AND contract_address = %s
               AND stance = %s
               AND notified_at IS NULL
             ORDER BY created_at ASC
             LIMIT %d",
            $chainId,
            $contract,
            self::STANCE_WAITLIST,
            $limit
        ));

        return array_map('intval', $ids ?: []);
    }

    /**
     * Stamp the go-live bell as sent for a bounded user set so
     * provisioning re-runs can't re-notify.
     *
     * @param list<int> $userIds
     */
    public static function markNotified(int $chainId, string $contract, array $userIds): void
    {
        $contract = strtolower(trim($contract));
        $userIds  = array_values(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0));
        if ($chainId <= 0 || $contract === '' || $userIds === []) {
            return;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        foreach (array_chunk($userIds, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET notified_at = %s
                 WHERE chain_id = %d
                   AND contract_address = %s
                   AND user_id IN ({$ph})",
                array_merge([$now, $chainId, $contract], $chunk)
            ));
        }

        self::bumpGeneration();
    }

    // ── Cache invalidation (§5 generation counter) ────────────────────

    /** Mirrors NftSelectionRepository::bumpUserGeneration's seed-then-incr. */
    private static function bumpGeneration(): void
    {
        if (wp_cache_get(self::GENERATION_KEY, self::CACHE_GROUP) === false) {
            wp_cache_add(self::GENERATION_KEY, 1, self::CACHE_GROUP);
            return;
        }
        wp_cache_incr(self::GENERATION_KEY, 1, self::CACHE_GROUP);
    }

    private static function getGeneration(): int
    {
        $value = wp_cache_get(self::GENERATION_KEY, self::CACHE_GROUP);
        return is_numeric($value) ? (int) $value : 0;
    }
}
