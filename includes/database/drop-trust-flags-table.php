<?php
/**
 * Drop the retired `bcc_trust_flags` table.
 *
 * `bcc_trust_flags` was the legacy vote-flag primitive that predated the
 * panel-adjudication dispute system (`bcc_disputes`). Its only writer was
 * the deleted `report_vote` REST route, so the table was write-dead —
 * every reader (the profile/entity Disputes tabs, the `disputes_signed`
 * counts) returned nothing. Those readers were repointed at the live
 * `bcc_disputes` table (reporter-keyed) 2026-07-08; FlagsRepository plus
 * CardDisputesService/CardDisputesEndpoint were deleted in the same
 * change. The table is now fully orphaned.
 *
 * Idempotent AND resurrection-safe: guarded by a fast-path option plus an
 * hourly transient re-check (a stale checkout re-running the old
 * schema-ensure could re-create the table; the transient gate catches
 * that on the next init rather than letting it become permanent drift —
 * same pattern as drop-onchain-role-boost-columns.php). DROP TABLE IF
 * EXISTS is a clean no-op on fresh installs (the CREATE was removed from
 * schema-core.php in the same change).
 *
 * @since 2026-07-08 — disputes reconciliation (bcc_trust_flags retired)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_trust_flags_table')) {

    function bcc_trust_drop_trust_flags_table(): void
    {
        // Fast path: already dropped AND re-verified within the last hour.
        if (get_option('bcc_trust_flags_table_dropped')
            && get_transient('bcc_trust_flags_drop_recheck')
        ) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'bcc_trust_flags';

        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($tableExists === $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");

            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning(
                    '[bcc-trust] dropped retired bcc_trust_flags table',
                    ['table' => $table]
                );
            }
        }

        update_option('bcc_trust_flags_table_dropped', time(), false);
        set_transient('bcc_trust_flags_drop_recheck', 1, HOUR_IN_SECONDS);
    }

    // Priority 30 — after drop-onchain-role-boost-columns (29), keeping
    // the drop-legacy chain strictly ordered.
    add_action('init', 'bcc_trust_drop_trust_flags_table', 30);
}
