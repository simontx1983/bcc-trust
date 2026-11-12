<?php
/**
 * Post View Dedupe Table Schema
 *
 * F-324 post analytics. Short-retention guard that stops one reader being
 * counted twice for the same post on the same UTC day, so
 * `bcc_trust_post_reach_daily.unique_viewers` means something. It is the
 * ONLY table in this feature that holds anything viewer-derived, and what
 * it holds is a keyed one-way hash, never an identity.
 *
 * ── THE HASH ─────────────────────────────────────────────────────────
 *   viewer_hash = HMAC-SHA256(key = BCC_ENCRYPTION_KEY, msg = day || subject)
 *                 truncated to 16 bytes
 *
 * `subject` is the viewer's user id when signed in, and the /24-or-/48
 * subnet plus user-agent when signed out (signed-out views count, per the
 * product decision; bots are excluded by never firing the beacon).
 *
 * ⚠ The **day is mixed into the message**, which is what bounds
 * linkability: the same reader produces a different hash tomorrow, so rows
 * cannot be correlated across days and there is no durable pseudonym
 * anywhere in the system. That is also precisely why `unique_viewers` is a
 * per-day figure and can never be a lifetime one.
 *
 * ⚠⚠ FAIL CLOSED ON A MISSING KEY. Mint nothing if the HMAC key is absent
 * — follow `AuthSupport`'s rule of refusing rather than falling back to an
 * unkeyed digest. An unkeyed (or weakly keyed) hash of a low-entropy
 * subject is a confirmation oracle: with on the order of a hundred
 * members, a user-id space is trivially enumerable, and `AuthSupport`
 * documents this exact defect being fixed once already. The secret, not
 * the truncation, is the defence. Plugin activation is hard-gated on
 * `BCC_ENCRYPTION_KEY`, so it is present in any booting install.
 *
 * ⛔ Do not reimplement the construction. Promote and reuse the existing
 * keyed-fingerprint helper (`Logger::fingerprintAddress`,
 * `DeviceFingerprinter::hashIp`) rather than adding a third copy, and
 * reuse `normalizeIpToSubnet` — already duplicated privately in
 * `Throttle` and `RateLimiter`, so a third copy would itself be a §11
 * violation.
 *
 * ── RETENTION IS THE ERASURE STORY ───────────────────────────────────
 * Rows are deleted within 48 hours. There is deliberately NO on-request
 * delete path, and that is the approved position rather than an omission:
 * `viewer_hash` is not reversible to a user id, so a per-user erasure
 * request could not target this table even if we wanted it to. The TTL is
 * the mechanism. The user-facing privacy copy says so plainly — the codes
 * delete themselves within 48 hours, so there is nothing left to delete on
 * request.
 *
 * ⚠ Belt AND suspenders, copied from `HeliusSeenSignaturesRepository`: an
 * age TTL **and** a row cap, with an overgrown alarm. Its docblock names
 * the footgun outright — replay/dedupe tables silently becoming infinite
 * append-only junk drawers — and a 48-hour promise that silently stops
 * being kept is worse here than elsewhere, because the retention IS the
 * privacy guarantee. Register the table in `RetentionHealthRepository` or
 * a stalled prune has nothing watching it.
 *
 * `idx_seen_at` serves both the age cutoff (`WHERE seen_at < %s`) and the
 * row-cap trim (`ORDER BY seen_at ASC LIMIT %d`).
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since F-324 post analytics V1 (2026-11-12)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_post_view_dedupe_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_post_view_dedupe';
    $charset = $wpdb->get_charset_collate();

    // viewer_hash is BINARY(16), not VARBINARY: it is always exactly 16
    // bytes, and a fixed-width column keeps the PRIMARY KEY compact.
    $sql = "CREATE TABLE {$table} (
        post_id BIGINT UNSIGNED NOT NULL,
        day DATE NOT NULL,
        viewer_hash BINARY(16) NOT NULL,
        seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (post_id, day, viewer_hash),
        KEY idx_seen_at (seen_at)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Post view dedupe table installed', []);
}
