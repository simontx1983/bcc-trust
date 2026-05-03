<?php
/**
 * Content Reports Table Schema
 *
 * §K1 Phase B — viewer-filed reports against feed items. Stores one
 * row per (reporter, target) pair (UNIQUE prevents duplicate reports
 * from the same user). Phase C's auto-hide threshold counts rows by
 * `(target_kind, target_id, status=0)` and triggers when the count
 * crosses the configured threshold.
 *
 * Lifecycle:
 *   - status = 0  pending (admin queue surfaces these)
 *   - status = 1  resolved-action_taken (post hidden / user warned)
 *   - status = 2  dismissed (no action; reporter notified later)
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1 (2026-04, §K1 Phase B)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_content_reports_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_content_reports';
    $charset = $wpdb->get_charset_collate();

    // target_kind is short ('feed_item' for V1; future kinds: 'comment',
    // 'profile', 'page'). reason_code mirrors the V1 enum locked in
    // ContentReportService — keep that list canonical there, not here.
    // Column widths are conservative — TEXT for the optional comment so
    // a 500-char limit can grow without a migration.
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        target_kind VARCHAR(20) NOT NULL,
        target_id BIGINT UNSIGNED NOT NULL,
        reporter_user_id BIGINT UNSIGNED NOT NULL,
        reason_code VARCHAR(40) NOT NULL,
        comment TEXT DEFAULT NULL,
        status TINYINT NOT NULL DEFAULT 0,
        resolved_by BIGINT UNSIGNED DEFAULT NULL,
        resolved_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_reporter_target (reporter_user_id, target_kind, target_id),
        KEY idx_status_created (status, created_at),
        KEY idx_target_status (target_kind, target_id, status),
        KEY idx_reporter (reporter_user_id, created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Content reports table installed', []);
}
