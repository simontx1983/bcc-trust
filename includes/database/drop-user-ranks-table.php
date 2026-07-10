<?php
/**
 * Drop the retired `bcc_user_ranks` table (Option B ranking-system slice).
 *
 * The conferred-rank / Foreman-Role scaffolding this table backed was
 * never built: the table held zero rows, had zero write callers, and its
 * only read (RankService viewer-block + the /members rank filter) surfaced
 * always-`false` flags (`foreman_insignia` / `is_admin_conferred`) that no
 * frontend consumed. Rank is fully auto-derived from feature-access level
 * (RankService::rankForLevel), so nothing reads this table anymore.
 * UserRankRepository, the schema, and the view-model fields were removed in
 * the same release that ships this drop; dbDelta never DROPs, so this
 * one-time guarded migration removes the dead table.
 *
 * Idempotent AND resurrection-proof: the one-shot option is only a
 * fast-path — once set, the existence probe still re-runs hourly (transient
 * gated). A purely one-shot guard let a table come back permanently once (a
 * parallel checkout on a pre-retirement branch re-ran dbDelta), so the
 * transient gate catches a resurrected table on the next init rather than
 * letting it become permanent drift. Runs on init after
 * drop-onchain-role-boost-columns (29).
 *
 * @since Option B ranking-system slice (2026-07-09)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_drop_user_ranks_table')) {

    function bcc_trust_drop_user_ranks_table(): void
    {
        // Fast path: already dropped AND re-verified within the last hour.
        // The transient (not the option alone) gates the skip so a
        // resurrected table is caught by the next hourly probe instead of
        // becoming permanent drift.
        if (get_option('bcc_trust_user_ranks_table_dropped')
            && get_transient('bcc_trust_user_ranks_drop_recheck')
        ) {
            return;
        }

        global $wpdb;

        $table  = $wpdb->prefix . 'bcc_user_ranks';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning(
                    '[bcc-trust] dropped retired bcc_user_ranks table',
                    [
                        'table'        => $table,
                        'resurrection' => (bool) get_option('bcc_trust_user_ranks_table_dropped'),
                    ]
                );
            }
        }

        update_option('bcc_trust_user_ranks_table_dropped', time(), false);
        set_transient('bcc_trust_user_ranks_drop_recheck', 1, HOUR_IN_SECONDS);
    }

    // Priority 30 — after drop-onchain-role-boost-columns (29), keeping the
    // drop-legacy chain strictly ordered.
    add_action('init', 'bcc_trust_drop_user_ranks_table', 30);
}
