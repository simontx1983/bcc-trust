<?php
/**
 * Create the covering indexes BCC's reaction-aggregation queries depend on.
 *
 * These live on PeepSo's own tables (peepso_reactions, peepso_activities) —
 * risky territory, but every index is additive and pre-checked, so this is
 * non-destructive. They unblock realistic read load on `solids_received`
 * and per-time-window reaction queries.
 *
 * Moved out of ReactionSeeder::ensureIndexes() (§1 remediation: DDL belongs
 * in includes/database/, not a service). Behaviour preserved byte-for-byte:
 * the same three indexes, the same SHOW INDEX pre-check, the same CREATE
 * INDEX statements. Idempotent and per-index existence-checked; guarded by
 * the `bcc_peepso_reaction_indexes_v1` option so it runs at most once.
 *
 * If PeepSo drops these on upgrade, bumping the option-flag suffix (and the
 * matching constant below) re-creates them.
 *
 * @since §1 wpdb remediation (2026-06-28)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_create_peepso_reaction_indexes')) {
    /**
     * @param string $table     Fully-qualified table name (prefix + suffix).
     * @param string $indexName Fixed literal index name (no user input).
     * @param string $columns   Fixed literal column list (no user input).
     */
    function bcc_trust_create_peepso_reaction_index_if_missing(string $table, string $indexName, string $columns): void
    {
        global $wpdb;

        // SHOW INDEX FROM is the canonical pre-check. Table name comes from
        // $wpdb->prefix + a hard-coded suffix — no user input, safe to
        // interpolate.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
            $indexName
        ));

        if (!empty($existing)) {
            return;
        }

        // CREATE INDEX doesn't accept prepared placeholders for the
        // identifier; columns are hard-coded by the caller. No user input.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query("CREATE INDEX `{$indexName}` ON `{$table}` ({$columns})");

        if ($result === false) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] failed to create PeepSo reaction index', [
                    'table' => $table,
                    'index' => $indexName,
                    'error' => $wpdb->last_error,
                ]);
            }
            return;
        }

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::info('[bcc-trust] PeepSo reaction index created', [
                'table' => $table,
                'index' => $indexName,
            ]);
        }
    }

    function bcc_trust_create_peepso_reaction_indexes(): void
    {
        if (get_option('bcc_peepso_reaction_indexes_v1') === '1') {
            return;
        }

        global $wpdb;

        // peepso_reactions covering index for "solids given by user"
        // — covers WHERE reaction_user_id = ? AND reaction_type = ?
        bcc_trust_create_peepso_reaction_index_if_missing(
            $wpdb->prefix . 'peepso_reactions',
            'idx_bcc_reactions_user_type',
            'reaction_user_id, reaction_type'
        );

        // peepso_reactions covering index for "solids received since"
        // — covers JOIN by reaction_act_id + filter on reaction_type
        // + reaction_timestamp boundary.
        bcc_trust_create_peepso_reaction_index_if_missing(
            $wpdb->prefix . 'peepso_reactions',
            'idx_bcc_reactions_act_type_time',
            'reaction_act_id, reaction_type, reaction_timestamp'
        );

        // peepso_activities — speeds up "received" query's JOIN target
        // (the act_owner_id filter). PeepSo's default schema has indexes
        // on act_user_id but not always act_owner_id; covering this
        // explicitly avoids the table scan when filtering by content owner.
        bcc_trust_create_peepso_reaction_index_if_missing(
            $wpdb->prefix . 'peepso_activities',
            'idx_bcc_activities_owner',
            'act_owner_id'
        );

        update_option('bcc_peepso_reaction_indexes_v1', '1', false);
    }

    // Priority 28 — after the other init-time DB migrations (25–27) so the
    // PeepSo tables are present and the seeder's own init wiring has settled.
    add_action('init', 'bcc_trust_create_peepso_reaction_indexes', 28);
}
