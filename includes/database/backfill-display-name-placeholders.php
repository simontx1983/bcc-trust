<?php
/**
 * Display-name hygiene backfill (owner-directed 2026-08-06).
 *
 * Public display names must never carry non-public identity. Two leak
 * classes exist in real rows (neither produced by BCC signup code,
 * which always falls back to the user-chosen handle):
 *
 *   1. EMAIL-shaped — WP-native / PeepSo-native account creation
 *      defaults display_name to user_login, which for wp-admin-created
 *      accounts is the email (observed: user #1 on staging AND prod
 *      rendering a full email address in member search).
 *   2. INTERNAL-LOGIN-shaped — the §B3 `u_<handle>` login surfacing
 *      publicly (observed: one row locally).
 *
 * Replacement is a NEUTRAL placeholder: the user's §B6 bcc_handle when
 * one exists (public, self-chosen), else `Member <ID>`. The
 * completeness gate (QuestValidator::displayNameLooksChosen) then
 * nudges `Member <ID>` holders to pick a real name — placeholders are
 * deliberately detectable.
 *
 * Also rewrites the denormalized copy in bcc_user_info (UserSyncService
 * mirrors wp_users.display_name there; leaving it stale would keep the
 * email in the admin/user-search read model).
 *
 * Runner conventions honored: bounded batches, self-narrowing
 * selection (updated rows stop matching), no self-guard/self-complete,
 * direct $wpdb writes (wp_update_user fires notification emails — the
 * wallet-placeholder backfill set this precedent).
 *
 * Status contract: DB error → INCOMPLETE (retry next request); zero
 * matching rows remaining → COMPLETE.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since 2026-08-06 (display-name hygiene)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_backfill_display_name_placeholders')) {

    function bcc_trust_backfill_display_name_placeholders(): string
    {
        global $wpdb;

        $batchSize     = 200;
        $maxIterations = 25;

        // Leak predicate. The LIKE patterns are BOUND as %s values —
        // NEVER written literally in the prepare() template: wpdb
        // replaces literal '%' in templates with its placeholder-escape
        // sentinel, which silently turns the pattern into a
        // match-nothing string (the v1 run of this migration completed
        // against zero rows exactly that way). Bound values keep their
        // wildcards — the same idiom every search repository here uses.
        //   display_name containing '@' (email-shaped), or
        //   display_name = user_email, or
        //   display_name starting 'u_' literally (internal login shape;
        //   esc_like keeps the underscore literal).
        $emailPattern = '%' . $wpdb->esc_like('@') . '%';
        $loginPattern = $wpdb->esc_like('u_') . '%';

        for ($i = 0; $i < $maxIterations; $i++) {
            /** @var list<object{ID: string, handle: string|null}> $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID, um.meta_value AS handle
                   FROM {$wpdb->users} u
                   LEFT JOIN {$wpdb->usermeta} um
                          ON um.user_id = u.ID AND um.meta_key = %s
                  WHERE (u.display_name LIKE %s
                         OR u.display_name = u.user_email
                         OR u.display_name LIKE %s)
                  LIMIT %d",
                'bcc_handle',
                $emailPattern,
                $loginPattern,
                $batchSize
            ));
            if ($wpdb->last_error !== '') {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            if ($rows === null || $rows === []) {
                return BCC_TRUST_MIGRATION_COMPLETE;
            }

            foreach ($rows as $row) {
                $userId = (int) $row->ID;
                $handle = is_string($row->handle) ? trim($row->handle) : '';

                // Neutral placeholder: the public self-chosen handle
                // when available, else Member <ID> (which the
                // completeness gate treats as not-yet-chosen).
                $placeholder = $handle !== '' && !str_contains($handle, '@')
                    ? $handle
                    : 'Member ' . $userId;

                $wpdb->update(
                    $wpdb->users,
                    ['display_name' => $placeholder],
                    ['ID' => $userId],
                    ['%s'],
                    ['%d']
                );
                if ($wpdb->last_error !== '') {
                    return BCC_TRUST_MIGRATION_INCOMPLETE;
                }

                // Keep the denormalized read-model copy honest.
                $infoTable = $wpdb->prefix . 'bcc_user_info';
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$infoTable} SET display_name = %s WHERE user_id = %d",
                    $placeholder,
                    $userId
                ));
                // bcc_user_info may not exist on minimal installs; a
                // table-missing error must not wedge the migration.
                if ($wpdb->last_error !== '' && stripos($wpdb->last_error, "doesn't exist") === false) {
                    return BCC_TRUST_MIGRATION_INCOMPLETE;
                }
            }

            if (count($rows) < $batchSize) {
                return BCC_TRUST_MIGRATION_COMPLETE;
            }
        }

        // Batch cap hit with rows possibly remaining — resume next request.
        return BCC_TRUST_MIGRATION_INCOMPLETE;
    }
}
