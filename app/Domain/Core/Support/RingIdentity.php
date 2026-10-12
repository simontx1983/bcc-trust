<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

/**
 * Identifies a vote/endorsement ring by its MEMBERS, and resolves one back
 * out of wherever the ring list currently lives.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * The Vote Rings tab offers two DESTRUCTIVE actions per ring — invalidate
 * every vote between the members, and suspend all of them. Both used to post
 * the member ids in a hidden field, so the set of accounts to suspend arrived
 * from the browser. This class replaces that: the form posts a fingerprint,
 * and the handler resolves the members server-side.
 *
 * ── ⚠ IT READS THE STORE THAT EXISTS TODAY, DELIBERATELY ────────────────
 * `wp_cache_get('ring_results', 'bcc_trust_admin')` — the same key
 * `AdminDashboardRepository::getRingsData()` renders from, and the same key
 * `CronService::dailyGraphUpdate()` writes. Renderer and resolver therefore
 * read the same list by construction, which is the property that makes a
 * fingerprint meaningful.
 *
 * This is NOT a storage change. Nothing here writes, nothing changes where
 * rings live, and nothing changes which rings exist. A separate, unmerged
 * change proposes making that store durable; this class works with or
 * without it, because it only ever asks "is this ring in the list the page
 * just rendered from".
 *
 * ⚠ The consequence worth stating: on a host with no `object-cache.php`
 * drop-in, `wp_cache_*` is per-request, so this read MISSES and resolution
 * refuses. That is correct and changes nothing — on such a host
 * `getRingsData()` already returns `available: false`, the tab renders no
 * ring panels, and therefore no destructive form exists to submit. The
 * buttons are reachable today only where the read can succeed.
 *
 * ── SHAPE ───────────────────────────────────────────────────────────────
 * It depends on exactly one key: `users`. BOTH detectors emit it —
 * `detectVoteRings()` as `users, size, strength, detected_at` and
 * `detectEndorsementRings()` as `users, size, type, detected_at` — so
 * resolution needs no normalisation and works on the raw merged list that
 * `getSuspiciousClusters()` produces.
 */
final class RingIdentity
{
    /** The store the tab renders from and the cron writes. */
    public const CACHE_GROUP = 'bcc_trust_admin';
    public const CACHE_KEY   = 'ring_results';

    /**
     * A stable identifier for a ring, derived from its members.
     *
     * ⚠ Same shape as TrustGraph::ringTransientKey() — intval, sort, md5 of
     * the comma-joined list — because that is already how this codebase
     * decides two detections are "the same ring" for its 30-day transient.
     * Two competing notions of ring identity would be worse than one.
     *
     * ⚠ That transient was a re-penalisation guard until reciprocity stopped
     * carrying a penalty. It now dedupes the soft-flag log line, and it is
     * what makes a revert of that change safe: with the keys intact an
     * already-flagged ring stays un-penalised until each lapses, whereas
     * clearing them would re-penalise every member on the next pass.
     *
     * ⚠ md5 is an identity key here, not a security control. Nothing is
     * authenticated by it: the capability check and the nonce do that, and a
     * fingerprint only ever selects from a list the server already holds.
     *
     * @param array<int, int|string> $users
     */
    public static function fingerprint(array $users): string
    {
        $ids = array_map('intval', $users);
        sort($ids);
        return md5(implode(',', $ids));
    }

    /**
     * The ring in the CURRENT list with this fingerprint, or null.
     *
     * ⚠ Returns null — meaning refuse — for every uncertain case: no list,
     * an unreadable or non-array value, a fingerprint from a page rendered
     * before the list changed, a ring no longer detected, a subset, a
     * superset, or a malformed hash. A destructive action must have a
     * positively identified target, so absence is never treated as
     * "act on what you were given".
     *
     * @return array<string, mixed>|null
     */
    public static function findByFingerprint(string $fingerprint): ?array
    {
        if ($fingerprint === '' || preg_match('/^[a-f0-9]{32}$/', $fingerprint) !== 1) {
            return null;
        }

        $rings = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if (!\is_array($rings)) {
            return null;
        }

        foreach ($rings as $ring) {
            if (!\is_array($ring) || !isset($ring['users']) || !\is_array($ring['users'])) {
                continue;
            }
            if (hash_equals(self::fingerprint($ring['users']), $fingerprint)) {
                return $ring;
            }
        }

        return null;
    }

    /**
     * The member ids of a ring, as positive integers.
     *
     * ⚠ THIS IS THE PARSE THAT WAS WRONG. The tab read `$ring['members']`
     * and then array_column(..., 'user_id') over it — a different key AND a
     * different shape, since both detectors emit `users` as a flat list of
     * ints. So the member list was always empty, the count always 0, and the
     * hidden field the destructive forms posted always "".
     *
     * @param array<string, mixed> $ring
     * @return list<int>
     */
    public static function members(array $ring): array
    {
        if (!isset($ring['users']) || !\is_array($ring['users'])) {
            return [];
        }
        $ids = [];
        foreach ($ring['users'] as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Which detector produced a ring.
     *
     * ⚠ Reads `kind` first and falls back to `type`. The raw merged list
     * carries `type` on endorsement rings only; a separate change normalises
     * the payload and emits `kind` on every ring. Accepting either means
     * this file does not have to move when that lands.
     *
     * @param array<string, mixed> $ring
     */
    public static function isEndorsementRing(array $ring): bool
    {
        $kind = $ring['kind'] ?? $ring['type'] ?? '';
        return $kind === 'endorsement_ring';
    }
}
