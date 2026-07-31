<?php
/**
 * Rank State Table Schema — the CANONICAL Rank progression state
 * (Rank redesign Phase 5; approved plan §24).
 *
 * One row per RANKED member. A MISSING row is the New Member account
 * state (fail-safe: no row ⇒ no rank ⇒ no reputation-bearing
 * capability). `apprentice_awarded_at` is the §16.3 maturity epoch —
 * vesting runs from it for the full 90 days and survives promotion
 * (owner correction 3). Scores are a materialization of the
 * RankScoreCalculator over the append-only rank_events ledger — the
 * ledger is authoritative; this row is reproducible from it (§24.1)
 * and rewritten by the promotion engine, never hand-edited.
 *
 * recovery_status/'grace' + recovery_deadline carry the §14.1 90-day
 * evidence-loss grace (full recovery machine lands Phase 8; the daily
 * evaluate honors the deadline).
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 5 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_rank_state_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_rank_state';
    $charset = $wpdb->get_charset_collate();

    // Column is rank_slug (not `rank`) — RANK is a MySQL 8 reserved word.
    $sql = "CREATE TABLE {$table} (
        user_id BIGINT UNSIGNED NOT NULL,
        rank_slug VARCHAR(12) NOT NULL,
        apprentice_awarded_at DATETIME NOT NULL,
        journeyman_awarded_at DATETIME DEFAULT NULL,
        veteran_awarded_at DATETIME DEFAULT NULL,
        score DECIMAL(7,4) NOT NULL DEFAULT 0,
        cat_contribution DECIMAL(7,4) NOT NULL DEFAULT 0,
        cat_helping DECIMAL(7,4) NOT NULL DEFAULT 0,
        cat_recognition DECIMAL(7,4) NOT NULL DEFAULT 0,
        cat_outcomes DECIMAL(7,4) NOT NULL DEFAULT 0,
        cat_time DECIMAL(7,4) NOT NULL DEFAULT 0,
        recovery_status VARCHAR(12) NOT NULL DEFAULT '',
        recovery_deadline DATETIME DEFAULT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (user_id),
        KEY idx_rank (rank_slug)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Rank state table installed', []);
}
