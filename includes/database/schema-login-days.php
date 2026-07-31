<?php
/**
 * Login Days Table Schema
 *
 * Rank redesign Phase 1 — one row per (user, UTC day) with at least one
 * EXPLICIT successful authentication/session-start event. Written only by
 * RankLoginListener via LoginDaysRepository::recordLogin (INSERT IGNORE —
 * idempotent by PK; same-day duplicates are harmless by construction).
 * There is deliberately NO request-touch writer: background API requests,
 * token refresh (SessionController), and re-mints (MyAccountEndpoint) fire
 * no auth event and therefore never reach this table (owner correction 2).
 *
 * Consumers (later phases read; Phase 1 only accumulates):
 *   - time credit  = COUNT(DISTINCT month), capped by config
 *   - inactivity / decay clock = MAX(day)
 *   - DormancyDetector (re-pointed from the retired bcc_trust_last_login
 *     usermeta in this same phase)
 *
 * `source` records which auth flow produced the day's first row
 * ('login' | 'signup' | 'email_verify' | 'wp_login') — hook-granular; the
 * emitters do not pass a finer via.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 1 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_login_days_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_login_days';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        user_id BIGINT UNSIGNED NOT NULL,
        day DATE NOT NULL,
        source VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, day),
        KEY idx_day (day)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Login days table installed', []);
}
