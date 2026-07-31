<?php
/**
 * Rank Events Table Schema — the append-only Rank evidence ledger
 * (Rank redesign Phase 4, shadow; approved plan §24.1).
 *
 * One row per (evidence event, category). `event_uid` =
 * "{source_type}:{source_id}:{subject_user_id}" and UNIQUE(event_uid,
 * category) makes ingestion idempotent — re-firing a hook is a no-op.
 * `raw_value` preserves the uncapped credit for audit; `capped_value`
 * is what the calculator sums (the §4.4 2-point event cap and the R3
 * representative zero-credit rule are applied at ingest — zeroed rows
 * KEEP their raw value). Reversal flips `status` and stamps
 * reversed_at/reversal_reason — rows are never mutated otherwise and
 * NEVER deleted (no retention cleanup, ever — Rank must stay
 * reproducible from this ledger, §24.1).
 *
 * Distinct from `wp_bcc_trust_score_events` (trust-score audit trail):
 * that ledger records score/tier transitions; this one records Rank
 * evidence. Do not cross-write.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 4 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_rank_events_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_rank_events';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_uid VARCHAR(120) NOT NULL,
        subject_user_id BIGINT UNSIGNED NOT NULL,
        source_type VARCHAR(30) NOT NULL,
        source_id BIGINT UNSIGNED NOT NULL,
        category VARCHAR(20) NOT NULL,
        relationship_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        raw_value DECIMAL(8,4) NOT NULL,
        capped_value DECIMAL(8,4) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        effective_at DATETIME NOT NULL,
        reversed_at DATETIME DEFAULT NULL,
        reversal_reason VARCHAR(120) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_event_category (event_uid, category),
        KEY idx_subject_status (subject_user_id, status, category),
        KEY idx_source (source_type, source_id)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Rank events table installed', []);
}
