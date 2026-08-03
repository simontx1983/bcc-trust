<?php
/**
 * Rank Pending Table Schema — the 24-hour Apprentice confirmation
 * clock (Rank redesign Phase 5; approved plan R1 — NO HELD state).
 *
 * One row per New Member whose first qualifying contribution started
 * the §5.2 24-hour confirmation. The 5-minute sweep awards Apprentice
 * at due_at iff the six R1 conditions hold; pending reports and
 * report-volume auto-hide have ZERO effect on this clock — they never
 * pause, reset, or extend it. States: pending → confirmed | voided.
 *
 * `content_act_id` is stored alongside the wp_posts id because content
 * reports key feed items by activity id — the R1 predicate's
 * report-resolution check needs it.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 5 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_rank_pending_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_rank_pending';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        user_id BIGINT UNSIGNED NOT NULL,
        source_type VARCHAR(30) NOT NULL,
        source_id BIGINT UNSIGNED NOT NULL,
        content_act_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        due_at DATETIME NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        voided_reason VARCHAR(120) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME DEFAULT NULL,
        PRIMARY KEY (user_id),
        KEY idx_due (status, due_at)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Rank pending table installed', []);
}
