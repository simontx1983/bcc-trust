<?php
/**
 * Drop the retired bcc_trust_reputation table (Architecture A, Slice 1c).
 *
 * A member's trust now lives on their self-page row in
 * bcc_trust_page_scores; the legacy per-user reputation table has been
 * fully relocated (seed-self-pages.php) and is no longer read or written.
 * dbDelta never DROPs, so this one-time guarded migration removes it.
 *
 * Ordering: fires on `init` priority 25 — AFTER the self-page seed
 * (priority 20), so the seed has already copied every reputation_score
 * onto the self-pages before the source table goes away. Guarded by the
 * `bcc_trust_reputation_dropped` option; no-op once done or already gone.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_legacy_reputation')) {
    function bcc_trust_drop_legacy_reputation(): void
    {
        if (get_option('bcc_trust_reputation_dropped')) {
            return;
        }

        // Safety interlock: never drop the source before the seed has run.
        if (!get_option('bcc_trust_self_pages_seeded')) {
            return;
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'bcc_trust_reputation';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($exists === $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] dropped retired bcc_trust_reputation table', []);
            }
        }

        update_option('bcc_trust_reputation_dropped', time(), false);
    }

    add_action('init', 'bcc_trust_drop_legacy_reputation', 25);
}
