<?php
/**
 * Pull Meta Table Schema
 *
 * Sidecar metadata for PeepSo follows that represent BCC card pulls.
 * Per §C2 of the V1 plan: the binder is a UI projection of PeepSo
 * follows + this thin metadata table. There is NO separate follow
 * graph — pulling a card is creating a peepso_follower row, and this
 * table only stores the BCC-specific extras PeepSo follows don't carry.
 *
 * Rows are 1:1 with peepso_follower rows. PK is follow_id (not an
 * autoincrement) so that an unfollow cascading on follow_id removes
 * exactly the matching meta row.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1 (2026-04)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_pull_meta_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_pull_meta';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        follow_id BIGINT UNSIGNED NOT NULL,
        tier_at_pull VARCHAR(20) DEFAULT NULL,
        batch_id VARCHAR(64) DEFAULT NULL,
        visibility VARCHAR(20) NOT NULL DEFAULT 'public',
        pulled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (follow_id),
        KEY idx_batch_id (batch_id),
        KEY idx_pulled_at (pulled_at),
        KEY idx_tier_at_pull (tier_at_pull)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Pull meta table installed', []);
}
