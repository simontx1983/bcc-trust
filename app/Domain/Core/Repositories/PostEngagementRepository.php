<?php

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-324 post analytics — ENGAGEMENT reads that had no reader yet.
 *
 * ⚠ This class exists for exactly TWO counts: reposts and saves. Reactions
 * and comments already have readers and must NOT be re-implemented here:
 *
 *   reactions → `PeepSoReactionRepository::countsByActId()`   (act_id)
 *   comments  → `CommentRepository::countsByParentPostIds()`  (wp_post id)
 *
 * Kept separate from `PostReachRepository` on purpose. The product
 * requirement is that REACH, ENGAGEMENT and TRUST stay separate in data,
 * API and UI and are never blended into one score; letting engagement reads
 * into the reach repository would be the first quiet step towards mixing
 * them.
 *
 * ── ⚠⚠ THE ID TRAP ──────────────────────────────────────────────────
 * Both methods here take an **act_id**, and for saves that is genuinely
 * surprising: `wp_peepso_saved_posts.post_id` stores an ACT_ID, not a
 * wp_posts.ID, despite the column name. PeepSo's own query proves it —
 * `peepso/activity/classes/activity.php` joins
 * `` `saved_posts`.`post_id` = `act`.`act_id` ``. Passing a wp_post id here
 * returns a confident **zero**, not an error, which is the worst kind of
 * wrong. Comments are the one metric in this feature keyed by wp_post id.
 *
 * Reads FAIL CLOSED (null on error), per the F-197/F-216/F-230 idiom: a
 * broken query must never be presented as "no engagement".
 *
 * Defensive posture mirrors `CommentRepository`: when the PeepSo tables are
 * absent (fresh install, PeepSo deactivated) every read returns null and the
 * panel shows the section as unavailable rather than fabricating zeros.
 *
 * @package BCC_Trust
 * @subpackage Repositories
 * @since F-324 post analytics V1 (2026-11-12)
 */
final class PostEngagementRepository
{
    private const ACTIVITIES_TABLE_SUFFIX  = 'peepso_activities';
    private const SAVED_POSTS_TABLE_SUFFIX = 'peepso_saved_posts';

    /**
     * Bound on {@see viewerReposts()}. One member should have at most ONE
     * repost of a given root; this caps the cleanup of a duplicate that
     * slipped past the once-only check, it is not a page size.
     */
    private const VIEWER_REPOST_CAP = 50;

    private static function activitiesTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::ACTIVITIES_TABLE_SUFFIX;
    }

    private static function savedPostsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::SAVED_POSTS_TABLE_SUFFIX;
    }

    /**
     * How many MEMBERS have reposted this post.
     *
     * Counts only reposts whose own wp_posts row is still published, so a
     * deleted or trashed repost stops counting — the same `post_status`
     * filter `CommentRepository::countsByParentPostIds()` applies.
     *
     * ⚠⚠ `COUNT(DISTINCT a.act_owner_id)`, NOT `COUNT(*)`, and that is a
     * robustness choice rather than a change of meaning. Reposting is
     * once-per-member-per-post, so under correct behaviour the two are
     * identical — but nothing in the DATABASE can enforce that rule, so
     * DISTINCT is what keeps a duplicate that slipped past the app-level
     * check from inflating a public number.
     *
     * Why no constraint is possible: PeepSo's activities table carries
     * `act_owner_index(act_owner_id)` and `act_repost_index(act_repost_id)`
     * as two separate NON-unique single-column indexes, and a
     * `UNIQUE (act_owner_id, act_repost_id)` is incompatible with the schema
     * anyway — `act_repost_id` defaults to 0 for every ordinary post, so
     * uniqueness would cap each member at one ordinary post ever. The
     * once-only rule is entirely ours to enforce, which is exactly why this
     * read must not assume it held.
     *
     * ⚠ $actId MUST BE THE ROOT. PeepSo stores the chain root in
     * `act_repost_id` (it calls `get_repost_root()` first), so passing a
     * repost's OWN act_id returns 0 rather than the original's count — a
     * confident zero, not an error. Callers resolve the root with
     * `PeepSoActivityRepository::resolveRepostRoot()`.
     *
     * @param int $actId The ROOT post's act_id (NOT a wp_posts.ID).
     * @return int|null null when the count could not be read.
     */
    public static function repostCount(int $actId): ?int
    {
        if ($actId <= 0) {
            return null;
        }

        $activities = self::activitiesTable();
        if (!TableRegistry::exists($activities)) {
            return null;
        }

        global $wpdb;

        return self::readCount($wpdb->prepare(
            "SELECT COUNT(DISTINCT a.act_owner_id) AS n
               FROM {$activities} a
               INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
              WHERE a.act_repost_id = %d
                AND p.post_status = 'publish'
                AND p.post_content = ''",
            $actId
        ));
    }

    /**
     * How many members have saved this post.
     *
     * ⚠ `post_id` in this table is an ACT_ID — see the class docblock.
     * There is no status column: a save is a member action, not content.
     *
     * ⚠⚠ THIS COUNT CANNOT USE AN INDEX, and that is PeepSo's schema, not
     * something we can fix from here. `saved_posts` carries only
     * `PRIMARY KEY (id)` and `INDEX user_id (user_id, post_id)` — a
     * composite whose leftmost column is `user_id`, so a predicate on
     * `post_id` alone has no usable prefix and MySQL falls back to a full
     * scan. Acceptable for now because this endpoint is owner-only and
     * read by one member looking at one of their own posts, and because
     * saves are a low-volume action — but it is a scan per request, so if
     * this ever moves onto a hot path (a post list, a feed card) it needs
     * an index on `(post_id)` first. Adding one means altering a
     * third-party table, which is a deliberate decision rather than a
     * detail to slip in.
     *
     * @param int $actId The post's act_id (NOT a wp_posts.ID).
     * @return int|null null when the count could not be read.
     */
    public static function saveCount(int $actId): ?int
    {
        if ($actId <= 0) {
            return null;
        }

        $saved = self::savedPostsTable();
        if (!TableRegistry::exists($saved)) {
            // PeepSo's saved-posts feature not installed — unknown, not zero.
            return null;
        }

        global $wpdb;

        return self::readCount($wpdb->prepare(
            "SELECT COUNT(*) AS n FROM {$saved} WHERE post_id = %d",
            $actId
        ));
    }

    /**
     * The viewer's OWN published reposts of this root.
     *
     * Answers both questions the repost routes need from one read: whether
     * this member has already reposted (`[] !== $rows`), and which posts to
     * trash when they un-repost.
     *
     * ⚠ $rootActId MUST BE THE ROOT, resolved through
     * `PeepSoActivityRepository::resolveRepostRoot()`. `act_repost_id` always
     * stores the chain root, so keying on a submitted repost's own act_id
     * matches NOTHING and the once-only rule silently stops applying — the
     * member reposts the same content twice.
     *
     * ⚠⚠ KEYED ON `act_owner_id`, THE SAME COLUMN `repostCount()` COUNTS
     * DISTINCT, and the two must not drift. `act_owner_id` is the WALL the
     * post sits on (PeepSo: `'act_owner_id' => $owner` in `add_post`), not
     * necessarily its author — they coincide here only because
     * `PeepSoStatusWriter::createSelfStatus()` passes the author as both.
     * Were this keyed on `post_author` while the count stayed on
     * `act_owner_id`, "already reposted" and "counted as a reposter" could
     * answer differently for the same row.
     *
     * ⚠ PUBLISHED ONLY, matching `repostCount()`. An un-reposted (trashed)
     * row must not block a fresh repost, or un-repost would be a one-way
     * door.
     *
     * ⚠ BOUNDED (§2). One member should have at most ONE row here, so the
     * cap is not about paging — it is about a duplicate that slipped past
     * the once-only check still being cleaned up in bounded work rather
     * than becoming an unbounded delete.
     *
     * @return list<array{act_id: int, post_id: int}>|null null when the
     *         read failed; `[]` when this member has not reposted.
     */
    public static function viewerReposts(int $rootActId, int $ownerId): ?array
    {
        if ($rootActId <= 0 || $ownerId <= 0) {
            return null;
        }

        $activities = self::activitiesTable();
        if (!TableRegistry::exists($activities)) {
            return null;
        }

        global $wpdb;

        $rows = self::readRows($wpdb->prepare(
            "SELECT a.act_id, a.act_external_id
               FROM {$activities} a
               INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
              WHERE a.act_repost_id = %d
                AND a.act_owner_id = %d
                AND p.post_status = 'publish'
                AND p.post_content = ''
              ORDER BY a.act_id ASC
              LIMIT %d",
            $rootActId,
            $ownerId,
            self::VIEWER_REPOST_CAP
        ));

        if ($rows === null) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            /** @var object{act_id?: int|numeric-string, act_external_id?: int|numeric-string} $row */
            $actId  = (int) ($row->act_id ?? 0);
            $postId = (int) ($row->act_external_id ?? 0);
            if ($actId > 0 && $postId > 0) {
                $out[] = ['act_id' => $actId, 'post_id' => $postId];
            }
        }

        return $out;
    }

    /**
     * Run a COUNT and return it, or null if the read failed.
     *
     * ⚠ `$wpdb->query()` returning false is not an exhaustive failure
     * signal — it returns false without setting `last_error` on the
     * `! $this->ready` reconnect guard and on an empty `query`-filter
     * result — so both are tested (the F-230 lesson).
     *
     * The clear and the check go through separate methods deliberately:
     * assigning `$wpdb->last_error = ''` inline narrows the property to the
     * literal `''` for PHPStan, which then proves the later `!== ''` dead
     * and fails level 8.
     */
    private static function readCount(string $sql): ?int
    {
        $rows = self::readRows($sql);

        if ($rows === null || !isset($rows[0])) {
            return null;
        }

        /** @var object{n?: int|numeric-string} $first */
        $first = $rows[0];

        return (int) ($first->n ?? 0);
    }

    /**
     * How many QUOTE reposts of this root exist — a raw total, not distinct
     * members, because quotes are UNLIMITED per member (the X model, user
     * decision 2026-11-18) and two quotes by one member are two posts.
     *
     * ⭐ PLAIN vs QUOTE is `post_content`, and that is PeepSo's own rule
     * rather than a convention invented here: `PeepSoActivity::add_post()`
     * REFUSES empty content unless a repost is set
     * (`if (!maybe_strlen($content) && NULL === $repost ...) return;`), and
     * it stores the content already trimmed. So among rows carrying
     * `act_repost_id`, empty content is reachable ONLY as a plain repost,
     * and anything non-empty is a quote.
     *
     * ⚠ A RAW count, deliberately not filtered to what the viewer can see.
     * It is the same posture {@see repostCount()} already has, and the
     * reason is the same: a per-viewer count cannot be cached or batched
     * without an N+1, and the number discloses no identity. Filtering one
     * and not the other would be the inconsistency.
     *
     * Returns null when the count could not be READ — never 0. See
     * {@see readCount()}; 0 and "unknown" are different answers and the
     * contract makes clients render them differently.
     */
    public static function quoteCount(int $actId): ?int
    {
        if ($actId <= 0) {
            return null;
        }

        $activities = self::activitiesTable();
        if (!TableRegistry::exists($activities)) {
            return null;
        }

        global $wpdb;

        return self::readCount($wpdb->prepare(
            "SELECT COUNT(*) AS n
               FROM {$activities} a
               INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
              WHERE a.act_repost_id = %d
                AND p.post_status = 'publish'
                AND p.post_content <> ''",
            $actId
        ));
    }

    /**
     * Run a read and return its rows, or null if the read failed.
     *
     * ⚠ EXTRACTED FROM {@see readCount()} rather than written beside it, so
     * the F-230 failure detection above lives in ONE place. A second copy is
     * exactly how one of the two reads would later lose the `$ok === false`
     * leg and start reporting a broken query as "no rows".
     *
     * ⚠ An EMPTY array is a successful read of nothing and is returned as
     * `[]`, never as null. The distinction is the whole point: `[]` means
     * "this member has not reposted", null means "I could not find out", and
     * a caller that collapses them re-creates the bug this idiom exists to
     * prevent.
     *
     * @return list<object>|null
     */
    private static function readRows(string $sql): ?array
    {
        global $wpdb;

        self::clearLastError();
        $ok = $wpdb->query($sql);

        if ($ok === false || self::readFailed()) {
            return null;
        }

        /** @var mixed $rows */
        $rows = $wpdb->last_result;

        if (!is_array($rows)) {
            return null;
        }

        /** @var list<object> $rows */
        return array_values($rows);
    }

    private static function clearLastError(): void
    {
        global $wpdb;

        $wpdb->last_error = '';
    }

    private static function readFailed(): bool
    {
        global $wpdb;

        return (string) $wpdb->last_error !== '';
    }
}
