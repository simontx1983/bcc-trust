<?php
/**
 * Chain Checkpoints Schema
 *
 * Per-chain progress tracking for any chain-walking worker. The
 * NftEthIndexerWorker (V2 Phase 1a) is the first consumer; the
 * table is intentionally a shared primitive — future workers
 * (holders-table reconciler, signal-event ingester) extend it
 * with new state columns rather than create parallel tables.
 *
 * Per-row state:
 *   - last_processed_block / head_block — checkpoint advance
 *   - state — healthy | degraded | breaker_open | disabled
 *   - cu_used_today / cu_budget_reset_at — daily Alchemy CU budget
 *     (per spike 1: alchemy_getAssetTransfers = 120 CU flat). Worker
 *     decrements per call; circuit-breaks at BCC_ETH_DAILY_RPC_BUDGET
 *     (default 50 000 CU/day).
 *   - last_run_at / last_error — operator-facing diagnostics
 *   - block_progression_history — bounded JSON array (max 5 entries,
 *     oldest first) of `{block, head, at}` snapshots written on every
 *     successful tick. First-class operational state, NOT a UI nicety:
 *     enables detection of "worker alive but no progression," monotonic
 *     lag drift, and backward progression (checkpoint regression — a
 *     correctness anomaly that should never occur outside the
 *     N=CONFIRMATIONS reorg window). Bounded write amplification: one
 *     ~50-byte append per successful tick, capped at 5 entries by the
 *     repository before write.
 *
 * lag_blocks is a virtual GENERATED column so the operator dashboard
 * doesn't have to compute (head - last_processed) on every read.
 *
 * ── CosmWasm discovery extension: REMOVED (S9b, 2026-10) ───────────────
 * This table carried seven `cw_*` columns and one index over two of them,
 * holding overall per-chain CW-721 discovery progress: a durable
 * discovery state, the resumable `pagination.key` cursor for the walk of
 * `/cosmwasm/wasm/v1/code`, a max-code-id watermark, three timestamps and
 * a sanitized error excerpt. Two sibling tables held the per-code-family
 * and per-contract state.
 *
 * S8 deleted every reader and writer; S9a removed the columns from
 * `ChainCheckpointRepository::COLUMNS`; S9b dropped the columns, the index
 * and both sibling tables. The drop is
 * `includes/database/drop-scanner-schema.php`.
 *
 * ⚠ THE INDEX MATTERED. `KEY idx_cw_discovery (cw_discovery_state,
 * cw_last_discovery_at)` spanned two of the retired columns, so this
 * `CREATE TABLE` could not simply lose the column lines — stripping them
 * while keeping the index is invalid DDL (`ERROR 1072: Key column
 * 'cw_discovery_state' doesn't exist in table`). The migration drops the
 * index explicitly and first, for the same reason.
 *
 * What this table still is, unchanged: the shared "where is each
 * chain-walking worker up to" primitive, read by the EVM indexer, the
 * circuit breaker and the daily CU budget. A second per-chain progress
 * table would be exactly the parallel implementation §11 forbids, which is
 * why the discovery extension lived here rather than in a fourth table.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 * @since V2 Phase 1a
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table name helper.
 */
function bcc_onchain_chain_checkpoints_table(): string {
    return \BCC\Core\DB\DB::table('chain_checkpoints');
}

/**
 * Create the chain checkpoints table.
 */
function bcc_onchain_create_chain_checkpoints_table(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_chain_checkpoints_table();

    // Note: GENERATED VIRTUAL columns are MySQL 5.7+ / MariaDB 10.2+.
    // dbDelta does not handle GENERATED clauses well across upgrade
    // paths, so lag_blocks is computed in SQL queries by the
    // ChainCheckpointRepository instead of stored. Trade-off accepted:
    // ~1 µs per row vs migration fragility.
    $sql = "CREATE TABLE {$table} (
        chain_id BIGINT UNSIGNED NOT NULL,
        last_processed_block BIGINT UNSIGNED NOT NULL DEFAULT 0,
        head_block BIGINT UNSIGNED NOT NULL DEFAULT 0,
        state VARCHAR(20) NOT NULL DEFAULT 'disabled',
        cu_used_today INT UNSIGNED NOT NULL DEFAULT 0,
        cu_budget_reset_at DATE NOT NULL DEFAULT '1970-01-01',
        last_run_at DATETIME DEFAULT NULL,
        last_error VARCHAR(255) DEFAULT NULL,
        block_progression_history VARCHAR(500) DEFAULT NULL,
        PRIMARY KEY (chain_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
