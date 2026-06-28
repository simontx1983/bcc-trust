<?php
/**
 * Attestor Reliability Cache schema — §J.3.2 self-mirror memoization
 * (Slice 3).
 *
 * One row per attestor (PRIMARY KEY user_id) memoizing the expensive
 * AttestationOutcomeClassifier::classifyForAttestor output. The
 * `bcc_attestor_reliability_sweep` daily cron recomputes every row;
 * the read paths (/me/reliability, ReliabilityStandingComputer) read
 * this cache first and fall back to a live compute on cache miss.
 *
 * This is a RECOMPUTE CACHE, not a source of truth — the classifier
 * remains canonical. The cron owns all writes; reads are read-only
 * (no read-through caching) so the read path never writes.
 *
 * §J.3.2.1 sub-tracks (consensus_reliability, early_read_accuracy) are
 * carried as columns now for forward-compat but stay at their defaults
 * until Slice 4 populates them. The four `targets_*` outcome counts ARE
 * populated by the sweep (the classifier's track_record breakdown) so a
 * cache HIT serves the same /me/reliability track_record as a cache MISS.
 *
 * @package BCC\Trust\Core
 * @subpackage Database
 * @since V2 Trust Attestation Layer (Slice 3, 2026-06-28)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create the bcc_attestor_reliability_cache table.
 */
function bcc_trust_create_attestor_reliability_cache_table(): void {
    global $wpdb;

    $table   = \BCC\Trust\Core\Database\TableRegistry::attestorReliabilityCache();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        user_id               BIGINT UNSIGNED NOT NULL,
        operator_reliability  DECIMAL(4,3) NOT NULL DEFAULT 0.000,
        reliability_standing  VARCHAR(20) NOT NULL DEFAULT 'newly_active',
        consensus_reliability DECIMAL(4,3) NOT NULL DEFAULT 0.000,
        early_read_accuracy   DECIMAL(4,3) NOT NULL DEFAULT 0.000,
        attestation_count     INT UNSIGNED NOT NULL DEFAULT 0,
        trend                 VARCHAR(10) NOT NULL DEFAULT 'steady',
        reliability_baseline  DECIMAL(4,3) NOT NULL DEFAULT 0.000,
        baseline_at           DATETIME NULL,
        targets_disputed_and_upheld           INT UNSIGNED NOT NULL DEFAULT 0,
        targets_disputed_and_dismissed        INT UNSIGNED NOT NULL DEFAULT 0,
        targets_received_further_attestations INT UNSIGNED NOT NULL DEFAULT 0,
        targets_clean_and_active              INT UNSIGNED NOT NULL DEFAULT 0,
        computed_at           DATETIME NOT NULL,
        PRIMARY KEY (user_id),
        KEY idx_computed (computed_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Attestor reliability cache table installed', []);
}
