<?php
/**
 * On-Chain Claims Schema
 *
 * Tracks user claims to on-chain entities (validators, collections).
 * A claim links a verified wallet to a specific entity with a role
 * (operator, creator, holder) validated by on-chain RPC queries.
 *
 * @package BCC_Onchain_Signals
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_claims_table(): string {
    return \BCC\Core\DB\DB::table('onchain_claims');
}

function bcc_onchain_create_claims_table(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_claims_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        entity_type VARCHAR(20) NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL,
        wallet_address VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        claim_role VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        verified_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_entity (user_id, entity_type, entity_id),
        KEY idx_entity (entity_type, entity_id),
        KEY idx_user (user_id),
        KEY idx_status (status)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
