<?php
/**
 * One-shot: fold the discovery opt-out into hide_from_search (F-257, option A).
 *
 * Why: "hidden from member search" was two settings with one meaning.
 * `bcc_privacy_discovery_optout` (GET/PATCH /me/privacy) and PeepSo's
 * `peepso_is_hide_profile_from_user_listing` (`hide_from_search` on
 * /me/profile-prefs) were stored apart and could disagree. PeepSo's key is
 * now the single source of truth: /me/privacy's `discovery_optout` reads and
 * writes it (PrivacySettings::KEY_ALIASES) and search excludes on it alone.
 *
 * This copies every opt-out that is ON into the one flag, so nobody hidden
 * today becomes visible:
 *   - only TURNS ON hide_from_search (sets '1'); it never turns one off, and
 *     a member whose hide_from_search is already '1' is not selected.
 *     "Already on" is exactly '1', what the search clause excludes on
 *     (`value != '1'` lists the member): a hand-edited 'true' / 'on' would
 *     read as on to PrivacySettings::flag() but LIST the member, so it is
 *     normalised to '1' rather than skipped (review, Low 2);
 *   - leaves the legacy `bcc_privacy_discovery_optout` rows untouched
 *     (nothing reads them once this is done; deleting them buys nothing and
 *     would make a code rollback less safe).
 *
 * Until this returns COMPLETE (the runner then sets
 * PrivacySettings::FOLD_DONE_OPTION), search keeps honouring the legacy key
 * as well, so the copy cannot open a window where someone is listed.
 *
 * "ON" is what PrivacySettings::flag() reads as true: FILTER_VALIDATE_BOOLEAN,
 * i.e. '1', 'true', 'on', 'yes' (any case; the column collation is
 * case-insensitive). The writer only ever stored '1' / '0'.
 *
 * Runner conventions: bounded batches, self-narrowing selection (a folded
 * member stops matching), no self-guard / self-complete. Status: DB error or
 * a failed write -> INCOMPLETE (retry next request); batch cap hit -> MORE;
 * nothing left -> COMPLETE.
 *
 * @package BCC_Trust
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_fold_discovery_optout')) {
    function bcc_trust_fold_discovery_optout(): string
    {
        global $wpdb;

        $batchSize     = 200;
        $maxIterations = 20;
        $legacy        = \BCC\Trust\Core\Support\PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META;
        $hide          = \BCC\Trust\Core\Support\PrivacySettings::HIDE_FROM_LISTING_META;

        for ($i = 0; $i < $maxIterations; $i++) {
            /** @var list<string> $userIds */
            $userIds = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT o.user_id
                   FROM {$wpdb->usermeta} o
                  WHERE o.meta_key = %s
                    AND o.meta_value IN ('1', 'true', 'on', 'yes')
                    AND NOT EXISTS (
                        SELECT 1 FROM {$wpdb->usermeta} h
                         WHERE h.user_id = o.user_id
                           AND h.meta_key = %s
                           AND h.meta_value = '1'
                    )
                  ORDER BY o.user_id ASC
                  LIMIT %d",
                $legacy,
                $hide,
                $batchSize
            ));
            // get_col() never returns null: a failed read is [] with
            // last_error set, so the error is what tells them apart.
            if ($wpdb->last_error !== '') {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            if ($userIds === []) {
                return BCC_TRUST_MIGRATION_COMPLETE;
            }

            foreach ($userIds as $userId) {
                $ok = \BCC\Trust\Core\Repositories\DbWriteCheck::run(
                    static fn () => update_user_meta((int) $userId, $hide, '1')
                );
                if (!$ok) {
                    return BCC_TRUST_MIGRATION_INCOMPLETE;
                }
            }

            \BCC\Core\Log\Logger::info('[bcc-trust] discovery opt-out folded into hide_from_search', [
                'count' => count($userIds),
            ]);
        }

        return BCC_TRUST_MIGRATION_MORE;
    }
}
