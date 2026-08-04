<?php
/**
 * Trust Findings Table Schema — formal MISCONDUCT findings against a
 * member (Rank redesign Phase 8; spec §15).
 *
 * One row per finding. §15.1: raw reports / downvotes / accusations
 * NEVER create rows here — issuance is an explicit admin decision
 * (wp-admin Rank Findings). `source` records provenance: 'admin'
 * (direct), 'dispute' (an admin read a closed dispute and issued
 * manually — §18.4 forbids automation from dispute outcomes), or
 * 'fraud_detection' (reserved — the enum value exists but no writer
 * does yet).
 *
 * `score_penalty` / `ceiling_rank_slug` / expiries are SNAPSHOT from
 * the §15.3 config class at issuance — config edits never rewrite
 * history. `restriction_note` is free text RECORDING any suspension /
 * restriction context; actual suspension stays in the existing
 * Permissions machinery (§15.2).
 *
 * `status` is 'active'|'reversed'; `appeal_status` is the once-only
 * member appeal machine ('' → 'requested' → upheld/reduced/reversed/
 * remanded). Decision history is append-only in
 * wp_bcc_trust_finding_decisions — the original penalty snapshot lives
 * in that table's 'issued' row even after a reduction (§15.5).
 *
 * BOUNDARY: distinct from `wp_bcc_trust_cluster_findings` (Phase 4
 * non-independence determinations) — different domain, different
 * lifecycle; do not merge.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 8 (2026-08-04)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_findings_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_findings';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        subject_user_id BIGINT UNSIGNED NOT NULL,
        finding_type VARCHAR(40) NOT NULL,
        severity VARCHAR(12) NOT NULL,
        evidence_refs TEXT DEFAULT NULL,
        source VARCHAR(20) NOT NULL,
        issued_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        effective_at DATETIME NOT NULL,
        score_penalty DECIMAL(6,2) NOT NULL,
        ceiling_rank_slug VARCHAR(20) DEFAULT NULL,
        ceiling_expires_at DATETIME DEFAULT NULL,
        penalty_expires_at DATETIME NOT NULL,
        restriction_note TEXT DEFAULT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        appeal_status VARCHAR(12) NOT NULL DEFAULT '',
        reversed_at DATETIME DEFAULT NULL,
        reversal_reason VARCHAR(160) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_subject_status (subject_user_id, status),
        KEY idx_appeal (appeal_status)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Findings table installed', []);
}
