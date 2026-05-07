<?php
/**
 * NFT Holdings Schema
 *
 * Persistent per-(wallet, token) ownership rows fed by the
 * confirmation-gated NftHoldingsIndexer (V2 Phase 1a). One row per
 * (wallet_link_id, contract_address, token_id) tuple. Replaces the
 * 24-hour transient-only model used in V1 — the transient stays as
 * read-path fallback when checkpoint state is degraded or a wallet
 * has not yet been backfilled.
 *
 * metadata_status semantics (load-bearing — see plan §"Read-path"):
 *   0 = pending  — row exists, metadata enrichment not yet attempted
 *   1 = ok       — visible to user-facing surfaces
 *   2 = spam     — filtered by NftSpamFilter; admin-review only
 *   3 = failed   — enrichment errored permanently; admin-review only
 *
 * NftHoldingsRepository is the only reader/writer; user-facing reads
 * filter `metadata_status IN (0, 1)` structurally via separate methods
 * (findVisibleForWallet vs findAllIncludingSpam) so future callers
 * cannot accidentally surface spam.
 *
 * V2 Phase 1c added the enrichment columns (`name`, `image_url`,
 * `metadata_uri`, `collection_name`, `enriched_at`). Indexer writes
 * leave them NULL on insert; NftEnrichmentService backfills them
 * asynchronously. The read-path swap inside HoldingsService gates
 * on `enriched_at IS NOT NULL` so a freshly-indexed (un-enriched)
 * row falls through to the V1 transient-with-Alchemy-metadata path
 * — no thumbnail-less gallery regression.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 * @since V2 Phase 1a (1c added enrichment columns)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table name helper.
 */
function bcc_onchain_nft_holdings_table(): string {
    return \BCC\Core\DB\DB::table('nft_holdings');
}

/**
 * Create the NFT holdings table.
 */
function bcc_onchain_create_nft_holdings_table(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_nft_holdings_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wallet_link_id BIGINT UNSIGNED NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        contract_address VARCHAR(64) NOT NULL,
        token_id VARCHAR(128) NOT NULL,
        token_standard VARCHAR(16) DEFAULT NULL,
        balance INT UNSIGNED NOT NULL DEFAULT 1,
        metadata_status TINYINT UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(255) DEFAULT NULL,
        image_url VARCHAR(500) DEFAULT NULL,
        metadata_uri VARCHAR(500) DEFAULT NULL,
        collection_name VARCHAR(255) DEFAULT NULL,
        last_seen_block BIGINT UNSIGNED NOT NULL,
        confirmed_at DATETIME NOT NULL,
        indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        enriched_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_wallet_token (wallet_link_id, contract_address, token_id),
        KEY idx_wallet_chain (wallet_link_id, chain_id),
        KEY idx_chain_contract (chain_id, contract_address),
        KEY idx_metadata_status (metadata_status),
        KEY idx_enriched_at (enriched_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
