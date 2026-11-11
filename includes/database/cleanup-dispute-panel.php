<?php
/**
 * A1 targeted cleanup — retire the five-member dispute panel
 * (Rank redesign Phase 6 Wave 3; owner decision D-7).
 *
 * Dispute adjudication moved onto the generic poll engine; the panel
 * table (`bcc_dispute_panel`) and the denormalised panel tally columns
 * on `bcc_disputes` (panel_accepts / panel_rejects / panel_size) lost
 * every reader and writer in the same change. Open ('reviewing')
 * disputes at migration time are panel-era staging fixtures with no
 * poll behind them — they are VOIDED (status → 'dismissed', the
 * existing cleanup-event terminal state that sends no user-facing
 * result email) rather than migrated.
 *
 * A1 discipline (approved plan): READ-ONLY inventory logged BEFORE any
 * mutation (table, predicate, count), explicit predicates/statements
 * only — nothing wildcard — and idempotent: COMPLETE only when zero
 * work remains (no reviewing rows, panel table gone, panel columns
 * gone). Production is frozen and untouched regardless.
 *
 * Status contract (migration-runner callback):
 *   - DB error on any step → INCOMPLETE (fail closed, retry next request)
 *   - all three targets clean → COMPLETE
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 6 (2026-08-03)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_cleanup_dispute_panel')) {

    function bcc_trust_cleanup_dispute_panel(): string
    {
        global $wpdb;

        $disputesTable = $wpdb->prefix . 'bcc_disputes';
        $panelTable    = $wpdb->prefix . 'bcc_dispute_panel';

        // F-149: only PANEL-ERA open disputes are voided — the ones with no
        // poll behind them. A live dispute is ALSO status 'reviewing', but
        // the poll engine opens a poll for it (DisputeVoteService::open →
        // poll_type/subject_type 'dispute', subject_id = dispute id). The old
        // predicate (`status = 'reviewing'` alone) would dismiss every live
        // open dispute on any environment where this migration had not yet
        // been stamped done (a restore, a clone, a fresh copy). Unresolvable
        // poll constants or table name fail closed rather than fall back to
        // the wide predicate.
        //
        // Concurrency note: the NOT EXISTS below cannot see a poll opened in
        // a concurrent, not-yet-committed transaction. That window is
        // negligible for a one-off migration (it would need a dispute filed
        // in the same instant the cleanup runs on a never-stamped
        // environment), so it is documented rather than locked against.
        if (!class_exists('\\BCC\\Trust\\Disputes\\Services\\DisputeVoteService')
            || !class_exists('\\BCC\\Trust\\Core\\Database\\TableRegistry')
        ) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] dispute-panel cleanup deferred: poll engine classes unavailable', [
                'dispute_vote_service' => class_exists('\\BCC\\Trust\\Disputes\\Services\\DisputeVoteService'),
                'table_registry'       => class_exists('\\BCC\\Trust\\Core\\Database\\TableRegistry'),
            ]);
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }
        $pollsTable  = \BCC\Trust\Core\Database\TableRegistry::polls();
        $pollType    = \BCC\Trust\Disputes\Services\DisputeVoteService::POLL_TYPE;
        $subjectType = \BCC\Trust\Disputes\Services\DisputeVoteService::SUBJECT_TYPE;
        $panelEraPredicate = "d.status = %s
                  AND NOT EXISTS (
                        SELECT 1 FROM {$pollsTable} p
                         WHERE p.poll_type = %s
                           AND p.subject_type = %s
                           AND p.subject_id = d.id
                  )";

        // ── READ-ONLY inventory first (logged before any mutation) ────
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $reviewingCount = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$disputesTable} d WHERE {$panelEraPredicate}",
            'reviewing',
            $pollType,
            $subjectType
        ));
        if ($reviewingCount === null && $wpdb->last_error !== '') {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }
        $reviewingCount = (int) $reviewingCount;

        $panelTableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $panelTable)) === $panelTable;

        $panelRows   = 0;
        $ballotRows  = 0;
        if ($panelTableExists) {
            $panelRows  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$panelTable}");
            $ballotRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$panelTable} WHERE decision IS NOT NULL");
        }

        /** @var list<string> $panelColumns */
        $panelColumns = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                AND COLUMN_NAME IN (%s, %s, %s)',
            DB_NAME,
            $disputesTable,
            'panel_accepts',
            'panel_rejects',
            'panel_size'
        ));
        $panelColumns = array_values(array_map('strval', $panelColumns ?: []));

        \BCC\Core\Log\Logger::info('[bcc-trust] dispute-panel cleanup inventory', [
            ['table' => $disputesTable, 'predicate' => "status = 'reviewing' AND no dispute poll", 'count' => $reviewingCount],
            ['table' => $panelTable,    'predicate' => 'all rows',               'count' => $panelRows],
            ['table' => $panelTable,    'predicate' => 'decision IS NOT NULL',   'count' => $ballotRows],
            ['table' => $disputesTable, 'predicate' => 'panel_* columns',        'count' => count($panelColumns)],
        ]);

        // ── 1. Void open panel-era disputes (no poll behind them).
        if ($reviewingCount > 0) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $voided = $wpdb->query($wpdb->prepare(
                "UPDATE {$disputesTable} d
                    SET d.status = %s, d.resolved_at = UTC_TIMESTAMP()
                  WHERE {$panelEraPredicate}",
                'dismissed',
                'reviewing',
                $pollType,
                $subjectType
            ));
            if ($voided === false) {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            wp_cache_delete('dispute_status_counts', 'bcc_disputes');
            \BCC\Core\Log\Logger::info('[bcc-trust] dispute-panel cleanup: voided open disputes', [
                'table'        => $disputesTable,
                'predicate'    => "status = 'reviewing' AND no dispute poll -> 'dismissed'",
                'rows_updated' => (int) $voided,
            ]);
        }

        // ── 2. Drop the panel table.
        if ($panelTableExists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("DROP TABLE IF EXISTS `{$panelTable}`") === false) {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            \BCC\Core\Log\Logger::info('[bcc-trust] dispute-panel cleanup: dropped table', [
                'table'        => $panelTable,
                'rows_dropped' => $panelRows,
            ]);
        }

        // ── 3. Drop the denormalised panel columns from bcc_disputes.
        if ($panelColumns !== []) {
            $drops = implode(', ', array_map(
                static fn(string $col): string => "DROP COLUMN `{$col}`",
                $panelColumns
            ));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("ALTER TABLE `{$disputesTable}` {$drops}") === false) {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            \BCC\Core\Log\Logger::info('[bcc-trust] dispute-panel cleanup: dropped columns', [
                'table'   => $disputesTable,
                'columns' => $panelColumns,
            ]);
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] dispute-panel cleanup complete', [
            'voided_disputes' => $reviewingCount,
            'panel_rows'      => $panelRows,
            'panel_columns'   => count($panelColumns),
        ]);

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
