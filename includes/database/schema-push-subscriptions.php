<?php
/**
 * Push Subscriptions Table Schema
 *
 * Stores Web Push (VAPID) subscriptions per user, per device. One row
 * per (user_id, browser endpoint) pair — a single user can have
 * multiple devices registered.
 *
 * Per V2 Phase 1 §P1.D1 (push notifications) — extends the existing
 * NotificationPrefs/NotificationDispatcher chain with a delivery-channel
 * table. No parallel notification framework.
 *
 * Uniqueness via SHA-256 hash of the endpoint URL: endpoints can be
 * 300–500 chars and a unique-key prefix on a utf8mb4 VARCHAR(500) would
 * exceed InnoDB's 3072-byte index limit on older row formats. Hashing
 * sidesteps that and stays dbDelta-friendly.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V2 Phase 1
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_push_subscriptions_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_push_subscriptions';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        endpoint VARCHAR(500) NOT NULL,
        endpoint_hash CHAR(64) NOT NULL,
        p256dh_key VARCHAR(128) NOT NULL,
        auth_key VARCHAR(48) NOT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_user_endpoint (user_id, endpoint_hash),
        KEY idx_user_id (user_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Push subscriptions table installed', []);
}
