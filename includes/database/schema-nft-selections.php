<?php
/**
 * User NFT Selections Schema
 *
 * Stores user-curated NFT picks for profile display. Populated via the
 * post-wallet-connect picker modal. Metadata (name, image_url, etc.)
 * is snapshotted at selection time so profile renders don't need RPC.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_user_nft_selections_table(): string {
    return \BCC\Core\DB\DB::table('user_nft_selections');
}

function bcc_onchain_create_user_nft_selections_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_user_nft_selections_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        wallet_link_id BIGINT UNSIGNED NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        contract_address VARCHAR(128) NOT NULL,
        token_id VARCHAR(128) NOT NULL,
        collection_name VARCHAR(200) DEFAULT NULL,
        name VARCHAR(200) DEFAULT NULL,
        image_url VARCHAR(500) DEFAULT NULL,
        metadata_uri VARCHAR(500) DEFAULT NULL,
        token_standard VARCHAR(30) DEFAULT NULL,
        display_order INT UNSIGNED NOT NULL DEFAULT 0,
        added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_token (user_id, chain_id, contract_address, token_id),
        KEY user_id (user_id),
        KEY wallet_link_id (wallet_link_id),
        KEY chain_id (chain_id),
        KEY user_order (user_id, display_order)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
