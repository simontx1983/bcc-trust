<?php
/**
 * Post Reach Daily Table Schema
 *
 * F-324 post analytics, REACH half. One row per
 * (post, UTC day, distribution source) holding three additive counters:
 * `views`, `unique_viewers` and `profile_visits`. Written on the request
 * path by PostReachRepository via a single-statement
 * `INSERT … ON DUPLICATE KEY UPDATE col = col + 1`.
 *
 * ── WHY (post, day, source) AND NOT A LIFETIME ROW ──────────────────
 * Keying per day is what bounds write contention. A lifetime-per-post row
 * would be a single hot row for a popular post, serialising every view
 * behind one row lock. Per (post, day, source) the writes spread across
 * source buckets and roll to a fresh row every UTC day, so no row stays
 * hot. The (entity, day, source) shape mirrors
 * `schema-login-days.php`; `source` is in the PRIMARY KEY here (not a
 * plain column as it is there) because one post legitimately accrues
 * views from several surfaces on the same day.
 *
 * ── THE COUNTERS ─────────────────────────────────────────────────────
 *   views           every counted view (repeat views by the same reader
 *                   count, subject to the 30-minute dedupe window)
 *   unique_viewers  distinct readers THAT DAY — see the note below
 *   profile_visits  clicks from this post through to the author's profile
 *
 * ⚠ `unique_viewers` is deliberately **per day**, not per lifetime. The
 * dedupe hash that makes it possible is salted per day and deleted within
 * 48 hours (see `schema-post-view-dedupe.php`), so there is no durable
 * per-reader identity to deduplicate across days — by design, that being
 * the most private option. Consequence that MUST be carried into the API
 * and the UI copy: summing days overcounts a reader who returns on two
 * days, so any lifetime figure is a conservative upper bound on viewers
 * and the engagement rate computed from it is a conservative LOWER bound.
 * Label it "daily unique viewers"; never present it as lifetime uniques.
 *
 * ── SOURCE VALUES ────────────────────────────────────────────────────
 * 'feed' | 'hot' | 'tag' | 'profile' | 'group' | 'direct'
 *
 * ⛔ There is deliberately NO 'cold-start' source. `FeedColdStartService`
 * carries a standing "Deliberately NOT tracked (do not add analytics
 * here)" list which includes per-block performance comparison; a
 * cold-start source would let an author compare the cold-start surface
 * against the feed, which is exactly that. Cold-start views are recorded
 * as 'feed'. This is a hard constraint, not a simplification — do not add
 * the value "for completeness".
 *
 * ── RETENTION ────────────────────────────────────────────────────────
 * Daily rows are kept 90 days, then collapsed into a lifetime totals row
 * and deleted.
 *
 * ⚠⚠ The collapse MUST be archive-then-delete inside ONE guarded method,
 * hung off the existing `CronService::dailyCleanup` under the
 * `bcc_cron_cleanup_lock` it already holds — never a standalone DELETE and
 * never a new cron. `docs/pattern-registry.md` states the rule
 * ("Retention belongs to archiveBatch(), not standalone DELETE") because a
 * standalone `AuditLogger::cleanOldLogs()` once preempted the archive path
 * and silently emptied the archive table in production. A naive
 * "collapse, then prune" here reproduces that exactly.
 *
 * `idx_day` exists for the prune's `WHERE day < %s` cutoff; reads by post
 * use the PRIMARY KEY prefix and need no extra index.
 *
 * ── PRIVACY ──────────────────────────────────────────────────────────
 * This table holds counts only. No viewer identity, no viewer list, and
 * nothing derived from a viewer beyond an increment. It is post-keyed, so
 * it is removed with the post. The view beacon that drives these counters
 * must NEVER write to the audit log — `docs/pattern-registry.md` forbids
 * auditing read paths outright ("Audit is for state transitions, not
 * request volume").
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since F-324 post analytics V1 (2026-11-12)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_post_reach_daily_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_post_reach_daily';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        post_id BIGINT UNSIGNED NOT NULL,
        day DATE NOT NULL,
        source VARCHAR(20) NOT NULL,
        views INT UNSIGNED NOT NULL DEFAULT 0,
        unique_viewers INT UNSIGNED NOT NULL DEFAULT 0,
        profile_visits INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (post_id, day, source),
        KEY idx_day (day)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Post reach daily table installed', []);
}
