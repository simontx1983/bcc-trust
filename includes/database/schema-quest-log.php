<?php
/**
 * Quest Log Table Schema
 *
 * Tracks which onboarding quests each user has completed.
 * One row per (user, quest) — no duplicates, no expiry.
 * The quest multiplier is derived at read time, not stored.
 */
if (!defined('ABSPATH')) exit;

function bcc_trust_create_quest_log_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_quest_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        quest_slug VARCHAR(50) NOT NULL,
        completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_quest (user_id, quest_slug),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
