<?php
/**
 * Stoke Table Schema
 *
 * One row per (act_id, user_id). `stoke_count` accumulates up to
 * BCC_STOKE_CAP_PER_USER (server-enforced in StokeRepository's upsert,
 * not by a column constraint) — this is why Stoke needs its own table
 * rather than reusing PeepSo's peepso_reactions: that table's unique
 * (reaction_act_id, reaction_user_id) key models "one reaction, set/
 * replace", not "tap N times, accumulate, cap." Cosmetic for trust —
 * never written to bcc_trust_scores.
 *
 * @package BCC\Trust\Core
 */
if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_stokes_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_stokes';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        act_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        stoke_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_stoked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_act_user (act_id, user_id),
        KEY idx_act_id (act_id),
        KEY idx_last_stoked_at (last_stoked_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Stokes table installed', []);
}
