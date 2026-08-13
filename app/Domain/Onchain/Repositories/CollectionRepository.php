<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type CollectionRow object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     contract_address: string,
 *     chain_id: string,
 *     collection_name: string|null,
 *     token_standard: string|null,
 *     total_supply: string|null,
 *     floor_price: string|null,
 *     floor_currency: string|null,
 *     unique_holders: string|null,
 *     total_volume: string|null,
 *     listed_percentage: string|null,
 *     royalty_percentage: string|null,
 *     metadata_storage: string|null,
 *     image_url: string|null,
 *     show_on_profile: string,
 *     is_verified: string,
 *     fetched_at: string,
 *     expires_at: string
 * }
 *
 * @phpstan-type CollectionWithChain object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     contract_address: string,
 *     chain_id: string,
 *     collection_name: string|null,
 *     token_standard: string|null,
 *     total_supply: string|null,
 *     floor_price: string|null,
 *     floor_currency: string|null,
 *     unique_holders: string|null,
 *     total_volume: string|null,
 *     listed_percentage: string|null,
 *     royalty_percentage: string|null,
 *     metadata_storage: string|null,
 *     image_url: string|null,
 *     show_on_profile: string,
 *     fetched_at: string,
 *     expires_at: string,
 *     chain_slug: string,
 *     chain_name: string,
 *     explorer_url: string|null,
 *     native_token: string|null
 * }
 *
 * @phpstan-type CollectionIdWithChain object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     contract_address: string,
 *     chain_id: string,
 *     collection_name: string|null,
 *     token_standard: string|null,
 *     total_supply: string|null,
 *     floor_price: string|null,
 *     unique_holders: string|null,
 *     total_volume: string|null,
 *     is_verified: string,
 *     chain_slug: string,
 *     chain_type: string
 * }
 *
 * @phpstan-type CollectionCountByChain object{
 *     chain_id: string,
 *     cnt: string,
 *     last_fetched: string|null
 * }
 *
 * Display shape consumed by CollectionService::enrichWithBadges() and mergeWithManual().
 * Superset of CollectionWithChain plus UI decoration props populated post-fetch.
 * Decoration props are optional because repository rows start without them.
 *
 * @phpstan-type CollectionDisplay object{
 *     id: string|null,
 *     wallet_link_id?: string|null,
 *     contract_address: string,
 *     chain_id?: string,
 *     collection_name: string|null,
 *     token_standard: string|null,
 *     total_supply: string|int|null,
 *     floor_price: string|float|null,
 *     floor_currency: string|null,
 *     unique_holders: string|int|null,
 *     total_volume: string|float|null,
 *     listed_percentage: string|float|null,
 *     royalty_percentage: string|float|null,
 *     metadata_storage: string|null,
 *     image_url?: string|null,
 *     show_on_profile: int|string,
 *     fetched_at: string|null,
 *     expires_at?: string,
 *     chain_slug: string,
 *     chain_name: string,
 *     explorer_url: string|null,
 *     native_token: string|null,
 *     is_creator?: bool,
 *     viewer_holds?: bool,
 *     data_source?: string,
 *     can_toggle?: bool
 * }
 */
final class CollectionRepository
{
    /** @var string Explicit column list — must match schema-collections.php. */
    private const COLUMNS = 'id, wallet_link_id, contract_address, chain_id, collection_name,
                 token_standard, total_supply, floor_price, floor_currency, unique_holders,
                 total_volume, listed_percentage, royalty_percentage, metadata_storage,
                 image_url, show_on_profile, is_verified, fetched_at, expires_at';

    public static function table(): string
    {
        return DB::table('onchain_collections');
    }

    /**
     * Insert or update a collection row by wallet_link_id + chain_id + contract_address.
     *
     * @param array<string, mixed> $data  Normalized collection data from a fetcher.
     * @param int   $walletLinkId  The wallet link this collection belongs to.
     * @param int   $ttlSeconds    Cache TTL before the row is considered expired.
     * @return int|false  Row ID on success, false on failure.
     */
    public static function upsert(array $data, int $walletLinkId, int $ttlSeconds = 4 * HOUR_IN_SECONDS)
    {
        global $wpdb;
        $table = self::table();

        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE wallet_link_id = %d AND chain_id = %d AND contract_address = %s
             LIMIT 1",
            $walletLinkId,
            (int) $data['chain_id'],
            $data['contract_address']
        ));

        $row = [
            'wallet_link_id'    => $walletLinkId,
            'contract_address'  => $data['contract_address'],
            'chain_id'          => (int) $data['chain_id'],
            'collection_name'   => isset($data['collection_name']) ? sanitize_text_field($data['collection_name']) : null,
            'token_standard'    => isset($data['token_standard']) ? sanitize_text_field($data['token_standard']) : null,
            'total_supply'      => $data['total_supply'] ?? null,
            'floor_price'       => $data['floor_price'] ?? null,
            'floor_currency'    => isset($data['floor_currency']) ? sanitize_text_field($data['floor_currency']) : null,
            'unique_holders'    => $data['unique_holders'] ?? null,
            'total_volume'      => $data['total_volume'] ?? null,
            'listed_percentage' => $data['listed_percentage'] ?? null,
            'royalty_percentage' => $data['royalty_percentage'] ?? null,
            'metadata_storage'  => isset($data['metadata_storage']) ? sanitize_text_field($data['metadata_storage']) : null,
            // Persisted from V1 NFT discovery (Alchemy `openSeaMetadata.imageUrl`)
            // — schema column existed since Phase 1c but the V1 path dropped it
            // on the floor pre-migration. `esc_url_raw` because the value is
            // ultimately rendered as `<img src>` on the Verify Collections page.
            'image_url'         => isset($data['image_url']) && is_string($data['image_url'])
                ? esc_url_raw($data['image_url'])
                : null,
            'fetched_at'        => current_time('mysql', true),
            'expires_at'        => $expiresAt,
        ];

        $format = ['%d', '%s', '%d', '%s', '%s', '%d', '%f', '%s', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s'];

        if ($existing) {
            $wpdb->update($table, $row, ['id' => (int) $existing], $format, ['%d']);
            return (int) $existing;
        }

        $wpdb->insert($table, $row, $format);
        return $wpdb->insert_id ?: false;
    }

    /**
     * Bulk-upsert collections for a chain (no wallet_link_id required).
     * Used by the chain-level indexing cron. Matches on (chain_id, contract_address).
     *
     * @param array<int, array<string, mixed>> $collections Normalized collection rows from fetch_top_collections().
     * @param int     $ttlSeconds  TTL for expires_at.
     * @return int Number of rows written.
     */
    public static function bulkUpsert(array $collections, int $ttlSeconds = 4 * HOUR_IN_SECONDS): int
    {
        if (empty($collections)) {
            return 0;
        }

        global $wpdb;
        $table     = self::table();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $now       = current_time('mysql', true);
        $count     = 0;

        // Transaction wrapper: without this, a PHP timeout or fatal
        // mid-loop left the top-collections list partially updated
        // (first N rows current-cycle, remaining N from hours earlier).
        // Mirrors ValidatorRepository::bulkUpsert's atomicity guarantee.
        try {

        // Fail closed if the transaction never opened (DB failover): a no-op
        // START leaves the batch in autocommit, defeating the mid-loop
        // rollback this wrapper provides.
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('START TRANSACTION failed');
        }

        foreach ($collections as $data) {
            // Build the row manually so NULLs stay NULL in the DB
            // (wpdb::prepare with %d/%f converts null to 0).
            $supply    = $data['total_supply'] ?? null;
            $floor     = $data['floor_price'] ?? null;
            $holders   = $data['unique_holders'] ?? null;
            $volume    = $data['total_volume'] ?? null;
            $listed    = $data['listed_percentage'] ?? null;
            $royalty   = $data['royalty_percentage'] ?? null;

            $sqlSupply  = $supply !== null ? $wpdb->prepare('%d', (int) $supply) : 'NULL';
            $sqlFloor   = $floor !== null ? $wpdb->prepare('%f', (float) $floor) : 'NULL';
            $sqlHolders = $holders !== null ? $wpdb->prepare('%d', (int) $holders) : 'NULL';
            $sqlVolume  = $volume !== null ? $wpdb->prepare('%f', (float) $volume) : 'NULL';
            $sqlListed  = $listed !== null ? $wpdb->prepare('%f', (float) $listed) : 'NULL';
            $sqlRoyalty = $royalty !== null ? $wpdb->prepare('%f', (float) $royalty) : 'NULL';

            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (wallet_link_id, contract_address, chain_id, collection_name, token_standard,
                     total_supply, floor_price, floor_currency, unique_holders, total_volume,
                     listed_percentage, royalty_percentage, metadata_storage, image_url,
                     source, fetched_at, expires_at)
                 VALUES (NULL, %s, %d, %s, %s, {$sqlSupply}, {$sqlFloor}, %s, {$sqlHolders}, {$sqlVolume}, {$sqlListed}, {$sqlRoyalty}, %s, %s, 'toplist', %s, %s)
                 ON DUPLICATE KEY UPDATE
                    collection_name    = VALUES(collection_name),
                    token_standard     = VALUES(token_standard),
                    total_supply       = VALUES(total_supply),
                    floor_price        = VALUES(floor_price),
                    unique_holders     = VALUES(unique_holders),
                    total_volume       = VALUES(total_volume),
                    listed_percentage  = VALUES(listed_percentage),
                    royalty_percentage  = VALUES(royalty_percentage),
                    image_url          = VALUES(image_url),
                    -- Preserve operator curation: a 'manual' row that later
                    -- shows up in a top-collections sync keeps source='manual'.
                    -- ('toplist' was 'stargaze' before the 2026 Stargaze →
                    -- Cosmos Hub migration; retire-stargaze-chain.php renames
                    -- the legacy rows.)
                    source             = IF(source = 'manual', 'manual', VALUES(source)),
                    fetched_at         = VALUES(fetched_at),
                    expires_at         = VALUES(expires_at)",
                $data['contract_address'],
                (int) $data['chain_id'],
                $data['collection_name'] ?? null,
                $data['token_standard'] ?? null,
                $data['floor_currency'] ?? null,
                $data['metadata_storage'] ?? null,
                $data['image_url'] ?? null,
                $now,
                $expiresAt
            ));

            if ($result !== false) {
                $count++;
            }
        }

        $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        return $count;
    }

    /**
     * Insert (or update) a single operator-curated collection by
     * (chain_id, contract_address). Powers the admin "Add Collection"
     * form on the Verify Collections page — the only path that creates
     * a collection row for a chain with no auto-discovery (e.g. a
     * curated Cosmos Hub CW-721 contract).
     *
     * Mirrors bulkUpsert()'s row shape and NULL-preservation, scoped to
     * one row. wallet_link_id stays NULL (chain-level, not wallet-owned);
     * is_verified stays 0 so the operator must still flip it via the
     * existing verification checkbox before any holdings are queried.
     *
     * @param array{
     *     chain_id: int,
     *     contract_address: string,
     *     collection_name?: ?string,
     *     token_standard?: ?string,
     *     total_supply?: ?int,
     *     image_url?: ?string,
     *     show_on_profile?: int
     * } $data
     * @param int $ttlSeconds  TTL for expires_at. Defaults long (30 days) so a
     *                         curated entry isn't garbage-collected like a
     *                         transient discovery row.
     * @return int|false  Row ID on success, false on failure.
     */
    public static function addManual(array $data, int $ttlSeconds = 30 * DAY_IN_SECONDS)
    {
        $chainId  = (int) ($data['chain_id'] ?? 0);
        $contract = isset($data['contract_address']) ? trim((string) $data['contract_address']) : '';

        if ($chainId <= 0 || $contract === '') {
            return false;
        }

        global $wpdb;
        $table     = self::table();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $now       = current_time('mysql', true);

        // Build NULL-safe SQL fragments for the nullable numeric column
        // (wpdb::prepare with %d turns null into 0 — see bulkUpsert note).
        $supply    = $data['total_supply'] ?? null;
        $sqlSupply = $supply !== null ? $wpdb->prepare('%d', (int) $supply) : 'NULL';

        $name     = isset($data['collection_name']) ? sanitize_text_field((string) $data['collection_name']) : null;
        $standard = isset($data['token_standard']) ? sanitize_text_field((string) $data['token_standard']) : null;
        $image    = isset($data['image_url']) && is_string($data['image_url']) && $data['image_url'] !== ''
            ? esc_url_raw($data['image_url'])
            : null;
        $showOnProfile = isset($data['show_on_profile']) ? (int) (bool) $data['show_on_profile'] : 1;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (wallet_link_id, contract_address, chain_id, collection_name, token_standard,
                 total_supply, image_url, show_on_profile, is_verified, source, fetched_at, expires_at)
             VALUES (NULL, %s, %d, %s, %s, {$sqlSupply}, %s, %d, 0, 'manual', %s, %s)
             ON DUPLICATE KEY UPDATE
                collection_name = VALUES(collection_name),
                token_standard  = VALUES(token_standard),
                total_supply    = VALUES(total_supply),
                image_url       = VALUES(image_url),
                show_on_profile = VALUES(show_on_profile),
                source          = VALUES(source),
                fetched_at      = VALUES(fetched_at),
                expires_at      = VALUES(expires_at)",
            $contract,
            $chainId,
            $name,
            $standard,
            $image,
            $showOnProfile,
            $now,
            $expiresAt
        ));

        if ($result === false) {
            return false;
        }

        // Per-chain count display (admin Chains page) is a 1h TTL cache;
        // drop it so the new row shows up immediately rather than lagging.
        wp_cache_delete('collection_counts_by_chain', 'bcc_onchain');

        // insert_id is 0 on a pure ON DUPLICATE KEY UPDATE; resolve the
        // existing row id in that case so callers always get the row id.
        $insertId = (int) $wpdb->insert_id;
        if ($insertId > 0) {
            return $insertId;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE chain_id = %d AND contract_address = %s
             LIMIT 1",
            $chainId,
            $contract
        )) ?: false;
    }

    /**
     * Delete a single collection row by id. Powers the admin "Remove"
     * action on the Verify Collections page — primarily for cleaning up
     * a mistyped manual add. Bounded by the primary key.
     *
     * Returns the number of rows deleted (0 if the id didn't exist, 1 on
     * success). Group teardown is intentionally NOT handled here — a
     * verified collection's community is a separate concern; the caller
     * decides whether to block deletion of provisioned rows.
     */
    public static function deleteById(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::table();

        $deleted = (int) $wpdb->delete($table, ['id' => $id], ['%d']);

        if ($deleted > 0) {
            wp_cache_delete('collection_counts_by_chain', 'bcc_onchain');
        }

        return $deleted;
    }

    /**
     * Get top collections filtered by chain type (evm, solana, cosmos).
     * Each chain type is ranked independently — no cross-chain mixing.
     *
     * @param string $chainType One of: 'evm', 'solana', 'cosmos'.
     * @return array{items: list<CollectionWithChain>, total: int, pages: int}
     */
    public static function getTopCollectionsByChainType(
        string $chainType,
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'total_volume'
    ): array {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $allowedOrder = ['total_volume', 'floor_price', 'unique_holders', 'total_supply'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_volume';
        }

        $offset = ($page - 1) * $perPage;

        $countSql = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table} c
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE ch.chain_type = %s",
            $chainType
        );

        $mainSql = $wpdb->prepare(
            "SELECT c.id, c.wallet_link_id, c.contract_address, c.chain_id, c.collection_name,
                    c.token_standard, c.total_supply, c.floor_price, c.floor_currency,
                    c.unique_holders, c.total_volume, c.listed_percentage, c.royalty_percentage,
                    c.metadata_storage, c.image_url, c.show_on_profile, c.fetched_at, c.expires_at,
                    ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
             FROM {$table} c
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE ch.chain_type = %s
             ORDER BY c.{$orderBy} DESC
             LIMIT %d OFFSET %d",
            $chainType,
            $perPage,
            $offset
        );

        $total = (int) $wpdb->get_var($countSql);
        // LIMIT %d OFFSET %d is in the $mainSql prepared above.
        /** @var list<CollectionWithChain>|null $items */
        $items = $wpdb->get_results($mainSql);

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * @param bool $includeHidden If true, returns all collections regardless of show_on_profile.
     *                            Used by the page owner's dashboard. Public views pass false.
     * @return array{items: list<CollectionWithChain>, total: int, pages: int}
     */
    public static function getForProject(int $postId, int $page = 1, int $perPage = 8, string $orderBy = 'total_volume', bool $includeHidden = false): array
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        $allowedOrder = ['total_volume', 'floor_price', 'unique_holders', 'total_supply', 'collection_name'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_volume';
        }

        $offset = ($page - 1) * $perPage;

        $visibilityFilter = $includeHidden ? '' : ' AND c.show_on_profile = 1';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} c JOIN {$wallets} w ON w.id = c.wallet_link_id WHERE w.post_id = %d{$visibilityFilter}",
            $postId
        ));

        /** @var list<CollectionWithChain>|null $items */
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.wallet_link_id, c.contract_address, c.chain_id, c.collection_name,
                    c.token_standard, c.total_supply, c.floor_price, c.floor_currency,
                    c.unique_holders, c.total_volume, c.listed_percentage, c.royalty_percentage,
                    c.metadata_storage, c.image_url, c.show_on_profile, c.fetched_at, c.expires_at,
                    ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE w.post_id = %d{$visibilityFilter}
             ORDER BY c.{$orderBy} DESC
             LIMIT %d OFFSET %d",
            $postId, $perPage, $offset
        ));

        return ['items' => $items ?: [], 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    /**
     * Check whether a user holds NFTs from any of the given contract addresses.
     *
     * A "hold" means the user has a wallet_link whose address appears in the
     * onchain_collections table for that contract. This is an approximation:
     * the collection was fetched from that wallet, implying the wallet
     * interacted with (minted from) the contract.
     *
     * @param int      $userId           WordPress user ID.
     * @param string[] $contractAddresses Contract addresses to check.
     * @return array<string, bool> Keyed by lowercase contract address.
     */
    public static function getUserHoldings(int $userId, array $contractAddresses): array
    {
        if (empty($contractAddresses)) {
            return [];
        }

        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        $placeholders = implode(',', array_fill(0, count($contractAddresses), '%s'));
        $lowerAddrs   = array_map('strtolower', $contractAddresses);

        $args = array_merge([$userId], $lowerAddrs);

        $held = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT LOWER(c.contract_address)
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             WHERE w.user_id = %d AND LOWER(c.contract_address) IN ({$placeholders})",
            ...$args
        ));

        $result = [];
        foreach ($lowerAddrs as $addr) {
            $result[$addr] = in_array($addr, $held, true);
        }

        return $result;
    }

    /**
     * Toggle show_on_profile for a collection row owned by a user.
     *
     * @param int  $collectionId  Collection row ID.
     * @param int  $userId        Must own the wallet_link.
     * @param bool $show          Whether to show on profile.
     * @return bool True if updated.
     */
    public static function setShowOnProfile(int $collectionId, int $userId, bool $show): bool
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        // Verify the user owns this collection row via wallet_link
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT c.id
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             WHERE c.id = %d AND w.user_id = %d
             LIMIT 1",
            $collectionId, $userId
        ));

        if (!$owned) {
            return false;
        }

        return (bool) $wpdb->update(
            $table,
            ['show_on_profile' => $show ? 1 : 0],
            ['id' => $collectionId],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Load a collection with chain metadata. Used by ClaimService.
     *
     * @return CollectionIdWithChain|null
     */
    public static function getByIdWithChain(int $collectionId): ?object
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var CollectionIdWithChain|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.id, c.wallet_link_id, c.contract_address, c.chain_id,
                    c.collection_name, c.token_standard, c.total_supply,
                    c.floor_price, c.unique_holders, c.total_volume, c.is_verified,
                    ch.slug AS chain_slug, ch.chain_type
             FROM {$table} c
             INNER JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE c.id = %d",
            $collectionId
        ));
    }

    /**
     * Resolve post_id for a collection via wallet_link. Used for cache invalidation.
     */
    public static function getPostIdForCollection(int $collectionId): int
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT w.post_id
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             WHERE c.id = %d LIMIT 1",
            $collectionId
        ));
    }

    /**
     * Exponential backoff: push expires_at forward by 2x the original TTL,
     * capped at 7 days to prevent collections from disappearing from
     * refresh cycles indefinitely (uncapped would reach 170+ days after
     * 10 failures).
     */
    public static function backoffRow(int $rowId): bool
    {
        global $wpdb;
        $table   = self::table();
        $maxSecs = 7 * DAY_IN_SECONDS; // 604800 seconds = 7 days cap

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET expires_at = DATE_ADD(NOW(), INTERVAL LEAST(
                 TIMESTAMPDIFF(SECOND, fetched_at, expires_at) * 2,
                 %d
             ) SECOND)
             WHERE id = %d",
            $maxSecs,
            $rowId
        ));

        return $result !== false;
    }

    /** @return list<CollectionRow> */
    public static function getExpired(int $limit = 50): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<CollectionRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table} WHERE expires_at < NOW() ORDER BY expires_at ASC LIMIT %d",
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Check whether any collection rows exist for a given wallet_link.
     * Used by WalletSeedService to skip redundant API calls.
     */
    public static function existsForWalletLink(int $walletLinkId): bool
    {
        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE wallet_link_id = %d LIMIT 1",
            $walletLinkId
        ));
    }

    /**
     * Listing for the admin "Verify Collections" page. Ordered by
     * unique_holders DESC so popular collections surface first for
     * verification decisions.
     *
     * `token_standard` is included so the admin UI can label rows by
     * their on-chain standard (ERC-721 / ERC-1155). The holder gate
     * supports both standards: ERC-721 via `EvmFetcher::count_holdings`
     * (eth_call) and ERC-1155 via `NftHoldingsRepository::countVisibleByContract`
     * (persistent transfer index).
     *
     * Optional `$chainSlug` filter scopes the listing to a single chain
     * (e.g. "ethereum", "polygon"). Unknown slugs return an empty result
     * rather than the unfiltered list — admins should never silently see
     * cross-chain rows when they asked for one chain.
     *
     * Optional `$verified` filter splits the listing by verification
     * state — `true` = verified only, `false` = unverified only, `null` =
     * both. Powers the Verified / Unverified sub-tabs on the admin page.
     *
     * `total_supply` and `explorer_url` ride along for the CosmWasm
     * scanner detail the admin page renders under each Cosmos row (token
     * count when the upstream reported one; the chain's explorer base).
     * Both come from rows the query already touches, so projecting them
     * here is what keeps the page from issuing a per-row second lookup.
     *
     * @return array{items: list<object{
     *     id: string,
     *     contract_address: string,
     *     collection_name: string|null,
     *     token_standard: string|null,
     *     total_supply: string|null,
     *     unique_holders: string|null,
     *     image_url: string|null,
     *     is_verified: string,
     *     source: string,
     *     chain_id: string,
     *     chain_slug: string,
     *     chain_type: string,
     *     explorer_url: string|null
     * }>, total: int, pages: int}
     */
    public static function listForAdminVerification(
        int $page = 1,
        int $perPage = 50,
        ?string $chainSlug = null,
        ?string $tokenStandard = null,
        ?bool $verified = null
    ): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $chainId = null;
        if ($chainSlug !== null && $chainSlug !== '') {
            $chain = ChainRepository::getBySlug($chainSlug);
            if ($chain === null) {
                return ['items' => [], 'total' => 0, 'pages' => 0];
            }
            $chainId = (int) $chain->id;
        }

        // Build WHERE fragments + matching params. Each fragment is a
        // hardcoded string with %d/%s placeholders only — user input
        // flows through $wpdb->prepare placeholders, never into the SQL
        // string itself.
        $conditions = [];
        $params     = [];

        if ($chainId !== null) {
            $conditions[] = 'c.chain_id = %d';
            $params[]     = $chainId;
        }

        if ($tokenStandard !== null && $tokenStandard !== '') {
            $conditions[] = 'c.token_standard = %s';
            $params[]     = $tokenStandard;
        }

        if ($verified !== null) {
            $conditions[] = 'c.is_verified = %d';
            $params[]     = $verified ? 1 : 0;
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        if ($params === []) {
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} c {$whereSql}"
            );
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} c {$whereSql}",
                ...$params
            ));
        }

        // `total_supply` and `explorer_url` are projected for the admin
        // candidate detail (token count when the upstream reported one, and
        // the per-chain explorer base). Both were already on the joined
        // rows; naming them here is what keeps the admin page from issuing
        // a second per-row lookup for either. §2: still an explicit column
        // list, never SELECT *.
        /** @var list<object{
         *     id: string,
         *     contract_address: string,
         *     collection_name: string|null,
         *     token_standard: string|null,
         *     total_supply: string|null,
         *     unique_holders: string|null,
         *     image_url: string|null,
         *     is_verified: string,
         *     source: string,
         *     chain_id: string,
         *     chain_slug: string,
         *     chain_type: string,
         *     explorer_url: string|null
         * }>|null $items */
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.contract_address, c.collection_name, c.token_standard,
                    c.total_supply, c.unique_holders, c.image_url, c.is_verified,
                    c.source, c.chain_id,
                    ch.slug AS chain_slug, ch.chain_type, ch.explorer_url
               FROM {$table} c
          LEFT JOIN {$chains} ch ON ch.id = c.chain_id
               {$whereSql}
               ORDER BY c.unique_holders DESC, c.id DESC
               LIMIT %d OFFSET %d",
            ...array_merge($params, [$perPage, $offset])
        ));

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * DISTINCT non-empty `token_standard` values currently present in
     * the collections table. Used to populate the admin "Verify
     * Collections" page's token-standard filter dropdown so new
     * standards added by the indexer appear automatically.
     *
     * Bounded by an explicit LIMIT — there are only a handful of
     * standards in existence (ERC721, ERC1155, SPL, CW721, …), so 50
     * is well above any realistic cardinality but caps pathological
     * cases (corrupted data, fuzzing).
     *
     * @return list<string>
     */
    public static function getDistinctTokenStandards(): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<string>|null $rows */
        $rows = $wpdb->get_col(
            "SELECT DISTINCT token_standard
               FROM {$table}
              WHERE token_standard IS NOT NULL AND token_standard <> ''
              ORDER BY token_standard ASC
              LIMIT 50"
        );

        return $rows ?: [];
    }

    /**
     * Count collections split by verification state, honouring the same
     * optional chain / token-standard filters as the admin listing.
     * Powers the "Unverified (N)" / "Verified (N)" sub-tab labels so the
     * counts stay accurate under an active filter.
     *
     * Single bounded aggregate (GROUP BY on a 0/1 column → at most two
     * rows), no LIMIT needed.
     *
     * @return array{verified: int, unverified: int}
     */
    public static function countByVerification(
        ?string $chainSlug = null,
        ?string $tokenStandard = null
    ): array {
        global $wpdb;
        $table = self::table();

        $conditions = [];
        $params     = [];

        if ($chainSlug !== null && $chainSlug !== '') {
            $chain = ChainRepository::getBySlug($chainSlug);
            if ($chain === null) {
                return ['verified' => 0, 'unverified' => 0];
            }
            $conditions[] = 'chain_id = %d';
            $params[]     = (int) $chain->id;
        }

        if ($tokenStandard !== null && $tokenStandard !== '') {
            $conditions[] = 'token_standard = %s';
            $params[]     = $tokenStandard;
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $sql      = "SELECT is_verified, COUNT(*) AS cnt FROM {$table} {$whereSql} GROUP BY is_verified";

        /** @var list<object{is_verified: string, cnt: string}>|null $rows */
        $rows = $params === []
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $out = ['verified' => 0, 'unverified' => 0];
        foreach ($rows ?: [] as $row) {
            if ((int) $row->is_verified === 1) {
                $out['verified'] = (int) $row->cnt;
            } else {
                $out['unverified'] = (int) $row->cnt;
            }
        }

        return $out;
    }

    /**
     * Single-row lookup by (chain_id, contract_address). Used by the
     * V2 Phase 6 §H1 NFT-piece view-model builder to assemble the
     * `collection` embed (§3.7). Returns null when no row exists —
     * the builder falls through to a read-time Cosmos fetch or
     * returns 404 for indexed chains.
     *
     * Bounded by the unique key (chain_id, contract_address) + LIMIT 1.
     *
     * @return CollectionWithChain|null
     */
    public static function findByChainContract(int $chainId, string $contract): ?object
    {
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var CollectionWithChain|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.id, c.wallet_link_id, c.contract_address, c.chain_id, c.collection_name,
                    c.token_standard, c.total_supply, c.floor_price, c.floor_currency,
                    c.unique_holders, c.total_volume, c.listed_percentage, c.royalty_percentage,
                    c.metadata_storage, c.image_url, c.show_on_profile, c.fetched_at, c.expires_at,
                    ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
               FROM {$table} c
               JOIN {$chains} ch ON ch.id = c.chain_id
              WHERE c.chain_id = %d
                AND c.contract_address = %s
              LIMIT 1",
            $chainId,
            strtolower($contract)
        ));
    }

    /**
     * Resolve the on-chain `token_standard` for a (chain, contract) pair.
     *
     * Reads from `wp_bcc_onchain_collections.token_standard` — populated by
     * the indexer (Alchemy `category` field for EVM, fetcher metadata for
     * Cosmos/Solana). Returns the raw stored string (e.g. "ERC-721",
     * "ERC-1155", "SPL", "CW-721") or null when the row exists but the
     * standard is unknown / when no row exists.
     *
     * Used by the holder-gate path to decide between the ERC-721 RPC
     * route and the ERC-1155 persistent-index route.
     */
    public static function findTokenStandard(int $chainId, string $contract): ?string
    {
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        global $wpdb;
        $table = self::table();

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT token_standard
               FROM {$table}
              WHERE chain_id = %d
                AND contract_address = %s
              LIMIT 1",
            $chainId,
            strtolower($contract)
        ));

        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);
        return $str === '' ? null : $str;
    }

    /**
     * Toggle the admin `is_verified` flag.
     *
     * Verified collections become candidates for auto-provisioning of
     * holder groups (see GatedGroupProvisioningService). Sync paths
     * never write this column — admin-only.
     */
    public static function setVerified(int $collectionId, bool $verified): bool
    {
        if ($collectionId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->update(
            $table,
            ['is_verified' => $verified ? 1 : 0],
            ['id' => $collectionId],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Bulk-toggle is_verified across two id sets in two UPDATE statements.
     * Each UPDATE is gated by `is_verified <> target` so unchanged rows
     * are not touched (no recurring no-op writes).
     *
     * @param list<int> $idsToVerify
     * @param list<int> $idsToUnverify
     * @return int Total rows actually changed
     */
    public static function setVerifiedBulk(array $idsToVerify, array $idsToUnverify): int
    {
        $verify   = array_values(array_filter(array_map('intval', $idsToVerify),   static fn ($id) => $id > 0));
        $unverify = array_values(array_filter(array_map('intval', $idsToUnverify), static fn ($id) => $id > 0));

        if ($verify === [] && $unverify === []) {
            return 0;
        }

        global $wpdb;
        $table = self::table();
        $changed = 0;

        if ($verify !== []) {
            $ph = implode(',', array_fill(0, count($verify), '%d'));
            $changed += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_verified = 1 WHERE id IN ({$ph}) AND is_verified <> 1",
                ...$verify
            ));
        }

        if ($unverify !== []) {
            $ph = implode(',', array_fill(0, count($unverify), '%d'));
            $changed += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_verified = 0 WHERE id IN ({$ph}) AND is_verified <> 0",
                ...$unverify
            ));
        }

        return $changed;
    }

    /**
     * Bulk-fetch collections by id with chain slug/type and market
     * stats. Used by the holder-groups REST surface and the cross-kind
     * groups discovery endpoint to enrich each gated group with its
     * underlying collection metadata + decision-grade trade signals.
     *
     * ── THE RETURN IS A MAP KEYED BY COLLECTION ID ──────────────────
     * `collection id → row`, NOT a positional list, and NOT ordered:
     * ids that matched no row are simply absent, so the result is
     * shorter than `$ids` whenever one is unknown. Callers index by
     * the id they asked for (`$map[$id] ?? null`) or iterate the pairs.
     * A caller that reads `$result[0]` gets null for every real id —
     * that was a live defect in the Verify Collections hide/unhide
     * button, which answered "collection not found" for every
     * collection until 2026-08-13. Any test double for this method
     * MUST key by id too; the one that returned a convenient
     * zero-indexed list is what let the defect ship green.
     *
     * @param list<int> $ids
     * @return array<int, object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     collection_name: string|null,
     *     image_url: string|null,
     *     token_standard: string|null,
     *     total_supply: string|null,
     *     unique_holders: string|null,
     *     floor_price: string|null,
     *     floor_currency: string|null,
     *     total_volume: string|null,
     *     listed_percentage: string|null,
     *     royalty_percentage: string|null,
     *     chain_slug: string,
     *     chain_type: string
     * }>
     */
    public static function findManyByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $table        = self::table();
        $chains       = ChainRepository::table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<object{
         *     id: string,
         *     chain_id: string,
         *     contract_address: string,
         *     collection_name: string|null,
         *     image_url: string|null,
         *     token_standard: string|null,
         *     total_supply: string|null,
         *     unique_holders: string|null,
         *     floor_price: string|null,
         *     floor_currency: string|null,
         *     total_volume: string|null,
         *     listed_percentage: string|null,
         *     royalty_percentage: string|null,
         *     chain_slug: string,
         *     chain_type: string
         * }>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.chain_id, c.contract_address, c.collection_name, c.image_url,
                    c.token_standard, c.total_supply, c.unique_holders,
                    c.floor_price, c.floor_currency, c.total_volume,
                    c.listed_percentage, c.royalty_percentage,
                    ch.slug AS chain_slug, ch.chain_type
               FROM {$table} c
               JOIN {$chains} ch ON ch.id = c.chain_id
              WHERE c.id IN ({$placeholders})
              LIMIT 200",
            ...$ids
        ));

        // Keyed by the ROW's own id (not the requested one) so the key and
        // the row can never disagree — see the contract note above.
        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->id] = $row;
        }
        return $map;
    }

    /**
     * Verified collections, joined to chains for the slug + chain_type.
     * Drives the holder-group provisioning sweep.
     *
     * @return list<object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     collection_name: string|null,
     *     image_url: string|null,
     *     chain_slug: string,
     *     chain_type: string
     * }>
     */
    public static function listVerified(int $limit = 200): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var list<object{
         *     id: string,
         *     chain_id: string,
         *     contract_address: string,
         *     collection_name: string|null,
         *     image_url: string|null,
         *     chain_slug: string,
         *     chain_type: string
         * }>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.chain_id, c.contract_address, c.collection_name, c.image_url,
                    ch.slug AS chain_slug, ch.chain_type
             FROM {$table} c
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE c.is_verified = 1
             ORDER BY c.id ASC
             LIMIT %d",
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Verified collections scoped to a single chain.
     *
     * Sibling of {@see listVerified()} — same JOIN + column list,
     * scoped to one chain_id. Used by V2 Phase 2's
     * `CosmosFetcher::list_holdings` to enumerate which CW-721
     * contracts to query per refresh.
     *
     * Ordered by `unique_holders DESC` so the most popular
     * collections are queried first when the per-refresh cap (set by
     * caller, default 30 contracts/chain via
     * `BCC_COSMOS_HOLDINGS_CONTRACT_CAP`) is hit. NULL holders sort
     * last so unenriched rows don't push popular ones out of the cap.
     *
     * @return list<object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     collection_name: string|null,
     *     image_url: string|null,
     *     chain_slug: string,
     *     chain_type: string
     * }>
     */
    public static function listVerifiedByChain(int $chainId, int $limit = 30): array
    {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var list<object{
         *     id: string,
         *     chain_id: string,
         *     contract_address: string,
         *     collection_name: string|null,
         *     image_url: string|null,
         *     chain_slug: string,
         *     chain_type: string
         * }>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.chain_id, c.contract_address, c.collection_name, c.image_url,
                    ch.slug AS chain_slug, ch.chain_type
             FROM {$table} c
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE c.is_verified = 1
               AND c.chain_id = %d
             ORDER BY c.unique_holders IS NULL ASC,
                      c.unique_holders DESC,
                      c.id ASC
             LIMIT %d",
            $chainId,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Every known collection on a chain — verified AND unverified —
     * verified first, then by holder count.
     *
     * Sibling of {@see listVerifiedByChain} for the user's OWN gallery
     * (CosmosFetcher::list_holdings): a linked wallet's assets render
     * even when the operator hasn't verified the collection yet (the UI
     * dims them via the `collection_verified` annotation). Verified
     * rows win the cap so widening the iteration can never evict a
     * verified collection from the gallery. NOT for gating — gate
     * reads stay on the verified-only queries.
     *
     * @return list<object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     collection_name: string|null,
     *     image_url: string|null,
     *     is_verified: string,
     *     chain_slug: string,
     *     chain_type: string
     * }>
     */
    public static function listKnownByChain(int $chainId, int $limit = 30): array
    {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var list<object{
         *     id: string,
         *     chain_id: string,
         *     contract_address: string,
         *     collection_name: string|null,
         *     image_url: string|null,
         *     is_verified: string,
         *     chain_slug: string,
         *     chain_type: string
         * }>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.chain_id, c.contract_address, c.collection_name, c.image_url,
                    c.is_verified, ch.slug AS chain_slug, ch.chain_type
             FROM {$table} c
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE c.chain_id = %d
             ORDER BY c.is_verified DESC,
                      c.unique_holders IS NULL ASC,
                      c.unique_holders DESC,
                      c.id ASC
             LIMIT %d",
            $chainId,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Verification map for a bounded set of contracts on one chain:
     * `strtolower(contract) => bool`. Contracts with NO collection row
     * are absent from the map — callers treat absent as unverified.
     *
     * Powers the gallery's per-item `collection_verified` annotation
     * (HoldingsService); chunked IN() keeps the query bounded per §4.
     *
     * @param list<string> $contracts
     * @return array<string, bool>
     */
    public static function verifiedMapForContracts(int $chainId, array $contracts): array
    {
        if ($chainId <= 0 || $contracts === []) {
            return [];
        }

        $contracts = array_values(array_unique(array_map('strtolower', $contracts)));

        global $wpdb;
        $table = self::table();
        $map   = [];

        foreach (array_chunk($contracts, 100) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%s'));
            /** @var list<object{contract_address: string, is_verified: string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT contract_address, is_verified
                 FROM {$table}
                 WHERE chain_id = %d
                   AND contract_address IN ({$ph})",
                array_merge([$chainId], $chunk)
            ));
            foreach ($rows ?: [] as $row) {
                $map[strtolower((string) $row->contract_address)] = ((int) $row->is_verified === 1);
            }
        }

        return $map;
    }

    /**
     * Get collection counts grouped by chain_id.
     * Used by the admin Chains page to show per-chain stats.
     *
     * @return array<int, CollectionCountByChain> Keyed by chain_id, each with ->cnt and ->last_fetched.
     */
    public static function getCountsByChain(): array
    {
        $cached = wp_cache_get('collection_counts_by_chain', 'bcc_onchain');
        if (is_array($cached)) {
            /** @var array<int, CollectionCountByChain> $cached */
            return $cached;
        }

        global $wpdb;
        $table = self::table();

        /** @var list<CollectionCountByChain>|null $rows */
        $rows = $wpdb->get_results(
            "SELECT chain_id, COUNT(*) AS cnt,
                    MAX(fetched_at) AS last_fetched
             FROM {$table}
             GROUP BY chain_id
             LIMIT 100"
        );

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->chain_id] = $row;
        }

        wp_cache_set('collection_counts_by_chain', $map, 'bcc_onchain', 3600);

        return $map;
    }

    // ── Discovery bridge (holdings indexer → collection rows) ───────────

    /**
     * Idempotently ensure a collection row exists for every (chain_id,
     * contract_address) pair the holdings indexer encounters. Used as a
     * write-side bridge so collections appear in the admin Verify list as
     * soon as a connected wallet's transfers ingest, without depending on
     * any external "top collections" feed.
     *
     * Discovery rows are minimal: `wallet_link_id = NULL` (matches the
     * chain-level pattern from `bulkUpsert`), `token_standard` populated
     * from the transfer event when known, all market-stat fields NULL.
     * NftEnrichmentService backfills `collection_name`, `image_url`,
     * `total_supply`, and `floor_price` on a separate cron tick via
     * `findPendingEnrichment` / `applyEnrichment`.
     *
     * On collision (row already exists) we INSERT IGNORE plus a defensive
     * `token_standard` fill via `COALESCE(token_standard, VALUES(...))` —
     * never overwrites an existing non-null standard. This means a row
     * that was already enriched will not have its data clobbered, while
     * a stub row with `token_standard = NULL` gets corrected as soon as
     * a transfer event provides the standard.
     *
     * @param list<array{chain_id: int, contract_address: string, token_standard?: ?string}> $rows
     * @param int $ttlSeconds  Initial expires_at horizon. Matches bulkUpsert default.
     * @return int Rows actually inserted (existing rows count as 0 from `rows_affected`).
     */
    public static function ensureExistsBatch(array $rows, int $ttlSeconds = 4 * HOUR_IN_SECONDS): int
    {
        if ($rows === []) {
            return 0;
        }

        // Dedupe by (chain_id, contract_address) — the indexer's batch
        // typically contains many holdings rows for the same contract.
        $deduped = [];
        foreach ($rows as $r) {
            $chainId  = (int) ($r['chain_id'] ?? 0);
            $contract = strtolower((string) ($r['contract_address'] ?? ''));
            if ($chainId <= 0 || $contract === '') {
                continue;
            }
            $key = $chainId . '|' . $contract;
            // Last-write-wins on token_standard within the dedupe pass —
            // any non-null standard supersedes a null. Lets a single batch
            // with both 721 and 1155 transfers for the same contract
            // (rare but possible across token_ids) end up tagged correctly.
            $existing = $deduped[$key]['token_standard'] ?? null;
            $incoming = isset($r['token_standard']) && is_string($r['token_standard']) && $r['token_standard'] !== ''
                ? $r['token_standard']
                : null;
            $deduped[$key] = [
                'chain_id'         => $chainId,
                'contract_address' => $contract,
                'token_standard'   => $existing ?? $incoming,
            ];
        }

        if ($deduped === []) {
            return 0;
        }

        global $wpdb;
        $table     = self::table();
        $now       = current_time('mysql', true);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $inserted  = 0;

        foreach ($deduped as $entry) {
            $chainId  = $entry['chain_id'];
            $contract = $entry['contract_address'];
            $std      = $entry['token_standard'];

            // Inline the token_standard literal so we can pass NULL when
            // unknown without %s coercing it to an empty string. Same
            // pattern bulkUpsert uses for nullable numeric fields.
            $stdSql = $std !== null
                ? $wpdb->prepare('%s', $std)
                : 'NULL';

            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (wallet_link_id, contract_address, chain_id, token_standard,
                     show_on_profile, is_verified, fetched_at, expires_at)
                 VALUES (NULL, %s, %d, {$stdSql}, 1, 0, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    token_standard = COALESCE(token_standard, VALUES(token_standard))",
                $contract,
                $chainId,
                $now,
                $expiresAt
            ));

            // rows_affected = 1 → newly inserted; 2 → row updated via
            // ON DUPLICATE KEY (existed); 0 → existed and update was a
            // no-op (token_standard already populated). We only count
            // genuine inserts so a re-ingestion sweep returns 0.
            if ($result !== false && (int) $wpdb->rows_affected === 1) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Pull rows that have not yet been enriched with collection-level
     * metadata. "Pending" here means `collection_name IS NULL` — every
     * row written by `ensureExistsBatch` starts with name null and only
     * gets a name from a successful enrichment call.
     *
     * Bounded by `$limit` (capped at 100 to fit the per-chain enrichment
     * cron's 5-minute cadence without runaway). Ordered by `id ASC` so
     * the oldest discoveries enrich first — newer arrivals queue up
     * behind them rather than starving the backlog under sustained load.
     *
     * @return list<object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     token_standard: string|null
     * }>
     */
    public static function findPendingEnrichment(int $chainId, int $limit = 50): array
    {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));

        global $wpdb;
        $table = self::table();

        /** @var list<object{id: string, chain_id: string, contract_address: string, token_standard: string|null}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, chain_id, contract_address, token_standard
               FROM {$table}
              WHERE chain_id = %d
                AND collection_name IS NULL
              ORDER BY id ASC
              LIMIT %d",
            $chainId,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Write enrichment fields for a single collection row. Mirrors the
     * shape `EvmFetcher::fetchContractMetadata` returns.
     *
     * Two write disciplines, picked per-column:
     *
     *   IDENTITY fields (`collection_name`, `image_url`, `token_standard`)
     *   — preserve-on-write via COALESCE. Once a row gets a real name,
     *   no future enrichment can wipe it. Defends against partial
     *   Alchemy responses that return e.g. `name=null` mid-incident.
     *
     *   MARKET fields (`floor_price`, `floor_currency`, `total_supply`)
     *   — overwrite when the input is non-null, leave alone when null.
     *   These genuinely move (a floor of 0.05 ETH today may be 0.5 ETH
     *   tomorrow); preserve-once would silently freeze stale data.
     *   Symmetric with `bulkUpsert`'s `VALUES(floor_price)` semantics.
     *
     *   In both cases a null input field is omitted from the SET list,
     *   so a partial response can't wipe a previously-good column —
     *   the divergence is only in what happens when both old AND new
     *   values are non-null (preserve old vs. take new).
     *
     * `fetched_at` is unconditionally bumped so the admin "last refresh"
     * column reflects the attempt even when no individual column changed.
     *
     * @param array{
     *     collection_name?: ?string,
     *     image_url?: ?string,
     *     total_supply?: ?int,
     *     floor_price?: ?float,
     *     floor_currency?: ?string,
     *     token_standard?: ?string
     * } $fields
     */
    public static function applyEnrichment(int $collectionId, array $fields): bool
    {
        if ($collectionId <= 0) {
            return false;
        }

        $name      = isset($fields['collection_name']) && is_string($fields['collection_name']) && $fields['collection_name'] !== ''
            ? $fields['collection_name']
            : null;
        $image     = isset($fields['image_url']) && is_string($fields['image_url']) && $fields['image_url'] !== ''
            ? $fields['image_url']
            : null;
        $supply    = isset($fields['total_supply']) && is_numeric($fields['total_supply'])
            ? (int) $fields['total_supply']
            : null;
        $floor     = isset($fields['floor_price']) && is_numeric($fields['floor_price'])
            ? (float) $fields['floor_price']
            : null;
        $currency  = isset($fields['floor_currency']) && is_string($fields['floor_currency']) && $fields['floor_currency'] !== ''
            ? $fields['floor_currency']
            : null;
        $standard  = isset($fields['token_standard']) && is_string($fields['token_standard']) && $fields['token_standard'] !== ''
            ? $fields['token_standard']
            : null;

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        // Build SET list dynamically: omit any column whose input is null
        // so a partial fetch never touches that column at all. Identity
        // columns wrap the value in COALESCE; market columns overwrite.
        $setClauses = [];
        $params     = [];

        // Identity fields (preserve-once via COALESCE).
        if ($name !== null) {
            $setClauses[] = 'collection_name = COALESCE(collection_name, %s)';
            $params[]     = $name;
        }
        if ($image !== null) {
            $setClauses[] = 'image_url = COALESCE(image_url, %s)';
            $params[]     = $image;
        }
        if ($standard !== null) {
            $setClauses[] = 'token_standard = COALESCE(token_standard, %s)';
            $params[]     = $standard;
        }

        // Market fields (refresh when fresh data arrives).
        if ($supply !== null) {
            $setClauses[] = 'total_supply = %d';
            $params[]     = $supply;
        }
        if ($floor !== null) {
            $setClauses[] = 'floor_price = %f';
            $params[]     = $floor;
        }
        if ($currency !== null) {
            $setClauses[] = 'floor_currency = %s';
            $params[]     = $currency;
        }

        // Always bump fetched_at — the "we tried" signal that survives
        // an all-null partial response.
        $setClauses[] = 'fetched_at = %s';
        $params[]     = $now;

        $params[] = $collectionId;

        $sql    = "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE id = %d";
        $result = $wpdb->query($wpdb->prepare($sql, $params));

        return $result !== false;
    }
}
