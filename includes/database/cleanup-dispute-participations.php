<?php
/**
 * A1 targeted cleanup — drop the §D5 dispute-participation table
 * (Rank redesign Phase 6 Wave 3; owner decision D-7).
 *
 * The five-member panel retired and its participation trust-credit
 * retired with it: the writer (DisputeParticipationService) and every
 * reader (UserViewService bonus, MemberCardPrefetcher prime, the
 * resolver/scheduler outcome-match backfills) are deleted in the same
 * change, so `bcc_dispute_participations` has neither writers nor
 * readers. Rows are panel-era staging fixtures — dropped, not migrated.
 *
 * A1 discipline (approved plan): READ-ONLY inventory logged BEFORE the
 * mutation (table, predicate, count), explicit single-table DROP —
 * nothing wildcard — and idempotent: COMPLETE when the table is gone.
 * Production is frozen and untouched regardless.
 *
 * Status contract (migration-runner callback):
 *   - DB error → INCOMPLETE (fail closed, retry next request)
 *   - table absent → COMPLETE
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 6 (2026-08-04)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_cleanup_dispute_participations')) {

    function bcc_trust_cleanup_dispute_participations(): string
    {
        global $wpdb;

        $table = $wpdb->prefix . 'bcc_dispute_participations';

        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$tableExists) {
            return BCC_TRUST_MIGRATION_COMPLETE;
        }

        // ── READ-ONLY inventory first (logged before any mutation) ────
        $rowCount = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($rowCount === null && $wpdb->last_error !== '') {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }
        $creditedCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE was_credited = 1");

        \BCC\Core\Log\Logger::info('[bcc-trust] dispute-participations cleanup inventory', [
            ['table' => $table, 'predicate' => 'all rows',         'count' => (int) $rowCount],
            ['table' => $table, 'predicate' => 'was_credited = 1', 'count' => $creditedCount],
        ]);

        // ── Drop the table (recreatable: N/A — retired feature). ──────
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->query("DROP TABLE IF EXISTS `{$table}`") === false) {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] dispute-participations cleanup complete', [
            'table'        => $table,
            'rows_dropped' => (int) $rowCount,
        ]);

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
