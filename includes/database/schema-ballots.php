<?php
/**
 * Ballots Table Schema — per-voter ballots for the meaningful-voting
 * poll engine (Rank redesign Phase 6, Wave 2; approved plan §16–§19).
 *
 * One row per CAST — recasts and withdrawals never rewrite a row's
 * substance; they close it ('superseded' / 'withdrawn') and, for a
 * recast, insert a fresh 'active' row. The §16.6 weight-input snapshot
 * (rank_slug … effective_weight) is captured at the ORIGINAL cast and
 * COPIED VERBATIM onto every recast row — snapshot model, no
 * re-weighting (the caller computes it once via VoteWeightCalculator).
 *
 * Cluster audit columns (suspected_cluster_id, confirmed_cluster_id,
 * counted_weight, counted) are NULL until the close sweep evaluates the
 * poll; they are audit-only records of what actually counted after the
 * confirmed-cluster representative rule and the suspected pro-rata cap
 * (§18/§19) — the closed-poll tally is derived from them.
 *
 * One-ACTIVE-ballot-per-voter-per-poll is enforced at the DATABASE
 * level via the same generated-column technique as the polls table:
 * `active_lock` = 1 while status='active', NULL otherwise, inside
 * UNIQUE uniq_active_ballot (dbDelta can't express generated columns —
 * added by the idempotent post-install migration below).
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 6 (2026-08-03)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_ballots_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_ballots';
    $charset = $wpdb->get_charset_collate();

    // NOTE: active_lock (generated column) + uniq_active_ballot
    // deliberately absent here — see the post-install migration below.
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        poll_id BIGINT UNSIGNED NOT NULL,
        voter_user_id BIGINT UNSIGNED NOT NULL,
        choice VARCHAR(16) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        recast_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        rank_slug VARCHAR(20) DEFAULT NULL,
        maturity DECIMAL(6,4) NOT NULL,
        rank_multiplier DECIMAL(6,4) NOT NULL,
        trust_multiplier DECIMAL(6,4) NOT NULL,
        trust_score DECIMAL(6,2) NOT NULL,
        fraud_discount DECIMAL(6,4) NOT NULL,
        effective_weight DECIMAL(8,4) NOT NULL,
        suspected_cluster_id BIGINT UNSIGNED DEFAULT NULL,
        confirmed_cluster_id BIGINT UNSIGNED DEFAULT NULL,
        counted_weight DECIMAL(8,4) DEFAULT NULL,
        counted TINYINT(1) DEFAULT NULL,
        cast_at DATETIME NOT NULL,
        last_action_at DATETIME NOT NULL,
        withdrawn_at DATETIME DEFAULT NULL,
        superseded_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_poll_status (poll_id, status),
        KEY idx_voter (voter_user_id)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    bcc_trust_migrate_ballots_active_lock();

    \BCC\Core\Log\Logger::info('[bcc-trust] Ballots table installed', []);
}

/**
 * Post-install migration: STORED generated column `active_lock` (1 while
 * status='active', NULL otherwise) + UNIQUE KEY uniq_active_ballot over
 * (poll_id, voter_user_id, active_lock) — one ACTIVE ballot per voter
 * per poll at the DB level; superseded/withdrawn rows carry NULL and
 * are ignored by the UNIQUE index. Idempotent via INFORMATION_SCHEMA
 * existence check (DisputeRepository precedent).
 */
function bcc_trust_migrate_ballots_active_lock(): void {
    global $wpdb;

    $table = $wpdb->prefix . 'bcc_trust_ballots';

    $colExists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'active_lock'",
        DB_NAME,
        $table
    ));

    if ((int) $colExists > 0) {
        return; // Already migrated.
    }

    $wpdb->query(
        "ALTER TABLE {$table}
         ADD COLUMN active_lock TINYINT
             GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN 1 ELSE NULL END) STORED,
         ADD UNIQUE KEY uniq_active_ballot (poll_id, voter_user_id, active_lock)"
    );

    if ($wpdb->last_error) {
        \BCC\Core\Log\Logger::error('[bcc-trust] Failed to add ballots active_lock constraint', [
            'error' => $wpdb->last_error,
        ]);
    }
}
