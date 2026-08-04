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

        // ── READ-ONLY inventory first (logged before any mutation) ────
        $reviewingCount = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$disputesTable} WHERE status = %s",
            'reviewing'
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
            ['table' => $disputesTable, 'predicate' => "status = 'reviewing'",   'count' => $reviewingCount],
            ['table' => $panelTable,    'predicate' => 'all rows',               'count' => $panelRows],
            ['table' => $panelTable,    'predicate' => 'decision IS NOT NULL',   'count' => $ballotRows],
            ['table' => $disputesTable, 'predicate' => 'panel_* columns',        'count' => count($panelColumns)],
        ]);

        // ── 1. Void open panel-era disputes (disposable staging fixtures).
        if ($reviewingCount > 0) {
            $voided = $wpdb->query($wpdb->prepare(
                "UPDATE {$disputesTable}
                    SET status = %s, resolved_at = UTC_TIMESTAMP()
                  WHERE status = %s",
                'dismissed',
                'reviewing'
            ));
            if ($voided === false) {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            wp_cache_delete('dispute_status_counts', 'bcc_disputes');
            \BCC\Core\Log\Logger::info('[bcc-trust] dispute-panel cleanup: voided open disputes', [
                'table'        => $disputesTable,
                'predicate'    => "status = 'reviewing' -> 'dismissed'",
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
