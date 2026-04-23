<?php

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database queries for user sync operations.
 *
 * Extracted from UserSyncService to enforce repository-only DB access.
 * Queries PeepSo-owned tables (page_members, group_members, peepso_users)
 * and WordPress core tables (posts, comments).
 */
final class UserSyncRepository
{
    /**
     * Batch-fetch aggregate counts for a chunk of user IDs.
     *
     * Returns pages_owned, groups_owned, posts_created, comments_made,
     * and peepso_user data in 5 queries total (instead of 5N).
     *
     * @param int[] $userIds
     * @return array<int, array<string, mixed>> Keyed by user_id.
     */
    public static function batchFetchCounts(array $userIds): array
    {
        global $wpdb;

        if (empty($userIds)) {
            return [];
        }

        $ids_safe = implode(',', array_map('intval', $userIds));
        $result   = array_fill_keys($userIds, [
            'pages_owned'    => 0,
            'groups_owned'   => 0,
            'posts_created'  => 0,
            'comments_made'  => 0,
            'peepso_user'    => null,
        ]);

        // 1. Pages owned
        $rows = $wpdb->get_results(
            "SELECT pm_user_id AS uid, COUNT(*) AS cnt
             FROM {$wpdb->prefix}peepso_page_members
             WHERE pm_user_id IN ({$ids_safe}) AND pm_user_status = 'member_owner'
             GROUP BY pm_user_id"
        );
        foreach ($rows as $r) {
            $result[(int) $r->uid]['pages_owned'] = (int) $r->cnt;
        }

        // 2. Groups owned
        $rows = $wpdb->get_results(
            "SELECT gm_user_id AS uid, COUNT(*) AS cnt
             FROM {$wpdb->prefix}peepso_group_members
             WHERE gm_user_id IN ({$ids_safe}) AND gm_user_status = 'member_owner'
             GROUP BY gm_user_id"
        );
        foreach ($rows as $r) {
            $result[(int) $r->uid]['groups_owned'] = (int) $r->cnt;
        }

        // 3. Posts created
        $rows = $wpdb->get_results(
            "SELECT post_author AS uid, COUNT(*) AS cnt
             FROM {$wpdb->posts}
             WHERE post_author IN ({$ids_safe})
               AND post_type IN ('post', 'peepso-page', 'peepso-group')
             GROUP BY post_author"
        );
        foreach ($rows as $r) {
            $result[(int) $r->uid]['posts_created'] = (int) $r->cnt;
        }

        // 4. Comments made
        $rows = $wpdb->get_results(
            "SELECT user_id AS uid, COUNT(*) AS cnt
             FROM {$wpdb->comments}
             WHERE user_id IN ({$ids_safe})
             GROUP BY user_id"
        );
        foreach ($rows as $r) {
            $result[(int) $r->uid]['comments_made'] = (int) $r->cnt;
        }

        // 5. PeepSo user data
        $rows = $wpdb->get_results(
            "SELECT usr_id, usr_last_activity, usr_views, usr_likes, usr_role
             FROM {$wpdb->prefix}peepso_users
             WHERE usr_id IN ({$ids_safe})"
        );
        foreach ($rows as $r) {
            $result[(int) $r->usr_id]['peepso_user'] = $r;
        }

        return $result;
    }

    /**
     * Fetch counts for a single user (fallback when batch data not available).
     *
     * @return array<string, mixed> Same shape as one entry from batchFetchCounts().
     * @phpstan-return array{
     *   pages_owned: int,
     *   groups_owned: int,
     *   posts_created: int,
     *   comments_made: int,
     *   peepso_user: object{
     *     usr_id: int|numeric-string,
     *     usr_last_activity: string|null,
     *     usr_views: int|numeric-string,
     *     usr_likes: int|numeric-string,
     *     usr_role: string|null
     *   }|null
     * }
     */
    public static function fetchCountsForUser(int $userId): array
    {
        global $wpdb;

        $peepso_user = $wpdb->get_row($wpdb->prepare(
            "SELECT usr_id, usr_last_activity, usr_views, usr_likes, usr_role
             FROM {$wpdb->prefix}peepso_users WHERE usr_id = %d",
            $userId
        ));

        $pages_owned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}peepso_page_members
             WHERE pm_user_id = %d AND pm_user_status = 'member_owner'",
            $userId
        ));

        $groups_owned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}peepso_group_members
             WHERE gm_user_id = %d AND gm_user_status = 'member_owner'",
            $userId
        ));

        $posts_created = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_author = %d AND post_type IN ('post', 'peepso-page', 'peepso-group')",
            $userId
        ));

        $comments_made = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments}
             WHERE user_id = %d",
            $userId
        ));

        return [
            'pages_owned'    => $pages_owned,
            'groups_owned'   => $groups_owned,
            'posts_created'  => $posts_created,
            'comments_made'  => $comments_made,
            'peepso_user'    => $peepso_user,
        ];
    }
}
