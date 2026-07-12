<?php
/**
 * CommentRepository — read-side access to PeepSo comments.
 *
 * Comment storage in PeepSo:
 *   - wp_posts row with post_type = peepso-activity-comment (CPT_COMMENT)
 *     holds the body (post_excerpt + post_content), timestamp
 *     (post_date_gmt), active state (post_status), and author
 *     (post_author).
 *   - wp_peepso_activities row with act_comment_object_id = parent's
 *     act_external_id (= parent post's wp_posts.ID) and act_external_id
 *     = comment's own wp_posts.ID. The (object_id) index makes
 *     listing comments-on-a-post a single keyed range scan.
 *
 * BCC writes route through bcc-core's PeepSoCommentWriter (single-graph
 * rule, mirrors PeepSoReactionRepository's read-only pattern).
 *
 * Cursor format matches ActivityFeedService::encodeCursor —
 * base64url(json({"t":"<iso8601>","id":<act_id>})). Keyset pagination
 * on (post_date_gmt DESC, act_id DESC) so a tie on identical
 * timestamps doesn't drop or duplicate items.
 *
 * Defensive posture: when peepso_activities is absent (fresh install,
 * PeepSo deactivated), every read returns empty / zero. The feed
 * card's comment_count chip falls back to "💬" with no number;
 * the drawer renders an empty list. The page MUST render either way.
 *
 * @package BCC\Trust\Core\Repositories
 * @since v1.5 (2026-05, hybrid PeepSo-proxy comments)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type CommentRow object{
 *   act_id: int|numeric-string,
 *   comment_post_id: int|numeric-string,
 *   author_id: int|numeric-string,
 *   author_login: string,
 *   author_display_name: string,
 *   body: string,
 *   posted_at: string,
 *   stoke_total: int|numeric-string,
 *   relevance_score: float|numeric-string
 * }
 * @phpstan-type CommentMetaRow object{
 *   act_id: int|numeric-string,
 *   comment_post_id: int|numeric-string,
 *   author_id: int|numeric-string,
 *   parent_post_id: int|numeric-string
 * }
 */
final class CommentRepository
{
    private const ACTIVITIES_TABLE_SUFFIX = 'peepso_activities';

    /**
     * Hard ceiling on a single page. Mirrors NotificationsEndpoint's
     * PER_PAGE_MAX. Keeps the round-trip bounded under abuse.
     */
    public const PER_PAGE_MAX = 50;

    /**
     * Explicit SELECT list — no `*`. Aliases keep the row shape stable
     * across the wp_posts / wp_users JOINs so the service layer
     * doesn't need to know the underlying table topology.
     *
     * post_excerpt holds the cleaned/sanitized body PeepSo's add_comment
     * stored — same field its native UI reads. Reading post_excerpt
     * (not post_content) avoids dragging in any wptexturize/auto-p
     * filter chain that fires on post_content rendering.
     */
    private const LIST_COLUMNS = 'a.act_id,
                                  a.act_external_id AS comment_post_id,
                                  p.post_author     AS author_id,
                                  u.user_login      AS author_login,
                                  u.display_name    AS author_display_name,
                                  p.post_excerpt    AS body,
                                  p.post_date_gmt   AS posted_at';

    /** Sort modes accepted by listByParentPostId — mirrors the §4.13 `sort` param. */
    public const SORT_NEW      = 'new';
    public const SORT_TOP      = 'top';
    public const SORT_RELEVANT = 'relevant';

    private static function activitiesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::ACTIVITIES_TABLE_SUFFIX;
    }

    /**
     * Per-comment stoke count as a correlated subquery. Bounded to the
     * ≤PER_PAGE_MAX rows the outer query returns, so counting per-row is
     * cheaper than GROUP-ing the whole stokes table. Both the SELECT
     * column and the top/relevant cursor predicates reuse this string.
     */
    private static function stokeCountExpr(): string
    {
        return '(SELECT COUNT(*) FROM ' . TableRegistry::stokes() . ' st WHERE st.act_id = a.act_id)';
    }

    /** Decimal places the relevance score is rounded to — see relevanceScoreExpr. */
    private const RELEVANCE_PRECISION = 6;

    /**
     * "Relevant" v1 score — LEAN heuristic: stoke_count blended with
     * recency decay (Hacker-News-style gravity). More stokes rank higher;
     * older comments decay. `+1` keeps a fresh zero-stoke comment ordered
     * by recency rather than collapsing to zero. The richer blend
     * (reply_count, author trust-tier, viewer-follows-author) is deferred
     * — see the Comment-v2 handover Slice 3 + project-deferred-features.
     *
     * `GREATEST(age, 0)` clamps the age so a future-dated comment (clock
     * skew / import) never feeds POW a non-positive base — POW(<=0, 1.5)
     * is NULL in MySQL, which would drop the row out of the ordering.
     *
     * The whole score is ROUNDed to RELEVANCE_PRECISION so the keyset
     * cursor's `score = %f` tiebreak compares equal values on both sides:
     * the cursor stores the same rounded number the SELECT emitted, so
     * tied scores paginate on act_id instead of being skipped/duplicated
     * by full-double precision drift.
     */
    private static function relevanceScoreExpr(): string
    {
        return 'ROUND((' . self::stokeCountExpr()
            . ' + 1) / POW(GREATEST(TIMESTAMPDIFF(HOUR, p.post_date_gmt, UTC_TIMESTAMP()), 0) + 2, 1.5), '
            . self::RELEVANCE_PRECISION . ')';
    }

    /**
     * List comments on a parent post, ordered per `$sort`:
     *   - new      → post_date_gmt DESC, act_id DESC (chronological)
     *   - top      → stoke_total DESC, act_id DESC (most-stoked first)
     *   - relevant → relevance_score DESC, act_id DESC (lean heuristic)
     *
     * Each mode keyset-paginates on its own ordering key + act_id tiebreak,
     * so the cursor stays stable within a sort. `$cursorKey` is the raw
     * key for the active sort: a MySQL datetime for `new`, an integer
     * stoke total for `top`, a float score for `relevant` (all passed as
     * strings and cast in the predicate).
     *
     * `$parentPostId` is the parent activity's `act_external_id`
     * (= parent's wp_posts.ID), which is exactly what
     * peepso_activities.act_comment_object_id stores for every
     * comment on that parent.
     *
     * @phpstan-return list<CommentRow>
     */
    public function listByParentPostId(
        int $parentPostId,
        string $sort,
        ?string $cursorKey,
        ?int $cursorActId,
        int $limit
    ): array {
        if ($parentPostId <= 0) {
            return [];
        }
        $limit = max(1, min($limit, self::PER_PAGE_MAX));

        global $wpdb;
        $activities = self::activitiesTable();
        $stokeExpr  = self::stokeCountExpr();
        $scoreExpr  = self::relevanceScoreExpr();

        $where  = [
            'a.act_comment_object_id = %d',
            "p.post_status = 'publish'",
        ];
        $params = [$parentPostId];

        $hasCursor = $cursorKey !== null && $cursorActId !== null && $cursorActId > 0;

        // Per-sort ORDER BY (uses SELECT aliases, allowed in ORDER BY) +
        // the matching keyset predicate (must repeat the expression —
        // MySQL forbids SELECT aliases in WHERE).
        switch ($sort) {
            case self::SORT_TOP:
                $orderBy = 'stoke_total DESC, a.act_id DESC';
                if ($hasCursor) {
                    $where[]  = "({$stokeExpr} < %d OR ({$stokeExpr} = %d AND a.act_id < %d))";
                    $params[] = (int) $cursorKey;
                    $params[] = (int) $cursorKey;
                    $params[] = $cursorActId;
                }
                break;

            case self::SORT_RELEVANT:
                $orderBy = 'relevance_score DESC, a.act_id DESC';
                if ($hasCursor) {
                    $where[]  = "({$scoreExpr} < %f OR ({$scoreExpr} = %f AND a.act_id < %d))";
                    $params[] = (float) $cursorKey;
                    $params[] = (float) $cursorKey;
                    $params[] = $cursorActId;
                }
                break;

            case self::SORT_NEW:
            default:
                $orderBy = 'p.post_date_gmt DESC, a.act_id DESC';
                if ($hasCursor) {
                    $where[]  = '(p.post_date_gmt < %s OR (p.post_date_gmt = %s AND a.act_id < %d))';
                    $params[] = (string) $cursorKey;
                    $params[] = (string) $cursorKey;
                    $params[] = $cursorActId;
                }
                break;
        }

        $params[] = $limit;

        // stoke_total rides every sort (it's the public stoke_count);
        // relevance_score only the relevant sort reads (ordering + cursor),
        // so skip its POW/subquery work on new/top.
        $selectExtra = ',
                       ' . $stokeExpr . ' AS stoke_total';
        if ($sort === self::SORT_RELEVANT) {
            $selectExtra .= ',
                       ' . $scoreExpr . ' AS relevance_score';
        }

        $sql = 'SELECT ' . self::LIST_COLUMNS . $selectExtra . '
                  FROM ' . $activities . ' a
                  INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
                  INNER JOIN ' . $wpdb->users . ' u ON u.ID = p.post_author
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY ' . $orderBy . '
                 LIMIT %d';

        /** @phpstan-var list<CommentRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return $rows ?: [];
    }

    /**
     * Count visible comments on each of the given parent post IDs.
     * Used by the feed hydrator to attach `comment_count` per item;
     * one batched query covers a whole feed page.
     *
     * Missing entries in the returned map mean "zero comments." The
     * caller pre-fills its own zero map; this returns only non-zero
     * counts.
     *
     * @param list<int> $parentPostIds
     * @return array<int, int> parent_post_id => count
     */
    public function countsByParentPostIds(array $parentPostIds): array
    {
        if ($parentPostIds === []) {
            return [];
        }

        // Filter + dedupe positive ids; build a bounded IN(...) clause.
        $clean = [];
        foreach ($parentPostIds as $id) {
            $iid = (int) $id;
            if ($iid > 0) {
                $clean[$iid] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $ids = array_keys($clean);

        global $wpdb;
        $activities = self::activitiesTable();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = "SELECT a.act_comment_object_id AS parent_id, COUNT(*) AS cnt
                  FROM {$activities} a
                  INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
                 WHERE a.act_comment_object_id IN ({$placeholders})
                   AND p.post_status = 'publish'
                 GROUP BY a.act_comment_object_id";

        /** @phpstan-var list<object{parent_id: int|numeric-string, cnt: int|numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$ids));
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->parent_id] = (int) $r->cnt;
        }
        return $out;
    }

    /**
     * Count published comments AUTHORED by a user since a MySQL DATETIME
     * boundary. A contribution-recovery signal ("helpful comments"); the
     * `post_status = 'publish'` predicate is the quality floor (deleted /
     * unapproved comments don't count). Aggregate COUNT — bounded.
     */
    public function countByAuthorSince(int $authorId, string $sinceMysql): int
    {
        if ($authorId <= 0 || $sinceMysql === '') {
            return 0;
        }

        global $wpdb;
        $activities = self::activitiesTable();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$activities} a
               INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
              WHERE p.post_author          = %d
                AND a.act_comment_object_id > 0
                AND p.post_status           = 'publish'
                AND p.post_date_gmt        >= %s",
            $authorId,
            $sinceMysql
        ));
    }

    /**
     * Single-comment full-row lookup keyed by the comment's wp_post.ID.
     * Used by the create-comment path: PeepSoActivity::add_comment
     * returns the new wp_post.ID but not the act_id, so we resolve the
     * canonical CommentRow shape (matching listByParentPostId's output)
     * via this lookup before responding to the client.
     *
     * @phpstan-return CommentRow|null
     */
    public function getCommentRowByPostId(int $commentPostId): ?object
    {
        if ($commentPostId <= 0) {
            return null;
        }
        global $wpdb;
        $activities = self::activitiesTable();

        /** @phpstan-var CommentRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::LIST_COLUMNS . '
               FROM ' . $activities . ' a
               INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
               INNER JOIN ' . $wpdb->users . ' u ON u.ID = p.post_author
              WHERE a.act_external_id      = %d
                AND a.act_comment_object_id > 0
                AND p.post_status = \'publish\'
              LIMIT 1',
            $commentPostId
        ));
        return $row ?: null;
    }

    /**
     * Single-comment lookup keyed by act_id — returns just the fields
     * the delete-own path needs (author_id for ownership check,
     * comment_post_id for the writer's wp_trash_post call) plus
     * parent_post_id (= act_comment_object_id, the parent post's
     * wp_posts.ID) for the comment-stoke group gate, which must resolve
     * membership off the PARENT post, not the comment's own wp_post.
     *
     * Returns null when the act_id doesn't resolve to a published
     * comment row (already trashed, never existed, or points at a
     * non-comment activity).
     *
     * @phpstan-return CommentMetaRow|null
     */
    public function getCommentMeta(int $commentActId): ?object
    {
        if ($commentActId <= 0) {
            return null;
        }
        global $wpdb;
        $activities = self::activitiesTable();

        /** @phpstan-var CommentMetaRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT a.act_id,
                    a.act_external_id       AS comment_post_id,
                    a.act_comment_object_id AS parent_post_id,
                    p.post_author           AS author_id
               FROM {$activities} a
               INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
              WHERE a.act_id = %d
                AND a.act_comment_object_id > 0
                AND p.post_status = 'publish'
              LIMIT 1",
            $commentActId
        ));
        return $row ?: null;
    }
}
