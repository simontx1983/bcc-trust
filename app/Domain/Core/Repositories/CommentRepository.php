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
 *   posted_at: string
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

    private static function activitiesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::ACTIVITIES_TABLE_SUFFIX;
    }

    /**
     * List comments on a parent post in chronological-newest-first
     * order. Cursor pagination on (post_date_gmt DESC, act_id DESC).
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
        ?string $cursorTime,
        ?int $cursorActId,
        int $limit
    ): array {
        if ($parentPostId <= 0) {
            return [];
        }
        $limit = max(1, min($limit, self::PER_PAGE_MAX));

        global $wpdb;
        $activities = self::activitiesTable();

        $where  = [
            'a.act_comment_object_id = %d',
            "p.post_status = 'publish'",
        ];
        $params = [$parentPostId];

        if ($cursorTime !== null && $cursorActId !== null && $cursorActId > 0) {
            $where[]  = '(p.post_date_gmt < %s OR (p.post_date_gmt = %s AND a.act_id < %d))';
            $params[] = $cursorTime;
            $params[] = $cursorTime;
            $params[] = $cursorActId;
        }

        $params[] = $limit;

        $sql = 'SELECT ' . self::LIST_COLUMNS . '
                  FROM ' . $activities . ' a
                  INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
                  INNER JOIN ' . $wpdb->users . ' u ON u.ID = p.post_author
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY p.post_date_gmt DESC, a.act_id DESC
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
