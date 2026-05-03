<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ValidatorRow object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     operator_address: string,
 *     chain_id: string,
 *     moniker: string|null,
 *     status: string,
 *     commission_rate: string|null,
 *     total_stake: string|null,
 *     self_stake: string|null,
 *     delegator_count: string|null,
 *     uptime_30d: string|null,
 *     jailed_count: string|null,
 *     voting_power_rank: string|null,
 *     fetched_at: string,
 *     expires_at: string,
 *     last_enriched_at: string|null,
 *     next_enrichment_at: string|null,
 *     retry_after: string|null,
 *     enrichment_attempts: string
 * }
 *
 * @phpstan-type ValidatorWithChain object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     operator_address: string,
 *     chain_id: string,
 *     moniker: string|null,
 *     status: string,
 *     commission_rate: string|null,
 *     total_stake: string|null,
 *     self_stake: string|null,
 *     delegator_count: string|null,
 *     uptime_30d: string|null,
 *     jailed_count: string|null,
 *     voting_power_rank: string|null,
 *     fetched_at: string,
 *     expires_at: string,
 *     chain_slug: string,
 *     chain_name: string,
 *     explorer_url: string|null,
 *     native_token: string|null
 * }
 *
 * @phpstan-type ValidatorTopForProject object{
 *     id: string,
 *     operator_address: string,
 *     chain_id: string,
 *     moniker: string|null,
 *     status: string,
 *     commission_rate: string|null,
 *     total_stake: string|null,
 *     self_stake: string|null,
 *     delegator_count: string|null,
 *     uptime_30d: string|null,
 *     jailed_count: string|null,
 *     voting_power_rank: string|null,
 *     fetched_at: string,
 *     chain_slug: string,
 *     chain_name: string
 * }
 *
 * @phpstan-type ValidatorAggregateStats object{
 *     chains_count: string,
 *     active_count: string|null,
 *     total_stake: string,
 *     total_delegators: string
 * }
 *
 * @phpstan-type ValidatorIdWithChain object{
 *     id: string,
 *     wallet_link_id: string|null,
 *     operator_address: string,
 *     chain_id: string,
 *     moniker: string|null,
 *     status: string,
 *     commission_rate: string|null,
 *     total_stake: string|null,
 *     self_stake: string|null,
 *     delegator_count: string|null,
 *     uptime_30d: string|null,
 *     jailed_count: string|null,
 *     voting_power_rank: string|null,
 *     chain_slug: string,
 *     chain_type: string
 * }
 *
 * @phpstan-type ValidatorBulkExistingRow object{
 *     id: string,
 *     operator_address: string,
 *     moniker: string|null,
 *     status: string,
 *     commission_rate: string|null,
 *     total_stake: string|null,
 *     jailed_count: string|null,
 *     voting_power_rank: string|null,
 *     enrichment_attempts: string,
 *     fetched_at: string
 * }
 *
 * @phpstan-type ValidatorCountByChain object{
 *     chain_id: string,
 *     cnt: string,
 *     last_fetched: string|null
 * }
 */
final class ValidatorRepository
{
    /** @var string Explicit column list — must match schema-validators.php. */
    private const COLUMNS = 'id, wallet_link_id, operator_address, chain_id, moniker, status,
                 commission_rate, total_stake, self_stake, delegator_count, uptime_30d,
                 jailed_count, voting_power_rank, fetched_at, expires_at,
                 last_enriched_at, next_enrichment_at, retry_after, enrichment_attempts';

    public static function table(): string
    {
        return DB::table('onchain_validators');
    }

    /**
     * Resolve the first validator row backing a peepso-page, with its
     * chain slug. Used by CardViewService to surface `claim_target`
     * on the validator card view-model so the §N8 claim flow knows
     * which entity_id + chain_slug to send back to the claim endpoint.
     *
     * Validator pages typically map 1:1 to a single validator row;
     * the LIMIT 1 is defensive in case a page accidentally hosts
     * multiple validator rows during transitional re-indexing.
     *
     * @return object{validator_id: numeric-string, chain_slug: string}|null
     */
    public static function findFirstByPageId(int $pageId): ?object
    {
        if ($pageId <= 0) {
            return null;
        }

        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        /** @var object{validator_id: numeric-string, chain_slug: string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT v.id AS validator_id, c.slug AS chain_slug
               FROM {$table} v
               JOIN {$wallets} w ON w.id = v.wallet_link_id
               JOIN {$chains} c ON c.id = v.chain_id
              WHERE w.post_id = %d
              LIMIT 1",
            $pageId
        ));

        return $row;
    }

    /**
     * Enumerate every validator row backing a peepso-page, one per chain.
     *
     * §K3 — used by CardViewService to populate the `chains` view-model
     * field on validator cards. When an operator runs validators on
     * multiple chains and links each wallet to the same peepso-page,
     * this method returns one row per chain in chain-name order.
     *
     * Bounded: result count is the number of distinct chains an operator
     * runs on (single-digit in practice — Cosmos Hub + Osmosis + Injective
     * is the realistic ceiling), no LIMIT needed but kept defensively
     * to cap pathological data at 32.
     *
     * @return list<object{validator_id: int, chain_slug: string, chain_name: string, operator_address: string}>
     */
    public static function findAllByPageId(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        /**
         * @var list<object{
         *     validator_id: numeric-string,
         *     chain_slug: string,
         *     chain_name: string,
         *     operator_address: string
         * }> $rows
         */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id AS validator_id,
                    c.slug AS chain_slug,
                    c.name AS chain_name,
                    v.operator_address AS operator_address
               FROM {$table} v
               JOIN {$wallets} w ON w.id = v.wallet_link_id
               JOIN {$chains}  c ON c.id = v.chain_id
              WHERE w.post_id = %d
              ORDER BY c.name ASC
              LIMIT 32",
            $pageId
        ));

        $out = [];
        foreach ($rows as $row) {
            $out[] = (object) [
                'validator_id'     => (int) $row->validator_id,
                'chain_slug'       => $row->chain_slug,
                'chain_name'       => $row->chain_name,
                'operator_address' => $row->operator_address,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return int|false Inserted/updated row ID, or false on failure.
     */
    public static function upsert(array $data, int $walletLinkId, int $ttlSeconds = 3600)
    {
        global $wpdb;
        $table = self::table();

        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE wallet_link_id = %d AND chain_id = %d AND operator_address = %s
             LIMIT 1",
            $walletLinkId,
            (int) $data['chain_id'],
            $data['operator_address']
        ));

        $row = [
            'wallet_link_id'   => $walletLinkId,
            'operator_address' => $data['operator_address'],
            'chain_id'         => (int) $data['chain_id'],
            'moniker'          => isset($data['moniker']) ? sanitize_text_field($data['moniker']) : null,
            'status'           => sanitize_text_field($data['status'] ?? 'unknown'),
            'jailed_count'     => $data['jailed_count'] ?? 0,
            'fetched_at'       => current_time('mysql', true),
            'expires_at'       => $expiresAt,
        ];
        $format = ['%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s'];

        // Nullable floats: only include when non-null to avoid %f converting NULL to 0.00.
        // On INSERT, omitted columns get DEFAULT NULL from schema.
        // On UPDATE, omitted columns keep their existing value.
        $nullableFloats = [
            'commission_rate'   => $data['commission_rate'] ?? null,
            'total_stake'       => $data['total_stake'] ?? null,
            'self_stake'        => $data['self_stake'] ?? null,
            'uptime_30d'        => $data['uptime_30d'] ?? null,
        ];
        foreach ($nullableFloats as $col => $val) {
            if ($val !== null) {
                $row[$col] = (float) $val;
                $format[]  = '%f';
            }
        }

        // Nullable ints — same pattern.
        $nullableInts = [
            'delegator_count'   => $data['delegator_count'] ?? null,
            'voting_power_rank' => $data['voting_power_rank'] ?? null,
        ];
        foreach ($nullableInts as $col => $val) {
            if ($val !== null) {
                $row[$col] = (int) $val;
                $format[]  = '%d';
            }
        }

        if ($existing) {
            $wpdb->update($table, $row, ['id' => (int) $existing], $format, ['%d']);
            return (int) $existing;
        }

        $wpdb->insert($table, $row, $format);
        return $wpdb->insert_id ?: false;
    }

    /**
     * Enrich a validator row with expensive per-validator data.
     * Matches by (chain_id, operator_address) — works for both
     * wallet-linked and bulk-indexed (NULL wallet_link_id) rows.
     *
     * Only updates columns that have non-null values in $data,
     * preserving existing data for fields the fetcher didn't return.
     */
    /** @param array<string, mixed> $data */
    public static function enrichByOperator(array $data, int $ttlSeconds = HOUR_IN_SECONDS): bool
    {
        global $wpdb;
        $table = self::table();

        $sets   = [];
        $params = [];

        $enrichable = [
            'self_stake'       => '%f',
            'delegator_count'  => '%d',
            'uptime_30d'       => '%f',
            'moniker'          => '%s',
            'status'           => '%s',
            'commission_rate'  => '%f',
            'total_stake'      => '%f',
            'jailed_count'     => '%d',
            'voting_power_rank'=> '%d',
        ];

        foreach ($enrichable as $col => $fmt) {
            if (isset($data[$col])) {
                $sets[]   = "{$col} = {$fmt}";
                $params[] = $data[$col];
            }
        }

        if (empty($sets)) {
            return false;
        }

        // Always update timestamps.
        $sets[]   = 'fetched_at = %s';
        $params[] = current_time('mysql', true);
        $sets[]   = 'expires_at = %s';
        $params[] = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        // WHERE clause.
        $params[] = (int) $data['chain_id'];
        $params[] = $data['operator_address'];

        $sql = "UPDATE {$table} SET " . implode(', ', $sets)
             . " WHERE chain_id = %d AND operator_address = %s";

        $result = $wpdb->query($wpdb->prepare($sql, ...$params));
        return $result !== false;
    }

    /**
     * Bulk-upsert validators for a chain. Lean write strategy:
     *
     *   1. SELECT existing rows for this chain (1 query)
     *   2. Compare each incoming validator against the existing row
     *   3. NEW rows → INSERT individually (with staggered next_enrichment_at)
     *   4. CHANGED rows → UPDATE individually (data columns + reset retry)
     *   5. UNCHANGED rows → skip entirely (zero writes)
     *   6. Time-gated fetched_at — batch UPDATE for rows not seen in 6h+ (1 query)
     *
     * Write budget at 500 validators, ~20 changed, ~80 stale fetched_at:
     *   20 individual UPDATEs + 1 batch UPDATE = 21 queries (down from 500).
     *
     * @param array<int, array<string, mixed>> $validators Array of validator data arrays from fetch_all_validators().
     * @param int     $ttlSeconds TTL for expires_at.
     * @return array{total: int, new: int, updated: int, unchanged: int, refreshed: int}
     */
    public static function bulkUpsert(array $validators, int $ttlSeconds = HOUR_IN_SECONDS): array
    {
        $stats = ['total' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'refreshed' => 0];

        if (empty($validators)) {
            return $stats;
        }

        // Contract enforcement: bulkUpsert expects a single-chain batch.
        // A mixed-chain batch would only load existing rows for the first
        // element's chain_id (next query), so validators from any other
        // chain would appear "new" → duplicate INSERTs / UNIQUE key collisions.
        // Fail loudly instead of silently corrupting the table.
        $chainIds = [];
        foreach ($validators as $v) {
            $chainIds[(int) ($v['chain_id'] ?? 0)] = true;
        }
        if (count($chainIds) !== 1 || isset($chainIds[0])) {
            throw new \LogicException(
                'ValidatorRepository::bulkUpsert requires a single-chain batch; got chain_ids=['
                . implode(',', array_keys($chainIds)) . ']'
            );
        }

        // How often to touch fetched_at on unchanged rows (observability).
        // Every 6 hours keeps the "last seen" timestamp useful for dead
        // validator detection without writing on every 4-hour index cycle.
        $fetchedAtStaleThreshold = 6 * HOUR_IN_SECONDS;

        global $wpdb;
        $table     = self::table();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $now       = current_time('mysql', true);

        // ── Step 1: fetch existing rows for this chain in one query ──────
        $chainId = (int) $validators[0]['chain_id'];
        // Bounded SELECT — the architectural guardrail requires every
        // SELECT to be bounded. 10000 is far above realistic bonded-set
        // sizes (Cosmos chains cap ~200; larger chains cap ~500 via LCD
        // paging) and protects against runaway memory on future chains.
        /** @var list<ValidatorBulkExistingRow>|null $existingRows */
        $existingRows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, operator_address, moniker, status, commission_rate,
                    total_stake, jailed_count, voting_power_rank,
                    enrichment_attempts, fetched_at
             FROM {$table}
             WHERE chain_id = %d
             LIMIT 10000",
            $chainId
        ));

        $existing = [];
        foreach ($existingRows ?: [] as $row) {
            $existing[$row->operator_address] = $row;
        }

        $stats['total'] = count($validators);

        // Wrap all writes in a transaction so a PHP timeout mid-batch
        // rolls back cleanly instead of leaving partial state.
        $wpdb->query('START TRANSACTION');

        try {

        // Collect IDs of unchanged rows whose fetched_at is stale (>6h).
        // These get a single batch UPDATE at the end instead of N individual writes.
        $staleFetchedIds = [];

        foreach ($validators as $data) {
            $addr = $data['operator_address'];
            $prev = $existing[$addr] ?? null;

            if ($prev === null) {
                // ── NEW ─────────────────────────────────────────────────
                $jitterSec        = crc32($addr) & 0x3FFF;
                $nextEnrichmentAt = gmdate('Y-m-d H:i:s', time() + $jitterSec);

                // Enrichment-only columns (self_stake, delegator_count, uptime_30d)
                // are omitted — they default to NULL in the schema and are populated
                // by the EnrichmentScheduler. Using %f with null would store 0.00
                // instead of NULL, which breaks the "needs enrichment" detection.
                // Build nullable float fragments to preserve NULL (not 0.00)
                $sqlCommission = ($data['commission_rate'] ?? null) !== null
                    ? $wpdb->prepare('%f', (float) $data['commission_rate'])
                    : 'NULL';
                $sqlStake = ($data['total_stake'] ?? null) !== null
                    ? $wpdb->prepare('%f', (float) $data['total_stake'])
                    : 'NULL';

                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table}
                        (wallet_link_id, operator_address, chain_id, moniker, status,
                         commission_rate, total_stake, jailed_count,
                         voting_power_rank, fetched_at, expires_at, next_enrichment_at)
                     VALUES (NULL, %s, %d, %s, %s, {$sqlCommission}, {$sqlStake}, %d, %d, %s, %s, %s)",
                    $addr,
                    $chainId,
                    $data['moniker'] ?? null,
                    $data['status'] ?? 'unknown',
                    $data['jailed_count'] ?? 0,
                    $data['voting_power_rank'] ?? null,
                    $now,
                    $expiresAt,
                    $nextEnrichmentAt
                ));
                $stats['new']++;
                continue;
            }

            // ── EXISTING: check if anything the indexer owns has changed ─
            $changed = ($data['moniker'] ?? null)           !== ($prev->moniker ?? null)
                || ($data['status'] ?? 'unknown')           !== ($prev->status ?? 'unknown')
                || round((float) ($data['commission_rate'] ?? 0), 2) !== round((float) ($prev->commission_rate ?? 0), 2)
                || round((float) ($data['total_stake'] ?? 0), 6)     !== round((float) ($prev->total_stake ?? 0), 6)
                || (int) ($data['jailed_count'] ?? 0)       !== (int) ($prev->jailed_count ?? 0)
                || (int) ($data['voting_power_rank'] ?? 0)  !== (int) ($prev->voting_power_rank ?? 0)
                || (int) ($prev->enrichment_attempts ?? 0)  > 0;  // reset stuck validators

            if (!$changed) {
                // UNCHANGED — no per-row write. If fetched_at is stale (>6h),
                // collect the ID for a batch timestamp refresh at the end.
                $fetchedAge = $prev->fetched_at
                    ? (time() - strtotime($prev->fetched_at))
                    : PHP_INT_MAX;

                if ($fetchedAge >= $fetchedAtStaleThreshold) {
                    $staleFetchedIds[] = (int) $prev->id;
                }

                $stats['unchanged']++;
                continue;
            }

            // ── CHANGED — per-row UPDATE (data columns) ─────────────────
            // Build nullable float fragments to preserve NULL (not 0.00)
            $sqlCommission = ($data['commission_rate'] ?? null) !== null
                ? $wpdb->prepare('%f', (float) $data['commission_rate'])
                : 'NULL';
            $sqlStake = ($data['total_stake'] ?? null) !== null
                ? $wpdb->prepare('%f', (float) $data['total_stake'])
                : 'NULL';

            // Build nullable voting_power_rank to preserve NULL (not 0)
            // for validators that left the active set.
            $sqlVotingRank = ($data['voting_power_rank'] ?? null) !== null
                ? $wpdb->prepare('%d', (int) $data['voting_power_rank'])
                : 'NULL';

            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET moniker             = %s,
                     status              = %s,
                     commission_rate     = {$sqlCommission},
                     total_stake         = {$sqlStake},
                     jailed_count        = %d,
                     voting_power_rank   = {$sqlVotingRank},
                     fetched_at          = %s,
                     expires_at          = %s,
                     enrichment_attempts = 0,
                     retry_after         = NULL
                 WHERE id = %d",
                $data['moniker'] ?? null,
                $data['status'] ?? 'unknown',
                $data['jailed_count'] ?? 0,
                $now,
                $expiresAt,
                (int) $prev->id
            ));
            $stats['updated']++;
        }

        // ── Step 6: batch timestamp refresh for stale unchanged rows ────
        // Single UPDATE per chunk instead of N individual writes.
        if (!empty($staleFetchedIds)) {
            $chunks = array_chunk($staleFetchedIds, 200);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET fetched_at = %s WHERE id IN ({$placeholders})",
                    $now,
                    ...$chunk
                ));
            }
            $stats['refreshed'] = count($staleFetchedIds);
        }

        $wpdb->query('COMMIT');

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        // Invalidate the 1-hour getCountsByChain cache so the partial-fetch
        // detector in ChainRefreshService::index_validators reads fresh
        // counts on the next cycle. Without this, a stale count caused
        // false-positive partial-fetch warnings right after a healthy
        // bulkUpsert added new validators.
        wp_cache_delete('counts_by_chain', 'bcc_onchain_validators');

        return $stats;
    }

    /**
     * @return array{items: list<ValidatorWithChain>, total: int, pages: int}
     */
    public static function getForProject(int $postId, int $page = 1, int $perPage = 8, string $orderBy = 'total_stake'): array
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        $allowedOrder = ['total_stake', 'voting_power_rank', 'commission_rate', 'delegator_count', 'uptime_30d', 'chain_name'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_stake';
        }

        $orderDir = ($orderBy === 'voting_power_rank' || $orderBy === 'commission_rate') ? 'ASC' : 'DESC';
        $offset   = ($page - 1) * $perPage;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table} v
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             WHERE w.post_id = %d",
            $postId
        ));

        /** @var list<ValidatorWithChain>|null $items */
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.wallet_link_id, v.operator_address, v.chain_id, v.moniker,
                    v.status, v.commission_rate, v.total_stake, v.self_stake,
                    v.delegator_count, v.uptime_30d, v.jailed_count,
                    v.voting_power_rank, v.fetched_at, v.expires_at,
                    c.slug AS chain_slug, c.name AS chain_name, c.explorer_url, c.native_token
             FROM {$table} v
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             JOIN {$chains} c ON c.id = v.chain_id
             WHERE w.post_id = %d
             ORDER BY v.{$orderBy} {$orderDir}
             LIMIT %d OFFSET %d",
            $postId, $perPage, $offset
        ));

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Get top validators globally (not per-project). Used by leaderboard block.
     *
     * @param int         $page
     * @param int         $perPage
     * @param string      $orderBy
     * @param int|null    $chainId    Filter by chain.
     * @param string|null $timeWindow Filter by fetched_at window: '1h','6h','12h','1d','7d','30d'. Null = all.
     * @return array{items: list<ValidatorWithChain>, total: int, pages: int}
     */
    public static function getTopValidators(int $page = 1, int $perPage = 20, string $orderBy = 'total_stake', ?int $chainId = null, ?string $timeWindow = null, ?string $direction = null): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $allowedOrder = ['total_stake', 'self_stake', 'voting_power_rank', 'commission_rate', 'delegator_count', 'uptime_30d'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_stake';
        }

        // Default direction: ASC for rank/commission (lower is better), DESC for everything else.
        $defaultDir = ($orderBy === 'voting_power_rank' || $orderBy === 'commission_rate') ? 'ASC' : 'DESC';
        $orderDir   = ($direction === 'asc' || $direction === 'desc') ? strtoupper($direction) : $defaultDir;
        $offset   = ($page - 1) * $perPage;

        $where  = '1=1';
        $params = [];

        if ($chainId) {
            $where   .= ' AND v.chain_id = %d';
            $params[] = $chainId;
        }

        // Time window filter — show validators fetched within this period.
        $windowMap = [
            '1h'  => 1,      '6h'  => 6,       '12h' => 12,
            '1d'  => 24,     '7d'  => 24 * 7,   '30d' => 24 * 30,
        ];
        if ($timeWindow && isset($windowMap[$timeWindow])) {
            $where   .= ' AND v.fetched_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)';
            $params[] = $windowMap[$timeWindow];
        }

        $countSql = "SELECT COUNT(*) FROM {$table} v WHERE {$where}";
        $mainSql  = "SELECT v.id, v.wallet_link_id, v.operator_address, v.chain_id, v.moniker,
                            v.status, v.commission_rate, v.total_stake, v.self_stake,
                            v.delegator_count, v.uptime_30d, v.jailed_count,
                            v.voting_power_rank, v.fetched_at, v.expires_at,
                            c.slug AS chain_slug, c.name AS chain_name, c.explorer_url, c.native_token
                     FROM {$table} v
                     JOIN {$chains} c ON c.id = v.chain_id
                     WHERE {$where}
                     ORDER BY v.{$orderBy} {$orderDir}
                     LIMIT %d OFFSET %d";

        $countParams = $params;
        $mainParams  = array_merge($params, [$perPage, $offset]);

        $total = empty($countParams)
            ? (int) $wpdb->get_var($countSql)
            : (int) $wpdb->get_var($wpdb->prepare($countSql, ...$countParams));

        // LIMIT %d OFFSET %d is in the $mainSql (last two placeholders
        // supplied by $mainParams via array_merge above). Bounded query.
        /** @var list<ValidatorWithChain>|null $items */
        $items = $wpdb->get_results($wpdb->prepare($mainSql, ...$mainParams));

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    // ── Aggregate stats (OnchainDataReadService) ────────────────────────────

    /**
     * Aggregate validator stats for a project page.
     *
     * @return ValidatorAggregateStats|null
     */
    public static function getAggregateStatsForProject(int $postId): ?object
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        /** @var ValidatorAggregateStats|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*)                                          AS chains_count,
                SUM(CASE WHEN v.status = 'active' THEN 1 ELSE 0 END) AS active_count,
                COALESCE(SUM(v.total_stake), 0)                   AS total_stake,
                COALESCE(SUM(v.delegator_count), 0)               AS total_delegators
             FROM {$table} v
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             WHERE w.post_id = %d",
            $postId
        ));
    }

    /**
     * Top validator by total_stake for a project page.
     *
     * @return ValidatorTopForProject|null
     */
    public static function getTopValidatorForProject(int $postId): ?object
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        /** @var ValidatorTopForProject|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT v.id, v.operator_address, v.chain_id, v.moniker, v.status,
                    v.commission_rate, v.total_stake, v.self_stake, v.delegator_count,
                    v.uptime_30d, v.jailed_count, v.voting_power_rank, v.fetched_at,
                    c.slug AS chain_slug, c.name AS chain_name
             FROM {$table} v
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             JOIN {$chains} c ON c.id = v.chain_id
             WHERE w.post_id = %d
             ORDER BY v.total_stake DESC
             LIMIT 1",
            $postId
        ));
    }

    /**
     * Load a validator with chain metadata. Used by ClaimService.
     *
     * @return ValidatorIdWithChain|null
     */
    public static function getByIdWithChain(int $validatorId): ?object
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        /** @var ValidatorIdWithChain|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT v.id, v.wallet_link_id, v.operator_address, v.chain_id, v.moniker,
                    v.status, v.commission_rate, v.total_stake, v.self_stake,
                    v.delegator_count, v.uptime_30d, v.jailed_count, v.voting_power_rank,
                    c.slug AS chain_slug, c.chain_type
             FROM {$table} v
             INNER JOIN {$chains} c ON c.id = v.chain_id
             WHERE v.id = %d",
            $validatorId
        ));
    }

    // ── Enrichment scheduler methods ────────────────────────────────────────

    /**
     * Mark validators as inactive if not seen by indexer in 30+ days.
     *
     * @return int Number of rows updated.
     */
    public static function markDeadValidators(int $maxAttempts): int
    {
        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'inactive',
                 next_enrichment_at = NULL
             WHERE enrichment_attempts >= %d
               AND fetched_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND status != 'inactive'",
            $maxAttempts
        ));

        return (int) $result;
    }

    /**
     * Fetch the next batch of validators due for enrichment.
     *
     * @return list<ValidatorRow>
     */
    public static function fetchEnrichmentBatch(int $maxAttempts, int $limit): array
    {
        global $wpdb;
        $table = self::table();

        // LIMIT %d at tail of the prepared SQL; $limit is the caller's cap.
        /** @var list<ValidatorRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
             WHERE (next_enrichment_at IS NULL OR next_enrichment_at <= NOW())
               AND (retry_after IS NULL OR retry_after <= NOW())
               AND enrichment_attempts < %d
             ORDER BY
                CASE
                    WHEN wallet_link_id IS NOT NULL AND self_stake IS NULL THEN 0
                    WHEN wallet_link_id IS NOT NULL THEN 1
                    WHEN self_stake IS NULL THEN 2
                    ELSE 3
                END ASC,
                total_stake DESC,
                last_enriched_at ASC
             LIMIT %d",
            $maxAttempts,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Mark a validator as successfully enriched with next schedule.
     * Logs (but does not throw) on DB error so the scheduler's per-row
     * try/catch doesn't turn a metadata write failure into a double-
     * failure path that re-queues enrichment on top of itself.
     */
    public static function markEnrichmentSuccess(int $validatorId, string $nextEnrichmentAt): void
    {
        global $wpdb;
        $table = self::table();

        $result = $wpdb->update(
            $table,
            [
                'last_enriched_at'    => current_time('mysql', true),
                'next_enrichment_at'  => $nextEnrichmentAt,
                'retry_after'         => null,
                'enrichment_attempts' => 0,
            ],
            ['id' => $validatorId],
            ['%s', '%s', '%s', '%d'],
            ['%d']
        );

        if ($result === false) {
            \BCC\Core\Log\Logger::error('[Onchain] markEnrichmentSuccess UPDATE failed', [
                'validator_id' => $validatorId,
                'db_error'     => (string) $wpdb->last_error,
            ]);
        }
    }

    /**
     * Mark a validator enrichment as failed with backoff.
     */
    public static function markEnrichmentFailure(int $validatorId, int $attempts, string $retryAfter): void
    {
        global $wpdb;
        $table = self::table();

        $result = $wpdb->update(
            $table,
            [
                'enrichment_attempts' => $attempts,
                'retry_after'         => $retryAfter,
            ],
            ['id' => $validatorId],
            ['%d', '%s'],
            ['%d']
        );

        if ($result === false) {
            \BCC\Core\Log\Logger::error('[Onchain] markEnrichmentFailure UPDATE failed', [
                'validator_id' => $validatorId,
                'attempts'     => $attempts,
                'db_error'     => (string) $wpdb->last_error,
            ]);
        }
    }

    // ── Admin queries (ChainsPage) ──────────────────────────────────────────

    /**
     * Get active validators for a chain. Admin enrichment use.
     *
     * @return list<ValidatorRow>
     */
    public static function getActiveForChain(int $chainId, int $limit = 500): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<ValidatorRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
             WHERE chain_id = %d AND status != 'inactive'
             ORDER BY total_stake DESC
             LIMIT %d",
            $chainId, $limit
        ));

        return $rows ?: [];
    }

    /**
     * Get validator counts grouped by chain_id. Admin page summary.
     *
     * @return array<int, ValidatorCountByChain>  Keyed by chain_id. Each has cnt, last_fetched.
     */
    public static function getCountsByChain(): array
    {
        global $wpdb;
        $table = self::table();

        $cached = wp_cache_get('counts_by_chain', 'bcc_onchain_validators');
        if (is_array($cached)) {
            /** @var array<int, ValidatorCountByChain> $cached */
            return $cached;
        }

        /** @var list<ValidatorCountByChain>|null $rows */
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

        wp_cache_set('counts_by_chain', $map, 'bcc_onchain_validators', HOUR_IN_SECONDS);

        return $map;
    }

    /**
     * Exponential backoff: push expires_at forward by 2x the original TTL,
     * capped at 7 days to prevent validators from disappearing from
     * refresh cycles indefinitely.
     */
    public static function backoffRow(int $rowId): bool
    {
        global $wpdb;
        $table   = self::table();
        $maxSecs = 7 * DAY_IN_SECONDS;

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

    /**
     * Check whether any validator rows exist for a given wallet_link.
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

}
