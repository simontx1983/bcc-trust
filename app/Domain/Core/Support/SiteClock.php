<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The ONE time frame of the vote and score tables, as bindable DATETIME
 * strings (F-87 L3 / F-222).
 *
 * ── The frame ───────────────────────────────────────────────────────────
 * `bcc_trust_votes.created_at/updated_at` and every `bcc_trust_page_scores`
 * timestamp (`last_vote_at`, `last_calculated_at`) are SITE-LOCAL wall-clock
 * DATETIMEs: they are written with `current_time('mysql')`, and the
 * velocity ledger's `track_date` with `current_time('Y-m-d')`.
 *
 * A comparison against one of those columns must therefore bind a boundary
 * computed HERE, never use the database clock:
 *
 *   - `NOW()` / `CURDATE()` are the MySQL SESSION time zone, which WordPress
 *     never sets, so it is whatever the server defaults to;
 *   - `UTC_TIMESTAMP()` and PHP `gmdate()` / `new \DateTimeImmutable()` are
 *     UTC (WordPress pins PHP's default zone to UTC).
 *
 * Every one of those agrees with site-local only while the site time zone is
 * UTC. Production is UTC today, so the mixing was latent: it skews every
 * window by the site's UTC offset the day the setting is changed.
 *
 * WordPress core's own `wp_users.user_registered` and every `*_gmt` column
 * are UTC — compare those against `gmdate()`, not against this class.
 *
 * Arithmetic is on the wall-clock string itself (parsed as a naive UTC
 * value), so it does not depend on which zone PHP's default is set to.
 */
final class SiteClock
{
    /** Site-local "now" as a MySQL DATETIME — the stamp for new rows. */
    public static function now(): string
    {
        return (string) current_time('mysql');
    }

    /** Site-local DATETIME $seconds before now — a window boundary. */
    public static function secondsAgo(int $seconds): string
    {
        $now = new \DateTimeImmutable(self::now(), new \DateTimeZone('UTC'));

        return $now->modify('-' . max(0, $seconds) . ' seconds')->format('Y-m-d H:i:s');
    }

    /** Site-local calendar day (Y-m-d) — the velocity ledger's bucket. */
    public static function today(): string
    {
        return (string) current_time('Y-m-d');
    }
}
