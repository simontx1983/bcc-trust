<?php
/**
 * On-Chain Delegations Schema
 *
 * Stores per-user delegation sets — which validators a connected wallet
 * stakes to. Populated by chain fetchers that implement
 * `supports_feature('delegations')` (currently Cosmos).
 *
 * Refresh strategy is replace-per-wallet (delete-then-insert in one
 * transaction), because a user can undelegate at any time and a pure
 * upsert would leak removed rows.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_delegations_table(): string {
    return \BCC\Core\DB\DB::table('onchain_delegations');
}

/**
 * Create the on-chain delegations table.
 */
function bcc_onchain_create_delegations_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_delegations_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wallet_link_id BIGINT UNSIGNED NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        validator_address VARCHAR(128) NOT NULL,
        shares DECIMAL(40,18) DEFAULT NULL,
        amount DECIMAL(30,8) DEFAULT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wallet_validator (wallet_link_id, validator_address),
        KEY chain_validator (chain_id, validator_address),
        KEY wallet_link_id (wallet_link_id),
        KEY expires_at (expires_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
