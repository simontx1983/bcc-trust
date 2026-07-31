<?php
/**
 * Tier Days Table Schema
 *
 * Rank redesign Phase 1 — one row per (user, UTC day) recording the
 * member's resolved Trust Tier ordinal that day. Feeds the §13.1
 * promotion trust windows (Journeyman: Neutral+ on 45 of 60 days;
 * Veteran: Trusted+ on 120 of 180 days) evaluated from Phase 5 on.
 *
 * A MISSING row is a non-qualifying day — the fail-safe direction: a
 * snapshot gap can only delay a promotion, never grant one. Written by
 * TierSnapshotService (daily sweep + request-time lazy fallback), always
 * INSERT IGNORE — the first write for a day wins and is never rewritten.
 *
 * tier_ord encoding lives in includes/config/rank-scoring.php
 * ('tier_ord': risky 0 … elite 4); retention (730 days) likewise.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 1 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_tier_days_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_tier_days';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        user_id BIGINT UNSIGNED NOT NULL,
        day DATE NOT NULL,
        tier_ord TINYINT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, day),
        KEY idx_day (day)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Tier days table installed', []);
}
