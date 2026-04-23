<?php
/**
 * Score Events (Audit Trail) Table Schema
 *
 * Records every trust score change with reason, delta, and actor.
 * Used for: dispute evidence, admin debugging, user-facing score history.
 *
 * @package BCC_Trust_Engine
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_score_events_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_score_events';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        page_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        score_before DECIMAL(5,2) DEFAULT NULL,
        score_after DECIMAL(5,2) DEFAULT NULL,
        delta DECIMAL(5,2) DEFAULT NULL,
        tier_before VARCHAR(20) DEFAULT NULL,
        tier_after VARCHAR(20) DEFAULT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        actor_user_id BIGINT UNSIGNED DEFAULT NULL,
        meta JSON DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_page_id (page_id),
        KEY idx_event_type (event_type),
        KEY idx_created_at (created_at),
        KEY idx_page_created (page_id, created_at DESC)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
