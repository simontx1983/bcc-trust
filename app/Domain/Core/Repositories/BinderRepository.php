<?php
/**
 * Binder Repository — read-only projection of PeepSo follows
 * enriched with bcc_pull_meta sidecar metadata.
 *
 * Per §C2: the binder is a UI-layer projection of PeepSo follows,
 * NOT a separate relationship graph. This repository owns the
 * cross-table read that JOINs:
 *   - {prefix}peepso_user_followers (PeepSo, source of truth for follows)
 *   - {prefix}bcc_pull_meta         (BCC sidecar metadata; LEFT JOIN)
 *   - {prefix}users                 (handle / user_login fallback)
 *   - {prefix}usermeta              (bcc_handle resolution)
 *
 * Why a dedicated repository (not on PullMetaRepository): PullMeta's
 * scope is the bcc_pull_meta table only. The binder read crosses
 * three other tables; putting it elsewhere would either violate
 * PullMeta's documented scope or scatter $wpdb across services
 * (forbidden by §L6 "Repository-only DB access").
 *
 * Read-only. No writes — pull/unpull are Phase 2/3 mutations and
 * route through PullMetaRepository directly when they land.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04, Binder Phase 1)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type BinderItemRow object{
 *     follow_id: int|numeric-string,
 *     card_user_id: int|numeric-string,
 *     user_login: string,
 *     card_handle: string|null,
 *     tier_at_pull: string|null,
 *     batch_id: string|null,
 *     pulled_at: string|null
 * }
 */
final class BinderRepository
{
    /**
     * Active-follow predicate for peepso_user_followers per the
     * existing PeepSoFollowerRepository convention.
     */
    private const ACTIVE_FOLLOW = 'uf_follow = 1';

    /**
     * Hard cap defending against pathological page_size requests
     * even after route-arg validation. Matches BinderService::MAX_PAGE_SIZE
     * — the cap is enforced at three layers (route arg, service, repo)
     * so a misconfigured caller never leaks the unbounded query.
     */
    private const MAX_PAGE_SIZE = 50;

    /**
     * The binder for a viewer — paginated, most-recent-pull first
     * (follow ordinal break-tie when pull_meta absent).
     *
     * @return list<BinderItemRow>
     */
    public function findItemsForUser(int $userId, int $offset, int $limit): array
    {
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $offset = max(0, $offset);

        global $wpdb;
        $follows  = $wpdb->prefix . 'peepso_user_followers';
        $pullMeta = TableRegistry::pullMeta();
        $users    = $wpdb->users;
        $usermeta = $wpdb->usermeta;

        $sql = $wpdb->prepare(
            "SELECT
                uf.uf_id              AS follow_id,
                uf.uf_passive_user_id AS card_user_id,
                u.user_login          AS user_login,
                handle.meta_value     AS card_handle,
                pm.tier_at_pull       AS tier_at_pull,
                pm.batch_id           AS batch_id,
                pm.pulled_at          AS pulled_at
            FROM {$follows} uf
            INNER JOIN {$users} u
                ON u.ID = uf.uf_passive_user_id
            LEFT JOIN {$pullMeta} pm
                ON pm.follow_id = uf.uf_id
            LEFT JOIN {$usermeta} handle
                ON handle.user_id = uf.uf_passive_user_id
               AND handle.meta_key = 'bcc_handle'
            WHERE uf.uf_active_user_id = %d
              AND " . self::ACTIVE_FOLLOW . "
            ORDER BY pm.pulled_at DESC, uf.uf_id DESC
            LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        );
        // ────────────────────────────────────────────────────────────
        //  Sort tiebreaker (LOCKED — never remove uf.uf_id DESC):
        //  prevents pagination jitter when multiple items share a
        //  pulled_at value. Two cases that hit this in practice:
        //    1. Legacy follows (no bcc_pull_meta row) — pm.pulled_at
        //       is NULL for all of them, so they collide on the primary
        //       sort key.
        //    2. Phase 3 batches — every member of a batch shares the
        //       batch's pulled_at boundary timestamp.
        //  Without the uf_id tiebreaker, MySQL is free to return
        //  these rows in different orders across requests, breaking
        //  offset pagination (some items shown twice, others missed).
        // ────────────────────────────────────────────────────────────

        /** @var list<BinderItemRow>|null $rows */
        $rows = $wpdb->get_results($sql);
        return $rows ?: [];
    }

    /**
     * Single-item lookup using the same JOIN as findItemsForUser, scoped
     * to a specific follow_id and ownership-checked against the viewer.
     *
     * Used by the pull/unpull mutation handlers to return the canonical
     * binder-item shape immediately after a successful write — same
     * shape as GET /me/binder, no separate hydration path.
     *
     * Returns null when:
     *   - the follow_id doesn't exist
     *   - the follow_id belongs to a different user (ownership-fail)
     *   - the follow has been flipped to uf_follow=0 (no longer in binder)
     *
     * @phpstan-return BinderItemRow|null
     */
    public function findItemByFollowId(int $userId, int $followId): ?object
    {
        if ($userId <= 0 || $followId <= 0) {
            return null;
        }

        global $wpdb;
        $follows  = $wpdb->prefix . 'peepso_user_followers';
        $pullMeta = TableRegistry::pullMeta();
        $users    = $wpdb->users;
        $usermeta = $wpdb->usermeta;

        $sql = $wpdb->prepare(
            "SELECT
                uf.uf_id              AS follow_id,
                uf.uf_passive_user_id AS card_user_id,
                u.user_login          AS user_login,
                handle.meta_value     AS card_handle,
                pm.tier_at_pull       AS tier_at_pull,
                pm.batch_id           AS batch_id,
                pm.pulled_at          AS pulled_at
            FROM {$follows} uf
            INNER JOIN {$users} u
                ON u.ID = uf.uf_passive_user_id
            LEFT JOIN {$pullMeta} pm
                ON pm.follow_id = uf.uf_id
            LEFT JOIN {$usermeta} handle
                ON handle.user_id = uf.uf_passive_user_id
               AND handle.meta_key = 'bcc_handle'
            WHERE uf.uf_id = %d
              AND uf.uf_active_user_id = %d
              AND " . self::ACTIVE_FOLLOW . "
            LIMIT 1",
            $followId,
            $userId
        );

        /** @phpstan-var BinderItemRow|null $row */
        $row = $wpdb->get_row($sql);
        return $row ?: null;
    }

    /**
     * Look up the passive_user_id (followee) for a follow row owned
     * by $userId. Used by the unpull handler to get the followee
     * before deletion (so the bcc_card_unpulled event can carry it).
     *
     * Returns 0 when the row doesn't exist or isn't owned by $userId.
     */
    public function getFolloweeForOwner(int $userId, int $followId): int
    {
        if ($userId <= 0 || $followId <= 0) {
            return 0;
        }

        global $wpdb;
        $follows = $wpdb->prefix . 'peepso_user_followers';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT uf_passive_user_id FROM {$follows}
              WHERE uf_id = %d AND uf_active_user_id = %d
              LIMIT 1",
            $followId,
            $userId
        ));
    }

    /**
     * Open pull_meta rows for a user (batch_id IS NULL). Used by the
     * §C3 batch aggregator to find members of the user's currently-open
     * batch + their pulled_at timestamps.
     *
     * Joins peepso_user_followers to scope by uf_active_user_id (the
     * pull_meta table doesn't carry user_id directly per §C2 — it's
     * sidecar metadata keyed by the PeepSo follow's uf_id).
     *
     * @return list<object{follow_id: int|numeric-string, pulled_at: string}>
     */
    public function findOpenPullMetaForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        global $wpdb;
        $follows  = $wpdb->prefix . 'peepso_user_followers';
        $pullMeta = TableRegistry::pullMeta();

        /** @var list<object{follow_id: int|numeric-string, pulled_at: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.follow_id, pm.pulled_at
               FROM {$pullMeta} pm
               INNER JOIN {$follows} uf ON uf.uf_id = pm.follow_id
              WHERE uf.uf_active_user_id = %d
                AND pm.batch_id IS NULL
              ORDER BY pm.pulled_at ASC, pm.follow_id ASC
              LIMIT 500",
            $userId
        ));
        return $rows ?: [];
    }

    /**
     * User IDs with open batches whose most-recent pulled_at is at or
     * before the cutoff — i.e., batches that have been quiet long
     * enough to close (per §C3 10-minute inactivity window).
     *
     * The sweep handler iterates this list and calls closeForUser()
     * per user. Bounded by LIMIT to keep the query cheap and the
     * per-tick work bounded under load.
     *
     * @return list<int>
     */
    public function findUsersWithExpiredOpenBatches(string $cutoffMysqlDatetime, int $limit): array
    {
        $limit = max(1, min(500, $limit));

        global $wpdb;
        $follows  = $wpdb->prefix . 'peepso_user_followers';
        $pullMeta = TableRegistry::pullMeta();

        /** @var list<object{user_id: int|numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT uf.uf_active_user_id AS user_id
               FROM {$pullMeta} pm
               INNER JOIN {$follows} uf ON uf.uf_id = pm.follow_id
              WHERE pm.batch_id IS NULL
              GROUP BY uf.uf_active_user_id
             HAVING MAX(pm.pulled_at) <= %s
              LIMIT %d",
            $cutoffMysqlDatetime,
            $limit
        ));

        $ids = [];
        foreach ($rows ?: [] as $row) {
            $ids[] = (int) $row->user_id;
        }
        return $ids;
    }

    /**
     * Bulk reverse-lookup: given a set of user_ids (followed users),
     * resolve each to its peepso-page (if any) so the binder can
     * surface validator / project / creator card_kinds with real
     * post_name slugs in URLs.
     *
     * Returns one row per user, keyed by user_id. Users with no
     * peepso-page are absent from the map (caller falls back to
     * 'member' kind).
     *
     * Multi-page-per-user resolution (LOCKED — deterministic):
     *   1. Highest-priority _bcc_page_type wins — validator > builder > nft > unknown.
     *      (MIN(ID) alone would flip when a new page imports/migrates;
     *      anchoring to the contract page_type keeps the mapping stable
     *      across backups and reorderings.)
     *   2. Within the same priority, lowest post_id wins (earliest-published).
     *
     * Bounded by LIMIT 200 — at the binder cap of 50 follows × an
     * unrealistic 4 pages each, the 200 row budget still holds.
     *
     * @param list<int> $userIds
     * @return array<int, object{user_id: int|numeric-string, page_id: int|numeric-string, page_slug: string, page_type: string}>
     */
    public function findPageInfoByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        global $wpdb;
        $posts    = $wpdb->posts;
        $postmeta = $wpdb->postmeta;

        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
        $params       = $userIds;

        // Single-query approach: select all candidate pages with
        // priority + tiebreak ordering, then pick the first per user
        // in PHP. Avoids window functions (MySQL-8+) for broader
        // compat; one query, bounded result.
        /** @var list<object{user_id: int|numeric-string, page_id: int|numeric-string, page_slug: string, page_type: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                p.post_author               AS user_id,
                p.ID                        AS page_id,
                p.post_name                 AS page_slug,
                COALESCE(pt.meta_value, '') AS page_type
            FROM {$posts} p
            LEFT JOIN {$postmeta} pt
                ON pt.post_id = p.ID
               AND pt.meta_key = '_bcc_page_type'
            WHERE p.post_type = 'peepso-page'
              AND p.post_status = 'publish'
              AND p.post_author IN ({$placeholders})
            ORDER BY
                p.post_author ASC,
                CASE COALESCE(pt.meta_value, '')
                    WHEN 'validator' THEN 1
                    WHEN 'builder'   THEN 2
                    WHEN 'nft'       THEN 3
                    ELSE                  4
                END ASC,
                p.ID ASC
            LIMIT 200",
            ...$params
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $userId = (int) $row->user_id;
            // First row per user_id wins (rows are ORDER BYed by
            // priority + ID, so the first is the canonical pick).
            if (!isset($map[$userId])) {
                $map[$userId] = $row;
            }
        }
        return $map;
    }

    /**
     * Bulk-resolve follow_id → card_handle for a small set of
     * follow_ids. Used by the §C3 batch aggregator to enrich the
     * top-3 cards in the bcc_pull_batch_emitted event payload so
     * subscribers (feed writer, notification dispatcher) don't
     * re-fetch handles for content the aggregator already touched.
     *
     * Resolution: bcc_handle preferred; user_login fallback.
     *
     * Bounded by LIMIT 50 — the §C3 contract caps top-cards
     * display at 3, and even allowing for a generous future cap
     * 50 is well above realistic per-batch cardinality.
     *
     * @param list<int> $followIds
     * @return array<int, string> follow_id => handle
     */
    public function findHandlesForFollowIds(array $followIds): array
    {
        if ($followIds === []) {
            return [];
        }

        global $wpdb;
        $follows  = $wpdb->prefix . 'peepso_user_followers';
        $users    = $wpdb->users;
        $usermeta = $wpdb->usermeta;

        $placeholders = implode(',', array_fill(0, count($followIds), '%d'));

        /** @var list<object{follow_id: int|numeric-string, bcc_handle: string|null, user_login: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT uf.uf_id           AS follow_id,
                    handle.meta_value  AS bcc_handle,
                    u.user_login       AS user_login
               FROM {$follows} uf
               INNER JOIN {$users} u
                  ON u.ID = uf.uf_passive_user_id
               LEFT JOIN {$usermeta} handle
                  ON handle.user_id = uf.uf_passive_user_id
                 AND handle.meta_key = 'bcc_handle'
              WHERE uf.uf_id IN ({$placeholders})
              LIMIT 50",
            ...$followIds
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $handle = $row->bcc_handle !== null && $row->bcc_handle !== ''
                ? $row->bcc_handle
                : $row->user_login;
            $map[(int) $row->follow_id] = $handle;
        }
        return $map;
    }

    /**
     * Tier-distribution rollup for the §N9 binder identity-snapshot.
     *
     * GROUP BY tier_at_pull on the active-follows table left-joined to
     * bcc_pull_meta. Returns counts keyed by tier_at_pull value.
     * Legacy follows (no pull_meta row) surface under the 'unknown'
     * key so the caller can render them honestly without inflating
     * any specific tier bucket.
     *
     * Bounded by the COUNT/GROUP BY shape — at most 6 distinct
     * tier values (legendary/rare/uncommon/common/null + unexpected
     * future values), no LIMIT needed but the result set is
     * inherently small.
     *
     * @return array<string, int> tier_at_pull => count, plus 'unknown' for null
     */
    public function countByTierForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        global $wpdb;
        $follows  = $wpdb->prefix . 'peepso_user_followers';
        $pullMeta = TableRegistry::pullMeta();

        /** @var list<object{tier_at_pull: string|null, n: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.tier_at_pull AS tier_at_pull,
                    COUNT(*)        AS n
               FROM {$follows} uf
               LEFT JOIN {$pullMeta} pm
                 ON pm.follow_id = uf.uf_id
              WHERE uf.uf_active_user_id = %d
                AND " . self::ACTIVE_FOLLOW . "
              GROUP BY pm.tier_at_pull",
            $userId
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $key = $row->tier_at_pull !== null && $row->tier_at_pull !== ''
                ? $row->tier_at_pull
                : 'unknown';
            $out[$key] = (int) $row->n;
        }
        return $out;
    }

    /**
     * Total count for the same predicate set as findItemsForUser.
     * Single COUNT(*) on the active-follows table — no joins.
     * Drives `pagination.total` / `pagination.total_pages`.
     */
    public function countItemsForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;
        $follows = $wpdb->prefix . 'peepso_user_followers';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$follows}
              WHERE uf_active_user_id = %d
                AND " . self::ACTIVE_FOLLOW,
            $userId
        ));
    }
}
