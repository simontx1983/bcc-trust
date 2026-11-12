<?php

namespace BCC\Trust\Core\Support;

use BCC\Trust\Core\Security\DeviceFingerprinter;
use BCC\Trust\Core\Security\RateLimiter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-324 — the per-day viewer code used to deduplicate post views.
 *
 * Produces 16 raw bytes for `wp_bcc_trust_post_view_dedupe.viewer_hash`.
 * It is NOT an identifier for a person and must never be treated as one: no
 * endpoint returns it, nothing joins on it, and nothing stores it for longer
 * than the 48-hour sweep leaves it alive.
 *
 * ── CONSTRUCTION ─────────────────────────────────────────────────────
 *   viewer_hash = first 16 bytes of
 *                 HMAC-SHA256(key = BCC_ENCRYPTION_KEY, msg = day || subject)
 *
 *   subject = "u:<user id>"      when signed in
 *           = "a:<subnet>|<ua>"  when signed out
 *
 * ⚠ The DAY IS PART OF THE MESSAGE. That is the whole privacy design, not a
 * detail: the same reader hashes to something different tomorrow, so rows
 * cannot be correlated across days and no durable pseudonym exists anywhere
 * in the system. It is also exactly why `unique_viewers` is a per-day figure
 * and can never be a lifetime one — there is nothing stable to deduplicate
 * against across days, by choice.
 *
 * ⚠ The subject carries a TYPE PREFIX (`u:` / `a:`) so a signed-in and a
 * signed-out subject can never collide. Without it a user id of `12` and an
 * anonymous subject that happened to render as `12` would be the same
 * reader.
 *
 * ⚠⚠ FAILS CLOSED. `DeviceFingerprinter::keyedHash()` returns null when
 * `BCC_ENCRYPTION_KEY` is absent, and this returns null in turn rather than
 * substituting an unkeyed digest. An unkeyed hash of a low-entropy subject
 * is a confirmation oracle — with a membership on the order of a hundred, a
 * `u:<id>` space is trivially enumerable by anyone who can recompute it. The
 * secret, not the 16-byte truncation, is the defence. A null here must make
 * the caller count the view as NON-unique; it must never make it count as a
 * fresh viewer.
 *
 * ── WHY THE SUBNET AND NOT THE FULL IP ───────────────────────────────
 * Signed-out subjects use `RateLimiter::normalizeIpToSubnet()` — IPv4 → /24,
 * IPv6 → /48 (a /48 is the standard end-site allocation, RFC 6177; /64 would
 * let a client hop subnets inside one allocation). Coarsening costs some
 * accuracy — a household or office reads as one viewer — and buys a
 * meaningful reduction in how specifically a code can be confirmed against a
 * guessed address. The same helper the rate limiter uses, deliberately, so
 * the two cannot disagree about what "the same client" means.
 *
 * @package BCC_Trust
 * @subpackage Support
 * @since F-324 post analytics V1 (2026-11-12)
 */
final class ViewerFingerprint
{
    /** Raw bytes stored per row. The column is BINARY(16). */
    public const HASH_BYTES = 16;

    /**
     * Build the viewer code for a given UTC day.
     *
     * @param string $day       UTC day, 'Y-m-d' (callers use gmdate, the
     *                          house convention — there is no shared
     *                          utcDay() helper and we don't add one).
     * @param int    $viewerId  Signed-in user id, or 0 for a signed-out view.
     * @param string $ip        Raw client IP; ignored when $viewerId > 0.
     * @param string $userAgent Raw UA string; ignored when $viewerId > 0.
     * @return string|null 16 raw bytes, or null when no key is available.
     */
    public static function forDay(string $day, int $viewerId, string $ip = '', string $userAgent = ''): ?string
    {
        if ($day === '') {
            return null;
        }

        $subject = $viewerId > 0
            ? 'u:' . $viewerId
            : 'a:' . RateLimiter::normalizeIpToSubnet($ip) . '|' . $userAgent;

        // A signed-out subject with neither an IP nor a UA carries no
        // signal at all; every such viewer would collapse into one code and
        // report as a single unique viewer. Refuse instead of inventing one.
        if ($viewerId <= 0 && $ip === '' && $userAgent === '') {
            return null;
        }

        // ⚠ 'viewer:' DOMAIN PREFIX. `keyedHash()` is shared (hashIp() uses
        // it too), so every caller must namespace its message or two
        // different kinds of input could, in principle, hash identically and
        // mean different things. Free to add now because the feature ships
        // dark and no dedupe row exists yet — changing it later would
        // silently reset every viewer's code mid-day and double-count them.
        $hex = DeviceFingerprinter::keyedHash('viewer:' . $day . '|' . $subject, 'viewerFingerprint');

        if ($hex === null) {
            return null;
        }

        $raw = hex2bin($hex);

        return is_string($raw) ? substr($raw, 0, self::HASH_BYTES) : null;
    }
}
