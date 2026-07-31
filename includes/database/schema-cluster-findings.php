<?php
/**
 * Cluster Findings Table Schema — FORMAL non-independence determinations
 * (Rank redesign Phase 4, shadow; approved plan R3/D-5).
 *
 * One row per formal cluster determination: level 'suspected' (feeds
 * only the future 20% binding-ballot cap) or 'confirmed' (exactly one
 * `representative_user_id` earns Rank evidence; every other member is
 * zero-credited at ingest). `selection_method` records how the
 * representative was chosen ('auto_oldest_account' default per D-5,
 * or 'admin_override' with decided_by provenance). Reversal stamps
 * reversed_at/reason; a reversal restores members' evidence
 * deterministically via ledger recalculation.
 *
 * BOUNDARY (R3): this is the formal DETERMINATION store. The expiring
 * heuristic SIGNAL stores stay distinct — `wp_bcc_trust_patterns`
 * (TrustGraph vote/endorsement rings), `wp_bcc_trust_fraud_analysis`
 * (FraudDetector snapshots), and CohortOverlapDampener's attestation
 * cohort math. Signals may some day feed a detection pipeline that
 * PROPOSES findings (Phase 6, thresholds ⚖); they never zero credit
 * by themselves — environmental evidence alone can never confirm.
 *
 * `member_user_ids` is a JSON list; membership lookups scan active
 * findings (bounded LIMIT — formal findings are rare by construction)
 * and filter in PHP. A member sidecar table lands with the Phase 6
 * detection pipeline if volume ever warrants an index.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 4 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_cluster_findings_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_cluster_findings';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        level VARCHAR(12) NOT NULL,
        member_user_ids TEXT NOT NULL,
        representative_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        selection_method VARCHAR(30) NOT NULL DEFAULT '',
        evidence_refs TEXT DEFAULT NULL,
        confidence DECIMAL(4,3) NOT NULL DEFAULT 0,
        decided_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        decided_at DATETIME NOT NULL,
        effective_at DATETIME NOT NULL,
        reversed_at DATETIME DEFAULT NULL,
        reversal_reason VARCHAR(160) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_active_level (reversed_at, level)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Cluster findings table installed', []);
}
