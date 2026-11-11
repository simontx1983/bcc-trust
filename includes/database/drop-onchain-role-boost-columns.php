<?php
/**
 * Drop the retired `trust_boost` / `fraud_reduction` columns (and the
 * `idx_trust_boost` index) from `bcc_onchain_signals`.
 *
 * These backed the role-based wallet trust boost, which was dead code —
 * the sum they fed (`getTotalTrustBoost`) had zero scoring consumers, and
 * the chain-check hook that would have populated them was never fired
 * (2026-07-06 audit; confirmed finish-or-cut, chose cut). The whole
 * mechanism was removed 2026-07-07, so these columns are now unread. The
 * working claim-bonus path (`score_contribution` → BonusService) is
 * unaffected — it never touched these columns.
 *
 * Idempotent AND resurrection-safe: guarded by a fast-path option plus an
 * hourly transient re-check (a stale checkout re-running the old
 * schema-ensure could re-add the columns; the transient gate catches that
 * on the next init rather than letting it become permanent drift — same
 * pattern as drop-endorsements-table.php). Column/index existence is
 * probed before each DROP, so it's a clean no-op on fresh installs.
 *
 * @since 2026-07-07 — role-based wallet boost cut
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_onchain_role_boost_columns')) {

    function bcc_trust_drop_onchain_role_boost_columns(): void
    {
        // Fast path: already dropped AND re-verified within the last hour.
        if (get_option('bcc_trust_onchain_role_boost_cols_dropped')
            && get_transient('bcc_trust_onchain_role_boost_drop_recheck')
        ) {
            return;
        }
        // F-150: a failed step leaves this unstamped; retry at most hourly.
        if (bcc_trust_migration_backoff_active('onchain_role_boost_cols')) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'bcc_onchain_signals';

        // Table may not exist yet on a brand-new install (schema-ensure runs
        // on activation); bail quietly and let the next init retry.
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($tableExists === null && $wpdb->last_error !== '') {
            // A failed probe is UNKNOWN, not "table absent" (#401 review L2).
            bcc_trust_migration_step_failed('onchain_role_boost_cols', 'probe table');
            return;
        }
        if ($tableExists !== $table) {
            return;
        }

        // Pass 1 (read-only): which retired columns are present, and does any
        // of them carry data? A column that came back POPULATED (e.g. a
        // rollback to older code wrote to it) is not drift to clean silently
        // — refuse before dropping anything, so neither column is lost.
        $present  = [];
        $withData = [];
        foreach (['trust_boost', 'fraud_reduction'] as $column) {
            $hasColumn = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
                $table,
                $column
            ));
            if ($hasColumn === null && $wpdb->last_error !== '') {
                bcc_trust_migration_step_failed('onchain_role_boost_cols', "probe {$column}");
                return;
            }
            if ((int) $hasColumn === 0) {
                continue;
            }
            $present[] = $column;
            // Column name is a fixed literal from the list above.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $populated = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> 0");
            if ($populated === null) {
                bcc_trust_migration_step_failed('onchain_role_boost_cols', "count {$column}");
                return;
            }
            if ((int) $populated > 0) {
                $withData[$column] = (int) $populated;
            }
        }
        if ($withData !== []) {
            bcc_trust_migration_refuse_destructive('onchain_role_boost_cols', 'retired columns reappeared with data', [
                'table'   => $table,
                'columns' => $withData,
            ]);
            return;
        }

        // Pass 2: every present column is empty — drop them.
        $droppedColumns = [];
        foreach ($present as $column) {
            // Drop the index first — it's defined on trust_boost, so the
            // column DROP would otherwise fail on some MySQL versions.
            if ($column === 'trust_boost') {
                $hasIdx = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'idx_trust_boost'",
                    $table
                ));
                if ($hasIdx === null && $wpdb->last_error !== '') {
                    bcc_trust_migration_step_failed('onchain_role_boost_cols', 'probe idx_trust_boost');
                    return;
                }
                if ((int) $hasIdx > 0) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    if ($wpdb->query("ALTER TABLE `{$table}` DROP INDEX `idx_trust_boost`") === false) {
                        bcc_trust_migration_step_failed('onchain_role_boost_cols', 'drop idx_trust_boost');
                        return;
                    }
                }
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`") === false) {
                bcc_trust_migration_step_failed('onchain_role_boost_cols', "drop {$column}");
                return;
            }
            $droppedColumns[] = $column;
        }

        // Log only a real drop. This runs on every hourly re-verify pass, and
        // an unconditional "dropped" warning there was noise that would bury
        // the one line that matters: a resurrected column actually removed.
        if ($droppedColumns !== [] && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] dropped retired onchain role-boost columns',
                ['table' => $table, 'columns' => $droppedColumns]
            );
        }

        update_option('bcc_trust_onchain_role_boost_cols_dropped', time(), false);
        set_transient('bcc_trust_onchain_role_boost_drop_recheck', 1, HOUR_IN_SECONDS);
    }

    // Priority 29 — after drop-endorsements-table (28), keeping the
    // drop-legacy chain strictly ordered.
    add_action('init', 'bcc_trust_drop_onchain_role_boost_columns', 29);
}
