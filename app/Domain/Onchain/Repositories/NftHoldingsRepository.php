<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent NFT-holdings repository (V2 Phase 1a).
 *
 * Owns all DB access for `wp_bcc_nft_holdings`. The public API is
 * STRUCTURALLY split — visible reads cannot accidentally surface
 * spam/failed rows because the methods that return them don't exist
 * on the visible-read API.
 *
 * Visible reads (gallery, gating, discovery):
 *   - findVisibleForWallet
 *   - countVisibleByContract
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
 * @phpstan-type HoldingRow object{
 *     id: string,
 *     wallet_link_id: string,
 *     chain_id: string,
 *     contract_address: string,
 *     token_id: string,
 *     token_standard: string|null,
 *     balance: string,
 *     metadata_status: string,
 *     last_seen_block: string,
 *     confirmed_at: string,
 *     indexed_at: string
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
 */
final class NftHoldingsRepository
{
    public const STATUS_PENDING = 0;
    public const STATUS_OK      = 1;
    public const STATUS_SPAM    = 2;
    public const STATUS_FAILED  = 3;

    /** Columns returned by every read — no SELECT *. */
    private const COLUMNS = 'id, wallet_link_id, chain_id, contract_address, token_id, token_standard, balance, metadata_status, last_seen_block, confirmed_at, indexed_at';

    /** Hard cap so a single wallet's gallery query can't flood memory. */
    private const VISIBLE_READ_LIMIT = 5000;

    /** Hard cap on admin-audit pulls. */
    private const ADMIN_READ_LIMIT = 1000;

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
     * Returns the count of rows touched (insert or update).
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

        $touched = 0;
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
            }
        }

        return $touched;
    }

    /**
     * Delete a holding row (called on transfer-out events). Idempotent —
     * returns true if a row was deleted, false if no match.
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
     * spam → ok if a false positive is corrected).
     */
    public static function markStatus(int $holdingId, int $status): bool
    {
        if ($holdingId <= 0 || $status < 0 || $status > 3) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            ['metadata_status' => $status],
            ['id' => $holdingId],
            ['%d'],
            ['%d']
        );

        return is_int($updated) && $updated >= 0;
    }
}
