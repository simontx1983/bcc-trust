<?php
/**
 * Drop the 17 verified legacy orphan tables (Phase 5 DB cleanup).
 *
 * These tables are live in older DBs but have NO owning code — pre-self-page
 * "project_*"/"page_*" design leftovers, retired ledgers (user_locals,
 * page_claims, wallet_signals, trust_eligibility, trust_user_risk), the dead
 * endorsement-type catalogs, and the never-wired onchain dao_stats/treasury.
 * Full inventory + rationale: docs/database-schema.md (orphan section).
 * dbDelta never DROPs, so this one-time guarded migration removes them.
 *
 * FK-AWARE ORDERING (proven by the Phase 3 restore drill — a naive DROP fails):
 * the ACTIVE wp_bcc_trust_endorsements holds `fk_endorsement_type` INTO the
 * orphan endorsement_types, so the foreign key is dropped FIRST. The project_*
 * orphans carry outbound FKs to wp_posts that drop with the child table.
 *
 * Guarded by `bcc_trust_legacy_orphans_dropped` — idempotent, no-op once done
 * or already gone. On a FRESH deploy DB it runs automatically; the dev DB was
 * intentionally pre-marked done at staging time so the local orphans are
 * preserved (to run it on a dev box: delete that option and load any page).
 *
 * @since Phase 5 DB cleanup (2026-06-25)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_legacy_orphans')) {
    /** Bare table suffixes (without $wpdb->prefix) — the 17 verified orphans. */
    function bcc_trust_legacy_orphan_suffixes(): array
    {
        return [
            'bcc_user_locals',
            'bcc_page_claims',
            'bcc_wallet_signals',
            'bcc_trust_eligibility',
            'bcc_trust_user_risk',
            'bcc_endorsement_types',
            'bcc_trust_endorsement_types',
            'bcc_project_identities',
            'bcc_project_scores',
            'bcc_project_metrics_history',
            'bcc_project_verifications',
            'bcc_trust_page_composites',
            'bcc_trust_page_identities',
            'bcc_trust_page_metrics',
            'bcc_trust_page_verifications',
            'bcc_onchain_dao_stats',
            'bcc_onchain_treasury',
        ];
    }

    function bcc_trust_drop_legacy_orphans(): void
    {
        if (get_option('bcc_trust_legacy_orphans_dropped')) {
            return;
        }

        global $wpdb;

        // Step 1: drop the FK the ACTIVE endorsements table holds into the
        // orphan type-catalog, or the DROP TABLE below fails (errno 3730).
        $endorsements = $wpdb->prefix . 'bcc_trust_endorsements';
        $fk = $wpdb->get_var($wpdb->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = 'fk_endorsement_type'",
            $endorsements
        ));
        if ($fk === 'fk_endorsement_type') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$endorsements} DROP FOREIGN KEY fk_endorsement_type");
        }

        // Step 2: drop each orphan (guarded; idempotent).
        $dropped = [];
        foreach (bcc_trust_legacy_orphan_suffixes() as $suffix) {
            $table  = $wpdb->prefix . $suffix;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("DROP TABLE IF EXISTS {$table}");
                $dropped[] = $suffix;
            }
        }

        if ($dropped !== [] && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] dropped legacy orphan tables (Phase 5)',
                ['count' => count($dropped), 'tables' => $dropped]
            );
        }

        update_option('bcc_trust_legacy_orphans_dropped', time(), false);
    }

    // Priority 26 — after the reputation drop (25); these orphans are
    // independent, so exact ordering only matters relative to other migrations.
    add_action('init', 'bcc_trust_drop_legacy_orphans', 26);
}
