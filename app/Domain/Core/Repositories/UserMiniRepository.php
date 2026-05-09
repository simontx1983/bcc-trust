<?php
/**
 * User Mini Repository
 *
 * Slim "{id, handle, display_name}" view-model resolver for surfaces
 * that need author / participant / recipient identity but NOT the
 * full §3.1 User payload — DMs (§4.19), comments, future profile-link
 * popovers, etc. Avatar URLs are computed at the Service layer via
 * `get_avatar_url` (filterable + WP-cached); this repository owns the
 * SQL only.
 *
 * Single batched read against `wp_users` LEFT JOIN
 * `wp_usermeta.bcc_handle` so each row carries the canonical handle
 * (per §B6) when set, falling back to `user_login` when not.
 *
 * Bounded by per_page caps at the call site (the canonical caller is
 * MessagesService whose inbox cap is 50, ~5 participants per
 * conversation = ≤250 ids per resolve). Defensive `LIMIT 500` guards
 * any future caller that forgets to cap.
 *
 * @package BCC\Trust\Core\Repositories
 * @since v1.5 (2026-05, BCC messages adapter)
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type UserMiniRow array{
 *     id: int,
 *     user_login: string,
 *     display_name: string,
 *     handle: string|null
 * }
 */
final class UserMiniRepository
{
    /**
     * Batched user-mini fetch keyed by user id. Empty / unknown ids
     * are absent from the map; callers default to null.
     *
     * @param list<int> $userIds
     * @phpstan-return array<int, UserMiniRow>
     */
    public function getRowsByIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $clean = [];
        foreach ($userIds as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $clean[$i] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        // Defensive cap — covers any future caller that forgets to
        // bound the input.
        $ids = array_slice(array_keys($clean), 0, 500);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<array{ID: string, user_login: string, display_name: string, handle: string|null}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID, u.user_login, u.display_name, m.meta_value AS handle
                 FROM {$wpdb->users} AS u
                 LEFT JOIN {$wpdb->usermeta} AS m
                    ON m.user_id = u.ID AND m.meta_key = 'bcc_handle'
                 WHERE u.ID IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row['ID'];
            $out[$uid] = [
                'id'           => $uid,
                'user_login'   => (string) $row['user_login'],
                'display_name' => (string) $row['display_name'],
                'handle'       => isset($row['handle']) && $row['handle'] !== ''
                    ? (string) $row['handle']
                    : null,
            ];
        }
        return $out;
    }
}
