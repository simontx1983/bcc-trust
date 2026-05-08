<?php
/**
 * NFT Collection Pieces Schema (V2 Phase 6 / §H1)
 *
 * Per-(chain_id, contract_address, token_id) cached metadata superset
 * — name, description, image_url, image_url_thumb, attributes_json.
 * One row per uniquely-identified NFT piece. Distinct from
 * `bcc_onchain_nft_holdings` (which keys on wallet × token and is the
 * ownership ledger) — this table is the per-token METADATA cache,
 * shared across all holders of a given token.
 *
 * Lifecycle:
 *   - Populated on first sight by NftPieceViewModelBuilder when the
 *     §4.17 endpoint is hit for an indexed chain (EVM / Solana).
 *   - Refreshed on read via the SWR helper when expires_at < NOW().
 *   - Cosmos chains do NOT populate this table — read-time only per
 *     pattern-registry V2 Phase 2 asymmetry.
 *
 * Identity-vs-market discipline (mirrors CollectionRepository::applyEnrichment):
 *   - `name`, `image_url`, `image_url_thumb` — preserve-on-write via
 *     COALESCE so a partial Alchemy/Helius response can never wipe a
 *     previously-good identity column.
 *   - `description`, `attributes_json` — overwrite when non-null
 *     (these legitimately move when creators update on-chain metadata
 *     post-mint).
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 * @since V2 Phase 6 (§H1)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table name helper.
 */
function bcc_onchain_collection_pieces_table(): string {
    return \BCC\Core\DB\DB::table('onchain_collection_pieces');
}

/**
 * Create the collection-pieces table.
 */
function bcc_onchain_create_collection_pieces_table(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_collection_pieces_table();

    // token_id index uses (191) prefix — MySQL utf8mb4 InnoDB unique-key
    // limit is 767 bytes; chain_id (8) + contract (128 chars × 4 bytes)
    // already consumes most of the budget, so token_id is prefix-indexed.
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        chain_id BIGINT UNSIGNED NOT NULL,
        contract_address VARCHAR(128) NOT NULL,
        token_id VARCHAR(255) NOT NULL,
        name VARCHAR(255) DEFAULT NULL,
        description TEXT DEFAULT NULL,
        image_url TEXT DEFAULT NULL,
        image_url_thumb TEXT DEFAULT NULL,
        attributes_json LONGTEXT DEFAULT NULL,
        fetched_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_chain_contract_token (chain_id, contract_address, token_id(191)),
        KEY idx_chain_expires (chain_id, expires_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
