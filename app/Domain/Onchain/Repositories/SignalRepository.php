<?php

namespace BCC\Trust\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\DB\DB;
use BCC\Core\PeepSo\PeepSo;

/**
 * @phpstan-type TrustSignalMinimalRow object{
 *     id: string,
 *     meta: string|null
 * }
 *
 * @phpstan-type TrustSignalRow object{
 *     wallet_address: string,
 *     chain: string,
 *     role: string,
 *     trust_boost: string,
 *     fraud_reduction: string,
 *     contract_address: string|null,
 *     meta: string|null,
 *     last_synced: string|null
 * }
 */
class SignalRepository
{
    /** @var string Explicit column list — must match schema (install_own_table). */
    private const COLUMNS = 'id, user_id, wallet_address, chain, wallet_age_days, first_tx_at,
                 tx_count, contract_count, score_contribution, raw_data, fetched_at,
                 role, trust_boost, fraud_reduction, contract_address, meta, last_synced';

    /** @var string Object-cache group. */
    private const CACHE_GROUP = 'bcc_onchain_signals';

    /** @var int Default TTL in seconds (1 hour). Filterable via bcc_onchain_signal_cache_ttl. */
    private const DEFAULT_TTL = HOUR_IN_SECONDS;

    public static function table(): string
    {
        return DB::table('onchain_signals');
    }

    /**
     * Create this plugin's own table. No BCC Core dependency.
     */
    public static function install_own_table(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = self::table();

        // score_contribution / trust_boost use DECIMAL so arithmetic in SUM()
        // and comparison against BCC_ONCHAIN_MAX_TOTAL_BONUS is exact — FLOAT
        // lost precision past ~7 significant digits and caused 19.9999999
        // vs 20.0000001 inconsistencies at the cap boundary, which directly
        // affects trust-score correctness (fail-fast rule).
        $sql = "CREATE TABLE {$table} (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id          BIGINT UNSIGNED NOT NULL,
            wallet_address   VARCHAR(255)    NOT NULL,
            chain            VARCHAR(20)     NOT NULL,
            wallet_age_days  INT UNSIGNED    NOT NULL DEFAULT 0,
            first_tx_at      DATETIME                 DEFAULT NULL,
            tx_count         INT UNSIGNED    NOT NULL DEFAULT 0,
            contract_count   INT UNSIGNED    NOT NULL DEFAULT 0,
            score_contribution DECIMAL(6,2)  NOT NULL DEFAULT 0,
            raw_data         LONGTEXT                 DEFAULT NULL,
            fetched_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            role             VARCHAR(20)     NOT NULL DEFAULT 'pending',
            trust_boost      DECIMAL(6,2)    NOT NULL DEFAULT 0,
            fraud_reduction  INT             NOT NULL DEFAULT 0,
            contract_address VARCHAR(128)             DEFAULT NULL,
            meta             TEXT                     DEFAULT NULL,
            last_synced      DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wallet_chain (wallet_address(191), chain),
            INDEX idx_user (user_id),
            INDEX idx_trust_boost (trust_boost)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Migration: add trust-engine columns to existing tables.
        // dbDelta handles adding new columns, but verify the role column
        // exists as a canary — if it doesn't, the ALTER failed silently.
        $has_role = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'role'",
            $table
        ));
        if (!$has_role) {
            $wpdb->query("ALTER TABLE {$table}
                ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER fetched_at,
                ADD COLUMN trust_boost DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER role,
                ADD COLUMN fraud_reduction INT NOT NULL DEFAULT 0 AFTER trust_boost,
                ADD COLUMN contract_address VARCHAR(128) DEFAULT NULL AFTER fraud_reduction,
                ADD COLUMN meta TEXT DEFAULT NULL AFTER contract_address,
                ADD COLUMN last_synced DATETIME DEFAULT NULL AFTER meta");
        }

        // Migrate existing FLOAT columns to DECIMAL on upgrade. dbDelta's
        // column-type comparison is unreliable, so run the MODIFY COLUMN
        // explicitly when we detect the old type.
        $score_type = $wpdb->get_var($wpdb->prepare(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'score_contribution'",
            $table
        ));
        if ($score_type !== null && strtolower((string) $score_type) === 'float') {
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN score_contribution DECIMAL(6,2) NOT NULL DEFAULT 0");
        }
        $boost_type = $wpdb->get_var($wpdb->prepare(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'trust_boost'",
            $table
        ));
        if ($boost_type !== null && strtolower((string) $boost_type) === 'float') {
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN trust_boost DECIMAL(6,2) NOT NULL DEFAULT 0");
        }

        // Add trust_boost index if missing.
        $has_idx = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'idx_trust_boost'",
            $table
        ));
        if (!$has_idx) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_trust_boost (trust_boost)");
        }
    }

    /** @param array<string, mixed> $data */
    public static function upsert(array $data): void
    {
        // Range-validate numeric fields from blockchain APIs.
        // Corrupted/malicious API responses returning negative or astronomically
        // large values would corrupt onchain_bonus and downstream trust scores.
        $score = (float) ($data['score_contribution'] ?? 0);
        if ($score < 0 || $score > 100 || !is_finite($score)) {
            \BCC\Core\Log\Logger::warning('[Onchain] Invalid score_contribution rejected', [
                'wallet'  => $data['wallet_address'] ?? 'unknown',
                'chain'   => $data['chain'] ?? 'unknown',
                'value'   => $data['score_contribution'] ?? null,
                'clamped' => true,
            ]);
            $data['score_contribution'] = max(0, min(100, is_finite($score) ? $score : 0));
        }

        $data['tx_count']       = max(0, (int) ($data['tx_count'] ?? 0));
        $data['contract_count'] = max(0, (int) ($data['contract_count'] ?? 0));
        $data['wallet_age_days'] = max(0, (int) ($data['wallet_age_days'] ?? 0));

        global $wpdb;
        $table  = self::table();
        $userId = (int) $data['user_id'];

        // Invalidate BEFORE the write so any concurrent reader that misses
        // the cache will query the DB and see the new (or in-flight) data
        // rather than re-caching a stale snapshot.
        self::invalidateUser($userId);

        // VALUES() is deprecated in MySQL 8.0.20+ but MariaDB does not support
        // the replacement AS-alias syntax. Since WordPress supports both MySQL
        // and MariaDB, VALUES() remains the only portable option.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (user_id, wallet_address, chain, wallet_age_days, first_tx_at, tx_count, contract_count, score_contribution, raw_data, fetched_at)
             VALUES (%d, %s, %s, %d, %s, %d, %d, %f, %s, NOW())
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                wallet_age_days = VALUES(wallet_age_days),
                first_tx_at = VALUES(first_tx_at),
                tx_count = VALUES(tx_count),
                contract_count = VALUES(contract_count),
                score_contribution = VALUES(score_contribution),
                raw_data = VALUES(raw_data),
                fetched_at = NOW()",
            $userId,
            $data['wallet_address'],
            $data['chain'],
            $data['wallet_age_days'],
            $data['first_tx_at'] ?? null,
            $data['tx_count'],
            $data['contract_count'],
            $data['score_contribution'],
            isset($data['raw_data']) ? wp_json_encode($data['raw_data']) : null
        ));
        // Snapshot last_error immediately — Logger::error below may internally
        // touch the DB (depending on log handler), which would clobber this
        // connection-global value before we interpolate it into the log line.
        $lastError = (string) $wpdb->last_error;

        if ($result === false) {
            \BCC\Core\Log\Logger::error('[Onchain] Upsert failed for ' . $data['wallet_address'] . ' on ' . $data['chain'] . ': ' . $lastError);
        } else {
            // Invalidate again after the write to catch any reader that
            // re-cached stale data between the pre-invalidation and commit.
            self::invalidateUser($userId);
        }
    }

    /**
     * Return stored data if fetched within BCC_ONCHAIN_CACHE_HOURS.
     *
     * @return array<string, mixed>|null
     */
    public static function get_cached(string $address, string $chain): ?array
    {
        global $wpdb;
        $table   = self::table();
        $max_age = BCC_ONCHAIN_CACHE_HOURS;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
             WHERE wallet_address = %s AND chain = %s
               AND fetched_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
             LIMIT 1",
            $address, $chain, $max_age
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Return stored data regardless of age.
     *
     * @return array<string, mixed>|null
     */
    public static function get_permanent(string $address, string $chain): ?array
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table} WHERE wallet_address = %s AND chain = %s LIMIT 1",
            $address, $chain
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Get all on-chain signals for all wallets belonging to the page owner.
     *
     * Results are served from object cache (per-request, persistent with Redis)
     * backed by a transient (cross-request on vanilla WP). The cache is keyed
     * by owner user_id and invalidated automatically on upsert().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_for_page(int $page_id): array
    {
        $owner_id = PeepSo::get_page_owner($page_id);
        if (!$owner_id) {
            return [];
        }

        return self::getByUser($owner_id);
    }

    /**
     * Get all signals for a user, with two-tier caching.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getByUser(int $userId): array
    {
        $cache_key     = 'signals_user_' . $userId;
        $transient_key = 'bcc_signals_u' . $userId;

        // 1. Object cache (fastest).
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        // 2. Transient (cross-request fallback without persistent object cache).
        $transient = get_transient($transient_key);
        if (is_array($transient)) {
            wp_cache_set($cache_key, $transient, self::CACHE_GROUP, self::ttl());
            return $transient;
        }

        // 3. DB query on cache miss.
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT " . self::COLUMNS . " FROM {$table} WHERE user_id = %d ORDER BY score_contribution DESC LIMIT 1000", $userId),
            ARRAY_A
        ) ?: [];

        $ttl = self::ttl();
        wp_cache_set($cache_key, $rows, self::CACHE_GROUP, $ttl);
        set_transient($transient_key, $rows, $ttl);

        return $rows;
    }

    /**
     * Invalidate cached signals for a user.
     *
     * Called automatically from upsert(). Also safe to call manually
     * from cron handlers or admin tools.
     */
    public static function invalidateUser(int $userId): void
    {
        wp_cache_delete('signals_user_' . $userId, self::CACHE_GROUP);
        delete_transient('bcc_signals_u' . $userId);
    }

    /**
     * Delete signal rows for a specific wallet address and chain.
     *
     * Called on wallet disconnect to prevent stale score_contribution
     * from lingering after the wallet link is removed.
     *
     * Resolves the affected user_id(s) BEFORE deletion so that the
     * per-user signal cache is invalidated. Without this, a subsequent
     * BonusService::recomputeAndApply read served from cache would
     * include the just-deleted row and write an inflated onchain_bonus
     * that persists until HOUR_IN_SECONDS expiry.
     */
    /**
     * Returns the row count on success or FALSE on DB error. Callers that
     * sit inside a transaction MUST treat false as a hard failure and
     * roll back — previously we collapsed false to 0 via (int) casting,
     * which let a transient DB error on wallet disconnect look like
     * "nothing to delete", leaving stale signal rows inflating the
     * user's onchain_bonus until the next scheduled refresh.
     *
     * @return int|false Rows deleted on success, false on query error.
     */
    public static function deleteByWallet(string $walletAddress, string $chain)
    {
        global $wpdb;
        $table = self::table();

        // Snapshot owners before delete — can't resolve them afterward.
        // Bounded by LIMIT: a single wallet_address on a single chain should
        // never legitimately map to more than a handful of users. The cap
        // keeps memory predictable if a misconfigured indexer ever writes
        // fan-out rows here — we'd rather under-notify a few stragglers
        // than OOM the worker on the snapshot.
        /** @var list<string>|null $userIds */
        $userIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$table}
              WHERE wallet_address = %s AND chain = %s
              LIMIT 50",
            $walletAddress,
            $chain
        ));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE wallet_address = %s AND chain = %s",
            $walletAddress,
            $chain
        ));
        // Snapshot last_error immediately — any downstream log call could
        // issue another query that clobbers the connection-global field.
        $lastError = (string) $wpdb->last_error;

        if ($deleted === false) {
            \BCC\Core\Log\Logger::error('[Onchain] deleteByWallet failed', [
                'wallet'   => $walletAddress,
                'chain'    => $chain,
                'db_error' => $lastError,
            ]);
            // Do NOT invalidate cache — the rows still exist, the cache
            // is still correct. Returning false lets the caller (inside
            // a transaction in handleWalletDisconnect) roll back cleanly.
            return false;
        }

        foreach ($userIds ?: [] as $uid) {
            self::invalidateUser((int) $uid);
        }

        return max(0, (int) $deleted);
    }

    // ── Trust-signal write/read methods ─────────────────────────────────
    // These methods mirror the former WalletSignalRepository interface.
    // Trust-engine calls these via the WalletSignalWriteInterface contract.

    /**
     * Upsert trust-scoring columns for a wallet signal row.
     *
     * If a row already exists for (wallet_address, chain), updates the trust
     * columns. Otherwise inserts a minimal row with the trust columns set.
     *
     * @param array<string, mixed> $extra
     */
    public static function upsertTrustSignal(
        int    $userId,
        string $chain,
        string $walletAddress,
        string $role,
        float  $trustBoost,
        int    $fraudReduction,
        string $contractAddress = '',
        array  $extra = []
    ): void {
        global $wpdb;
        $table = self::table();

        // Range-clamp before the write — matches upsert()'s defence.
        // Trust-engine callers are trusted but a bug or schema drift
        // upstream shouldn't corrupt the DECIMAL(6,2) column or the
        // downstream onchain_bonus math.
        $trustBoost = self::clampTrustBoost($trustBoost, $walletAddress, $chain);
        if ($fraudReduction < 0 || $fraudReduction > 100) {
            \BCC\Core\Log\Logger::warning('[Onchain] fraud_reduction out of range — clamped', [
                'wallet' => $walletAddress,
                'chain'  => $chain,
                'value'  => $fraudReduction,
            ]);
            $fraudReduction = max(0, min(100, $fraudReduction));
        }

        $meta = wp_json_encode(array_merge($extra, [
            'verified_at' => current_time('mysql', true),
        ]));

        self::invalidateUser($userId);

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (user_id, wallet_address, chain, role, trust_boost, fraud_reduction, contract_address, meta, last_synced, fetched_at)
             VALUES (%d, %s, %s, %s, %f, %d, %s, %s, %s, NOW())
             ON DUPLICATE KEY UPDATE
               user_id          = VALUES(user_id),
               role             = VALUES(role),
               trust_boost      = VALUES(trust_boost),
               fraud_reduction  = VALUES(fraud_reduction),
               contract_address = VALUES(contract_address),
               meta             = VALUES(meta),
               last_synced      = VALUES(last_synced)",
            $userId,
            $walletAddress,
            $chain,
            $role,
            $trustBoost,
            $fraudReduction,
            $contractAddress ?: null,
            $meta,
            current_time('mysql', true)
        ));

        self::invalidateUser($userId);
    }

    /**
     * Save NFT collection metadata and recalculated trust boost for a wallet.
     *
     * @param list<array<string, mixed>> $collections
     */
    public static function saveCollections(
        int    $userId,
        string $chain,
        string $walletAddress,
        array  $collections,
        float  $trustBoost
    ): void {
        global $wpdb;
        $table = self::table();

        $trustBoost = self::clampTrustBoost($trustBoost, $walletAddress, $chain);

        /** @var TrustSignalMinimalRow|null $existing */
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, meta FROM {$table}
             WHERE wallet_address = %s AND chain = %s LIMIT 1",
            $walletAddress,
            $chain
        ));

        if (!$existing) {
            return;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($existing->meta ?: '{}', true) ?: [];
        $decoded['nft_collections'] = $collections;
        $decoded['nft_updated_at']  = current_time('mysql', true);

        $wpdb->update(
            $table,
            [
                'trust_boost' => $trustBoost,
                'meta'        => wp_json_encode($decoded),
                'last_synced' => current_time('mysql', true),
            ],
            ['id' => (int) $existing->id]
        );

        self::invalidateUser($userId);
    }

    /**
     * Get trust-signal row for a single chain+wallet, with decoded meta.
     *
     * The returned stdClass carries every column from TrustSignalRow plus two
     * decoration props populated from the JSON `meta` blob:
     *   ->nft_collections  list<array<array-key, mixed>>
     *   ->wallet_role      string
     *
     * ⚠️ CONSUMER CONTRACT — BOTH decoration props are sourced from a JSON blob
     * that this repository does NOT fully validate. Treat them as UNTRUSTED input
     * and re-validate at every point where they drive logic:
     *
     *   • `wallet_role` may not be in the expected enum {pending, none, operator,
     *     creator, holder, validator}. Callers performing authorization or trust
     *     bonus math MUST check the value against an explicit allow-list before
     *     branching on it. Do not use it as a key into a trust-weight table
     *     without a default-deny fallback.
     *
     *   • `nft_collections` is a list of arrays with arbitrary keys — the shape
     *     of each element is NOT validated. Accessing `$entry['contract_address']`
     *     without an `isset` check can produce nulls that silently corrupt
     *     downstream score aggregation.
     *
     * This is the plugin's primary future failure vector because it crosses
     * domain boundaries (bcc-trust's Core consumes it) and bypasses static
     * guarantees. A shared cross-domain DTO is the proper fix — see the note
     * on decorateTrustSignal() for the migration direction.
     */
    public static function getTrustSignalForUserChain(int $userId, string $chain): ?\stdClass
    {
        global $wpdb;
        $table = self::table();

        /** @var TrustSignalRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT wallet_address, chain, role, trust_boost, fraud_reduction,
                    contract_address, meta, last_synced
             FROM {$table}
             WHERE user_id = %d AND chain = %s
             LIMIT 1",
            $userId,
            $chain
        ));

        if (!$row) {
            return null;
        }

        return self::decorateTrustSignal($row);
    }

    /**
     * Get all trust-signal rows for a user, keyed by chain name.
     *
     * Each returned stdClass has the same decoration contract as
     * getTrustSignalForUserChain() — including the ⚠️ UNTRUSTED / re-validate
     * requirement on `wallet_role` and `nft_collections`.
     *
     * @return array<string, \stdClass>
     */
    public static function getAllTrustSignalsForUser(int $userId): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<TrustSignalRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT wallet_address, chain, role, trust_boost, fraud_reduction,
                    contract_address, meta, last_synced
             FROM {$table}
             WHERE user_id = %d AND role != 'pending'",
            $userId
        ));

        if (!is_array($rows)) {
            return [];
        }

        $signals = [];
        foreach ($rows as $row) {
            $decorated            = self::decorateTrustSignal($row);
            $signals[$row->chain] = $decorated;
        }

        return $signals;
    }

    /**
     * Decorate a trust-signal row with parsed meta fields (nft_collections, wallet_role).
     * Builds a fresh stdClass so callers receive every SQL column plus the two
     * decoration props.
     *
     * ARCHITECTURAL NOTE (tracked debt): this is the plugin's weakest type boundary.
     * The returned stdClass shape is implicit — PHPStan cannot statically verify
     * that `nft_collections` / `wallet_role` are present, and external consumers
     * (currently bcc-trust's Core via WalletSignalWriteInterface) read both.
     * A shared cross-domain DTO (e.g. \BCC\Core\DTO\TrustSignalDTO) should replace
     * this pattern once the same problem is solved for TrendingDataInterface —
     * the shape is genuinely identical there. Until then, any consumer that
     * reads `wallet_role` outside the enum {pending, none, operator, creator,
     * holder, …} must treat it defensively.
     *
     * @param TrustSignalRow $row
     */
    private static function decorateTrustSignal(object $row): \stdClass
    {
        // Guard rail: the SQL shape declared TrustSignalRow as always having these
        // columns. If a future schema change drops one, (array)$row would produce
        // an array without the key, which array_merge silently covers with the
        // merged defaults — masking the drift. Fail fast instead of producing
        // corrupted trust data.
        $rowArr = (array) $row;
        foreach (['wallet_address', 'chain', 'role', 'meta'] as $required) {
            if (!array_key_exists($required, $rowArr)) {
                throw new \LogicException(
                    "SignalRepository::decorateTrustSignal: required column '{$required}' missing from row — schema drift?"
                );
            }
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($row->meta ?: '{}', true) ?: [];

        $rawCollections = $decoded['nft_collections'] ?? [];
        $nftCollections = [];
        if (is_array($rawCollections)) {
            $total = count($rawCollections);
            foreach ($rawCollections as $collection) {
                if (is_array($collection)) {
                    $nftCollections[] = $collection;
                }
            }
            $dropped = $total - count($nftCollections);
            if ($dropped > 0) {
                self::reportCollectionDropsOnce($row->wallet_address, $row->chain, $total, count($nftCollections));
            }
        }

        $rawRole    = $decoded['role'] ?? $row->role;
        $walletRole = is_string($rawRole) ? $rawRole : $row->role;

        return (object) array_merge($rowArr, [
            'nft_collections' => $nftCollections,
            'wallet_role'     => $walletRole,
        ]);
    }

    /**
     * Rate-limited reporter for malformed nft_collections drops.
     *
     * A single bad meta blob typically contains multiple malformed entries, and a
     * hot trust-score path will decode the same row on every request. Without
     * dedup we'd emit one log line per bad entry per request — easily hundreds of
     * lines per minute per corrupted wallet. Throttle to one log line per
     * (wallet, chain) per 15 minutes; the `kept`/`total` counts in the payload
     * still reveal the scale of the drop.
     */
    private static function reportCollectionDropsOnce(
        string $walletAddress,
        string $chain,
        int $total,
        int $kept
    ): void {
        if (!class_exists('\\BCC\\Core\\Log\\Logger')) {
            return;
        }

        // md5 keeps the transient key short and free of address-format edge cases
        // (cosmos addresses can exceed the safe transient-key length on some hosts).
        $dedupKey = 'bcc_sig_drop_' . md5($chain . '|' . strtolower($walletAddress));
        if (get_transient($dedupKey) !== false) {
            return;
        }
        set_transient($dedupKey, 1, 15 * MINUTE_IN_SECONDS);

        \BCC\Core\Log\Logger::warning('[SignalRepository] dropped malformed nft_collections entries', [
            'wallet' => $walletAddress,
            'chain'  => $chain,
            'total'  => $total,
            'kept'   => $kept,
        ]);
    }

    /**
     * Zero out trust scoring for a disconnected wallet.
     */
    public static function disconnectTrustSignal(int $userId, string $chain): void
    {
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET role = 'none', trust_boost = 0, fraud_reduction = 0,
                 last_synced = %s
             WHERE user_id = %d AND chain = %s",
            current_time('mysql', true),
            $userId,
            $chain
        ));

        self::invalidateUser($userId);
    }

    /**
     * Sum trust_boost across all chains for a user.
     */
    public static function getTotalTrustBoost(int $userId): float
    {
        global $wpdb;
        $table = self::table();

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trust_boost), 0) FROM {$table} WHERE user_id = %d",
            $userId
        ));
    }

    /**
     * Delete all signal rows for a user (full account cleanup).
     */
    public static function deleteForUser(int $userId): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->delete($table, ['user_id' => $userId], ['%d']);
        self::invalidateUser($userId);
    }

    private static function ttl(): int
    {
        return (int) apply_filters('bcc_onchain_signal_cache_ttl', self::DEFAULT_TTL);
    }

    /**
     * Clamp a trust_boost value to the 0..100 range expected by the
     * DECIMAL(6,2) column and the onchain_bonus cap. NaN/Inf collapses
     * to 0 so downstream SUM() math never produces a NaN row. Logs on
     * coercion so a broken upstream is visible rather than silent.
     */
    private static function clampTrustBoost(float $trustBoost, string $walletAddress, string $chain): float
    {
        if (is_finite($trustBoost) && $trustBoost >= 0.0 && $trustBoost <= 100.0) {
            return $trustBoost;
        }
        \BCC\Core\Log\Logger::warning('[Onchain] trust_boost out of range — clamped', [
            'wallet' => $walletAddress,
            'chain'  => $chain,
            'value'  => is_finite($trustBoost) ? $trustBoost : 'non-finite',
        ]);
        if (!is_finite($trustBoost)) {
            return 0.0;
        }
        return max(0.0, min(100.0, $trustBoost));
    }
}
