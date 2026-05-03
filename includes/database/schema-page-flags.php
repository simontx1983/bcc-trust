<?php
/**
 * Page Flags Table Schema
 *
 * Lightweight public flagging system — signal only, no score impact.
 *
 * @package BCC\Trust\Core
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_page_flags_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_page_flags';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        page_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_page_user (page_id, user_id),
        KEY idx_page_id (page_id),
        KEY idx_user_id (user_id),
        KEY idx_created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Page flags table installed', []);
}
