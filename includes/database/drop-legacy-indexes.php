<?php
/**
 * Trim redundant duplicate indexes on hot tables (Phase 5 DB cleanup).
 *
 * dbDelta ADDS indexes but never DROPS them, so long-lived DBs accumulate
 * exact-duplicate / fully-covered indexes that the current CREATE no longer
 * declares — pure write-amplification with zero read benefit. This one-time
 * guarded migration drops the three provably-redundant ones:
 *
 *   - wp_bcc_trust_votes.idx_voter_created   — EXACT dup of declared
 *     idx_voter_history (voter_user_id, created_at).
 *   - wp_bcc_trust_activity.idx_ip_created   — EXACT dup of declared
 *     idx_ip_lookup (ip_address, created_at).
 *   - wp_bcc_trust_user_info.fraud_score     — single-col, fully covered by
 *     the leftmost prefix of idx_fraud_risk (fraud_score, risk_level); its
 *     declaration was removed from schema-user-info.php in the same change.
 *
 * Fresh installs never create these (not in the current CREATE statements),
 * so this only reconciles existing DBs. Each drop is existence-checked and
 * idempotent; guarded by `bcc_trust_legacy_indexes_trimmed`. Indexes carry no
 * data, so this is non-destructive (an index can be re-added if ever needed).
 *
 * @since Phase 5 DB cleanup (2026-06-25)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_legacy_indexes')) {
    function bcc_trust_drop_legacy_indexes(): void
    {
        if (get_option('bcc_trust_legacy_indexes_trimmed')) {
            return;
        }

        global $wpdb;

        // [bare table suffix, index name] — the redundant index on each.
        $targets = [
            ['bcc_trust_votes', 'idx_voter_created'],
            ['bcc_trust_activity', 'idx_ip_created'],
            ['bcc_trust_user_info', 'fraud_score'],
        ];

        $dropped = [];
        foreach ($targets as [$suffix, $index]) {
            $table = $wpdb->prefix . $suffix;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
                 LIMIT 1",
                $table,
                $index
            ));
            if ($exists !== '1') {
                continue;
            }

            // Index name is a fixed literal from the list above (no user input);
            // identifiers can't be bound placeholders.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$table} DROP INDEX `{$index}`");
            $dropped[] = "{$suffix}.{$index}";
        }

        if ($dropped !== [] && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] trimmed redundant duplicate indexes (Phase 5)',
                ['count' => count($dropped), 'indexes' => $dropped]
            );
        }

        update_option('bcc_trust_legacy_indexes_trimmed', time(), false);
    }

    add_action('init', 'bcc_trust_drop_legacy_indexes', 27);
}
