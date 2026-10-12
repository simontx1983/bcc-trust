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
 * ── ⚠ IT READS THE SAME LIST THE PAGE RENDERED FROM ─────────────────────
 * Through RingSnapshot::read(), which is also what
 * `AdminDashboardRepository::getRingsData()` reads. Renderer and resolver
 * therefore see the same list by construction, which is the property that
 * makes a fingerprint meaningful.
 *
 * ⚠ That read MOVED when the snapshot store landed. This class originally
 * read `wp_cache_get('ring_results')` directly, because that was where the
 * list lived; the store wraps that key in a payload and adds a durable
 * transient, so a direct read would now return the wrapper and resolve
 * nothing. The dependency runs one way only: resolution worked before the
 * store existed, but the store could not land without updating this.
 *
 * Nothing here writes, and nothing here changes which rings exist.
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
     * decides two detections are "the same ring" for its 30-day
     * re-penalisation guard. Two competing notions of ring identity would be
     * worse than one.
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

        // ⚠⚠ THIS READ MOVED, AND IT HAD TO. The moderation-safety change
        // read wp_cache_get('ring_results') directly, because that was where
        // the ring list lived. This change WRAPS that key in a payload
        // ({rings, computed_at}) and adds a durable transient — so a direct
        // read would now hand back the wrapper and find no `users` key in it,
        // and every destructive action would refuse.
        //
        // That is the one dependency between the two changes, and it runs in
        // this direction only: resolution works without the snapshot store
        // (it did), but the snapshot store cannot land without updating
        // resolution.
        //
        // RingSnapshot::read() handles both stores in the right order and
        // refuses a pre-change bare list, so a cache left warm across the
        // deploy reads as "no rings" until the next daily run rather than
        // being dereferenced for a computed_at that is not there.
        $snapshot = RingSnapshot::read();
        if ($snapshot['available'] !== true) {
            return null;
        }
        $rings = $snapshot['rings'];

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
