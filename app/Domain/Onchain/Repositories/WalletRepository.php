<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type WalletWithChain object{
 *     id: string,
 *     user_id: string,
 *     post_id: string,
 *     wallet_address: string,
 *     chain_id: string,
 *     wallet_type: string,
 *     label: string|null,
 *     verified_at: string|null,
 *     is_primary: string,
 *     created_at: string,
 *     chain_slug: string,
 *     chain_name: string,
 *     chain_type: string,
 *     explorer_url: string|null
 * }
 */
final class WalletRepository
{
    public static function table(): string
    {
        return DB::table('wallet_links');
    }

    /**
     * Atomic insert-or-find using INSERT ... ON DUPLICATE KEY UPDATE.
     *
     * Relies on the UNIQUE KEY user_chain_wallet (user_id, chain_id, wallet_address).
     * If the row already exists, returns ['id' => existing_id, 'inserted' => false].
     * If newly inserted, returns ['id' => new_id, 'inserted' => true].
     * Returns ['id' => 0, 'inserted' => false] on hard failure.
     *
     * @param array<string, mixed> $data
     * @return array{id: int, inserted: bool}
     */
    public static function insertOrFind(array $data): array
    {
        global $wpdb;
        $table = self::table();

        $userId  = (int) $data['user_id'];
        $postId  = (int) $data['post_id'];
        $address = strtolower(sanitize_text_field($data['wallet_address']));
        $chainId = (int) $data['chain_id'];
        $type    = sanitize_text_field($data['wallet_type'] ?? 'user');
        $label   = isset($data['label']) ? sanitize_text_field($data['label']) : '';

        // id = LAST_INSERT_ID(id) on duplicate makes $wpdb->insert_id return
        // the existing row's ID, giving us a single round-trip atomic upsert.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (user_id, post_id, wallet_address, chain_id, wallet_type, label)
             VALUES (%d, %d, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            $userId, $postId, $address, $chainId, $type, $label
        ));

        if ($result === false) {
            return ['id' => 0, 'inserted' => false];
        }

        $id = (int) $wpdb->insert_id;

        // $wpdb->rows_affected: 1 = inserted, 2 = duplicate key triggered update
        return [
            'id'       => $id,
            'inserted' => ((int) $wpdb->rows_affected === 1),
        ];
    }

    public static function verify(int $walletLinkId): bool
    {
        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->update(
            $table,
            ['verified_at' => current_time('mysql', true)],
            ['id' => $walletLinkId],
            ['%s'],
            ['%d']
        );
    }

    public static function delete(int $walletLinkId, int $userId): bool
    {
        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->delete(
            $table,
            ['id' => $walletLinkId, 'user_id' => $userId],
            ['%d', '%d']
        );
    }

    /**
     * Delete all wallet links owned by a user (full account cleanup).
     */
    public static function deleteForUser(int $userId): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->delete($table, ['user_id' => $userId], ['%d']);
    }

    public static function setPrimary(int $walletLinkId, int $userId): bool
    {
        global $wpdb;
        $table = self::table();

        $wpdb->query('START TRANSACTION');

        // Lock the target row to prevent concurrent setPrimary calls
        // from reading stale chain_id between SELECT and UPDATE.
        $chainId = $wpdb->get_var($wpdb->prepare(
            "SELECT chain_id FROM {$table} WHERE id = %d AND user_id = %d FOR UPDATE",
            $walletLinkId, $userId
        ));

        if (!$chainId) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        // Single atomic UPDATE: set is_primary based on whether the row ID
        // matches the target. This prevents the dual-primary state that could
        // occur if two separate UPDATEs (clear all → set one) are interrupted.
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET is_primary = CASE WHEN id = %d THEN 1 ELSE 0 END
             WHERE user_id = %d AND chain_id = %d",
            $walletLinkId,
            $userId,
            $chainId
        ));

        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $wpdb->query('COMMIT');
        return true;
    }

    /** @return list<WalletWithChain> */
    public static function getForUser(int $userId, ?string $walletType = null, bool $verifiedOnly = false): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $where = ['w.user_id = %d'];
        $args  = [$userId];

        if ($walletType) {
            $where[] = 'w.wallet_type = %s';
            $args[]  = $walletType;
        }

        if ($verifiedOnly) {
            $where[] = 'w.verified_at IS NOT NULL';
        }

        $whereSql = implode(' AND ', $where);

        // Scope: a user's wallets. A legitimate user has a handful of
        // wallets per chain. LIMIT caps a misconfigured dataset so this
        // query can't return unbounded memory if something ever inflates
        // the wallets table for a single user.
        /** @var list<WalletWithChain>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT w.id, w.user_id, w.post_id, w.wallet_address, w.chain_id, w.wallet_type,
                    w.label, w.verified_at, w.is_primary, w.created_at,
                    c.slug AS chain_slug, c.name AS chain_name, c.chain_type, c.explorer_url
             FROM {$table} w
             JOIN {$chains} c ON c.id = w.chain_id
             WHERE {$whereSql}
             ORDER BY w.is_primary DESC, w.created_at ASC
             LIMIT 200",
            ...$args
        ));

        return $rows ?: [];
    }

    /** @return list<WalletWithChain> */
    public static function getForProject(int $postId, ?string $walletType = null): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $where = ['w.post_id = %d'];
        $args  = [$postId];

        if ($walletType) {
            $where[] = 'w.wallet_type = %s';
            $args[]  = $walletType;
        }

        $whereSql = implode(' AND ', $where);

        // Scope: wallets for a single page. Same defence-in-depth cap as
        // getForUser above — a project page has a small, human-curated
        // set of wallets; 200 is generous and bounds memory.
        /** @var list<WalletWithChain>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT w.id, w.user_id, w.post_id, w.wallet_address, w.chain_id, w.wallet_type,
                    w.label, w.verified_at, w.is_primary, w.created_at,
                    c.slug AS chain_slug, c.name AS chain_name, c.chain_type, c.explorer_url
             FROM {$table} w
             JOIN {$chains} c ON c.id = w.chain_id
             WHERE {$whereSql}
             ORDER BY w.is_primary DESC, w.wallet_type ASC, w.created_at ASC
             LIMIT 200",
            ...$args
        ));

        return $rows ?: [];
    }

    /** @return WalletWithChain|null */
    public static function getById(int $walletLinkId): ?object
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var WalletWithChain|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT w.id, w.user_id, w.post_id, w.wallet_address, w.chain_id, w.wallet_type,
                    w.label, w.verified_at, w.is_primary, w.created_at,
                    c.slug AS chain_slug, c.name AS chain_name, c.chain_type, c.explorer_url
             FROM {$table} w
             JOIN {$chains} c ON c.id = w.chain_id
             WHERE w.id = %d",
            $walletLinkId
        ));
    }

    /**
     * Find wallet link ID by user, chain, and address. Used by unlink flows.
     */
    public static function findIdByUserChainAddress(int $userId, int $chainId, string $walletAddress): int
    {
        global $wpdb;
        $table = self::table();
        $walletAddress = strtolower($walletAddress);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND chain_id = %d AND wallet_address = %s LIMIT 1",
            $userId, $chainId, $walletAddress
        ));
    }

    /**
     * Count how many wallets a user has on a specific chain.
     *
     * Used to decide whether a newly linked wallet should be set as primary
     * without loading every wallet row for the user (avoids N+1).
     */
    public static function countForUserByChain(int $userId, int $chainId): int
    {
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND chain_id = %d",
            $userId, $chainId
        ));
    }

    /**
     * Check whether a user has at least one wallet on a specific chain.
     */
    public static function hasLinkForChain(int $userId, int $chainId): bool
    {
        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id = %d AND chain_id = %d LIMIT 1",
            $userId, $chainId
        ));
    }

    /**
     * Get distinct user IDs that have wallet links on any of the given chain slugs.
     *
     * @param string[] $chainSlugs
     * @return int[]
     */
    public static function getUserIdsWithChainSlugs(array $chainSlugs, int $limit = 100, int $offset = 0): array
    {
        if (empty($chainSlugs)) {
            return [];
        }

        global $wpdb;
        $table      = self::table();
        $chainTable = ChainRepository::table();

        $slugPlaceholders = implode(',', array_fill(0, count($chainSlugs), '%s'));
        $args = array_merge($chainSlugs, [$limit, $offset]);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT w.user_id
             FROM {$table} w
             JOIN {$chainTable} c ON c.id = w.chain_id
             WHERE c.slug IN ({$slugPlaceholders})
             LIMIT %d OFFSET %d",
            ...$args
        ));

        return array_map('intval', $ids);
    }

    /**
     * Resolve post_id from an entity row via wallet_link.
     * Works for both validators and collections.
     *
     * @param string $entityTable  Fully-qualified table name.
     * @param int    $entityId     Row ID in the entity table.
     */
    public static function getPostIdForEntity(string $entityTable, int $entityId): int
    {
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT w.post_id
             FROM {$entityTable} e
             JOIN {$table} w ON w.id = e.wallet_link_id
             WHERE e.id = %d
             LIMIT 1",
            $entityId
        ));
    }

    public static function exists(int $userId, int $chainId, string $walletAddress): bool
    {
        global $wpdb;
        $table = self::table();
        $walletAddress = strtolower($walletAddress);

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$table} WHERE user_id = %d AND chain_id = %d AND wallet_address = %s",
            $userId, $chainId, $walletAddress
        ));
    }

    /**
     * Check whether a wallet address on a chain is already linked to a different user.
     */
    public static function existsForOtherUser(int $userId, int $chainId, string $walletAddress): bool
    {
        global $wpdb;
        $table = self::table();
        $walletAddress = strtolower($walletAddress);

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$table} WHERE user_id != %d AND chain_id = %d AND wallet_address = %s",
            $userId, $chainId, $walletAddress
        ));
    }
}
