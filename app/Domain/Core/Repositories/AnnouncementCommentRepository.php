<?php

/**
 * A5 — §4.32.7 announcement comment reads and writes.
 *
 * ⚠⚠ WHY THIS QUERIES $wpdb DIRECTLY AND NOT `WP_Comment_Query`.
 * A1 installed a `comments_clauses` guard
 * ({@see AnnouncementPostType::excludeFromCommentQueries()}) that appends
 * `comment_type <> 'bcc_ann_comment'` to EVERY comment query in WordPress, so
 * an announcement comment can never leak into an ordinary thread, a moderation
 * queue or `/wp/v2/comments`. That guard is a security control and must stay.
 * The consequence is that this repository cannot use the WP comment API at all
 * — its own reads would be filtered out by its own protection — so it composes
 * SQL directly, which is also what §1 asks of a repository.
 *
 * ⚠⚠ THE TOMBSTONE STORAGE DECISION, and why it is meta rather than
 * `comment_approved = '0'`.
 *
 * §4.32.7 requires a removed comment to keep its POSITION, to count toward
 * `comment_count`, to expose no body or author publicly, AND to remain
 * retrievable in full by an administrator who may restore it. Two candidate
 * representations were considered:
 *
 *   - `comment_approved = '0'` — WordPress's "pending moderation" state. It
 *     survives, but it OVERLOADS a column whose meaning is a moderation queue
 *     this feature does not use, and an administrator approving the row in any
 *     generic tool would silently un-tombstone it. It also cannot carry WHICH
 *     actor removed it, and §4.32.7 needs that to pick between two labels.
 *   - comment meta — explicit, carries the actor type, and leaves
 *     `comment_approved = '1'` so the row keeps counting through the EXISTING
 *     `AnnouncementRepository::commentCountsFor()` predicate with no change.
 *
 * Meta wins on all three counts, and restoration is then the deletion of two
 * meta rows rather than a status write. ⚠ `commentCountsFor()` already accepts
 * `comment_approved IN ('1','0')`, so tombstones would count under either
 * choice; that is headroom, not the reason.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Support\KeysetCursor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type CommentRow \stdClass
 * @phpstan-type Page array{rows: list<\stdClass>, has_more: bool}
 */
final class AnnouncementCommentRepository
{
    public const LIMIT_DEFAULT = 20;
    public const LIMIT_MAX     = 50;

    /** Tombstone meta — presence of REMOVED_AT is the state. */
    public const META_REMOVED_AT    = '_bcc_ann_removed_at';
    public const META_REMOVED_ACTOR = '_bcc_ann_removed_actor';

    /**
     * ⚠ Explicit column list, never `SELECT *` (§2, and arch-guardrails Rule 2
     * enforces it). `comment_content` is included because the administrator
     * recovery surface needs the private original; every PUBLIC consumer is
     * responsible for withholding it on a tombstone, which is asserted in the
     * service rather than hoped for here.
     */
    private const COLUMNS = 'c.comment_ID, c.comment_post_ID, c.comment_author, c.user_id,
         c.comment_date_gmt, c.comment_content, c.comment_approved, c.comment_type';

    private string $commentsTable;
    private string $commentMetaTable;
    private string $postsTable;
    private string $postMetaTable;

    /**
     * ⚠ All four tables are constructor-injected for the same reason
     * `AnnouncementRepository` injects its two: the integration harness builds
     * reduced, uniquely-named fixture tables because the SHARED `wp_comments`
     * and `wp_posts` are not safe to drop and do not carry every column this
     * SQL reads. Dropping a shared table is what poisoned 57 unrelated cases
     * once already.
     */
    public function __construct(
        ?string $commentsTable = null,
        ?string $commentMetaTable = null,
        ?string $postsTable = null,
        ?string $postMetaTable = null
    ) {
        global $wpdb;

        $this->commentsTable    = self::ident($commentsTable ?? $wpdb->comments, 'comments table');
        $this->commentMetaTable = self::ident($commentMetaTable ?? $wpdb->commentmeta, 'commentmeta table');
        $this->postsTable       = self::ident($postsTable ?? $wpdb->posts, 'posts table');
        $this->postMetaTable    = self::ident($postMetaTable ?? $wpdb->postmeta, 'postmeta table');
    }

    /** Identifiers are never interpolated unvalidated. Same guard as AnnouncementVisibility. */
    private static function ident(string $value, string $label): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new RepositoryException('invalid_identifier');
        }

        return $value;
    }

    /** Clamp a caller-supplied limit into the documented band. */
    public static function clampLimit(?int $limit): int
    {
        if ($limit === null || $limit <= 0) {
            return self::LIMIT_DEFAULT;
        }

        return min($limit, self::LIMIT_MAX);
    }

    /**
     * §4.32.7 — "Cursor-paginated in STABLE OLDEST-FIRST order."
     *
     * ⚠⚠ THIS IS THE ONLY ASCENDING SURFACE IN THE ANNOUNCEMENT FEATURE, and
     * that inverts the cursor comparison. Every announcement list is newest
     * first and therefore pages with `<`; a discussion read oldest-first pages
     * with `>`. Pasting the comparison from `AnnouncementRepository` would
     * silently serve the FIRST page forever, because every row is "before" a
     * cursor taken from the end of the previous page.
     *
     * ⚠ `(comment_date_gmt, comment_ID)` is the sort key, and `comment_ID` is
     * the unique tiebreak. Two comments in the same second are ordered
     * deterministically — the creator-gallery defect (313 rows tied on one
     * column, under LIMIT/OFFSET) is the recorded reason this matters.
     *
     * Tombstones are returned IN POSITION, not filtered: §4.32.7 requires it,
     * and the service decides what a tombstone may say.
     *
     * @return Page
     * @throws RepositoryException when the read fails
     */
    public function listForAnnouncement(int $announcementId, int $limit, ?string $cursor): array
    {
        if ($announcementId <= 0) {
            return ['rows' => [], 'has_more' => false];
        }

        $limit = self::clampLimit($limit);
        $fetch = $limit + 1;

        $where  = [
            'c.comment_post_ID = %d',
            'c.comment_type = %s',
            "c.comment_approved IN ('1', '0')",
        ];
        $params = [$announcementId, AnnouncementPostType::COMMENT_TYPE];

        $decoded = KeysetCursor::decode(KeysetCursor::SURFACE_ANNOUNCEMENT_COMMENTS, $cursor);
        if ($decoded !== null) {
            // ⚠ ASCENDING: strictly AFTER the cursor.
            $where[]  = '(c.comment_date_gmt > %s OR (c.comment_date_gmt = %s AND c.comment_ID > %d))';
            $params[] = $decoded['key'];
            $params[] = $decoded['key'];
            $params[] = $decoded['id'];
        }

        $sql = 'SELECT ' . self::COLUMNS . ',
                       NULLIF(mr.meta_value, \'\')  AS removed_at,
                       NULLIF(ma.meta_value, \'\')  AS removed_actor
                  FROM ' . $this->commentsTable . ' c
                  LEFT JOIN ' . $this->commentMetaTable . ' mr
                         ON mr.comment_id = c.comment_ID AND mr.meta_key = %s
                  LEFT JOIN ' . $this->commentMetaTable . ' ma
                         ON ma.comment_id = c.comment_ID AND ma.meta_key = %s
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY c.comment_date_gmt ASC, c.comment_ID ASC
                 LIMIT %d';

        // The two join params bind BEFORE the where params.
        $bound = array_merge([self::META_REMOVED_AT, self::META_REMOVED_ACTOR], $params, [$fetch]);

        return $this->runPage($sql, $bound, $limit);
    }

    /**
     * One comment, by id, with its tombstone state. Used by the removal,
     * restore and administrator-recovery paths.
     *
     * ⚠ Scoped to the announcement comment type. A caller passing an ordinary
     * comment id must get null, not somebody else's blog comment.
     *
     * @throws RepositoryException when the read fails
     */
    public function findOne(int $commentId): ?\stdClass
    {
        if ($commentId <= 0) {
            return null;
        }

        global $wpdb;

        $sql = 'SELECT ' . self::COLUMNS . ',
                       NULLIF(mr.meta_value, \'\')  AS removed_at,
                       NULLIF(ma.meta_value, \'\')  AS removed_actor,
                       p.post_parent               AS page_id,
                       p.post_author               AS announcement_author
                  FROM ' . $this->commentsTable . ' c
                  LEFT JOIN ' . $this->commentMetaTable . ' mr
                         ON mr.comment_id = c.comment_ID AND mr.meta_key = %s
                  LEFT JOIN ' . $this->commentMetaTable . ' ma
                         ON ma.comment_id = c.comment_ID AND ma.meta_key = %s
                  INNER JOIN ' . $this->postsTable . ' p
                         ON p.ID = c.comment_post_ID AND p.post_type = %s
                 WHERE c.comment_ID = %d
                   AND c.comment_type = %s
                 LIMIT 1';

        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare(
            $sql,
            self::META_REMOVED_AT,
            self::META_REMOVED_ACTOR,
            AnnouncementPostType::POST_TYPE,
            $commentId,
            AnnouncementPostType::COMMENT_TYPE
        ));

        if (strlen((string) $wpdb->last_error) > 0) {
            throw new RepositoryException('announcement_comment_read_failed');
        }

        return $row instanceof \stdClass ? $row : null;
    }

    /**
     * Insert a comment. Returns the new id, or 0.
     *
     * ⚠ `comment_approved` is '1'. The tombstone representation is meta (see
     * the class docblock), so approval is not overloaded as a removal state
     * and the row counts through the existing count predicate unchanged.
     *
     * ⚠ `comment_type` is {@see AnnouncementPostType::COMMENT_TYPE} and must
     * never be `note`: WordPress's `check_read_permission()` opens with
     * `if ('note' !== $comment->comment_type && ...)`, skipping the post check
     * entirely, which would hand an announcement comment to anyone.
     *
     * @throws RepositoryException when the write fails
     */
    public function create(int $announcementId, int $userId, string $body, string $nowUtc): int
    {
        if ($announcementId <= 0 || $userId <= 0 || $body === '') {
            return 0;
        }

        global $wpdb;

        $wpdb->last_error = '';
        $ok = $wpdb->insert(
            $this->commentsTable,
            [
                'comment_post_ID'  => $announcementId,
                'user_id'          => $userId,
                'comment_author'   => '',
                'comment_content'  => $body,
                'comment_type'     => AnnouncementPostType::COMMENT_TYPE,
                'comment_approved' => '1',
                'comment_date_gmt' => $nowUtc,
                'comment_date'     => $nowUtc,
                'comment_parent'   => 0,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );

        if ($ok === false || strlen((string) $wpdb->last_error) > 0) {
            throw new RepositoryException('announcement_comment_write_failed');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Stamp the tombstone. Idempotent by intent: re-removing an already-removed
     * comment must not move `removed_at`, because a reader may already hold it.
     *
     * ⚠ ORDER IS LOAD-BEARING: the ACTOR is written before the REMOVED_AT
     * marker, because presence of REMOVED_AT is what makes a row a tombstone.
     * Writing the marker first would leave a window in which a reader sees a
     * tombstone whose actor — and therefore whose LABEL — is not yet stored,
     * and `labelFor(null)` has no answer. The same ordering reasoning as arm
     * C's block(), for the same reason.
     *
     * @throws RepositoryException when the write fails
     */
    public function markRemoved(int $commentId, string $actorType, string $nowUtc): bool
    {
        if ($commentId <= 0 || $actorType === '') {
            return false;
        }

        // Actor FIRST (see the ordering note above).
        if (!$this->putMeta($commentId, self::META_REMOVED_ACTOR, $actorType)) {
            throw new RepositoryException('announcement_comment_removal_failed');
        }

        if (!$this->putMeta($commentId, self::META_REMOVED_AT, $nowUtc)) {
            // Roll the actor back so a half-written tombstone cannot persist.
            $this->dropMeta($commentId, self::META_REMOVED_ACTOR);
            throw new RepositoryException('announcement_comment_removal_failed');
        }

        return true;
    }

    /**
     * Upsert one comment-meta row on the INJECTED table.
     *
     * ⚠⚠ WHY NOT `update_comment_meta()`. This repository READS its meta from
     * `$this->commentMetaTable`, which the integration harness replaces with a
     * fixture table. WordPress's meta API always writes to the REAL
     * `wp_commentmeta`, so mixing the two would have the tombstone written to
     * one table and read from another — passing in production, where they are
     * the same table, and silently proving nothing under test. Writing through
     * the same injected identifier the reads use is what makes the removal path
     * testable at all.
     *
     * ⚠ There is no UNIQUE key on (comment_id, meta_key) in WordPress's
     * schema, so this is an explicit update-then-insert rather than a REPLACE:
     * a REPLACE would delete and re-insert, changing meta_id, and an UPSERT
     * needs the unique key that does not exist.
     */
    private function putMeta(int $commentId, string $key, string $value): bool
    {
        global $wpdb;

        $wpdb->last_error = '';
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT meta_id FROM ' . $this->commentMetaTable . '
              WHERE comment_id = %d AND meta_key = %s LIMIT 1',
            $commentId,
            $key
        ));

        if (strlen((string) $wpdb->last_error) > 0) {
            return false;
        }

        if ($existing !== null) {
            $ok = $wpdb->update(
                $this->commentMetaTable,
                ['meta_value' => $value],
                ['meta_id' => (int) $existing],
                ['%s'],
                ['%d']
            );

            // ⚠ 0 rows is SUCCESS here: an identical re-removal changes nothing,
            // and §4.32.7 requires `removed_at` not to move for a reader who may
            // already hold it. Only `false` is a failure.
            //
            // ⚠ Local copy + the `> 0` form: PHPStan's wpdb stub narrows
            // `last_error` to the literal '', so a `=== 0` comparison reads as
            // "always true" at level 8. Same note as AnnouncementRepository.
            $updateError = (string) $wpdb->last_error;

            return $ok !== false && strlen($updateError) === 0;
        }

        $ok = $wpdb->insert(
            $this->commentMetaTable,
            ['comment_id' => $commentId, 'meta_key' => $key, 'meta_value' => $value],
            ['%d', '%s', '%s']
        );

        $insertError = (string) $wpdb->last_error;

        return $ok !== false && strlen($insertError) === 0;
    }

    /** Delete one comment-meta row from the INJECTED table. */
    private function dropMeta(int $commentId, string $key): void
    {
        global $wpdb;

        $wpdb->delete(
            $this->commentMetaTable,
            ['comment_id' => $commentId, 'meta_key' => $key],
            ['%d', '%s']
        );
    }

    /**
     * Administrator restoration — §4.32.7 makes this administrator-only, and
     * the permission decision lives in the policy, not here.
     *
     * ⚠ The marker is cleared BEFORE the actor, the reverse of removal, so
     * there is no instant at which a row is a tombstone with no actor.
     */
    public function clearRemoved(int $commentId): bool
    {
        if ($commentId <= 0) {
            return false;
        }

        // Marker first, actor second — the reverse of removal, so there is no
        // instant at which a row is a tombstone with no actor to label it.
        $this->dropMeta($commentId, self::META_REMOVED_AT);
        $this->dropMeta($commentId, self::META_REMOVED_ACTOR);

        return true;
    }

    /**
     * Is this announcement accepting comments? §4.32.7 gates creation on it and
     * the contract's write table carries `comments_enabled?`.
     *
     * ⚠ ABSENT MEANS ENABLED. The meta key is written only when an operator
     * turns comments OFF, so every announcement created before A5 — and every
     * one created without the field — has an open discussion. Defaulting the
     * other way would silently close every existing thread.
     *
     * @throws RepositoryException when the read fails
     */
    public function commentsEnabled(int $announcementId, string $metaKey): bool
    {
        if ($announcementId <= 0) {
            return false;
        }

        global $wpdb;

        $wpdb->last_error = '';
        $raw = $wpdb->get_var($wpdb->prepare(
            'SELECT meta_value FROM ' . $this->postMetaTable . '
              WHERE post_id = %d AND meta_key = %s LIMIT 1',
            $announcementId,
            $metaKey
        ));

        if (strlen((string) $wpdb->last_error) > 0) {
            throw new RepositoryException('announcement_comment_read_failed');
        }

        if ($raw === null) {
            return true;
        }

        $v = strtolower(trim((string) $raw));

        // A closed falsy set, mirroring AnnouncementFeature's closed truthy set.
        return !in_array($v, ['0', 'off', 'false', 'no', ''], true);
    }

    /**
     * ⚠ `limit + 1` over-fetch then slice — the `BlogService::getUserBlog`
     * precedent, NOT `CommentService`, which infers the cursor from
     * `count($rows) === $limit` and so mints a false-positive cursor on an
     * exact-boundary page, handing the reader an empty "next page".
     *
     * @param list<mixed> $params
     * @return Page
     * @throws RepositoryException when the read fails
     */
    private function runPage(string $sql, array $params, int $limit): array
    {
        global $wpdb;

        // ⚠⚠ FAIL-CLOSED. An errored read must not present as "no comments
        // yet" — an empty thread looks entirely normal, so the failure would
        // be invisible. Same reasoning as the announcement reads.
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        if (strlen((string) $wpdb->last_error) > 0) {
            throw new RepositoryException('announcement_comment_read_failed');
        }

        $rows    = is_array($rows) ? array_values($rows) : [];
        $hasMore = count($rows) > $limit;

        return [
            'rows'     => $hasMore ? array_slice($rows, 0, $limit) : $rows,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Mint the next cursor from the LAST row of a page.
     *
     * ⚠ Ascending surface, so the cursor is the last row's key and the next
     * page asks for rows strictly AFTER it.
     *
     * @param list<\stdClass> $rows
     */
    public function nextCursor(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        $last = $rows[count($rows) - 1];
        if (!$last instanceof \stdClass) {
            return null;
        }

        $key = (string) ($last->comment_date_gmt ?? '');
        $id  = (int) ($last->comment_ID ?? 0);

        if ($key === '' || $id <= 0) {
            return null;
        }

        return KeysetCursor::encode(KeysetCursor::SURFACE_ANNOUNCEMENT_COMMENTS, $key, $id);
    }
}
