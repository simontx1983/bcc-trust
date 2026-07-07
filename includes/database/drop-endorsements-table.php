<?php
/**
 * Drop the retired `bcc_trust_endorsements` table (endorse-retirement
 * final slice).
 *
 * The table was FROZEN after the Slice E endorse→vouch cutover: its rows
 * were materialized into `bcc_trust_attestations` as kind=vouch by the
 * (since-deleted) §J.11 / post_vouch migrations, POST /endorse casts vouch
 * attestations, and every remaining read (given-direction endpoints,
 * endorsement_count denorm, received counts, ring detection, wp-admin
 * debug/overview) was repointed to the attestations table in the same
 * release that ships this drop. dbDelta never DROPs, so this one-time
 * guarded migration removes the dead table.
 *
 * Idempotent AND resurrection-proof: the one-shot option is only a
 * fast-path — once set, the existence probe still re-runs hourly (transient
 * gated). A purely one-shot guard let the table come back permanently: the
 * drop ran 2026-07-02, then a parallel checkout on a pre-retirement branch
 * re-ran dbDelta and recreated the table on 2026-07-03, and the option
 * guard meant it was never looked at again. Runs on init after
 * drop-endorsement-bonus (27).
 *
 * @since Endorse retirement — reads-to-attestations slice (2026-07-02)
 * @since 2026-07-06 hourly resurrection re-check (zombie-table incident)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_endorsements_table')) {

    function bcc_trust_drop_endorsements_table(): void
    {
        // Fast path: already dropped AND re-verified within the last hour.
        // The transient (not the option alone) gates the skip so a
        // resurrected table is caught by the next hourly probe instead of
        // becoming permanent drift.
        if (get_option('bcc_trust_endorsements_table_dropped')
            && get_transient('bcc_trust_endorsements_drop_recheck')
        ) {
            return;
        }

        global $wpdb;

        $table  = $wpdb->prefix . 'bcc_trust_endorsements';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning(
                    '[bcc-trust] dropped retired bcc_trust_endorsements table',
                    [
                        'table'        => $table,
                        'resurrection' => (bool) get_option('bcc_trust_endorsements_table_dropped'),
                    ]
                );
            }
        }

        update_option('bcc_trust_endorsements_table_dropped', time(), false);
        set_transient('bcc_trust_endorsements_drop_recheck', 1, HOUR_IN_SECONDS);
    }

    // Priority 28 — after drop-endorsement-bonus (27), so the drop-legacy
    // chain stays strictly ordered.
    add_action('init', 'bcc_trust_drop_endorsements_table', 28);
}
