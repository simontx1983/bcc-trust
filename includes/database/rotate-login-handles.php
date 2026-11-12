<?php
/**
 * One-shot rotation of public handles that equal the member's login.
 *
 * Why: the original canonical-handle backfill derived handles for legacy
 * accounts from display_name, and for wp-admin / WP-CLI accounts
 * WordPress sets display_name to user_login. So some members' public
 * @handle IS their login, and /u/<login> shows it to anyone.
 *
 * Also picks up a squatted fallback: a 'member-N' handle whose N is not
 * the member's own id (chosen before HandleService reserved that form).
 * Those are re-derived the same way but NOT retired (step 2): member-N is
 * member N's own reserved fallback, so it gets no redirect.
 *
 * Per member whose bcc_handle equals user_login (case-insensitive):
 *   1. new base = bcc_trust_bch_derive_base(): the display-name slug
 *      unless that is (or normalises to) the login, else 'member-{id}';
 *      then the first free variant through HandleService.
 *   2. record the old handle as a RETIRED handle
 *      (HandleService::META_HANDLE_RETIRED). UserSlugResolver and the
 *      member-card resolver map an exact retired handle back to its
 *      member, so old /u/<old> links keep working (the client redirects
 *      to the current handle). HandleService::isAvailable() never hands
 *      a retired handle out again.
 *   3. write the new handle, and fire bcc_handle_changed (the same
 *      action a user rename fires; nothing listens to it today, it is fired
 *      for parity so a future listener sees rotations too). A failed
 *      handle write counts as skipped and is retried.
 *      The rename cooldown is NOT armed: the member didn't choose this.
 *
 * Logs a dry-run count of matching members before writing anything.
 *
 * Runner conventions: bounded batches, self-narrowing selection (a
 * rotated row stops matching), no self-guard / self-complete.
 * Status: DB error or an unassignable member -> INCOMPLETE (retry next
 * request); batch cap hit -> MORE; nothing left -> COMPLETE.
 *
 * @package BCC_Trust
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_rotate_login_handles')) {

    function bcc_trust_rotate_login_handles(): string
    {
        global $wpdb;
        $batchSize     = 100;
        $maxIterations = 20;
        $handleService = new \BCC\Trust\Core\Services\HandleService();
        $metaHandle    = \BCC\Trust\Core\Services\HandleService::META_HANDLE;

        // Who to rotate: a handle equal to the login (case-insensitive via
        // the column collation), OR a squatted fallback: a 'member-N'
        // handle whose N is not this member's own id, chosen before
        // HandleService reserved that form. A member's OWN member-{id}
        // never matches, so the selection still self-narrows. No '%'
        // literals here: this is interpolated into prepare() templates.
        $match = "um.meta_value = u.user_login
                  OR (um.meta_value REGEXP '^member-[0-9]+$'
                      AND um.meta_value <> CONCAT('member-', u.ID))";

        // Dry-run count, logged before any write.
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$wpdb->users} u
               INNER JOIN {$wpdb->usermeta} um
                       ON um.user_id = u.ID AND um.meta_key = %s
              WHERE {$match}",
            $metaHandle
        ));
        if ($wpdb->last_error !== '') {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }
        \BCC\Core\Log\Logger::info('[bcc-trust] login-handle rotation: members to rotate', [
            'count' => (int) $count,
        ]);

        /** @var list<int> $skipped */
        $skipped = [];

        for ($i = 0; $i < $maxIterations; $i++) {
            $notIn = $skipped === []
                ? ''
                : ' AND u.ID NOT IN (' . implode(',', array_map('intval', $skipped)) . ')';

            /** @var list<object{ID: string, handle: string, user_login: string, display_name: string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID, um.meta_value AS handle, u.user_login, u.display_name
                   FROM {$wpdb->users} u
                   INNER JOIN {$wpdb->usermeta} um
                           ON um.user_id = u.ID AND um.meta_key = %s
                  WHERE ({$match}){$notIn}
                  ORDER BY u.ID ASC
                  LIMIT %d",
                $metaHandle,
                $batchSize
            ));
            if ($wpdb->last_error !== '') {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            if ($rows === null || $rows === []) {
                return $skipped === [] ? BCC_TRUST_MIGRATION_COMPLETE : BCC_TRUST_MIGRATION_INCOMPLETE;
            }

            foreach ($rows as $row) {
                $userId = (int) $row->ID;
                $old    = strtolower(trim((string) $row->handle));

                $new = bcc_trust_rotate_one_login_handle(
                    $userId,
                    $old,
                    (string) $row->display_name,
                    (string) $row->user_login,
                    $handleService
                );
                if ($new === null) {
                    $skipped[] = $userId;
                }
            }
            // Rotated rows no longer match and skipped ones are excluded,
            // so the next query returns only the remainder.
        }

        return BCC_TRUST_MIGRATION_MORE;
    }

    /**
     * Rotate one member's login-equal handle. Returns the new handle, or
     * null when no valid handle could be found (caller retries later).
     */
    function bcc_trust_rotate_one_login_handle(
        int $userId,
        string $oldHandle,
        string $displayName,
        string $userLogin,
        \BCC\Trust\Core\Services\HandleService $handleService
    ): ?string {
        $base = bcc_trust_bch_derive_base($displayName, $userLogin, $userId);
        // $userId lets the internal path write this member's own reserved
        // 'member-{id}' fallback (HandleService::validate).
        $new  = bcc_trust_bch_find_free_variant($base, $handleService, $userId);
        if ($new === null) {
            $new = bcc_trust_bch_find_free_variant(
                \BCC\Trust\Core\Support\PublicIdentity::HANDLE_PREFIX . $userId,
                $handleService,
                $userId
            );
        }
        if ($new === null || strcasecmp($new, $userLogin) === 0) {
            return null;
        }

        // Retire first, so the old handle is reserved before the member
        // stops holding it (no window where someone else could take it).
        // Only a LOGIN-equal handle is retired. A squatted 'member-N' is
        // not: it is member N's own fallback form, already reserved by
        // HandleService::validate(), and must stay assignable to member N.
        // So no redirect for it either.
        // Idempotent across retries: a retired row already recorded for
        // this member (an earlier attempt whose handle write failed) is
        // not written again.
        if (
            $oldHandle !== ''
            && strcasecmp($oldHandle, trim($userLogin)) === 0
            && \BCC\Trust\Core\Services\HandleService::retiredOwner($oldHandle) !== $userId
        ) {
            add_user_meta($userId, \BCC\Trust\Core\Services\HandleService::META_HANDLE_RETIRED, $oldHandle, false);
        }

        // A failed handle write counts as skipped: no bcc_handle_changed,
        // and the caller retries this member on the next run. The retired
        // row (if any) stays; the check above stops a duplicate.
        if (update_user_meta($userId, \BCC\Trust\Core\Services\HandleService::META_HANDLE, $new) === false) {
            return null;
        }

        do_action('bcc_handle_changed', $userId, $oldHandle, $new);

        \BCC\Core\Log\Logger::info('[bcc-trust] login-handle rotated', [
            'user_id' => $userId,
            'handle'  => $new,
        ]);

        return $new;
    }
}
