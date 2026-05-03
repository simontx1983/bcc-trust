<?php
/**
 * Reputation Events Table Schema
 *
 * Per-user reputation-change log. One row written each time
 * ReputationCalculatorService::recalculateUserReputation actually
 * shifts a user's score (no row when the recalc is a no-op).
 *
 * Powers `progression.trust_score_recent_changes` on the user
 * view-model (§N11) — surfaces the last few score changes with
 * delta + reason so the user can see WHY their reputation moved.
 *
 * Distinct from bcc_trust_score_events, which logs PAGE score
 * events. This is the per-USER-reputation analogue.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1 (2026-04, §N11)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_reputation_events_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_reputation_events';
    $charset = $wpdb->get_charset_collate();

    // DECIMAL(5,2) → 0.00–999.99; covers our 0–100 score range with
    // headroom for any future float intermediaries.
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        score_before DECIMAL(5,2) NOT NULL,
        score_after DECIMAL(5,2) NOT NULL,
        delta DECIMAL(5,2) NOT NULL,
        reason VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_user_created (user_id, created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Reputation events table installed', []);
}
