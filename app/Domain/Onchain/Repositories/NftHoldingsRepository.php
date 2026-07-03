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
     * Distinct linked-wallet count per (chain, contract) across the
     * whole visible index — the demand signal behind the Verify
     * Collections queue: "N linked wallets hold this collection."
     *
     * Aggregate over the indexed chains only (EVM/SOL persistence);
     * Cosmos demand comes from CollectionDemandService's marketplace
     * rollups. Bounded by LIMIT — beyond 500 distinct contracts the
     * tail is noise for a curation queue (and the caller logs the cap).
     *
     * @return list<object{chain_id: string, contract_address: string, wallets: string}>
     */
    public static function countDistinctWalletsPerContract(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));

        global $wpdb;
        $table = self::table();

        /** @var list<object{chain_id: string, contract_address: string, wallets: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT chain_id, contract_address,
                    COUNT(DISTINCT wallet_link_id) AS wallets
               FROM {$table}
              WHERE metadata_status IN (%d, %d)
              GROUP BY chain_id, contract_address
              ORDER BY wallets DESC
              LIMIT %d",
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

    /**
     * Owners of a single (chain, contract, token_id) — used by the §4.17
     * NFT-piece detail endpoint to assemble `owner` + `owners[]` per
     * §3.7. Ordered by `balance DESC, wallet_address ASC` per the
     * dominant-owner tie-break rule. The first row is the dominant
     * `owner`; subsequent rows feed `owners[]`.
     *
     * Filtered to visible statuses (PENDING, OK) so a token with all
     * spam-flagged holders surfaces as zero-known-owners (correct: the
     * frontend will render `owner: null`).
     *
     * Bounded by $limit. Index path: `idx_chain_contract` filters to
     * the contract row set; `token_id` is a non-indexed filter on top
     * of that scan (acceptable — ERC-1155 contracts rarely exceed a
     * few thousand rows per contract, and ERC-721 yields one row).
     *
     * Joins `bcc_wallet_links` to surface `wallet_address` so callers
     * don't need a second round-trip per result.
     *
     * @return list<object{wallet_link_id: string, wallet_address: string, balance: string}>
     */
    public static function findOwnersByTokenId(
        int $chainId,
        string $contract,
        string $tokenId,
        int $limit
    ): array {
        if ($chainId <= 0 || $contract === '' || $tokenId === '') {
            return [];
        }

        $limit = max(1, min(self::ADMIN_READ_LIMIT, $limit));

        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        /** @var list<object{wallet_link_id: string, wallet_address: string, balance: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT h.wallet_link_id, w.wallet_address, h.balance
               FROM {$table} h
               JOIN {$wallets} w ON w.id = h.wallet_link_id
              WHERE h.chain_id = %d
                AND h.contract_address = %s
                AND h.token_id = %s
                AND h.metadata_status IN (%d, %d)
                AND h.balance > 0
              ORDER BY h.balance DESC, w.wallet_address ASC
              LIMIT %d",
            $chainId,
            strtolower($contract),
            $tokenId,
            self::STATUS_PENDING,
            self::STATUS_OK,
            $limit
        ));

        return $rows ?: [];
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

    /**
     * Admin-only — pull rows by status across multiple chains in one query.
     * Replaces N per-chain `findByStatus` calls in admin views.
     *
     * @param list<int> $chainIds
     * @return list<HoldingRow>
     */
    public static function findByStatusAcrossChains(array $chainIds, int $status, int $limit = 100): array
    {
        if ($chainIds === [] || $status < 0 || $status > 3) {
            return [];
        }

        $clean = array_values(array_filter(array_map('intval', $chainIds), static fn ($id) => $id > 0));
        if ($clean === []) {
            return [];
        }

        $limit = max(1, min(self::ADMIN_READ_LIMIT, $limit));

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;
        $placeholders = implode(',', array_fill(0, count($clean), '%d'));

        /** @var list<HoldingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id IN ({$placeholders})
                AND metadata_status = %d
              ORDER BY indexed_at DESC
              LIMIT %d",
            array_merge($clean, [$status, $limit])
        ));
        return $rows ?: [];
    }

    // ── Writes (NftHoldingsIndexer-only) ─────────────────────────────

    /**
     * Idempotent batch upsert keyed on (wallet_link_id, contract, token_id).
     * Returns the count of input rows that survived validation. Caller
     * (ingestBatch) is responsible for bumping per-wallet generation
     * counters AFTER its transaction commits — bumping inside this
     * method would double-bump when an upsert + delete touch the same
     * wallet, and would advance the counter even when a downstream
     * deletion rolled the whole batch back.
     *
     * Issues one batched multi-VALUES INSERT per chunk of CHUNK_SIZE
     * rows. The legacy per-row INSERT on Alchemy's 1000-transfer page
     * was the worst write hot-path in the indexer.
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

        $tuples = [];
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

            $tokenStd = isset($r['token_standard']) ? (string) $r['token_standard'] : '';
            $balance  = max(0, (int) ($r['balance'] ?? 1));
            $status   = isset($r['metadata_status']) ? (int) $r['metadata_status'] : self::STATUS_PENDING;
            if ($status < 0 || $status > 3) {
                $status = self::STATUS_PENDING;
            }

            $tuples[] = [
                $walletLinkId, $chainId, $contract, $tokenId,
                $tokenStd, $balance, $status, $lastSeenBlk,
                $confirmedAt, $now,
            ];
        }

        if ($tuples === []) {
            return 0;
        }

        // 500 rows × 10 placeholders = 5000 args per prepared statement.
        // Well under MySQL's max_allowed_packet (default 1MB) and within
        // wpdb::prepare's args ceiling.
        $touched = 0;
        foreach (array_chunk($tuples, 500) as $chunk) {
            $placeholders = [];
            $args = [];
            foreach ($chunk as $t) {
                $placeholders[] = '(%d, %d, %s, %s, %s, %d, %d, %d, %s, %s)';
                array_push($args, ...$t);
            }
            $args[] = self::STATUS_PENDING;

            $sql = "INSERT INTO {$table}
                    (wallet_link_id, chain_id, contract_address, token_id,
                     token_standard, balance, metadata_status, last_seen_block,
                     confirmed_at, indexed_at)
                 VALUES " . implode(', ', $placeholders) . "
                 ON DUPLICATE KEY UPDATE
                    balance = VALUES(balance),
                    metadata_status = CASE
                        WHEN metadata_status = %d THEN VALUES(metadata_status)
                        ELSE metadata_status
                    END,
                    last_seen_block = GREATEST(last_seen_block, VALUES(last_seen_block)),
                    confirmed_at = GREATEST(confirmed_at, VALUES(confirmed_at)),
                    indexed_at = VALUES(indexed_at)";

            $result = $wpdb->query($wpdb->prepare($sql, ...$args));
            if ($result !== false) {
                $touched += count($chunk);
            }
        }

        return $touched;
    }

    /**
     * Delete a holding row (called on transfer-out events). Idempotent —
     * returns true if a row was deleted, false if no match. Caller
     * (ingestBatch) is responsible for bumping the generation counter
     * AFTER its transaction commits; see upsertMany for why.
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

        return is_int($deleted) && $deleted > 0;
    }

    /**
     * Delete all holdings for every wallet linked to a user (full
     * account cleanup). nft_holdings has no user_id column of its own,
     * so ownership is resolved via a join to wallet_links — this MUST
     * run BEFORE WalletRepository::deleteForUser, or the join finds
     * nothing and holdings orphan.
     */
    public static function deleteForUser(int $userId): void
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        $wpdb->query($wpdb->prepare(
            "DELETE h FROM {$table} h
             INNER JOIN {$wallets} w ON w.id = h.wallet_link_id
             WHERE w.user_id = %d",
            $userId
        ));
    }

    /**
     * Delete every holding for a single wallet link. Called on wallet
     * unlink (WalletController::rest_unlink_wallet) so a disconnected
     * wallet's NFTs don't linger in the gallery / gating reads, and so a
     * later re-link starts from a clean slate. Bumps the wallet generation
     * so any cached read invalidates on the next request.
     *
     * Returns the number of rows removed.
     */
    public static function deleteForWalletLink(int $walletLinkId): int
    {
        if ($walletLinkId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::table();

        $deleted = $wpdb->delete($table, ['wallet_link_id' => $walletLinkId], ['%d']);
        $count   = is_int($deleted) ? $deleted : 0;

        if ($count > 0) {
            self::bumpWalletGeneration($walletLinkId);
        }
        return $count;
    }

    /**
     * Atomic batch ingest used by NftHoldingsIndexer. Wraps upsertMany +
     * per-row deletes in a single transaction so a mid-batch DB hiccup
     * cannot leave the chain checkpoint out of sync with row state.
     *
     * Generation-counter bumps fire ONCE per distinct wallet, AFTER
     * COMMIT. A rolled-back batch leaves the counter untouched, and an
     * upsert+delete on the same wallet only invalidates once.
     *
     * @param list<array<string, mixed>>                                $upserts
     * @param list<array{wallet_link_id: int, contract: string, token_id: string}> $deletes
     * @param list<array<string, mixed>>                                $deltas   ERC-1155 signed balance deltas.
     * @return array{inserts: int, deletes: int}
     * @throws \RuntimeException when the batch fails to commit
     */
    public static function ingestBatch(array $upserts, array $deletes, array $deltas = []): array
    {
        $result = ['inserts' => 0, 'deletes' => 0];
        if ($upserts === [] && $deletes === [] && $deltas === []) {
            return $result;
        }

        global $wpdb;
        $touchedWallets = [];

        $wpdb->query('START TRANSACTION');
        try {
            if ($upserts !== []) {
                $result['inserts'] = self::upsertMany($upserts);
                foreach ($upserts as $r) {
                    $linkId = (int) ($r['wallet_link_id'] ?? 0);
                    if ($linkId > 0) {
                        $touchedWallets[$linkId] = true;
                    }
                }
            }
            if ($deltas !== []) {
                $applied = self::applyDeltas($deltas);
                $result['inserts'] += $applied['upserts'];
                $result['deletes'] += $applied['deletes'];
                foreach ($applied['touched'] as $linkId) {
                    $touchedWallets[$linkId] = true;
                }
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
                    $touchedWallets[$linkId] = true;
                }
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException('NftHoldingsRepository::ingestBatch failed: ' . $e->getMessage(), 0, $e);
        }

        foreach (array_keys($touchedWallets) as $linkId) {
            self::bumpWalletGeneration((int) $linkId);
        }

        return $result;
    }

    /**
     * Apply signed ERC-1155 balance deltas within the caller's transaction.
     *
     * Each row does a block-guarded upsert that adds the signed delta to the
     * existing balance, clamped at zero, then deletes the row if the balance
     * reached zero. The `balance` column is INT UNSIGNED, so the arithmetic
     * is cast to SIGNED before adding a negative delta to avoid unsigned
     * underflow wrapping to a huge value before GREATEST clamps it.
     *
     * IDEMPOTENCY: the balance is only adjusted when the incoming block is
     * strictly newer than the row's stored `last_seen_block`. A batch's delta
     * row carries the MAX block among the events it folded, so re-processing
     * the same block range (the worker retries a range on failure, and the
     * 12-confirmation safe head keeps ranges non-overlapping) is a no-op.
     * A brand-new key seeds with GREATEST(0, delta); a negative-only net
     * (forward-history gap: we never saw the acquiring IN) seeds 0 and is
     * then cleaned up.
     *
     * @param list<array<string, mixed>> $deltas
     * @return array{upserts: int, deletes: int, touched: list<int>}
     */
    private static function applyDeltas(array $deltas): array
    {
        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $upserts = 0;
        $deletes = 0;
        $touched = [];

        foreach ($deltas as $d) {
            $walletLinkId = (int) ($d['wallet_link_id'] ?? 0);
            $chainId      = (int) ($d['chain_id'] ?? 0);
            $contract     = strtolower((string) ($d['contract_address'] ?? ''));
            $tokenId      = (string) ($d['token_id'] ?? '');
            $confirmedAt  = (string) ($d['confirmed_at'] ?? '');
            $block        = (int) ($d['last_seen_block'] ?? 0);
            $delta        = (int) ($d['delta'] ?? 0);

            if ($walletLinkId <= 0 || $chainId <= 0 || $contract === '' || $tokenId === '' || $confirmedAt === '' || $delta === 0) {
                continue;
            }

            $tokenStd = isset($d['token_standard']) ? (string) $d['token_standard'] : '';
            $status   = isset($d['metadata_status']) ? (int) $d['metadata_status'] : self::STATUS_PENDING;
            if ($status < 0 || $status > 3) {
                $status = self::STATUS_PENDING;
            }

            // New-row seed: a positive delta becomes the starting balance; a
            // negative-only net clamps to 0 (then the cleanup below removes it).
            $seedBalance = max(0, $delta);

            $sql = "INSERT INTO {$table}
                    (wallet_link_id, chain_id, contract_address, token_id,
                     token_standard, balance, metadata_status, last_seen_block,
                     confirmed_at, indexed_at)
                 VALUES (%d, %d, %s, %s, %s, %d, %d, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    balance = IF(
                        VALUES(last_seen_block) > last_seen_block,
                        GREATEST(0, CAST(balance AS SIGNED) + %d),
                        balance
                    ),
                    metadata_status = CASE
                        WHEN metadata_status = %d THEN VALUES(metadata_status)
                        ELSE metadata_status
                    END,
                    last_seen_block = GREATEST(last_seen_block, VALUES(last_seen_block)),
                    confirmed_at = GREATEST(confirmed_at, VALUES(confirmed_at)),
                    indexed_at = VALUES(indexed_at)";

            $applied = $wpdb->query($wpdb->prepare(
                $sql,
                $walletLinkId, $chainId, $contract, $tokenId,
                $tokenStd, $seedBalance, $status, $block,
                $confirmedAt, $now,
                $delta, self::STATUS_PENDING
            ));
            // Fail loud, not silent: a failed balance write must roll the batch
            // back (ingestBatch's try/catch) rather than leave the checkpoint
            // out of sync with a half-applied row set.
            if ($applied === false) {
                throw new \RuntimeException('applyDeltas balance upsert failed: ' . (string) $wpdb->last_error);
            }

            // Zero-cleanup: a holding decremented to zero (or a negative-only
            // seed) leaves no row, matching the 721 delete semantics and
            // keeping SUM(balance) gates + the gallery clean. Idempotent.
            $removed = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table}
                  WHERE wallet_link_id = %d AND contract_address = %s AND token_id = %s AND balance <= 0",
                $walletLinkId, $contract, $tokenId
            ));

            if (is_int($removed) && $removed > 0) {
                $deletes++;
            } else {
                $upserts++;
            }
            $touched[$walletLinkId] = true;
        }

        return [
            'upserts' => $upserts,
            'deletes' => $deletes,
            'touched' => array_map('intval', array_keys($touched)),
        ];
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

        // Resolve wallet first so we can invalidate even when the update
        // is a no-op (status already at target).
        $walletLinkId = self::getWalletLinkIdForHolding($holdingId);

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

        // One SELECT for both wallet (cache invalidation) and current
        // status (to gate the pending → ok flip). Three SELECTs were
        // previously issued per-row, multiplied by BATCH_SIZE × chains
        // every enrichment tick.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT wallet_link_id, metadata_status FROM {$table} WHERE id = %d LIMIT 1",
            $holdingId
        ));
        $walletLinkId  = $row !== null ? (int) $row->wallet_link_id : 0;
        $currentStatus = $row !== null ? (int) $row->metadata_status : -1;

        $update = ['enriched_at' => $now];
        $format = ['%s'];
        if ($markFailed) {
            $update['metadata_status'] = self::STATUS_FAILED;
            $format[] = '%d';
        } elseif ($currentStatus === self::STATUS_PENDING) {
            // Only flip pending → ok; never overwrite an admin-curated
            // spam/failed status that was set after the indexer wrote.
            $update['metadata_status'] = self::STATUS_OK;
            $format[] = '%d';
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

    /**
     * Resolve the wallet_link_id for a holding row by primary key.
     * Returns 0 when the row does not exist.
     */
    private static function getWalletLinkIdForHolding(int $holdingId): int
    {
        if ($holdingId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT wallet_link_id FROM {$table} WHERE id = %d LIMIT 1",
            $holdingId
        ));
    }
}
