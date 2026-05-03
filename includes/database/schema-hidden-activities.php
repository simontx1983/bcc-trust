<?php
/**
 * Hidden Activities Table Schema
 *
 * §K1 Phase C — sidecar table marking peepso_activities rows as hidden
 * by BCC moderation (auto-hide threshold OR explicit admin action).
 *
 * PK is act_id so the EXISTS-style filter in PeepSoActivityRepository
 * can use the index directly. Single row per hidden activity — restoring
 * is a DELETE, not a status flip, so the table stays small.
 *
 * Why a sidecar (not a column on peepso_activities):
 *   - PeepSo owns peepso_activities; BCC must not alter its schema
 *   - act_status (1=active, 0=deleted) belongs to PeepSo; we don't
 *     repurpose its values
 *   - DELETE-on-restore + INSERT-on-hide keeps the table append-mostly
 *     and easy to reason about
 *
 * Fields:
 *   - act_id            BIGINT UNSIGNED PK
 *   - hidden_at         DATETIME default NOW()
 *   - hidden_by_user_id BIGINT UNSIGNED — auto-hide rows have 0
 *   - reason_code       VARCHAR(40) — 'autohide_threshold'|'admin_action'|'spam'|...
 *   - related_report_id BIGINT UNSIGNED NULL — points at bcc_content_reports
 *                       row that tripped the threshold (or admin action source);
 *                       null when the hide was initiated outside the report flow
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1 (2026-04, §K1 Phase C)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_hidden_activities_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_hidden_activities';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        act_id BIGINT UNSIGNED NOT NULL,
        hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        hidden_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reason_code VARCHAR(40) NOT NULL,
        related_report_id BIGINT UNSIGNED DEFAULT NULL,
        PRIMARY KEY (act_id),
        KEY idx_hidden_at (hidden_at),
        KEY idx_reason (reason_code)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Hidden activities table installed', []);
}
