<?php
/**
 * Drop the retired `endorsement_bonus` column (Slice E follow-up).
 *
 * Slice E moved all backing to vouch/attestations; the trust-score formula
 * (`TrustScoreService::formulaSql()`) already excludes endorsement_bonus, and
 * the column has been zeroed across every row. The code references are removed
 * in the same release. dbDelta never DROPs, so this one-time guarded migration
 * drops the now-dead column from both score tables.
 *
 * `endorsement_count` is a DIFFERENT column (kept) — only `endorsement_bonus`
 * is dropped here.
 *
 * Idempotent: guarded by the `bcc_trust_endorsement_bonus_dropped` option AND a
 * per-table column-existence check, so it's a clean no-op once done, on a fresh
 * install (column never created), or if a table is absent. Runs on init after
 * the other drop-legacy migrations.
 *
 * @since Slice E follow-up — endorse retirement (2026-06-28)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_endorsement_bonus')) {

    function bcc_trust_drop_endorsement_bonus(): void
    {
        if (get_option('bcc_trust_endorsement_bonus_dropped')) {
            return;
        }
        // F-150: a failed step leaves this unstamped; retry at most hourly.
        if (bcc_trust_migration_backoff_active('endorsement_bonus')) {
            return;
        }

        global $wpdb;

        $dropped = [];
        foreach (['bcc_trust_page_scores', 'bcc_page_read_model'] as $suffix) {
            $table  = $wpdb->prefix . $suffix;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === null && $wpdb->last_error !== '') {
                bcc_trust_migration_step_failed('endorsement_bonus', "probe {$suffix}");
                return;
            }
            if ($exists !== $table) {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $hasCol = $wpdb->get_var($wpdb->prepare(
                "SHOW COLUMNS FROM `{$table}` LIKE %s",
                'endorsement_bonus'
            ));
            if ($hasCol === null && $wpdb->last_error !== '') {
                bcc_trust_migration_step_failed('endorsement_bonus', "probe {$suffix}.endorsement_bonus");
                return;
            }
            if ($hasCol === 'endorsement_bonus') {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if ($wpdb->query("ALTER TABLE `{$table}` DROP COLUMN `endorsement_bonus`") === false) {
                    bcc_trust_migration_step_failed('endorsement_bonus', "drop {$suffix}.endorsement_bonus");
                    return;
                }
                $dropped[] = $suffix;
            }
        }

        if ($dropped !== [] && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] dropped retired endorsement_bonus column',
                ['tables' => $dropped]
            );
        }

        update_option('bcc_trust_endorsement_bonus_dropped', time(), false);
    }

    // Priority 27 — after the other Phase-5 drop-legacy migrations (25/26).
    add_action('init', 'bcc_trust_drop_endorsement_bonus', 27);
}
