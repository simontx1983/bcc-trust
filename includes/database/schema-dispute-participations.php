<?php
/**
 * Dispute Participation Table Schema
 *
 * §D5 — every accepted panel vote optionally produces a participation
 * row. Rows are inserted **after** the panel-vote transaction commits,
 * so an INSERT failure here does NOT roll back the vote (the vote is
 * critical path; the credit is bookkeeping). Idempotency is enforced
 * at the DB level via UNIQUE(user_id, dispute_id) — duplicate-key
 * errors are treated as "already credited" and silently ignored.
 *
 * Column model:
 *   - was_credited: 1 when the credit counted toward the user's trust
 *     score; 0 when caps / eligibility skipped the credit. Skipped
 *     rows are still recorded so the system has an audit trail of
 *     "vote happened but no credit applied" — see credit_skipped_reason.
 *   - credit_skipped_reason: nullable code explaining the skip:
 *       'daily_cap'    — user already at DAILY_CAP for the last 24h
 *       'total_cap'    — user already at TOTAL_CAP lifetime
 *       'suspended'    — user is suspended
 *       'fraud_flag'   — user flagged by fraud / risk service
 *       'linked_users' — reserved for V1.5 fingerprint-based check
 *       'low_quality'  — reserved for future dispute-quality filter
 *   - outcome_match: NULL while dispute is still 'reviewing'.
 *     After resolution, set to 1 if (decision === final outcome),
 *     0 if not. timeout_no_quorum disputes leave this NULL forever
 *     (no clear outcome → no accuracy bonus possible).
 *
 * Indexes:
 *   - uq_user_dispute: idempotency (one credit per user per dispute)
 *   - idx_user_created: powers the daily-window query. Drops the
 *     was_credited column from the composite — we always filter
 *     `was_credited = 1` in WHERE, so leaving it out of the index
 *     keeps writes lean and the index small.
 *   - idx_user_outcome: powers the trust-score query
 *     (WHERE user_id=? AND was_credited=1 AND outcome_match=1).
 *   - idx_dispute: bulk backfill on dispute resolution.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1 (2026-04, §D5 panel-vote credit)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_dispute_participations_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_dispute_participations';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        dispute_id BIGINT UNSIGNED NOT NULL,
        decision ENUM('accept','reject') NOT NULL,
        was_credited TINYINT(1) NOT NULL DEFAULT 0,
        credit_skipped_reason VARCHAR(32) DEFAULT NULL,
        outcome_match TINYINT(1) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_dispute (user_id, dispute_id),
        KEY idx_user_created (user_id, created_at),
        KEY idx_user_outcome (user_id, outcome_match),
        KEY idx_dispute (dispute_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Dispute participations table installed', []);
}
