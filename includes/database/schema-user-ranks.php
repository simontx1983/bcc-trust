<?php
/**
 * User Ranks Table Schema
 *
 * BCC rank assignments per §E2 of the V1 plan. Apprentice and
 * Journeyman are auto-assigned by reputation tier + activity
 * thresholds; Foreman+ are admin-conferred (intentionally scarce).
 *
 * Revocation rule (§E2): on revoke, the user drops to their
 * auto-derived rank, NOT to flat Apprentice. Historical rows are
 * preserved (current rank = WHERE revoked_at IS NULL). The single-
 * active-rank-per-user invariant is enforced at the application
 * layer, not by a DB-level partial index (MySQL does not support
 * those).
 *
 * Note: column is rank_key, not rank, because RANK is a reserved
 * word in MySQL 8.0+ (window functions).
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1 (2026-04)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_user_ranks_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_user_ranks';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        rank_key VARCHAR(32) NOT NULL,
        awarded_by BIGINT UNSIGNED DEFAULT NULL,
        awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME DEFAULT NULL,
        revoke_reason VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_user_active (user_id, revoked_at),
        KEY idx_rank_key (rank_key),
        KEY idx_awarded_by (awarded_by)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] User ranks table installed', []);
}
