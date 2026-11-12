<?php
/**
 * One-shot backfill: retire the ORIGINAL handle of every member who renamed
 * before renames retired the outgoing handle (F-280).
 *
 * Why: a rename used to overwrite bcc_handle and keep nothing, so the old
 * handle's /u/<old> links and @old mentions went 404, and the handle showed
 * as available while signup (which mints the login u_<handle>) answered 409.
 * Renames now record the outgoing handle as RETIRED
 * (HandleService::META_HANDLE_RETIRED). This gives the members who renamed
 * earlier the same treatment, for the one old handle that is still known:
 * the ORIGINAL one, which every signup path wrote into the login as
 * u_<handle> (AuthSupport::deriveLogin). Handles held in between (alice ->
 * bob -> carol loses bob) were only ever in the file-based audit log and are
 * not recoverable here.
 *
 * Per member whose login is u_<X> and whose current handle is not X:
 *   - X must still be a valid handle (HandleService::validate), and
 *   - nobody may hold X as their CURRENT handle now (they took it after the
 *     rename; it is theirs and its links go to them), and
 *   - nobody else may have retired X already.
 * Then X is recorded as this member's retired handle. The owner may later
 * reclaim it (HandleService::isAvailable); nobody else can take it.
 *
 * Runner conventions: bounded batches, keyset cursor on wp_users.ID (members
 * that are skipped keep matching, so the selection does not self-narrow), no
 * self-guard / self-complete. Status: DB error or a failed write ->
 * INCOMPLETE (retry next request); batch cap hit -> MORE; end of the table
 * -> COMPLETE.
 *
 * @package BCC_Trust
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_retire_renamed_original_handles')) {
    function bcc_trust_retire_renamed_original_handles(): string
    {
        global $wpdb;

        $batchSize     = 100;
        $maxIterations = 20;
        $handleService = new \BCC\Trust\Core\Services\HandleService();
        $metaHandle    = \BCC\Trust\Core\Services\HandleService::META_HANDLE;
        $metaRetired   = \BCC\Trust\Core\Services\HandleService::META_HANDLE_RETIRED;
        $loginLike     = $wpdb->esc_like('u_') . '%';

        $cursor  = 0;
        $retired = 0;
        for ($i = 0; $i < $maxIterations; $i++) {
            /** @var list<object{ID: string, user_login: string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID, u.user_login
                   FROM {$wpdb->users} u
                   INNER JOIN {$wpdb->usermeta} um
                           ON um.user_id = u.ID AND um.meta_key = %s
                  WHERE u.user_login LIKE %s
                    AND u.ID > %d
                    AND um.meta_value <> SUBSTRING(u.user_login, 3)
                    AND NOT EXISTS (
                        SELECT 1 FROM {$wpdb->usermeta} r
                         WHERE r.user_id = u.ID
                           AND r.meta_key = %s
                           AND r.meta_value = SUBSTRING(u.user_login, 3)
                    )
                  ORDER BY u.ID ASC
                  LIMIT %d",
                $metaHandle,
                $loginLike,
                $cursor,
                $metaRetired,
                $batchSize
            ));
            if ($wpdb->last_error !== '' || $rows === null) {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            if ($rows === []) {
                \BCC\Core\Log\Logger::info('[bcc-trust] renamed original handles retired', [
                    'count' => $retired,
                ]);
                return BCC_TRUST_MIGRATION_COMPLETE;
            }

            foreach ($rows as $row) {
                $userId = (int) $row->ID;
                $cursor = max($cursor, $userId);
                $result = bcc_trust_retire_one_renamed_original_handle(
                    $userId,
                    (string) $row->user_login,
                    $handleService
                );
                if ($result === null) {
                    return BCC_TRUST_MIGRATION_INCOMPLETE;
                }
                if ($result) {
                    $retired++;
                }
            }
        }

        return BCC_TRUST_MIGRATION_MORE;
    }

    /**
     * Retire one member's original handle. True = retired, false = skipped
     * (not a handle, or someone else holds or retired it), null = the
     * write failed (the caller stops and retries next run).
     */
    function bcc_trust_retire_one_renamed_original_handle(
        int $userId,
        string $userLogin,
        \BCC\Trust\Core\Services\HandleService $handleService
    ): ?bool {
        $original = strtolower(substr($userLogin, 2));
        if ($handleService->validate($original) !== null) {
            return false;
        }

        $holders = get_users([
            'meta_key'   => \BCC\Trust\Core\Services\HandleService::META_HANDLE,
            'meta_value' => $original,
            'fields'     => 'ID',
            'number'     => 1,
        ]);
        if ($holders !== []) {
            return false;
        }

        $retiredBy = \BCC\Trust\Core\Services\HandleService::retiredOwner($original);
        if ($retiredBy !== null) {
            // Already retired, by anyone. The selection's NOT EXISTS skips
            // this member's own row up front; this check is what keeps a
            // re-run from writing a duplicate either way.
            return false;
        }

        if (add_user_meta($userId, \BCC\Trust\Core\Services\HandleService::META_HANDLE_RETIRED, $original, false) === false) {
            return null;
        }

        return true;
    }
}
