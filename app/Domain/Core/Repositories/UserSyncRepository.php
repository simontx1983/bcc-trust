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
     * Focused batched lookup of `pages_owned` only — for list-shape
     * consumers (e.g. /members directory) that need just this one
     * count and shouldn't pay for the four other queries that
     * `batchFetchCounts` issues. Same canonical join: `member_owner`
     * rows in `peepso_page_members`.
     *
     * Users with no owned pages are absent from the map; callers
     * default to 0.
     *
     * Empty `$userIds` short-circuits — no SQL.
     *
     * @param int[] $userIds Bounded by caller (directory `per_page`
     *                       cap, e.g. 50). The IN clause scales
     *                       linearly with the bound.
     * @return array<int, int> user_id → owned-pages count
     */
    public static function getOwnedPageCountsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // Sanitize + dedupe — same pattern as the other batched
        // repo methods. Reject zero/negative ids before SQL.
        $clean = [];
        foreach ($userIds as $id) {
            $intVal = (int) $id;
            if ($intVal > 0) {
                $clean[$intVal] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $idList = array_keys($clean);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        // Prepared-statement style (vs the intval-coerce style in
        // batchFetchCounts above). New code defaults to prepare so the
        // SQL surface is consistent across the repos that call this.
        /** @var list<array{user_id: string, c: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm_user_id AS user_id, COUNT(*) AS c
                   FROM {$wpdb->prefix}peepso_page_members
                  WHERE pm_user_id IN ({$placeholders})
                    AND pm_user_status = 'member_owner'
                  GROUP BY pm_user_id",
                ...$idList
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[(int) $row['user_id']] = (int) $row['c'];
        }
        return $out;
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

    /**
     * IDs of users whose `usr_last_activity` (PeepSo's canonical
     * last-seen timestamp on `peepso_users`) falls inside the last
     * `$intervalDays`. Optionally excludes a viewer so a self-viewing
     * cold-start panel doesn't surface the viewer themselves.
     *
     * Bounded (§4): caller-provided `$limit` is clamped to [1, 100].
     * Index posture (§4 cont'd): `peepso_users` carries
     * `usr_last_activity_index` (single-column BTREE) — the WHERE
     * clause + ORDER BY hits it; the `usr_id != %d` self-exclusion is
     * range-compatible. `EXPLAIN` shows `range` on `usr_last_activity_index`
     * with `Using where` (the != filter is applied after index seek but
     * before LIMIT, so the candidate scan stays bounded by the time
     * cutoff, not the table size).
     *
     * Used by FeedColdStartService for the home-feed empty-state
     * "recently active operators" block — see that service's class
     * doctype for why recency is the only honest order here.
     *
     * @return list<int>
     */
    public static function getRecentlyActiveUserIds(
        int $intervalDays,
        int $limit,
        int $excludeUserId = 0
    ): array {
        if ($intervalDays <= 0 || $limit <= 0) {
            return [];
        }
        if ($limit > 100) {
            $limit = 100;
        }
        if ($intervalDays > 365) {
            // Defensive cap. Anything over a year is "all active users"
            // and the caller should not be using this method for that.
            $intervalDays = 365;
        }

        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($intervalDays * 86_400));

        // Explicit column. The `usr_role IS NULL OR usr_role != 'banned'`
        // filter intentionally omitted — banned/suspended posture is
        // surfaced via the trust layer (Permissions / flags) at hydration
        // time, not at candidate-selection time. We don't want to leak
        // moderation state through ordering decisions here.
        if ($excludeUserId > 0) {
            $sql = $wpdb->prepare(
                "SELECT usr_id
                   FROM {$wpdb->prefix}peepso_users
                  WHERE usr_last_activity >= %s
                    AND usr_id != %d
                  ORDER BY usr_last_activity DESC
                  LIMIT %d",
                $cutoff,
                $excludeUserId,
                $limit
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT usr_id
                   FROM {$wpdb->prefix}peepso_users
                  WHERE usr_last_activity >= %s
                  ORDER BY usr_last_activity DESC
                  LIMIT %d",
                $cutoff,
                $limit
            );
        }

        /** @var list<string>|null $rows */
        $rows = $wpdb->get_col($sql);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $raw) {
            $userId = (int) $raw;
            if ($userId > 0) {
                $out[] = $userId;
            }
        }
        return $out;
    }
}
