<?php
/**
 * Community Ownership Log Table Schema — append-only custody ledger for
 * User-kind (member-created) communities (Rank redesign Phase 7, §21.2).
 *
 * One row per custody event:
 *   'create'       — the user created a community (owner from birth)
 *   'receive'      — ownership was transferred TO the user
 *   'transfer_out' — the user transferred ownership AWAY
 *
 * Every event (re)arms the actor's 30-day GLOBAL custody cooldown —
 * the CapabilityResolver reads MAX(created_at) per user live
 * (CommunityOwnershipLogRepository::lastCustodyEventAt); nothing is
 * materialized and rows are NEVER updated or deleted. counterparty
 * records the other side of a transfer (NULL for 'create').
 *
 * created_at is UTC, computed in PHP (gmdate) — never MySQL NOW()
 * (the session time zone is not UTC on this host).
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 7 (2026-08-04)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_community_ownership_log_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_community_ownership_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL,
        event VARCHAR(16) NOT NULL,
        counterparty_user_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_user_created (user_id, created_at),
        KEY idx_group (group_id)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Community ownership log table installed', []);
}
