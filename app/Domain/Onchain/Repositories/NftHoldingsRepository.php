<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent NFT-holdings repository (V2 Phase 1a, 1c additions).
 *
 * Owns all DB access for `wp_bcc_nft_holdings`. The public API is
 * STRUCTURALLY split — visible reads cannot accidentally surface
 * spam/failed rows because the methods that return them don't exist
 * on the visible-read API.
 *
 * Visible reads (gallery, gating, discovery):
 *   - findVisibleForWallet           (any visible row)
 *   - findVisibleEnrichedForWallet   (visible + enriched_at IS NOT NULL — gallery use)
 *   - countVisibleByContract
 *   - walletHasAnyEnriched
 *
 * Admin / recovery reads (audit only):
 *   - findAllIncludingSpam
 *   - findByStatus
 *
 * Writes (NftHoldingsIndexer-only consumer):
 *   - upsertMany
 *   - deleteByWalletAndToken
 *   - markStatus
 *
 * Enrichment writes (NftEnrichmentService-only consumer):
 *   - findPendingEnrichment
 *   - applyEnrichment
 *
 * Cache-invalidation contract (§5):
 *   - Every write path bumps the per-wallet generation counter via
 *     `bumpWalletGeneration(walletLinkId)`. Read-side consumers in
 *     HoldingsService key their per-request transient on the
 *     post-bump generation so a successful indexer write is visible
 *     to the next gallery render without a manual flush.
 *
 * @phpstan-type HoldingRow object{
 *     id: string,
 *     wallet_link_id: string,
 *     chain_id: string,
 *     contract_address: string,
 *     token_id: string,
 *     token_standard: string|null,
 *     balance: string,
 *     metadata_status: string,
 *     name: string|null,
 *     image_url: string|null,
 *     metadata_uri: string|null,
 *     collection_name: string|null,
 *     last_seen_block: string,
 *     confirmed_at: string,
 *     indexed_at: string,
 *     enriched_at: string|null
 * }
 *
 * @phpstan-type UpsertInput array{
 *     wallet_link_id: int,
 *     chain_id: int,
 *     contract_address: string,
 *     token_id: string,
 *     token_standard?: string|null,
 *     balance?: int,
 *     metadata_status?: int,
 *     last_seen_block: int,
 *     confirmed_at: string
 * }
 *
 * @phpstan-type EnrichmentInput array{
 *     name?: string|null,
 *     image_url?: string|null,
 *     metadata_uri?: string|null,
 *     collection_name?: string|null
 * }
 */
final class NftHoldingsRepository
{
    public const STATUS_PENDING = 0;
    public const STATUS_OK      = 1;
    public const STATUS_SPAM    = 2;
    public const STATUS_FAILED  = 3;

    /** Cache group for generation counters. */
    private const CACHE_GROUP = 'bcc_nft_holdings';
    private const GENERATION_KEY_PREFIX = 'gen_wallet_';

    /** Columns returned by every read — no SELECT *. */
    private const COLUMNS = 'id, wallet_link_id, chain_id, contract_address, token_id, token_standard, balance, metadata_status, name, image_url, metadata_uri, collection_name, last_seen_block, confirmed_at, indexed_at, enriched_at';

    /** Hard cap so a single wallet's gallery query can't flood memory. */
    private const VISIBLE_READ_LIMIT = 5000;

    /** Hard cap on admin-audit pulls. */
    private const ADMIN_READ_LIMIT = 1000;

    /** Default batch size for enrichment scans. */
    private const ENRICHMENT_BATCH_SIZE = 50;

    public static function table(): string
    {
        return DB::table('nft_holdings');
    }

    // ── Visible reads (status IN (0, 1)) ────────────────────────────

    /**
     * Visible holdings for a single wallet on a single chain.
     *
     * Hard-coded `metadata_status IN (0, 1)` filter — there is no
     * include-spam variant on this method by design.
     *
     * @return list<HoldingRow>
     */
    public static function findVisibleForWallet(int $walletLinkId, int $chainId): array
    {
        if ($walletLinkId <= 0 || $chainId <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;
        $limit = self::VISIBLE_READ_LIMIT;

        /** @var list<HoldingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE wallet_link_id = %d
                AND chain_id = %d
                AND metadata_status IN (%d, %d)
              ORDER BY indexed_at DESC
              LIMIT %d",
            $walletLinkId,
            $chainId,
            self::STATUS_PENDING,
            self::STATUS_OK,
            $limit
        ));
        return $rows ?: [];
    }

    /**
     * Per-wallet visible-balance map for a single (chain, contract) pair.
     *
     * Used by the gate fast-path. Returns map keyed by wallet_link_id;
     * wallets with zero matching tokens are absent from the result.
     *
     * @param list<int> $walletLinkIds
     * @return array<int, int>  wallet_link_id => max balance among held tokens
     */
    public static function countVisibleByContract(int $chainId, string $contract, array $walletLinkIds): array
    {
        if ($chainId <= 0 || $contract === '' || $walletLinkIds === []) {
            return [];
        }

        $clean = array_values(array_filter(array_map('intval', $walletLinkIds), static fn ($id) => $id > 0));
        if ($clean === []) {
            return [];
        }

        global $wpdb;
        $table       = self::table();
        $contractLc  = strtolower($contract);
        $placeholders = implode(',', array_fill(0, count($clean), '%d'));

        // SUM(balance) per wallet so ERC-1155 multi-balance rows aggregate.
        $sql = $wpdb->prepare(
            "SELECT wallet_link_id, CAST(SUM(balance) AS UNSIGNED) AS total
               FROM {$table}
              WHERE chain_id = %d
                AND contract_address = %s
                AND metadata_status IN (%d, %d)
                AND wallet_link_id IN ({$placeholders})
              GROUP BY wallet_link_id",
            array_merge(
                [$chainId, $contractLc, self::STATUS_PENDING, self::STATUS_OK],
                $clean
            )
        );

        $rows = $wpdb->get_results($sql);
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[(int) $row->wallet_link_id] = (int) $row->total;
            }
        }
        return $out;
    }

    /**
     * Visible AND enriched holdings for a single wallet on a single
     * chain. The gallery path consumes this — `enriched_at IS NULL`
     * rows still fall through to the V1 transient fetcher (no
     * thumbnail-less regression).
     *
     * @return list<HoldingRow>
     */
    public static function findVisibleEnrichedForWallet(int $walletLinkId, int $chainId): array
    {
        if ($walletLinkId <= 0 || $chainId <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;
        $limit = self::VISIBLE_READ_LIMIT;

        /** @var list<HoldingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE wallet_link_id = %d
                AND chain_id = %d
                AND metadata_status IN (%d, %d)
                AND enriched_at IS NOT NULL
              ORDER BY indexed_at DESC
              LIMIT %d",
            $walletLinkId,
            $chainId,
            self::STATUS_PENDING,
            self::STATUS_OK,
            $limit
        ));
        return $rows ?: [];
    }

    /**
     * Quick existence check — does this wallet have ANY enriched,
     * visible row on this chain? Used by the read-path swap to decide
     * persistent-first vs transient-fallback.
     */
    public static function walletHasAnyEnriched(int $walletLinkId, int $chainId): bool
    {
        if ($walletLinkId <= 0 || $chainId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1
               FROM {$table}
              WHERE wallet_link_id = %d
                AND chain_id = %d
                AND metadata_status IN (%d, %d)
                AND enriched_at IS NOT NULL
              LIMIT 1",
            $walletLinkId,
            $chainId,
            self::STATUS_PENDING,
            self::STATUS_OK
        ));

        return $exists !== null;
    }

    /**
     * Quick existence check used by HoldingsService to decide
     * persistent-first vs transient-fallback for a (wallet, chain) pair.
     */
    public static function walletHasAnyForChain(int $walletLinkId, int $chainId): bool
    {
        if ($walletLinkId <= 0 || $chainId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1
               FROM {$table}
              WHERE wallet_link_id = %d
                AND chain_id = %d
              LIMIT 1",
            $walletLinkId,
            $chainId
        ));

        return $exists !== null;
    }

    // ── Admin / recovery reads (any status) ─────────────────────────

    /**
     * Admin-only — every status, no filter. Consumed by IndexerStatusPage
     * and CLI recovery tooling. Never call from a public read path.
     *
     * @return list<HoldingRow>
     */
    public static function findAllIncludingSpam(int $walletLinkId): array
    {
        if ($walletLinkId <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;
        $limit = self::ADMIN_READ_LIMIT;

        /** @var list<HoldingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE wallet_link_id = %d
              ORDER BY indexed_at DESC
              LIMIT %d",
            $walletLinkId,
            $limit
        ));
        return $rows ?: [];
    }

    /**
     * Admin-only — pull rows by status for a chain (spam-queue review).
     *
     * @return list<HoldingRow>
     */
    public static function findByStatus(int $chainId, int $status, int $limit = 100): array
    {
        if ($chainId <= 0 || $status < 0 || $status > 3) {
            return [];
        }

        $limit = max(1, min(self::ADMIN_READ_LIMIT, $limit));

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        /** @var list<HoldingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND metadata_status = %d
              ORDER BY indexed_at DESC
              LIMIT %d",
            $chainId,
            $status,
            $limit
        ));
        return $rows ?: [];
    }

    // ── Writes (NftHoldingsIndexer-only) ─────────────────────────────

    /**
     * Idempotent batch upsert keyed on (wallet_link_id, contract, token_id).
     * Returns the count of rows touched (insert or update). Bumps the
     * generation counter for every distinct wallet_link_id touched so
     * read-side caches invalidate on the next read.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function upsertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $touched      = 0;
        $touchedWallets = [];
        foreach ($rows as $r) {
            $walletLinkId = (int) ($r['wallet_link_id'] ?? 0);
            $chainId      = (int) ($r['chain_id'] ?? 0);
            $contract     = strtolower((string) ($r['contract_address'] ?? ''));
            $tokenId      = (string) ($r['token_id'] ?? '');
            $confirmedAt  = (string) ($r['confirmed_at'] ?? '');
            $lastSeenBlk  = (int) ($r['last_seen_block'] ?? 0);

            if ($walletLinkId <= 0 || $chainId <= 0 || $contract === '' || $tokenId === '' || $confirmedAt === '') {
                continue;
            }

            $tokenStd = isset($r['token_standard']) ? (string) $r['token_standard'] : null;
            $balance  = max(0, (int) ($r['balance'] ?? 1));
            $status   = isset($r['metadata_status']) ? (int) $r['metadata_status'] : self::STATUS_PENDING;
            if ($status < 0 || $status > 3) {
                $status = self::STATUS_PENDING;
            }

            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (wallet_link_id, chain_id, contract_address, token_id,
                     token_standard, balance, metadata_status, last_seen_block,
                     confirmed_at, indexed_at)
                 VALUES (%d, %d, %s, %s, %s, %d, %d, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    balance = VALUES(balance),
                    metadata_status = CASE
                        WHEN metadata_status = %d THEN VALUES(metadata_status)
                        ELSE metadata_status
                    END,
                    last_seen_block = GREATEST(last_seen_block, VALUES(last_seen_block)),
                    confirmed_at = GREATEST(confirmed_at, VALUES(confirmed_at)),
                    indexed_at = VALUES(indexed_at)",
                $walletLinkId,
                $chainId,
                $contract,
                $tokenId,
                $tokenStd ?? '',
                $balance,
                $status,
                $lastSeenBlk,
                $confirmedAt,
                $now,
                self::STATUS_PENDING
            ));

            if ($result !== false) {
                $touched++;
                $touchedWallets[$walletLinkId] = true;
            }
        }

        foreach (array_keys($touchedWallets) as $linkId) {
            self::bumpWalletGeneration((int) $linkId);
        }

        return $touched;
    }

    /**
     * Delete a holding row (called on transfer-out events). Idempotent —
     * returns true if a row was deleted, false if no match. Bumps the
     * generation counter on success.
     */
    public static function deleteByWalletAndToken(int $walletLinkId, string $contract, string $tokenId): bool
    {
        if ($walletLinkId <= 0 || $contract === '' || $tokenId === '') {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $deleted = $wpdb->delete(
            $table,
            [
                'wallet_link_id'   => $walletLinkId,
                'contract_address' => strtolower($contract),
                'token_id'         => $tokenId,
            ],
            ['%d', '%s', '%s']
        );

        $ok = is_int($deleted) && $deleted > 0;
        if ($ok) {
            self::bumpWalletGeneration($walletLinkId);
        }
        return $ok;
    }

    /**
     * Atomic batch ingest used by NftHoldingsIndexer. Wraps upsertMany +
     * per-row deletes in a single transaction so a mid-batch DB hiccup
     * cannot leave the chain checkpoint out of sync with row state.
     *
     * @param list<array<string, mixed>>                                $upserts
     * @param list<array{wallet_link_id: int, contract: string, token_id: string}> $deletes
     * @return array{inserts: int, deletes: int}
     * @throws \RuntimeException when the batch fails to commit
     */
    public static function ingestBatch(array $upserts, array $deletes): array
    {
        $result = ['inserts' => 0, 'deletes' => 0];
        if ($upserts === [] && $deletes === []) {
            return $result;
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            if ($upserts !== []) {
                $result['inserts'] = self::upsertMany($upserts);
            }
            foreach ($deletes as $d) {
                $linkId   = (int) ($d['wallet_link_id'] ?? 0);
                $contract = (string) ($d['contract'] ?? '');
                $tokenId  = (string) ($d['token_id'] ?? '');
                if ($linkId <= 0 || $contract === '' || $tokenId === '') {
                    continue;
                }
                if (self::deleteByWalletAndToken($linkId, $contract, $tokenId)) {
                    $result['deletes']++;
                }
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException('NftHoldingsRepository::ingestBatch failed: ' . $e->getMessage(), 0, $e);
        }

        return $result;
    }

    /**
     * Move a row to a specific metadata_status. Used by the spam filter
     * (downgrade pending → spam) and admin-recovery tooling (upgrade
     * spam → ok if a false positive is corrected). Bumps the generation
     * counter on the row's wallet so a status flip from pending → spam
     * invalidates any cached read.
     */
    public static function markStatus(int $holdingId, int $status): bool
    {
        if ($holdingId <= 0 || $status < 0 || $status > 3) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        // Look up the wallet first so we can invalidate even if the
        // update returns 0 rows-affected (status already at target).
        $walletLinkId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT wallet_link_id FROM {$table} WHERE id = %d LIMIT 1",
            $holdingId
        ));

        $updated = $wpdb->update(
            $table,
            ['metadata_status' => $status],
            ['id' => $holdingId],
            ['%d'],
            ['%d']
        );

        $ok = is_int($updated) && $updated >= 0;
        if ($ok && $walletLinkId > 0) {
            self::bumpWalletGeneration($walletLinkId);
        }
        return $ok;
    }

    // ── Enrichment writes (NftEnrichmentService-only) ────────────────

    /**
     * Pull rows that need metadata enrichment. Returns rows where
     * `enriched_at IS NULL` AND `metadata_status IN (0, 1)` so the
     * enrichment scheduler doesn't waste calls on already-spam-flagged
     * or already-failed rows.
     *
     * Ordered by indexed_at DESC so newer mints are enriched first
     * (better UX — the row likely to render imminently is prioritized).
     *
     * @return list<HoldingRow>
     */
    public static function findPendingEnrichment(int $chainId, int $limit = self::ENRICHMENT_BATCH_SIZE): array
    {
        if ($chainId <= 0) {
            return [];
        }
        $limit = max(1, min(self::ENRICHMENT_BATCH_SIZE * 4, $limit));

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        /** @var list<HoldingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
                AND enriched_at IS NULL
                AND metadata_status IN (%d, %d)
              ORDER BY indexed_at DESC
              LIMIT %d",
            $chainId,
            self::STATUS_PENDING,
            self::STATUS_OK,
            $limit
        ));
        return $rows ?: [];
    }

    /**
     * Apply enrichment values for a single row + flip status to OK
     * (per spam filter run-on-write rule, "OK" is the right end-state
     * for a row that wasn't spam-flagged AND has been enriched). Bumps
     * the generation counter on success.
     *
     * @param array<string, string|null> $fields  Subset of {name, image_url, metadata_uri, collection_name}
     */
    public static function applyEnrichment(int $holdingId, array $fields, bool $markFailed = false): bool
    {
        if ($holdingId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        // Look up wallet for cache invalidation BEFORE the update so
        // we still bump even when no fields actually changed
        // (e.g., a re-enrichment that produced the same metadata).
        $walletLinkId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT wallet_link_id FROM {$table} WHERE id = %d LIMIT 1",
            $holdingId
        ));

        $update = ['enriched_at' => $now];
        $format = ['%s'];
        if ($markFailed) {
            $update['metadata_status'] = self::STATUS_FAILED;
            $format[] = '%d';
        } else {
            // Only flip pending → ok; never overwrite an admin-curated
            // spam/failed status that was set after the indexer wrote.
            $currentStatus = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT metadata_status FROM {$table} WHERE id = %d LIMIT 1",
                $holdingId
            ));
            if ($currentStatus === self::STATUS_PENDING) {
                $update['metadata_status'] = self::STATUS_OK;
                $format[] = '%d';
            }
        }

        $allowed = ['name', 'image_url', 'metadata_uri', 'collection_name'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $update[$key] = $fields[$key] !== null ? mb_substr((string) $fields[$key], 0, 500) : null;
                $format[] = '%s';
            }
        }

        $updated = $wpdb->update(
            $table,
            $update,
            ['id' => $holdingId],
            $format,
            ['%d']
        );

        $ok = is_int($updated) && $updated >= 0;
        if ($ok && $walletLinkId > 0) {
            self::bumpWalletGeneration($walletLinkId);
        }
        return $ok;
    }

    // ── Cache invalidation (§5 generation counter) ────────────────────

    /**
     * Bump the per-wallet generation counter. Read-side consumers in
     * HoldingsService key their per-request transient on the
     * post-bump value, so a successful indexer or enrichment write is
     * visible to the next gallery render without a manual flush.
     */
    public static function bumpWalletGeneration(int $walletLinkId): void
    {
        if ($walletLinkId <= 0) {
            return;
        }
        $key = self::GENERATION_KEY_PREFIX . $walletLinkId;
        // wp_cache_incr seeds the value at 1 if missing on most object
        // cache backends, but the WP core fallback transient does not —
        // call wp_cache_add first defensively.
        if (wp_cache_get($key, self::CACHE_GROUP) === false) {
            wp_cache_add($key, 1, self::CACHE_GROUP);
            return;
        }
        wp_cache_incr($key, 1, self::CACHE_GROUP);
    }

    /**
     * Read the current per-wallet generation. Used as part of the
     * cache key on read-side consumers. Returns 0 if no writes have
     * happened yet (callers can treat 0 as "no generation seeded").
     */
    public static function getWalletGeneration(int $walletLinkId): int
    {
        if ($walletLinkId <= 0) {
            return 0;
        }
        $key = self::GENERATION_KEY_PREFIX . $walletLinkId;
        $value = wp_cache_get($key, self::CACHE_GROUP);
        return is_numeric($value) ? (int) $value : 0;
    }
}
