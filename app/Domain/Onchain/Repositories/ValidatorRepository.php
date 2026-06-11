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
 * @phpstan-type ValidatorCardRow object{
 *     validator_id: int|numeric-string,
 *     chain_slug: string,
 *     chain_name: string,
 *     operator_address: string,
 *     status: string,
 *     uptime_30d: string|null,
 *     commission_rate: string|null,
 *     voting_power_rank: string|null,
 *     total_stake: string|null,
 *     self_stake: string|null,
 *     delegator_count: string|null,
 *     jailed_count: string|null,
 *     fetched_at: string,
 *     logo_url: string|null
 * }
 *
 * @phpstan-type ValidatorCardPageRow object{
 *     page_id: int|numeric-string,
 *     validator_id: int|numeric-string,
 *     chain_slug: string,
 *     chain_name: string,
 *     operator_address: string,
 *     status: string,
 *     uptime_30d: string|null,
 *     commission_rate: string|null,
 *     voting_power_rank: string|null,
 *     total_stake: string|null,
 *     self_stake: string|null,
 *     delegator_count: string|null,
 *     jailed_count: string|null,
 *     fetched_at: string,
 *     logo_url: string|null
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
                 last_enriched_at, next_enrichment_at, retry_after, enrichment_attempts,
                 identity, logo_url, logo_source_ref, logo_checked_at';

    public static function table(): string
    {
        return DB::table('onchain_validators');
    }

    /**
     * Batched moniker resolution for a set of (chain_id, operator_address)
     * pairs. Used by the who-to-follow recommender to label the
     * "Backs {moniker} too" reason line without an N+1 across candidates.
     *
     * Returns a map keyed `"<chainId>:<operatorAddress>"` → moniker.
     * Pairs that don't resolve (un-indexed validator) are absent from
     * the map; the caller falls back to a generic label.
     *
     * Bounded (§4): an explicit `IN (...)` over the composite key,
     * capped at 100 pairs (the recommender only resolves monikers for
     * its final, already-bounded result set). Reads `moniker` only — no
     * SELECT *.
     *
     * @param list<array{chain_id: int, operator_address: string}> $pairs
     * @return array<string, string>  "chainId:operatorAddress" => moniker
     */
    public static function getMonikersByAddresses(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        // Dedupe by composite key; cap defensively.
        $clean = [];
        foreach ($pairs as $pair) {
            $chainId = (int) ($pair['chain_id'] ?? 0);
            $address = (string) ($pair['operator_address'] ?? '');
            if ($chainId > 0 && $address !== '') {
                $clean[$chainId . ':' . $address] = ['chain_id' => $chainId, 'operator_address' => $address];
            }
            if (count($clean) >= 100) {
                break;
            }
        }
        if ($clean === []) {
            return [];
        }

        global $wpdb;
        $table = self::table();

        $conditions = [];
        $params     = [];
        foreach ($clean as $pair) {
            $conditions[] = '(chain_id = %d AND operator_address = %s)';
            $params[]     = $pair['chain_id'];
            $params[]     = $pair['operator_address'];
        }
        $whereSql = implode(' OR ', $conditions);

        /** @var list<array{chain_id: string, operator_address: string, moniker: string|null}>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT chain_id, operator_address, moniker
                   FROM {$table}
                  WHERE {$whereSql}
                  LIMIT 100",
                ...$params
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $moniker = $row['moniker'];
            if ($moniker !== null && $moniker !== '') {
                $out[(int) $row['chain_id'] . ':' . (string) $row['operator_address']] = (string) $moniker;
            }
        }
        return $out;
    }

    /**
     * Resolve the first validator row backing a peepso-page, with its
     * chain slug. Used by CardViewService to surface `claim_target`
     * on the validator card view-model so the §N8 claim flow knows
     * which entity_id + chain_slug to send back to the claim endpoint.
     *
     * Two resolution paths (tried in order):
     *
     *   1. wallet_link join — for pages an operator has already linked
     *      a wallet to (the post-link state). This is the canonical
     *      claimed/in-flight path.
     *
     *   2. _bcc_onchain_validator_id post_meta fallback — for system-minted
     *      placeholder pages (ValidatorPageMinter) where no wallet has
     *      been linked yet. Without this fallback the WANTED claim CTA
     *      can't render on unclaimed validator placeholders because
     *      CardViewService would receive null and skip claim_target.
     *
     * Validator pages typically map 1:1 to a single validator row; the
     * LIMIT 1 in each path is defensive against transitional re-indexing.
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

        if ($row !== null) {
            return $row;
        }

        // Fallback: placeholder pages minted by ValidatorPageMinter have
        // no wallet_link yet — the page<->validator binding lives in
        // post_meta until a real claim creates a wallet_link.
        /** @var object{validator_id: numeric-string, chain_slug: string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT v.id AS validator_id, c.slug AS chain_slug
               FROM {$wpdb->postmeta} pm
               JOIN {$table} v  ON v.id = CAST(pm.meta_value AS UNSIGNED)
               JOIN {$chains} c ON c.id = v.chain_id
              WHERE pm.meta_key = '_bcc_onchain_validator_id'
                AND pm.post_id  = %d
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
     * Two resolution paths (mirrors findFirstByPageId):
     *
     *   1. wallet_link join — for pages with operator-linked wallets.
     *      Returns multi-chain rows when an operator has linked wallets
     *      on several chains to the same peepso-page.
     *
     *   2. _bcc_onchain_validator_id post_meta fallback — for placeholder
     *      pages with no wallet_link yet. Always returns at most one row
     *      because the minter binds one placeholder page to exactly one
     *      validator. The multi-chain case can't apply pre-claim.
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

        if (empty($rows)) {
            // Fallback: placeholder pages bind to a single validator via
            // post_meta. At most one row, by construction.
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
                   FROM {$wpdb->postmeta} pm
                   JOIN {$table} v  ON v.id = CAST(pm.meta_value AS UNSIGNED)
                   JOIN {$chains} c ON c.id = v.chain_id
                  WHERE pm.meta_key = '_bcc_onchain_validator_id'
                    AND pm.post_id  = %d
                  LIMIT 1",
                $pageId
            ));
        }

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
     * Batch card-projection for a whole cards-list page of validator
     * pages. One combined fetch replacing the 4 per-page projections
     * (findFirstByPageId / findAllByPageId / findSignalsByPageId /
     * findLogoByPageId) the per-card path runs — the row carries the
     * union of their columns so CardViewService derives claim_target +
     * chains + onchain_signals + logo from a single map.
     *
     * Preserves the two-path resolution semantics of the per-page
     * methods: wallet_link join first for every page; the
     * `_bcc_onchain_validator_id` post_meta fallback runs ONLY for
     * pages the first query missed (placeholder pages with no
     * wallet_link yet), and contributes at most one row per page —
     * matching the per-page fallbacks' LIMIT 1.
     *
     * Rows are ordered chain-name ASC per page. Note: the per-page
     * LIMIT 1 projections (claim_target / signals / logo) picked an
     * arbitrary row on multi-chain pages; "first row" from this batch
     * is deterministically the alphabetically-first chain — a benign
     * tightening, not a behavior contract change.
     *
     * Bounded: caller-paginated IN-list (cards per_page ≤ 50) × the
     * defensive 32-chain ceiling per page → LIMIT 1600.
     *
     * @param list<int> $pageIds
     * @return array<int, list<object>> page_id => rows (chain-name ASC).
     * @phpstan-return array<int, list<ValidatorCardRow>>
     */
    public static function findCardRowsByPageIds(array $pageIds): array
    {
        $ids = [];
        foreach ($pageIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $idList = array_keys($ids);

        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        $selectCols =
            "v.id AS validator_id, c.slug AS chain_slug, c.name AS chain_name,
             v.operator_address, v.status, v.uptime_30d, v.commission_rate,
             v.voting_power_rank, v.total_stake, v.self_stake,
             v.delegator_count, v.jailed_count, v.fetched_at, v.logo_url";

        $ph = implode(',', array_fill(0, count($idList), '%d'));

        /** @var list<ValidatorCardPageRow> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT w.post_id AS page_id, {$selectCols}
               FROM {$table} v
               JOIN {$wallets} w ON w.id = v.wallet_link_id
               JOIN {$chains}  c ON c.id = v.chain_id
              WHERE w.post_id IN ({$ph})
              ORDER BY w.post_id ASC, c.name ASC
              LIMIT 1600",
            ...$idList
        ));

        $out = [];
        foreach ($rows as $row) {
            $pageId = (int) $row->page_id;
            if ($pageId > 0) {
                $out[$pageId][] = $row;
            }
        }

        // Fallback ONLY for pages the wallet_link join missed —
        // placeholder pages bind to a single validator via post_meta
        // until a real claim creates a wallet_link.
        $missing = [];
        foreach ($idList as $id) {
            if (!isset($out[$id])) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            $phMissing = implode(',', array_fill(0, count($missing), '%d'));

            /** @var list<ValidatorCardPageRow> $fallbackRows */
            $fallbackRows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.post_id AS page_id, {$selectCols}
                   FROM {$wpdb->postmeta} pm
                   JOIN {$table} v  ON v.id = CAST(pm.meta_value AS UNSIGNED)
                   JOIN {$chains} c ON c.id = v.chain_id
                  WHERE pm.meta_key = '_bcc_onchain_validator_id'
                    AND pm.post_id IN ({$phMissing})
                  ORDER BY pm.post_id ASC, c.name ASC
                  LIMIT 1600",
                ...$missing
            ));

            foreach ($fallbackRows as $row) {
                $pageId = (int) $row->page_id;
                // First row per page only — mirrors the per-page
                // fallbacks' LIMIT 1 (one placeholder = one validator
                // by construction; dup meta rows are pathological).
                if ($pageId > 0 && !isset($out[$pageId])) {
                    $out[$pageId] = [$row];
                }
            }
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
            'identity'         => '%s',
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

    /**
     * Enumerate validators that have NO peepso-page backing yet — neither
     * a user-linked wallet (wallet_link_id IS NULL) nor a system-minted
     * placeholder page (no post_meta `_bcc_onchain_validator_id` row).
     *
     * Used by ValidatorPageMinter to backfill missing placeholder pages
     * so the 97 Akash (and any other chain-indexed) validators surface in
     * /directory?kind=validator with a WANTED claim CTA.
     *
     * Bounded by LIMIT/OFFSET; caller paginates. The LEFT JOIN on postmeta
     * uses the `_bcc_onchain_validator_id` key — index lookup is fast
     * because postmeta has KEY(meta_key, meta_value(N)).
     *
     * @return list<object{
     *     id: int,
     *     operator_address: string,
     *     moniker: string|null,
     *     status: string,
     *     chain_id: int,
     *     chain_slug: string,
     *     chain_name: string
     * }>
     */
    public static function findMissingPlaceholderPage(?int $chainId, int $limit, int $offset): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $where  = ['v.wallet_link_id IS NULL'];
        $params = [];

        if ($chainId !== null) {
            $where[]  = 'v.chain_id = %d';
            $params[] = $chainId;
        }

        $params[] = max(1, min($limit, 500));
        $params[] = max(0, $offset);

        $whereSql = implode(' AND ', $where);

        /**
         * @var list<object{
         *     id: numeric-string,
         *     operator_address: string,
         *     moniker: string|null,
         *     status: string,
         *     chain_id: numeric-string,
         *     chain_slug: string,
         *     chain_name: string
         * }>|null $rows
         */
        // The LEFT JOIN compares an int (v.id) against postmeta.meta_value
        // (LONGTEXT) by numeric cast on the text side. Casting in the
        // other direction (CAST(v.id AS CHAR)) trips a collation mismatch
        // because wp_postmeta is utf8mb4_unicode_ci and the CHAR cast
        // inherits utf8mb4_unicode_520_ci on this MySQL build.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.operator_address, v.moniker, v.status,
                    v.chain_id, c.slug AS chain_slug, c.name AS chain_name
               FROM {$table} v
               JOIN {$chains} c ON c.id = v.chain_id
               LEFT JOIN {$wpdb->postmeta} pm
                      ON pm.meta_key = '_bcc_onchain_validator_id'
                     AND CAST(pm.meta_value AS UNSIGNED) = v.id
              WHERE {$whereSql}
                AND pm.meta_id IS NULL
              ORDER BY v.chain_id ASC, v.voting_power_rank ASC, v.id ASC
              LIMIT %d OFFSET %d",
            ...$params
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = (object) [
                'id'               => (int) $row->id,
                'operator_address' => $row->operator_address,
                'moniker'          => $row->moniker,
                'status'           => $row->status,
                'chain_id'         => (int) $row->chain_id,
                'chain_slug'       => $row->chain_slug,
                'chain_name'       => $row->chain_name,
            ];
        }
        return $out;
    }

    /**
     * Count validators eligible for placeholder-page minting. Cheap
     * companion to findMissingPlaceholderPage() for CLI progress output.
     */
    public static function countMissingPlaceholderPage(?int $chainId): int
    {
        global $wpdb;
        $table = self::table();

        $where  = ['v.wallet_link_id IS NULL'];
        $params = [];

        if ($chainId !== null) {
            $where[]  = 'v.chain_id = %d';
            $params[] = $chainId;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT COUNT(*)
                  FROM {$table} v
                  LEFT JOIN {$wpdb->postmeta} pm
                         ON pm.meta_key = '_bcc_onchain_validator_id'
                        AND CAST(pm.meta_value AS UNSIGNED) = v.id
                 WHERE {$whereSql}
                   AND pm.meta_id IS NULL";

        if (empty($params)) {
            return (int) $wpdb->get_var($sql);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * Resolve the first validator's on-chain signals (status, uptime,
     * commission, stake, rank, …) backing a peepso-page. Used by
     * CardViewService to surface the `onchain_signals` view-model field
     * on validator cards.
     *
     * Mirrors findFirstByPageId's two-path resolution: wallet_link join
     * for claimed/in-flight pages, post_meta fallback for placeholder
     * pages minted by ValidatorPageMinter.
     *
     * @return object{
     *     status: string,
     *     uptime_30d: string|null,
     *     commission_rate: string|null,
     *     voting_power_rank: string|null,
     *     total_stake: string|null,
     *     self_stake: string|null,
     *     delegator_count: string|null,
     *     jailed_count: string|null,
     *     fetched_at: string,
     *     chain_slug: string,
     *     chain_name: string
     * }|null
     */
    public static function findSignalsByPageId(int $pageId): ?object
    {
        if ($pageId <= 0) {
            return null;
        }

        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        $selectCols =
            "v.status, v.uptime_30d, v.commission_rate, v.voting_power_rank,
             v.total_stake, v.self_stake, v.delegator_count, v.jailed_count,
             v.fetched_at, c.slug AS chain_slug, c.name AS chain_name";

        /**
         * @var object{
         *     status: string,
         *     uptime_30d: string|null,
         *     commission_rate: string|null,
         *     voting_power_rank: string|null,
         *     total_stake: string|null,
         *     self_stake: string|null,
         *     delegator_count: string|null,
         *     jailed_count: string|null,
         *     fetched_at: string,
         *     chain_slug: string,
         *     chain_name: string
         * }|null $row
         */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$selectCols}
               FROM {$table} v
               JOIN {$wallets} w ON w.id = v.wallet_link_id
               JOIN {$chains}  c ON c.id = v.chain_id
              WHERE w.post_id = %d
              LIMIT 1",
            $pageId
        ));

        if ($row !== null) {
            return $row;
        }

        // Placeholder-page fallback (no wallet_link yet).
        /**
         * @var object{
         *     status: string,
         *     uptime_30d: string|null,
         *     commission_rate: string|null,
         *     voting_power_rank: string|null,
         *     total_stake: string|null,
         *     self_stake: string|null,
         *     delegator_count: string|null,
         *     jailed_count: string|null,
         *     fetched_at: string,
         *     chain_slug: string,
         *     chain_name: string
         * }|null $row
         */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$selectCols}
               FROM {$wpdb->postmeta} pm
               JOIN {$table} v  ON v.id = CAST(pm.meta_value AS UNSIGNED)
               JOIN {$chains} c ON c.id = v.chain_id
              WHERE pm.meta_key = '_bcc_onchain_validator_id'
                AND pm.post_id  = %d
              LIMIT 1",
            $pageId
        ));

        return $row;
    }

    /**
     * Resolve the auto-imported logo URL backing a peepso-page, if any.
     *
     * Mirrors findSignalsByPageId's two-path join (wallet_link first, then
     * placeholder post_meta) but returns only the locally-hosted logo URL.
     * Read by CardViewService as the lowest-precedence crest source, so a
     * claimer/manual image always wins over the auto logo.
     */
    public static function findLogoByPageId(int $pageId): ?string
    {
        if ($pageId <= 0) {
            return null;
        }

        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        $url = $wpdb->get_var($wpdb->prepare(
            "SELECT v.logo_url
               FROM {$table} v
               JOIN {$wallets} w ON w.id = v.wallet_link_id
              WHERE w.post_id = %d
              LIMIT 1",
            $pageId
        ));

        if (!is_string($url) || $url === '') {
            // Placeholder-page fallback (no wallet_link yet).
            $url = $wpdb->get_var($wpdb->prepare(
                "SELECT v.logo_url
                   FROM {$wpdb->postmeta} pm
                   JOIN {$table} v ON v.id = CAST(pm.meta_value AS UNSIGNED)
                  WHERE pm.meta_key = '_bcc_onchain_validator_id'
                    AND pm.post_id  = %d
                  LIMIT 1",
                $pageId
            ));
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Read the logo-resolution state for a single validator (by PK).
     * Used by ValidatorLogoService to gate re-resolution.
     *
     * @return object{id: string, chain_id: string, identity: string|null, logo_url: string|null, logo_source_ref: string|null, logo_checked_at: string|null}|null
     */
    public static function getLogoState(int $validatorId): ?object
    {
        if ($validatorId <= 0) {
            return null;
        }

        global $wpdb;
        $table = self::table();

        /** @var object{id: string, chain_id: string, identity: string|null, logo_url: string|null, logo_source_ref: string|null, logo_checked_at: string|null}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, chain_id, identity, logo_url, logo_source_ref, logo_checked_at
               FROM {$table}
              WHERE id = %d
              LIMIT 1",
            $validatorId
        ));

        return $row;
    }

    /**
     * Persist a resolved, locally-hosted logo for a validator. Stamps
     * logo_checked_at so the resolver throttle restarts.
     */
    public static function setLogo(int $validatorId, string $localUrl, string $sourceRef): bool
    {
        if ($validatorId <= 0) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->update(
            self::table(),
            [
                'logo_url'        => $localUrl,
                'logo_source_ref' => $sourceRef,
                'logo_checked_at' => current_time('mysql', true),
            ],
            ['id' => $validatorId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Stamp logo_checked_at without changing the image — called after a
     * resolution attempt that produced no change (already current, no
     * identity match, or a swallowed failure) so the throttle window
     * restarts and we don't re-hit the upstream every run.
     */
    public static function markLogoChecked(int $validatorId): bool
    {
        if ($validatorId <= 0) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->update(
            self::table(),
            ['logo_checked_at' => current_time('mysql', true)],
            ['id' => $validatorId],
            ['%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Resolve a peepso-page id from its placeholder-validator post_meta link.
     * Used by ValidatorPageMinter to dedupe re-runs without scanning postmeta
     * a second time.
     */
    public static function findPlaceholderPageId(int $validatorId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id
               FROM {$wpdb->postmeta}
              WHERE meta_key = '_bcc_onchain_validator_id'
                AND meta_value = %s
              LIMIT 1",
            (string) $validatorId
        ));
    }

}
