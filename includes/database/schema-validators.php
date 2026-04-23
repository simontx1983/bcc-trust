<?php
/**
 * On-Chain Validators Schema
 *
 * Stores auto-fetched validator data from Cosmos LCD / beacon chain APIs.
 * Read-only from the user's perspective — updated by cron and fetchers only.
 *
 * @package BCC_Onchain_Signals
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_validators_table(): string {
    return \BCC\Core\DB\DB::table('onchain_validators');
}

/**
 * Create the on-chain validators table.
 */
function bcc_onchain_create_validators_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_validators_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wallet_link_id BIGINT UNSIGNED DEFAULT NULL,
        operator_address VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        moniker VARCHAR(200) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'unknown',
        commission_rate DECIMAL(5,2) DEFAULT NULL,
        total_stake DECIMAL(30,8) DEFAULT NULL,
        self_stake DECIMAL(30,8) DEFAULT NULL,
        delegator_count INT UNSIGNED DEFAULT NULL,
        uptime_30d DECIMAL(5,2) DEFAULT NULL,
        jailed_count INT UNSIGNED DEFAULT 0,
        voting_power_rank INT UNSIGNED DEFAULT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        last_enriched_at DATETIME DEFAULT NULL,
        next_enrichment_at DATETIME DEFAULT NULL,
        retry_after DATETIME DEFAULT NULL,
        enrichment_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY wallet_link_id (wallet_link_id),
        KEY chain_id (chain_id),
        UNIQUE KEY uq_chain_operator (chain_id, operator_address),
        KEY operator_address (operator_address),
        KEY expires_at (expires_at),
        KEY status (status),
        KEY voting_power_rank (voting_power_rank),
        KEY idx_enrichment_schedule (next_enrichment_at, retry_after)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

