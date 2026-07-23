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
 * idempotent. Indexes carry no data, so this is non-destructive (an index
 * can be re-added if ever needed).
 *
 * v2 (2026-07-23, schema-drift reconcile): re-keyed the guard option to
 * `bcc_trust_legacy_indexes_trimmed_v2` — the v1 option had been PRE-SET to
 * 'dev-staged-2026-06-25' on at least one environment without the drops ever
 * running, so the migration no-op'd forever while the indexes survived (the
 * schema-drift guard caught all three still live). Lesson: never hand-set a
 * migration guard option to "stage" it. v2 also extends the target list:
 *
 *   - wp_bcc_dispute_participations.idx_user_credited_created /
 *     idx_user_credited_outcome — the schema docblock DELIBERATELY retired
 *     the was_credited column from these composites (leaner writes; declared
 *     replacements idx_user_created / idx_user_outcome); dbDelta added the
 *     new generation but never dropped the old.
 *   - wp_bcc_user_reports.uq_reporter_reported / uq_reporter_reported_reason
 *     — BUG FIX, not just hygiene: these pre-M1 UNIQUEs are status-blind,
 *     but UserReportRepository::createReport's dupe check is deliberately
 *     status-filtered (`status IN ('open','reviewing')`) — re-reporting a
 *     user after a prior report was RESOLVED is allowed by the application
 *     and blocked only by these stale constraints (insert fails →
 *     'insert_failed' rollback). The declared plain KEY idx_reporter_reported
 *     stays and serves the dupe-check query.
 *   - wp_bcc_onchain_collections.wallet_chain_contract — redundant UNIQUE
 *     (wallet_link_id, chain_id, contract_address): collections are global
 *     per (chain, contract) and the declared uq_chain_contract is strictly
 *     tighter, so this one can never be the deciding collision for the
 *     ON DUPLICATE KEY upserts; pre-"collections are global" leftover.
 *
 * @since Phase 5 DB cleanup (2026-06-25); v2 2026-07-23
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_legacy_indexes')) {
    function bcc_trust_drop_legacy_indexes(): void
    {
        if (get_option('bcc_trust_legacy_indexes_trimmed_v2')) {
            return;
        }

        global $wpdb;

        // [bare table suffix, index name] — the redundant index on each.
        // v1 targets stay in the list (existence-checked, so already-clean
        // environments skip them at zero cost).
        $targets = [
            ['bcc_trust_votes', 'idx_voter_created'],
            ['bcc_trust_activity', 'idx_ip_created'],
            ['bcc_trust_user_info', 'fraud_score'],
            ['bcc_dispute_participations', 'idx_user_credited_created'],
            ['bcc_dispute_participations', 'idx_user_credited_outcome'],
            ['bcc_user_reports', 'uq_reporter_reported'],
            ['bcc_user_reports', 'uq_reporter_reported_reason'],
            ['bcc_onchain_collections', 'wallet_chain_contract'],
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
                '[bcc-trust] trimmed redundant duplicate indexes (schema reconcile v2)',
                ['count' => count($dropped), 'indexes' => $dropped]
            );
        }

        update_option('bcc_trust_legacy_indexes_trimmed_v2', time(), false);
        // Retire the burned v1 marker so nobody mistakes it for a completed run.
        delete_option('bcc_trust_legacy_indexes_trimmed');
    }

    add_action('init', 'bcc_trust_drop_legacy_indexes', 27);
}
