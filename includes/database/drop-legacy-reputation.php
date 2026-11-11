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

if (!function_exists('bcc_trust_count_unreconciled_reputation')) {
    /**
     * How many legacy reputation rows have NOT been relocated onto their
     * member self-page (F-148 drop precondition).
     *
     * Returns null when the answer is unknown: the scoring constants or the
     * self-page id service are unavailable, or the query itself failed.
     * Callers must treat null exactly like a positive count — never drop on
     * an unknown.
     */
    function bcc_trust_count_unreconciled_reputation(string $legacyTable): ?int
    {
        if (!defined('BCC_TRUST_NEUTRAL_SCORE')
            || !class_exists('\\BCC\\Trust\\Core\\Services\\MemberSelfPageService')
        ) {
            return null;
        }

        global $wpdb;
        $scoresTable = $wpdb->prefix . 'bcc_trust_page_scores';
        // selfPageId(u) = ID_BASE + u; derive the base through the public
        // API rather than restating the constant here.
        $selfPageBase = \BCC\Trust\Core\Services\MemberSelfPageService::selfPageId(1) - 1;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$legacyTable} r
               LEFT JOIN {$scoresTable} s
                      ON s.page_id = %d + r.user_id
                     AND s.category_id = 0
              WHERE r.user_id > 0
                AND (
                      s.page_id IS NULL
                   OR (    s.vote_count = 0
                       AND s.positive_score = 0
                       AND s.negative_score = 0
                       AND s.contribution_bonus = 0
                       AND s.penalty_adjustment = 0
                       AND ABS(r.reputation_score - %f) > 0.0001)
                )",
            $selfPageBase,
            (float) BCC_TRUST_NEUTRAL_SCORE
        ));

        if ($count === null || $wpdb->last_error !== '') {
            return null;
        }

        return (int) $count;
    }
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

        // A refused drop re-checks at most hourly, so an environment that
        // cannot reconcile is not charged the reconciliation scan on every
        // request.
        if (get_transient('bcc_trust_reputation_drop_refused')) {
            return;
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'bcc_trust_reputation';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === null && $wpdb->last_error !== '') {
            return; // Probe failed — unknown, not "gone". Retry next request.
        }

        if ($exists === $table) {
            // F-148: the seed stamp alone is NOT proof the data moved — it
            // was historically written even after a failed read. Before the
            // source table goes, prove every legacy member has a self-page
            // that carries their score. Unreconciled = no self-page row, OR
            // a self-page still pristine (the exact predicate
            // ScoreRepository::seedSelfPageFromReputation writes through)
            // while the legacy score is not neutral. A neutral (50) score
            // seeds to an all-zero row, so pristine-and-neutral counts as
            // reconciled.
            $unreconciled = bcc_trust_count_unreconciled_reputation($table);
            if ($unreconciled !== 0) {
                set_transient('bcc_trust_reputation_drop_refused', 1, HOUR_IN_SECONDS);
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] refusing to drop bcc_trust_reputation: legacy scores not reconciled onto self-pages', [
                        'unreconciled' => $unreconciled, // null = the check itself failed
                        'db_error'     => $wpdb->last_error,
                    ]);
                }
                return;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("DROP TABLE IF EXISTS {$table}") === false) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] drop of bcc_trust_reputation failed; not marking done', [
                        'db_error' => $wpdb->last_error,
                    ]);
                }
                return;
            }
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] dropped retired bcc_trust_reputation table', []);
            }
        }

        update_option('bcc_trust_reputation_dropped', time(), false);
    }

    add_action('init', 'bcc_trust_drop_legacy_reputation', 25);
}
