<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type NftSelectionRow object{
 *     id: string,
 *     user_id: string,
 *     wallet_link_id: string,
 *     chain_id: string,
 *     contract_address: string,
 *     token_id: string,
 *     collection_name: string|null,
 *     name: string|null,
 *     image_url: string|null,
 *     metadata_uri: string|null,
 *     token_standard: string|null,
 *     display_order: string,
 *     added_at: string
 * }
 *
 * @phpstan-type NftSelectionWithChain object{
 *     id: string,
 *     user_id: string,
 *     wallet_link_id: string,
 *     chain_id: string,
 *     contract_address: string,
 *     token_id: string,
 *     collection_name: string|null,
 *     name: string|null,
 *     image_url: string|null,
 *     metadata_uri: string|null,
 *     token_standard: string|null,
 *     display_order: string,
 *     added_at: string,
 *     chain_slug: string,
 *     chain_name: string,
 *     explorer_url: string|null
 * }
 */
final class NftSelectionRepository
{
    /** Cache group for §5 generation counters. */
    private const CACHE_GROUP = 'bcc_nft_selections';
    private const GENERATION_KEY_PREFIX = 'gen_user_selections_';

    public static function table(): string
    {
        return DB::table('user_nft_selections');
    }

    /**
     * Insert or update a selection in a single query.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE keyed on
     * `uq_user_token (user_id, chain_id, contract_address, token_id)` so a
     * concurrent double-click can't race the existence check (the previous
     * 3-query implementation could produce duplicate-key errors). The
     * `id = LAST_INSERT_ID(id)` trick makes $wpdb->insert_id return the
     * correct row id on both insert and update paths.
     *
     * For new rows, display_order is computed inline as MAX+1 via a wrapped
     * SELECT (MySQL forbids referencing the target table directly in a VALUES
     * subquery without the wrap). For existing rows, display_order is
     * preserved — only the snapshot metadata fields update.
     *
     * @param array{
     *     user_id: int,
     *     wallet_link_id: int,
     *     chain_id: int,
     *     contract_address: string,
     *     token_id: string,
     *     collection_name?: ?string,
     *     name?: ?string,
     *     image_url?: ?string,
     *     metadata_uri?: ?string,
     *     token_standard?: ?string
     * } $data
     * @return int|false Row id, or false on failure.
     */
    public static function save(array $data)
    {
        global $wpdb;
        $table = self::table();

        // Build NULL-safe SQL fragments for nullable %s fields. wpdb->prepare
        // converts a PHP null into an empty string for %s, which would fail
        // to preserve the column's NULL semantics on snapshot fields.
        $nullable = [];
        foreach (['collection_name', 'name', 'image_url', 'metadata_uri', 'token_standard'] as $col) {
            $val = $data[$col] ?? null;
            $nullable[$col] = ($val === null || $val === '')
                ? 'NULL'
                : $wpdb->prepare('%s', (string) $val);
        }

        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
                (user_id, wallet_link_id, chain_id, contract_address, token_id,
                 collection_name, name, image_url, metadata_uri, token_standard,
                 display_order)
             VALUES
                (%d, %d, %d, %s, %s,
                 {$nullable['collection_name']}, {$nullable['name']},
                 {$nullable['image_url']}, {$nullable['metadata_uri']},
                 {$nullable['token_standard']},
                 (SELECT next_order FROM (
                    SELECT COALESCE(MAX(display_order), -1) + 1 AS next_order
                    FROM {$table} WHERE user_id = %d
                 ) AS tmp))
             ON DUPLICATE KEY UPDATE
                id              = LAST_INSERT_ID(id),
                wallet_link_id  = VALUES(wallet_link_id),
                collection_name = VALUES(collection_name),
                name            = VALUES(name),
                image_url       = VALUES(image_url),
                metadata_uri    = VALUES(metadata_uri),
                token_standard  = VALUES(token_standard)",
            (int) $data['user_id'],
            (int) $data['wallet_link_id'],
            (int) $data['chain_id'],
            (string) $data['contract_address'],
            (string) $data['token_id'],
            (int) $data['user_id']
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
            return false;
        }

        self::bumpUserGeneration((int) $data['user_id']);

        // insert_id returns auto-increment for new INSERTs and the
        // LAST_INSERT_ID(id) value for ON DUPLICATE KEY UPDATE matches.
        return $wpdb->insert_id ?: false;
    }

    public static function deleteById(int $id, int $userId): bool
    {
        global $wpdb;
        $table = self::table();

        // WHERE user_id guards against IDOR — a user can only delete their own.
        $result = $wpdb->delete(
            $table,
            ['id' => $id, 'user_id' => $userId],
            ['%d', '%d']
        );

        $ok = $result !== false && $result > 0;
        if ($ok) {
            self::bumpUserGeneration($userId);
        }
        return $ok;
    }

    /**
     * Delete by (user, chain, contract, token_id). Used when the picker
     * sends a deselect action keyed by identity rather than row id.
     */
    public static function deleteByIdentity(int $userId, int $chainId, string $contract, string $tokenId): bool
    {
        global $wpdb;
        $table = self::table();

        $result = $wpdb->delete(
            $table,
            [
                'user_id'          => $userId,
                'chain_id'         => $chainId,
                'contract_address' => $contract,
                'token_id'         => $tokenId,
            ],
            ['%d', '%d', '%s', '%s']
        );

        $ok = $result !== false && $result > 0;
        if ($ok) {
            self::bumpUserGeneration($userId);
        }
        return $ok;
    }

    /**
     * Delete all selections for a user (full account cleanup).
     */
    public static function deleteForUser(int $userId): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->delete($table, ['user_id' => $userId], ['%d']);
    }

    /**
     * List all selections for a user, joined with chain metadata.
     *
     * @return list<NftSelectionWithChain>
     */
    public static function getForUser(int $userId, int $limit = 200): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var list<NftSelectionWithChain>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.user_id, s.wallet_link_id, s.chain_id, s.contract_address,
                    s.token_id, s.collection_name, s.name, s.image_url, s.metadata_uri,
                    s.token_standard, s.display_order, s.added_at,
                    c.slug AS chain_slug, c.name AS chain_name, c.explorer_url
             FROM {$table} s
             JOIN {$chains} c ON c.id = s.chain_id
             WHERE s.user_id = %d
             ORDER BY s.display_order ASC, s.added_at ASC
             LIMIT %d",
            $userId,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Reorder selections. Accepts an ordered list of selection ids; any id
     * not belonging to this user is silently ignored (no error leak).
     *
     * @param list<int> $orderedIds
     */
    public static function reorder(int $userId, array $orderedIds): int
    {
        global $wpdb;
        $table = self::table();

        $updated = 0;
        foreach (array_values($orderedIds) as $position => $id) {
            $result = $wpdb->update(
                $table,
                ['display_order' => (int) $position],
                ['id' => (int) $id, 'user_id' => $userId],
                ['%d'],
                ['%d', '%d']
            );
            if ($result !== false && $result > 0) {
                $updated++;
            }
        }

        if ($updated > 0) {
            self::bumpUserGeneration($userId);
        }
        return $updated;
    }

    /**
     * Set of (chain_id, contract_address, token_id) the user has already
     * selected. Used by the picker to render "already selected" state.
     *
     * @return array<string, true> keyed by "chain_id|contract|token_id" lowercased
     */
    public static function selectedKeysForUser(int $userId): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<object{chain_id: string, contract_address: string, token_id: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT chain_id, contract_address, token_id
             FROM {$table}
             WHERE user_id = %d
             LIMIT 1000",
            $userId
        ));

        $keys = [];
        foreach ($rows ?: [] as $r) {
            $keys[strtolower($r->chain_id . '|' . $r->contract_address . '|' . $r->token_id)] = true;
        }
        return $keys;
    }

    // ── Cache invalidation (§5 generation counter) ────────────────────

    /**
     * Bump the per-user selections generation counter. Called from
     * save / delete / reorder so any future read-side cache (currently
     * none, but the read-side hook lands when picker GETs start
     * keying on it) sees mutations on the next request.
     *
     * Mirrors NftHoldingsRepository::bumpWalletGeneration — the
     * defensive `wp_cache_add` seeds the value because some object
     * cache backends don't auto-seed on `wp_cache_incr`.
     */
    public static function bumpUserGeneration(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $key = self::GENERATION_KEY_PREFIX . $userId;
        if (wp_cache_get($key, self::CACHE_GROUP) === false) {
            wp_cache_add($key, 1, self::CACHE_GROUP);
            return;
        }
        wp_cache_incr($key, 1, self::CACHE_GROUP);
    }

    /**
     * Read the current per-user selections generation. Returns 0 when
     * the counter hasn't been seeded yet (no writes since cache reset).
     */
    public static function getUserGeneration(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $key = self::GENERATION_KEY_PREFIX . $userId;
        $value = wp_cache_get($key, self::CACHE_GROUP);
        return is_numeric($value) ? (int) $value : 0;
    }
}
