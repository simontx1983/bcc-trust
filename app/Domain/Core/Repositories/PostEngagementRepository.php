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
     * How many times this post has been reposted.
     *
     * Counts only reposts whose own wp_posts row is still published, so a
     * deleted or trashed repost stops counting — the same `post_status`
     * filter `CommentRepository::countsByParentPostIds()` applies.
     *
     * @param int $actId The reposted post's act_id (NOT a wp_posts.ID).
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
            "SELECT COUNT(*) AS n
               FROM {$activities} a
               INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
              WHERE a.act_repost_id = %d
                AND p.post_status = 'publish'",
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
        global $wpdb;

        self::clearLastError();
        $ok = $wpdb->query($sql);

        if ($ok === false || self::readFailed()) {
            return null;
        }

        /** @var list<object{n?: int|numeric-string}>|mixed $rows */
        $rows = $wpdb->last_result;

        if (!is_array($rows) || !isset($rows[0])) {
            return null;
        }

        return (int) ($rows[0]->n ?? 0);
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
