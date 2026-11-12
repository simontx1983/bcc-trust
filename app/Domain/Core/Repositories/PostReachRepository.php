<?php

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-324 post analytics — REACH persistence.
 *
 * Owns the two post-reach tables: the additive per-(post, UTC day, source)
 * counters, and the 48-hour dedupe guard that makes `unique_viewers`
 * meaningful.
 *
 * ── TWO OPPOSITE FAILURE POLICIES, ON PURPOSE ────────────────────────
 * **Writes fail OPEN.** Every write path here returns a bool/null and
 * swallows its own failure, because analytics must never break a feed
 * render or a page load. This follows bcc-search's `SearchTermsRepository`,
 * whose writer opens: "Analytics can NEVER break a search — the whole body
 * is swallow-and-log." A lost view is a lost view.
 *
 * **Reads fail CLOSED.** Every read returns `null` on a DB error, never a
 * confident `0`, following the F-197/F-216/F-230 idiom: a broken query and
 * a post nobody read must not look the same. Core keeps its own
 * `last_error` idiom rather than the Onchain `GuardsReadFailures` trait —
 * see the notes in `AdminDashboardRepository` and `PageReadModelRepository`
 * for why Core deliberately does not adopt it.
 *
 * ⛔ NOTHING here may write to the audit log. `docs/pattern-registry.md`
 * forbids auditing read paths outright: "Audit is for state transitions,
 * not request volume."
 *
 * @package BCC_Trust
 * @subpackage Repositories
 * @since F-324 post analytics V1 (2026-11-12)
 */
final class PostReachRepository
{
    /**
     * The ONLY accepted distribution sources.
     *
     * ⛔ Do not add 'cold-start'. `FeedColdStartService` carries a standing
     * "do not add analytics here" list including per-block performance
     * comparison, and a cold-start source would let an author compare that
     * surface against the feed — exactly what it forbids. Cold-start views
     * record as 'feed'.
     */
    public const SOURCES = ['feed', 'hot', 'tag', 'profile', 'group', 'direct'];

    /** 48 hours — the retention the user-facing privacy copy promises. */
    public const DEDUPE_MAX_AGE_SECONDS = 172800;

    /**
     * Hard row cap for the dedupe table, trimmed oldest-first.
     *
     * ⚠ This is a safety valve against runaway growth, NOT a retention
     * policy — the age TTL is the policy. Trimming a row that is still
     * inside its 48-hour window makes that reader countable again, so the
     * only consequence is a slightly OVER-counted `unique_viewers`. It can
     * never leak anything: the rows hold a salted one-way hash.
     *
     * ⚠⚠ THIS CAP MUST STAY BELOW `RetentionHealthSnapshot`'s watermark
     * (`DEFAULT_ALARM_THRESHOLD`, 250_000, overridable by
     * `BCC_RETENTION_ALARM_THRESHOLD`). That snapshot applies ONE threshold
     * to every table in `RetentionHealthRepository::rowCounts()`, and this
     * table is registered there — so a cap above the watermark would make a
     * perfectly healthy table read as permanently "overgrown" in
     * /system/health, i.e. an alarm that is always on and therefore
     * useless. An earlier revision of this file had exactly that bug
     * (cap 500_000 against a 250_000 watermark).
     *
     * There is deliberately no local alarm constant: the overgrown alarm
     * promised in the schema docblock is that shared snapshot, not a second
     * threshold maintained here.
     */
    public const DEDUPE_MAX_ROWS = 200000;

    /** Bounded age-delete: rows per batch, and max batches per sweep. */
    private const CLEANUP_BATCH_SIZE     = 5000;
    private const CLEANUP_MAX_ITERATIONS = 20;

    private static function reachTable(): string
    {
        return TableRegistry::postReachDaily();
    }

    private static function dedupeTable(): string
    {
        return TableRegistry::postViewDedupe();
    }

    /**
     * True when both tables exist.
     *
     * Guards every write so a pre-migration install drops samples silently
     * instead of erroring on every feed render (the same guard
     * `SearchTermsRepository` uses via its installed-option). `TableRegistry`
     * memoizes existence per request, so this is not a query per view.
     */
    private static function installed(): bool
    {
        return TableRegistry::exists(self::reachTable())
            && TableRegistry::exists(self::dedupeTable());
    }

    /**
     * Claim a viewer for (post, day) — the dedupe decision.
     *
     * @param string $viewerHash RAW 16 bytes from the keyed day-salted HMAC.
     *                           Never a user id, never an IP.
     * @return bool|null TRUE  = first time this viewer saw this post today
     *                          (so it counts toward unique_viewers),
     *                   FALSE = already seen today,
     *                   NULL  = the claim could not be decided (not
     *                           installed, bad input, or a DB error).
     *
     * ⚠ NULL is deliberately distinct from FALSE. A caller must treat null
     * as "I don't know", not as "already seen": counting a view with
     * `unique = false` on an unknown claim is the safe direction (it may
     * under-count uniques), whereas treating null as a fresh claim would
     * over-count them on every DB hiccup.
     */
    public static function claimDailyViewer(int $postId, string $day, string $viewerHash): ?bool
    {
        if ($postId <= 0 || $day === '' || strlen($viewerHash) !== 16 || !self::installed()) {
            return null;
        }

        global $wpdb;
        $table = self::dedupeTable();

        // INSERT IGNORE: the PK collision IS the "already seen" answer, in
        // one round-trip and with no read-then-write race.
        $affected = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (post_id, day, viewer_hash, seen_at)
             VALUES (%d, %s, %s, %s)",
            $postId,
            $day,
            $viewerHash,
            gmdate('Y-m-d H:i:s')
        ));

        if ($affected === false) {
            self::logWriteFailure('claim_daily_viewer');
            return null;
        }

        return (int) $affected === 1;
    }

    /**
     * Record one view.
     *
     * @param bool $countsAsUnique Pass the TRUE from claimDailyViewer() only.
     *                             Anything else (false or null) must arrive
     *                             here as false.
     * @return bool Whether the counter was written. Callers ignore it —
     *              the return exists for tests and for the sweep's logging.
     */
    public static function incrementView(int $postId, string $day, string $source, bool $countsAsUnique): bool
    {
        return self::increment($postId, $day, $source, 1, $countsAsUnique ? 1 : 0, 0);
    }

    /**
     * Record one profile visit attributed to this post.
     *
     * Deliberately separate from incrementView: a profile visit is not a
     * view and must never inflate the view count.
     */
    public static function incrementProfileVisit(int $postId, string $day, string $source): bool
    {
        return self::increment($postId, $day, $source, 0, 0, 1);
    }

    /**
     * The single additive write.
     *
     * One statement, `INSERT … ON DUPLICATE KEY UPDATE col = col + n`,
     * following `EdgeRepository::incrementEdge`. The increments are bound
     * parameters rather than `VALUES(col)` — same result, and it avoids the
     * `VALUES()`-in-ODKU form MySQL 8.0.20 deprecated.
     *
     * Contention is bounded by the key itself: per (post, day, source) a
     * popular post spreads writes across source buckets and rolls to a
     * fresh row every UTC day, so no lifetime row is ever hot.
     */
    private static function increment(
        int $postId,
        string $day,
        string $source,
        int $views,
        int $uniques,
        int $profileVisits
    ): bool {
        if ($postId <= 0 || $day === '' || !in_array($source, self::SOURCES, true) || !self::installed()) {
            return false;
        }

        global $wpdb;
        $table = self::reachTable();

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                 (post_id, day, source, views, unique_viewers, profile_visits, created_at)
             VALUES (%d, %s, %s, %d, %d, %d, %s)
             ON DUPLICATE KEY UPDATE
                 views          = views + %d,
                 unique_viewers = unique_viewers + %d,
                 profile_visits = profile_visits + %d",
            $postId,
            $day,
            $source,
            $views,
            $uniques,
            $profileVisits,
            gmdate('Y-m-d H:i:s'),
            $views,
            $uniques,
            $profileVisits
        ));

        if ($result === false) {
            self::logWriteFailure('increment');
            return false;
        }

        return true;
    }

    /**
     * Remove every reach row for a post.
     *
     * Called when a post is deleted: these rows are post-keyed, so without
     * this they would outlive their subject. The dedupe rows are left to the
     * 48-hour TTL — they are at most two days from deletion anyway, and
     * deleting by post_id there would be an extra index scan on every post
     * deletion for no privacy gain.
     *
     * @return int|null Rows deleted, or null on failure.
     */
    public static function deleteForPost(int $postId): ?int
    {
        if ($postId <= 0 || !self::installed()) {
            return null;
        }

        global $wpdb;
        $table = self::reachTable();

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE post_id = %d",
            $postId
        ));

        if ($deleted === false) {
            self::logWriteFailure('delete_for_post');
            return null;
        }

        return (int) $deleted;
    }

    /**
     * Sweep the dedupe table: age TTL first, then an overflow trim.
     *
     * Modelled on `HeliusSeenSignaturesRepository::sweep()`, which solves the
     * same problem and whose docblock names the footgun — a dedupe table
     * silently becoming an infinite append-only junk drawer. Here an unkept
     * TTL is worse than untidy: the 48-hour deletion IS the privacy
     * guarantee the user-facing copy makes, so this must not quietly stall.
     *
     * @return array{deleted_age: int, deleted_overflow: int, remaining: int|null}
     *         `remaining` is null when the count could not be read.
     */
    public static function sweepDedupe(
        int $maxAgeSeconds = self::DEDUPE_MAX_AGE_SECONDS,
        int $maxRows = self::DEDUPE_MAX_ROWS
    ): array {
        $maxAgeSeconds = max(60, $maxAgeSeconds);
        $maxRows       = max(1000, $maxRows);

        if (!self::installed()) {
            return ['deleted_age' => 0, 'deleted_overflow' => 0, 'remaining' => null];
        }

        global $wpdb;
        $table = self::dedupeTable();

        // 1. Age TTL, in bounded batches. An unbounded DELETE would, if this
        //    cron stalls, take one long lock that blocks the request-path
        //    dedupe inserts. A backlog beyond the per-sweep cap is finished
        //    by the next sweep.
        $cutoff     = gmdate('Y-m-d H:i:s', time() - $maxAgeSeconds);
        $deletedAge = 0;
        for ($i = 0; $i < self::CLEANUP_MAX_ITERATIONS; $i++) {
            $n = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE seen_at < %s LIMIT %d",
                $cutoff,
                self::CLEANUP_BATCH_SIZE
            ));
            if ($n === false) {
                self::logWriteFailure('sweep_age');
                break;
            }
            $deletedAge += (int) $n;
            if ((int) $n < self::CLEANUP_BATCH_SIZE) {
                break;
            }
        }

        // 2. Overflow trim, sized from a REAL count.
        //
        // ⚠ F-101 discipline: a failed COUNT must not be treated as 0 and
        // must not drive a destructive trim. If the count is unreadable we
        // trim nothing and report remaining = null, so an operator sees "I
        // don't know" rather than a confident zero.
        $remaining       = self::countDedupeRows();
        $deletedOverflow = 0;

        if ($remaining !== null && $remaining > $maxRows) {
            // Batched for the same reason as the age delete above: one
            // unbounded `DELETE … LIMIT {excess}` would, after a long stall,
            // take a single long lock that blocks request-path dedupe
            // inserts. Whatever a sweep cannot finish, the next one does.
            $excess = $remaining - $maxRows;
            for ($i = 0; $i < self::CLEANUP_MAX_ITERATIONS && $excess > 0; $i++) {
                $batch = min($excess, self::CLEANUP_BATCH_SIZE);
                $n     = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} ORDER BY seen_at ASC LIMIT %d",
                    $batch
                ));
                if ($n === false) {
                    self::logWriteFailure('sweep_overflow');
                    break;
                }
                $deletedOverflow += (int) $n;
                $excess          -= (int) $n;
                if ((int) $n < $batch) {
                    break; // table drained faster than the estimate
                }
            }
            $remaining -= $deletedOverflow;
        }

        return [
            'deleted_age'      => $deletedAge,
            'deleted_overflow' => $deletedOverflow,
            'remaining'        => $remaining,
        ];
    }

    /**
     * Row count for the dedupe table.
     *
     * @return int|null null on a read failure — never a confident 0, because
     *                  a 0 here would switch the overflow cap off (F-101).
     */
    public static function countDedupeRows(): ?int
    {
        if (!self::installed()) {
            return null;
        }

        global $wpdb;
        $table = self::dedupeTable();

        self::clearLastError();
        $ok = $wpdb->query("SELECT COUNT(*) AS n FROM {$table}");

        if ($ok === false || self::readFailed()) {
            return null;
        }

        /** @var list<object{n?: int|numeric-string}>|mixed $rows */
        $rows = $wpdb->last_result;

        return is_array($rows) && isset($rows[0]) ? (int) ($rows[0]->n ?? 0) : 0;
    }

    /**
     * Clear `$wpdb->last_error` before a read.
     *
     * ⚠⚠ This is a METHOD, not an inline assignment, and that is the whole
     * point. Assigning `$wpdb->last_error = ''` inline narrows the property
     * to the literal `''` for PHPStan, which then proves the later
     * `!== ''` check is always false and fails level 8 with "Strict
     * comparison using !== between '' and '' will always evaluate to
     * false". It cannot see that `query()` mutates the property in between.
     * Routing the clear and the check through separate methods makes the
     * property opaque to that narrowing — the same shape `PageDiscoveryRepository`
     * uses (F-230), which is why that class passes level 8.
     */
    private static function clearLastError(): void
    {
        global $wpdb;

        $wpdb->last_error = '';
    }

    /**
     * Did the read just performed fail?
     *
     * ⚠ `$wpdb->query()` returning false is NOT an exhaustive failure
     * signal — it returns false without setting `last_error` on the
     * `! $this->ready` reconnect guard and on an empty `query`-filter
     * result. Callers therefore test BOTH (`$ok === false || readFailed()`),
     * which is the F-230 lesson.
     */
    private static function readFailed(): bool
    {
        global $wpdb;

        return (string) $wpdb->last_error !== '';
    }

    /**
     * Log a write failure without ever carrying viewer data.
     *
     * ⚠ The context is a fixed label plus `$wpdb->last_error` — never the
     * statement. A prepared statement has its values substituted, so logging
     * SQL here would put a raw `viewer_hash` into the log, and `Logger`
     * scrubs by KEY, which `sql` would not match. (Same defect I introduced
     * and fixed in F-144.)
     */
    private static function logWriteFailure(string $what): void
    {
        global $wpdb;

        if (!class_exists('\\BCC\\Core\\Log\\Logger')) {
            return;
        }

        \BCC\Core\Log\Logger::error('[bcc-trust] post-reach write failed', [
            'what'  => $what,
            'error' => (string) $wpdb->last_error,
        ]);
    }
}
