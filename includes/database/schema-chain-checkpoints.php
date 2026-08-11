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
 * ── CosmWasm discovery extension (2026-08) ─────────────────────────────
 * The `cw_*` columns are a NARROW extension for CW-721 discovery. They
 * carry OVERALL PER-CHAIN worker state and progress ONLY — never
 * per-code-family or per-contract state, which live in their own tables:
 *
 *   wp_bcc_chain_checkpoints          ← per-chain worker state + progress
 *   wp_bcc_cosmwasm_code_families     code-family inventory + classification
 *   wp_bcc_cosmwasm_contracts         contract inventory + classification
 *   wp_bcc_onchain_collections        canonical collection model (UNCHANGED)
 *   wp_bcc_onchain_nft_spam_contracts operator allow/deny (REUSED)
 *
 * The extension is deliberately here rather than in a fourth table: this
 * table already IS the shared "where is each chain-walking worker up to"
 * primitive, and a second per-chain progress table would be exactly the
 * parallel implementation §11 forbids.
 *
 *   - cw_discovery_state — idle | backfilling | backfilled | unsupported
 *     | paused. `unsupported` is durable and terminal for chains with no
 *     wasm module (cryptoorgchain answers the wasmd endpoints with HTTP
 *     501 — measured); it must never be retried by the routine sweeps.
 *     A chain whose LCD is merely DOWN (kujira, 502 — measured) stays in
 *     its current state and is retried.
 *   - cw_code_cursor — opaque `pagination.key` for the resumable
 *     historical walk of `/cosmwasm/wasm/v1/code`. Progress is a cursor
 *     plus a max-code-id watermark and NEVER a percentage: only ONE of
 *     the nine cosmos chains honours `pagination.count_total`
 *     (measured), so a reported total cannot be trusted.
 *   - cw_max_code_id — highest code id observed on this chain.
 *   - cw_backfill_completed_at — set once the historical walk drains.
 *   - cw_last_discovery_at — last incremental discovery pass.
 *   - cw_metadata_refreshed_at — durable ≥30-day elapsed guard for the
 *     "monthly" migration-check + mutable-metadata pass. wp-cron has no
 *     monthly interval, so the pass rides a DAILY hook and this column
 *     is what makes it monthly. No invented cron interval.
 *   - cw_last_error — sanitized ≤255-char excerpt, same convention as
 *     `last_error`. Raw LCD bodies are never stored.
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
        cw_discovery_state VARCHAR(20) NOT NULL DEFAULT 'idle',
        cw_code_cursor VARCHAR(255) DEFAULT NULL,
        cw_max_code_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        cw_backfill_completed_at DATETIME DEFAULT NULL,
        cw_last_discovery_at DATETIME DEFAULT NULL,
        cw_metadata_refreshed_at DATETIME DEFAULT NULL,
        cw_last_error VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (chain_id),
        KEY idx_cw_discovery (cw_discovery_state, cw_last_discovery_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
