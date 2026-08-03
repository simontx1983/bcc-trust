<?php
/**
 * Polls Table Schema — the generic meaningful-voting poll engine
 * (Rank redesign Phase 6, Wave 2; approved plan §16–§19).
 *
 * One row per poll over a SUBJECT (poll_type + subject_type + subject_id).
 * 'dispute' is the first poll_type; the engine stays generic — dispute
 * semantics (eligibility, conflict checks, outcome application) live in
 * Wave 3's dispute layer, which subscribes to `bcc_trust_poll_closed`.
 *
 * Lifecycle: rows open with the §17 windows precomputed in PHP UTC
 * (binding_earliest_at = day-7, expires_at = day-90 from RankScoringConfig
 * binding_window); the hourly close sweep (RankScheduler
 * EVENT_POLL_CLOSE_SWEEP → PollService::closeDuePolls) evaluates
 * quorum/majority past binding and closes 'passed'/'failed', or
 * 'inconclusive' at expiry when binding was never met. Windows NEVER
 * reset after open.
 *
 * One-OPEN-poll-per-subject is enforced at the DATABASE level via the
 * DisputeRepository::migrateActiveDisputeConstraint precedent: a STORED
 * generated column (`open_lock` = 1 while status='open', NULL otherwise)
 * inside a UNIQUE index — MySQL UNIQUE ignores NULLs, so closed rows
 * fall out of the constraint. dbDelta mangles generated columns, so the
 * column + unique index are added by an idempotent post-install
 * migration (INFORMATION_SCHEMA existence check), not the CREATE TABLE.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 6 (2026-08-03)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_polls_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_polls';
    $charset = $wpdb->get_charset_collate();

    // NOTE: open_lock (generated column) + uniq_open_subject deliberately
    // absent here — dbDelta cannot express generated columns; see the
    // post-install migration below.
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        poll_type VARCHAR(20) NOT NULL,
        subject_type VARCHAR(20) NOT NULL,
        subject_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'open',
        outcome VARCHAR(16) DEFAULT NULL,
        opened_at DATETIME NOT NULL,
        binding_earliest_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        closed_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_status_expiry (status, expires_at),
        KEY idx_subject (subject_type, subject_id)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    bcc_trust_migrate_polls_open_lock();

    \BCC\Core\Log\Logger::info('[bcc-trust] Polls table installed', []);
}

/**
 * Post-install migration: STORED generated column `open_lock` (1 while
 * status='open', NULL otherwise) + UNIQUE KEY uniq_open_subject over
 * (poll_type, subject_type, subject_id, open_lock) — one OPEN poll per
 * subject at the DB level; closed rows carry NULL and are ignored by
 * the UNIQUE index. Idempotent via INFORMATION_SCHEMA existence check
 * (DisputeRepository::migrateActiveDisputeConstraint precedent).
 */
function bcc_trust_migrate_polls_open_lock(): void {
    global $wpdb;

    $table = $wpdb->prefix . 'bcc_trust_polls';

    $colExists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'open_lock'",
        DB_NAME,
        $table
    ));

    if ((int) $colExists > 0) {
        return; // Already migrated.
    }

    $wpdb->query(
        "ALTER TABLE {$table}
         ADD COLUMN open_lock TINYINT
             GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN 1 ELSE NULL END) STORED,
         ADD UNIQUE KEY uniq_open_subject (poll_type, subject_type, subject_id, open_lock)"
    );

    if ($wpdb->last_error) {
        \BCC\Core\Log\Logger::error('[bcc-trust] Failed to add polls open_lock constraint', [
            'error' => $wpdb->last_error,
        ]);
    }
}
