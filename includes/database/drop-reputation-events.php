<?php
/**
 * Drop the orphaned bcc_reputation_events table (reputation cutover).
 *
 * The per-user reputation-event ledger lost its only writer when
 * ReputationCalculatorService::recalculateUserReputation was removed in
 * the Architecture A reputation cutover. The user view-model's "recent
 * trust changes" now reads the member's self-page rows from the LIVE
 * bcc_trust_score_events ledger instead, so this table is dead. dbDelta
 * never DROPs, so this one-time guarded migration removes it.
 *
 * No seed interlock is needed (nothing is relocated out of this table —
 * it was simply orphaned). Guarded by the `bcc_reputation_events_dropped`
 * option; no-op once done or already gone.
 *
 * Ordering: fires on `init` priority 25, matching the sibling
 * drop-legacy-reputation migration.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_reputation_events')) {
    function bcc_trust_drop_reputation_events(): void
    {
        if (get_option('bcc_reputation_events_dropped')) {
            return;
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'bcc_reputation_events';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($exists === $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] dropped orphaned bcc_reputation_events table', []);
            }
        }

        update_option('bcc_reputation_events_dropped', time(), false);
    }

    add_action('init', 'bcc_trust_drop_reputation_events', 25);
}
