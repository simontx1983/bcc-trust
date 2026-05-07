<?php
/**
 * NFT Spam Contracts Schema
 *
 * Allow/deny list for NFT contracts at the indexer write path. Spam
 * filtering happens BEFORE persistence — the persistent holdings
 * table represents what the user actually owns + cares about, not
 * raw chain truth.
 *
 * rule semantics:
 *   - 'deny'  — reject from indexer write path; row stored with
 *               metadata_status=2 for admin audit only
 *   - 'allow' — explicit override of any heuristic that would
 *               otherwise mark the contract as spam
 *
 * Curation is admin-driven for V2 Phase 1. A future heuristic-driven
 * suggestion queue is documented but out of scope.
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
function bcc_onchain_nft_spam_contracts_table(): string {
    return \BCC\Core\DB\DB::table('nft_spam_contracts');
}

/**
 * Create the NFT spam contracts table.
 */
function bcc_onchain_create_nft_spam_contracts_table(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_nft_spam_contracts_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        chain_id BIGINT UNSIGNED NOT NULL,
        contract_address VARCHAR(64) NOT NULL,
        rule VARCHAR(10) NOT NULL DEFAULT 'deny',
        reason VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_chain_contract (chain_id, contract_address),
        KEY idx_rule (rule)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
