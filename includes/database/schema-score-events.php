<?php
/**
 * Score Events (Audit Trail) Table Schema
 *
 * Records every trust score change with reason, delta, and actor.
 *
 * Used for (verified by the 2026-06-18 read-path trace — do not re-widen
 * this list without adding the reader):
 *   - the 24h highlights slot (§O2.1) — `ScoreEventRepository::findForPagesSince`,
 *     the ONLY live read path;
 *   - write-time audit trail (rows are written on every score transition
 *     and retained for admin/DB forensics — no runtime reader).
 *
 * NOT used for dispute evidence (disputes read the votes table) and NOT
 * used for user-facing score history (those readers were deleted
 * 2026-06-18 — `getForPage`/`getForActor`/`getTierChanges`, zero callers).
 *
 * @package BCC\Trust\Core
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
