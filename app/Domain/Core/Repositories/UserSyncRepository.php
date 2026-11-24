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
     * §F-02 — the JOIN and WHERE fragments that exclude level-30
     * ("friends only") profiles from a `WP_User_Query` listing.
     *
     * Lives here because this repository already owns reads against
     * `peepso_users`, and the arch guardrail is right to keep `$wpdb`
     * out of the Support layer: a clause built there is a second place
     * that knows PeepSo's schema and free to drift from this one.
     * {@see \BCC\Trust\Core\Support\PrivacySettings::filterPeepSoUserQuery}
     * decides WHETHER to apply it and owns the privacy meaning; this
     * method only renders SQL, which is why the level to exclude is a
     * parameter rather than a constant in here.
     *
     * ⚠ A missing `peepso_users` row is NOT excluded. Absence means
     * "never set", whose schema default is public, so treating it as 30
     * would hide unsynced accounts for no reason — hence LEFT JOIN plus
     * the IS NULL arm.
     *
     * ⚠ The alias is returned because the caller uses it to detect a
     * double append: `pre_user_query` can fire more than once on one
     * query, and a second join fails the whole listing with "Not unique
     * table/alias".
     *
     * @return array{from: string, where: string, alias: string}
     */
    public static function listingProfileAccessClause(int $viewerId, int $excludedLevel): array
    {
        global $wpdb;

        $alias = 'bcc_acc';

        return [
            'alias' => $alias,
            'from'  => ' LEFT JOIN `' . $wpdb->prefix . 'peepso_users` `' . $alias . '`'
                . ' ON `' . $alias . '`.`usr_id` = `' . $wpdb->users . '`.`ID`',
            'where' => (string) $wpdb->prepare(
                ' AND (`' . $alias . '`.`usr_profile_acc` IS NULL'
                . ' OR `' . $alias . '`.`usr_profile_acc` <> %d'
                . ' OR `' . $wpdb->users . '`.`ID` = %d)',
                $excludedLevel,
                $viewerId
            ),
        ];
    }

    /** The one live hide-from-listings flag (PrivacySettings::HIDE_FROM_LISTING_META). */
    public const LISTING_OPTOUT_KEY = 'peepso_is_hide_profile_from_user_listing';

    /**
     * The pre-F-257 opt-out the flag was folded from
     * (PrivacySettings::LEGACY_DISCOVERY_OPTOUT_META). Nothing writes it, but
     * the fold LEFT ITS ROWS IN PLACE, so it is read only until the fold is
     * done; see listingOptOutSql().
     */
    public const LISTING_OPTOUT_LEGACY_KEY = 'bcc_privacy_discovery_optout';

    /** Every stored value that means "opted out" (compared case-insensitively by the column collation). */
    public const LISTING_OPTOUT_TRUTHY = ['1', 'true', 'on', 'yes'];

    /**
     * F-518 / F-519 — the ONE SQL fragment that leaves listing opt-outs out
     * of a member listing. Shared on purpose: the app sitemap's public index
     * (PublicIndexRepository) and the member directory / search
     * (PrivacySettings::filterPeepSoUserQuery) must agree on who opted out.
     *
     *     $where .= ' AND ' . UserSyncRepository::listingOptOutSql(
     *         "{$wpdb->users}.ID",
     *         PrivacySettings::legacyOptOutStillRead()
     *     );
     *
     * TRUE when the member has NO row, on a key it reads, holding a truthy
     * value. Any truthy row excludes, so a member with both a '0' and a '1'
     * row stays out (the direction that cannot leak).
     *
     * WHY THE TRUTHY SET, not just '1'. The writers, verified in code:
     *   - PeepSo's own preferences form saves the client's value RAW
     *     (peepso/classes/profilepreferencesajax.php, savepreference:
     *     `$this->_input->raw('value')`), so the hide key can hold any string;
     *   - BCC's writers store '1' / '0' (MyProfilePrefsEndpoint, the profile
     *     prefs write; PrivacySettings::writePartial, /me/privacy's
     *     `discovery_optout`);
     *   - the fold migration (includes/database/fold-discovery-optout.php)
     *     writes '1', and reads the legacy key as this same set.
     * Excluding too many is the safe direction for a privacy setting.
     *
     * ⚠⚠ THE LEGACY KEY ONLY WHILE THE FOLD IS NOT DONE (`$withLegacy`).
     * fold_discovery_optout_v1 copied every legacy opt-out into the flag but
     * LEFT THE LEGACY ROWS IN PLACE, and after the fold the settings toggle
     * writes the flag alone. So a member who opted out the old way and later
     * turned listing back on holds flag '0' + legacy '1'. Reading the legacy
     * key after the fold would hide them again, for good, and they could not
     * undo it. Callers pass PrivacySettings::legacyOptOutStillRead(): true
     * until the fold's done-marker reads as set (a missing or unreadable
     * marker keeps the legacy key in, the fail-closed side). The bool is
     * required, so no caller can forget the choice.
     *
     * Pure SQL: no admin exemption and no option read here. A caller that
     * exempts admins (the directory) does so around this; the public index
     * never exempts anyone. No `%` in the output, so it is safe inside
     * prepare().
     *
     * @param string $userIdColumn a column reference such as `wp_users.ID`, never input
     * @param bool   $withLegacy   also read the legacy opt-out key: PrivacySettings::legacyOptOutStillRead()
     * @throws \InvalidArgumentException when `$userIdColumn` is not a column reference
     */
    public static function listingOptOutSql(string $userIdColumn, bool $withLegacy): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $userIdColumn) !== 1) {
            throw new \InvalidArgumentException('listingOptOutSql() takes a column reference such as wp_users.ID.');
        }

        global $wpdb;

        // Constants of [a-z0-9_] only, so quoting them inline is safe.
        $keyList = $withLegacy
            ? [self::LISTING_OPTOUT_KEY, self::LISTING_OPTOUT_LEGACY_KEY]
            : [self::LISTING_OPTOUT_KEY];
        $keys   = "'" . implode("', '", $keyList) . "'";
        $truthy = "'" . implode("', '", self::LISTING_OPTOUT_TRUTHY) . "'";

        return "NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} bcc_optout"
            . " WHERE bcc_optout.user_id = {$userIdColumn}"
            . " AND bcc_optout.meta_key IN ({$keys})"
            . " AND bcc_optout.meta_value IN ({$truthy}))";
    }

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
     * Only published, non-secret pages count: bcc-core's
     * `PeepSoPageRepository::listablePageSql()`, the condition the typed
     * counts beside it use, so the total and the per-type badges agree.
     * Rationale: F-427 (private tracker). `batchFetchCounts()` and
     * `fetchCountsForUser()` keep counting every owned page: they feed the
     * internal user_info sync, not a member-facing count.
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
        $listable     = \BCC\Core\Repositories\PeepSoPageRepository::listablePageSql('pm.pm_page_id');

        // Prepared-statement style (vs the intval-coerce style in
        // batchFetchCounts above). New code defaults to prepare so the
        // SQL surface is consistent across the repos that call this.
        /** @var list<array{user_id: string, c: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.pm_user_id AS user_id, COUNT(*) AS c
                   FROM {$wpdb->prefix}peepso_page_members pm
                  WHERE pm.pm_user_id IN ({$placeholders})
                    AND pm.pm_user_status = 'member_owner'
                    AND {$listable}
                  GROUP BY pm.pm_user_id",
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

        // Explicit column. Recency ONLY: no role, privacy or block filter
        // here, so moderation state never shapes the ordering. ⚠ The
        // candidates therefore include banned, private and blocked users;
        // the caller MUST filter them (FeedColdStartService does, through
        // MentionPolicy / PeepSoUserSearch, F-78).
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
