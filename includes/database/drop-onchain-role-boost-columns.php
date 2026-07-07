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

        global $wpdb;

        $table = $wpdb->prefix . 'bcc_onchain_signals';

        // Table may not exist yet on a brand-new install (schema-ensure runs
        // on activation); bail quietly and let the next init retry.
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($tableExists !== $table) {
            return;
        }

        foreach (['trust_boost', 'fraud_reduction'] as $column) {
            $hasColumn = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
                $table,
                $column
            ));
            if ((int) $hasColumn > 0) {
                // Drop the index first — it's defined on trust_boost, so the
                // column DROP would otherwise fail on some MySQL versions.
                if ($column === 'trust_boost') {
                    $hasIdx = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'idx_trust_boost'",
                        $table
                    ));
                    if ((int) $hasIdx > 0) {
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `idx_trust_boost`");
                    }
                }
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }
        }

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] dropped retired onchain role-boost columns',
                ['table' => $table]
            );
        }

        update_option('bcc_trust_onchain_role_boost_cols_dropped', time(), false);
        set_transient('bcc_trust_onchain_role_boost_drop_recheck', 1, HOUR_IN_SECONDS);
    }

    // Priority 29 — after drop-endorsements-table (28), keeping the
    // drop-legacy chain strictly ordered.
    add_action('init', 'bcc_trust_drop_onchain_role_boost_columns', 29);
}
