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
     * Stamp the last_holdings_refresh_at column. Called by HoldingsService
     * after a successful fresh fetch from chain — so the UI can show
     * "refreshed N ago" without consulting the transient cache directly.
     */
    public static function markHoldingsRefreshed(int $walletLinkId): bool
    {
        global $wpdb;
        $table = self::table();

        $result = $wpdb->update(
            $table,
            ['last_holdings_refresh_at' => current_time('mysql', true)],
            ['id' => $walletLinkId],
            ['%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Return last_holdings_refresh_at timestamps for the user's wallets,
     * keyed by wallet_link_id. NULL values are dropped from the result.
     *
     * @return array<int, string>
     */
    public static function getHoldingsRefreshForUser(int $userId): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<object{id: string, last_holdings_refresh_at: ?string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, last_holdings_refresh_at FROM {$table}
             WHERE user_id = %d AND last_holdings_refresh_at IS NOT NULL
             LIMIT 200",
            $userId
        ));

        $out = [];
        foreach ($rows ?: [] as $r) {
            if (!empty($r->last_holdings_refresh_at)) {
                $out[(int) $r->id] = (string) $r->last_holdings_refresh_at;
            }
        }
        return $out;
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
     * Reverse lookup: peepso-page post_id → underlying on-chain entity.
     *
     * Walks wallet_links → validators / collections to find the first
     * matching entity for the given page. Validator is preferred over
     * collection if both exist (V1 convention; revisit if multi-entity
     * pages become a thing).
     *
     * Used by CardViewService to emit a pre-baked `actions.claim` body
     * on the card view-model so the frontend doesn't have to construct
     * the page→entity mapping itself ("frontend is dumb" rule).
     *
     * Returns null when the page has no linked entity (e.g., a
     * standalone profile page without on-chain provenance).
     *
     * @return array{entity_type: 'validator'|'collection', entity_id: int}|null
     */
    public static function resolveEntityForPage(int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }

        global $wpdb;
        $walletLinks = self::table();
        $validators  = ValidatorRepository::table();
        $collections = CollectionRepository::table();

        /** @var object{entity_id: int|numeric-string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT v.id AS entity_id
               FROM {$walletLinks} w
               INNER JOIN {$validators} v ON v.wallet_link_id = w.id
              WHERE w.post_id = %d
              ORDER BY w.is_primary DESC, w.created_at ASC
              LIMIT 1",
            $postId
        ));
        if ($row !== null) {
            return ['entity_type' => 'validator', 'entity_id' => (int) $row->entity_id];
        }

        /** @var object{entity_id: int|numeric-string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.id AS entity_id
               FROM {$walletLinks} w
               INNER JOIN {$collections} c ON c.wallet_link_id = w.id
              WHERE w.post_id = %d
              ORDER BY w.is_primary DESC, w.created_at ASC
              LIMIT 1",
            $postId
        ));
        if ($row !== null) {
            return ['entity_type' => 'collection', 'entity_id' => (int) $row->entity_id];
        }

        return null;
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

    /**
     * Resolve the user_id linked to a (chain, wallet) pair, or 0 when no
     * such link exists. Used by /auth/wallet-login to identify the
     * account a signed challenge belongs to.
     *
     * The (chain_id, wallet_address) pair is constrained to one user by
     * the application layer (walletLink/walletSignup reject when
     * existsForOtherUser fires), so any matching row is the answer.
     * LIMIT 1 keeps the query bounded per repository convention.
     */
    public static function findUserIdByAddress(int $chainId, string $walletAddress): int
    {
        global $wpdb;
        $table = self::table();
        $walletAddress = strtolower($walletAddress);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table}
             WHERE chain_id = %d AND wallet_address = %s
             LIMIT 1",
            $chainId, $walletAddress
        ));
    }

    /**
     * Set the `helius_managed` flag on a single wallet link. Used when
     * the Helius webhook subscription's address list is mutated (PATCH
     * /v0/webhooks/:id) so the bookkeeping never drifts from the actual
     * remote state.
     *
     * Per Phase 1b spike: one shared webhook handles up to 100 000
     * addresses, so a per-wallet boolean flag is sufficient — no
     * separate subscriptions table is needed.
     */
    public static function markHeliusManaged(int $walletLinkId, bool $managed): bool
    {
        if ($walletLinkId <= 0) {
            return false;
        }

        global $wpdb;
        $updated = $wpdb->update(
            self::table(),
            ['helius_managed' => $managed ? 1 : 0],
            ['id' => $walletLinkId],
            ['%d'],
            ['%d']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * @return list<array{id: int, wallet_address: string}>
     */
    public static function listForChainBySolanaSync(int $chainId, bool $heliusManaged): array
    {
        if ($chainId <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::table();

        /** @var list<object{id: string, wallet_address: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, wallet_address
               FROM {$table}
              WHERE chain_id = %d
                AND helius_managed = %d
              ORDER BY id ASC
              LIMIT 100000",
            $chainId,
            $heliusManaged ? 1 : 0
        ));

        if ($rows === null) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'             => (int) $row->id,
                'wallet_address' => (string) $row->wallet_address,
            ];
        }
        return $out;
    }

    /**
     * Batched (chain, address) → wallet_link_id resolver. Returns map
     * keyed by lowercased address. Addresses with no link on this chain
     * are absent from the result. Used by the NftHoldingsIndexer batch
     * write path so per-event lookups are amortized into one query
     * per indexer batch.
     *
     * @param list<string> $addresses
     * @return array<string, int>  lowercased_address => wallet_link_id
     */
    public static function findIdsByChainAddresses(int $chainId, array $addresses): array
    {
        if ($chainId <= 0 || $addresses === []) {
            return [];
        }

        // Lowercase + dedupe + cap at 1000 per batch (defensive bound).
        $clean = [];
        foreach ($addresses as $a) {
            if (!is_string($a) || $a === '') {
                continue;
            }
            $clean[strtolower($a)] = true;
        }
        $clean = array_keys($clean);
        if (count($clean) > 1000) {
            $clean = array_slice($clean, 0, 1000);
        }
        if ($clean === []) {
            return [];
        }

        global $wpdb;
        $table = self::table();

        $placeholders = implode(',', array_fill(0, count($clean), '%s'));
        $params       = array_merge([$chainId], $clean);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, wallet_address
               FROM {$table}
              WHERE chain_id = %d
                AND wallet_address IN ({$placeholders})",
            $params
        ));

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[strtolower((string) $row->wallet_address)] = (int) $row->id;
            }
        }
        return $out;
    }
}
