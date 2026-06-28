<?php
/**
 * Watch Meta Table Schema (bcc_watch_meta)
 *
 * Sidecar metadata for PeepSo follows that represent BCC card watches.
 * Per §C2 of the V1 plan: the watchlist is a UI projection of PeepSo
 * follows + this thin metadata table. There is NO separate follow graph
 * — watching a card is creating a peepso_follower row, and this table
 * only stores the BCC-specific extras PeepSo follows don't carry.
 *
 * Rows are 1:1 with peepso_follower rows. PK is follow_id (not an
 * autoincrement) so that an unfollow cascading on follow_id removes
 * exactly the matching meta row.
 *
 * Renamed from the legacy `bcc_pull_meta` / `pulled_at` / `tier_at_pull`
 * forms (pull → watch vocabulary unification). The data-preserving
 * rename runs in includes/database/rename-pull-to-watch.php.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1 (2026-04)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_watch_meta_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_watch_meta';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        follow_id BIGINT UNSIGNED NOT NULL,
        tier_at_watch VARCHAR(20) DEFAULT NULL,
        batch_id VARCHAR(64) DEFAULT NULL,
        visibility VARCHAR(20) NOT NULL DEFAULT 'public',
        watched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (follow_id),
        KEY idx_batch_id (batch_id),
        KEY idx_watched_at (watched_at),
        KEY idx_tier_at_watch (tier_at_watch)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Watch meta table installed', []);
}
