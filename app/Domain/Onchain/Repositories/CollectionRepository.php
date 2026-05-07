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
            'fetched_at'        => current_time('mysql', true),
            'expires_at'        => $expiresAt,
        ];

        $format = ['%d', '%s', '%d', '%s', '%s', '%d', '%f', '%s', '%d', '%f', '%f', '%f', '%s', '%s', '%s'];

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
        $wpdb->query('START TRANSACTION');

        try {
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
                     fetched_at, expires_at)
                 VALUES (NULL, %s, %d, %s, %s, {$sqlSupply}, {$sqlFloor}, %s, {$sqlHolders}, {$sqlVolume}, {$sqlListed}, {$sqlRoyalty}, %s, %s, %s, %s)
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
                    c.floor_price, c.unique_holders, c.total_volume,
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
     * `token_standard` is included so the admin UI can flag ERC-1155
     * collections — the holder gate is ERC-721-only today, and verifying
     * an ERC-1155 collection silently fails the gate for every holder.
     *
     * @return array{items: list<object{
     *     id: string,
     *     contract_address: string,
     *     collection_name: string|null,
     *     token_standard: string|null,
     *     unique_holders: string|null,
     *     image_url: string|null,
     *     is_verified: string,
     *     chain_slug: string,
     *     chain_type: string
     * }>, total: int, pages: int}
     */
    public static function listForAdminVerification(int $page = 1, int $perPage = 50): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}"
        );

        /** @var list<object{
         *     id: string,
         *     contract_address: string,
         *     collection_name: string|null,
         *     token_standard: string|null,
         *     unique_holders: string|null,
         *     image_url: string|null,
         *     is_verified: string,
         *     chain_slug: string,
         *     chain_type: string
         * }>|null $items */
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.contract_address, c.collection_name, c.token_standard,
                    c.unique_holders, c.image_url, c.is_verified,
                    ch.slug AS chain_slug, ch.chain_type
               FROM {$table} c
          LEFT JOIN {$chains} ch ON ch.id = c.chain_id
              ORDER BY c.unique_holders DESC, c.id DESC
              LIMIT %d OFFSET %d",
            $perPage,
            $offset
        ));

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
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
     * Bulk-fetch collections by id with chain slug/type and market
     * stats. Used by the holder-groups REST surface and the cross-kind
     * groups discovery endpoint to enrich each gated group with its
     * underlying collection metadata + decision-grade trade signals.
     *
     * @param int[] $ids
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
}
