<?php
/**
 * Wallet Links Schema
 *
 * Links wallet addresses to users and their project pages.
 * Supports multiple wallets per user with typed roles
 * (user, treasury, multisig, validator, contract_deployer).
 *
 * @package BCC_Onchain_Signals
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table name helper.
 */
function bcc_onchain_wallet_links_table(): string {
    return \BCC\Core\DB\DB::table('wallet_links');
}

/**
 * Create the wallet links table.
 */
function bcc_onchain_create_wallet_links_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_wallet_links_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL,
        wallet_address VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        wallet_type VARCHAR(20) NOT NULL DEFAULT 'user',
        label VARCHAR(100) DEFAULT NULL,
        verified_at DATETIME DEFAULT NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY post_id (post_id),
        KEY chain_id (chain_id),
        KEY wallet_address (wallet_address),
        KEY wallet_type (wallet_type),
        UNIQUE KEY user_chain_wallet (user_id, chain_id, wallet_address),
        UNIQUE KEY uq_chain_address (chain_id, wallet_address)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

