<?php
/**
 * Finding Decisions Table Schema — APPEND-ONLY decision history for
 * misconduct findings (Rank redesign Phase 8; spec §15.5).
 *
 * One row per decision on a finding: 'issued' at creation (carrying
 * the original penalty snapshot in penalty_after), then any number of
 * reconsiderations ('upheld'|'reduced'|'reversed'|'remanded'). §15.5:
 * appeal decisions never edit the original silently — a reduction
 * updates the finding's CURRENT penalty column, but the original
 * snapshot survives forever in this table's 'issued' row.
 *
 * The owning repository exposes append + bounded read ONLY — no
 * update, no delete (append-only by construction, like the rank
 * events ledger).
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 8 (2026-08-04)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_finding_decisions_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_finding_decisions';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        finding_id BIGINT UNSIGNED NOT NULL,
        decision VARCHAR(12) NOT NULL,
        decided_by BIGINT UNSIGNED NOT NULL,
        reason TEXT NOT NULL,
        penalty_after DECIMAL(6,2) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_finding (finding_id)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Finding decisions table installed', []);
}
