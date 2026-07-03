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
 * Idempotent: guarded by the `bcc_trust_endorsements_table_dropped` option
 * AND DROP TABLE IF EXISTS, so it's a clean no-op once done or on a fresh
 * install (schema-core no longer creates the table). Runs on init after
 * drop-endorsement-bonus (27).
 *
 * @since Endorse retirement — reads-to-attestations slice (2026-07-02)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_endorsements_table')) {

    function bcc_trust_drop_endorsements_table(): void
    {
        if (get_option('bcc_trust_endorsements_table_dropped')) {
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
                    ['table' => $table]
                );
            }
        }

        update_option('bcc_trust_endorsements_table_dropped', time(), false);
    }

    // Priority 28 — after drop-endorsement-bonus (27), so the drop-legacy
    // chain stays strictly ordered.
    add_action('init', 'bcc_trust_drop_endorsements_table', 28);
}
