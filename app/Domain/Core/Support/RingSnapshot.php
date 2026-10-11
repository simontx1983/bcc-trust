<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

/**
 * The one place a ring-detection snapshot is written and read.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * The snapshot had ONE writer (CronService::dailyGraphUpdate) using
 * wp_cache_set and nothing else, and TWO readers with opposite cold-key
 * behaviour. wp_cache_* is per-request unless an object-cache.php drop-in is
 * installed, and staging has none — so the hourly job computed the expensive
 * thing and threw it away, every time.
 *
 * ⚠ MEASURED, not inferred (isolated MariaDB 11.8.9 container, 2026-10-11):
 *
 *   input shape                        clusters  serialize()   graph pass
 *   staging today (empty)                     0        6 B        0.001 s
 *   12 vote pairs + 30 vouch edges           14      2.1 KiB      0.060 s
 *   vote query saturated (500-pair cap)      84     11.7 KiB      1.032 s
 *   9,999 vouch edges as disjoint 3-cycles 3,333    521.7 KiB    11.000 s
 *   9,900 vouch edges as a 100-node clique 4,851   2,572.6 KiB   91.925 s
 *
 * The last row is the worst case INSIDE the algorithm's own limits, and it
 * is what makes this worth doing: 91.9 seconds of graph work, versus a
 * 12.6 ms read of the stored snapshot.
 *
 * ── WHY A TRANSIENT IS ENOUGH, ALSO MEASURED ────────────────────────────
 * The open question on the proposal was whether a payload this size belongs
 * in wp_options at all. It does:
 *
 *   - option_value is LONGTEXT, and max_allowed_packet is 16 MiB — 2.9 MiB
 *     of serialized payload fits with 5.7x headroom
 *   - ⚠ the row is NOT AUTOLOADED (measured `autoload = off` at every size).
 *     This was the decisive risk: an autoloaded 2.9 MiB row would be read on
 *     every request on the site. It is not, so it is not.
 *   - it round-trips byte-identically at every size tested
 *   - write 81.8 ms / read 12.6 ms at the worst case; 1.9 ms / 0.1 ms at the
 *     realistic one
 *
 * No table, therefore: no schema, no migration, no drift-guard row and no
 * retention policy for something a non-autoloaded transient holds for 12 ms.
 *
 * ── THE PATTERN IS COPIED, NOT INVENTED ─────────────────────────────────
 * PushMetrics in this same namespace already solves "work on a host with a
 * persistent object cache AND on a host without one", and #307 pins both of
 * its halves with tests. Four properties are taken verbatim:
 *
 *   - wp_using_ext_object_cache() is the gate, not a guess about the host
 *   - the transient is used on BOTH write and read, in the same order
 *   - one TTL for both paths, so the two cannot disagree about freshness
 *   - a backend hiccup falls through rather than losing the write
 *
 * ── TTL IS DELIBERATELY UNCHANGED ───────────────────────────────────────
 * DAY_IN_SECONDS, exactly as the wp_cache_set it replaces. Shortening it is
 * a behaviour change on hosts where caching currently works and needs its
 * own decision; this class reports `computed_at` and `age_seconds` so a
 * reader can judge age, and deliberately does NOT invent a staleness verdict.
 */
final class RingSnapshot
{
    /** Shared with the pre-existing wp_cache key so a warm cache is not orphaned. */
    public const CACHE_GROUP = 'bcc_trust_admin';
    public const CACHE_KEY   = 'ring_results';

    /** Transient name. Distinct from the cache key: the two stores are separate. */
    private const TRANSIENT = 'bcc_trust_ring_snapshot';

    /**
     * Ring kinds. `strength` is meaningful only for a vote ring — the
     * endorsement detector computes no strength at all.
     */
    public const KIND_VOTE        = 'vote_ring';
    public const KIND_ENDORSEMENT = 'endorsement_ring';

    private static function ttl(): int
    {
        return defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
    }

    /**
     * Store a snapshot so that something other than this request can read it.
     *
     * ⚠ The object cache is written when the host HAS one, and the transient
     * is written ALWAYS — not as a fallback-on-failure. A snapshot that
     * survives only when the backend happens to be healthy is the bug this
     * class exists to remove, and the write is once a day.
     *
     * ⚠ `array<int, ...>`, not `list<...>`: getSuspiciousClusters() is an
     * array_merge of two detectors and PHPStan will not narrow that to a
     * list. Declaring a list here is a lie the caller has to be cast around,
     * and nothing in store() needs list semantics — normalise() rebuilds a
     * genuine list with `$out[] =` regardless of what it was handed.
     *
     * @param array<int, array<string, mixed>> $rings as returned by
     *        TrustGraph::getSuspiciousClusters()
     */
    public static function store(array $rings): void
    {
        $payload = [
            'rings'       => self::normalise($rings),
            'computed_at' => time(),
        ];

        if (\function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            wp_cache_set(self::CACHE_KEY, $payload, self::CACHE_GROUP, self::ttl());
        }

        set_transient(self::TRANSIENT, $payload, self::ttl());
    }

    /**
     * Read the snapshot, with "there isn't one" reported as a FACT rather
     * than as an empty list.
     *
     * ⚠ `available` is deliberately not called `ok`: on a fresh install, or
     * before the first run, having no snapshot is correct and expected. The
     * Vote Rings tab leans on that distinction — it must never print
     * "no vote rings detected" from an absent snapshot.
     *
     * ⚠ Read order mirrors the write order and is not cosmetic: with a
     * persistent cache the live snapshot is there and the transient may be an
     * older copy of it, so reading the transient first would serve the stale
     * one.
     *
     * @return array{
     *   available: bool,
     *   rings: list<array<string, mixed>>,
     *   computed_at: int|null,
     *   age_seconds: int|null
     * }
     */
    public static function read(): array
    {
        $payload = false;

        if (\function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
            if (self::looksLikePayload($cached)) {
                $payload = $cached;
            }
        }

        if ($payload === false) {
            $stored = get_transient(self::TRANSIENT);
            if (self::looksLikePayload($stored)) {
                $payload = $stored;
            }
        }

        if ($payload === false) {
            return [
                'available'   => false,
                'rings'       => [],
                'computed_at' => null,
                'age_seconds' => null,
            ];
        }

        /** @var array{rings: list<array<string, mixed>>, computed_at: int} $payload */
        $computedAt = (int) $payload['computed_at'];

        return [
            'available'   => true,
            'rings'       => $payload['rings'],
            'computed_at' => $computedAt,
            'age_seconds' => max(0, time() - $computedAt),
        ];
    }

    /**
     * ⚠ A BARE LIST IS NOT ACCEPTED AS A PAYLOAD.
     *
     * The pre-existing wp_cache key held a bare list of rings. A build that
     * wrote one, or a cache left warm across a deploy, would otherwise be
     * read as a payload and then dereferenced for a 'computed_at' that is not
     * there. Requiring both keys makes an old value simply "not available",
     * which is the honest answer and the safe one.
     *
     * @param mixed $value
     */
    private static function looksLikePayload($value): bool
    {
        return \is_array($value)
            && \array_key_exists('rings', $value)
            && \array_key_exists('computed_at', $value)
            && \is_array($value['rings'])
            && \is_numeric($value['computed_at']);
    }

    /**
     * Give every ring the SAME KEYS, whichever detector produced it.
     *
     * ── ⚠⚠ THIS IS THE PART THAT IS NOT OPTIONAL ───────────────────────
     * getSuspiciousClusters() is array_merge(detectVoteRings(),
     * detectEndorsementRings()) and the two halves emit different shapes:
     *
     *   vote ring        users, size, strength,  detected_at   (no 'type')
     *   endorsement ring users, size, type,      detected_at   (no 'strength')
     *
     * FraudDetector reads $ring['strength'] UNGUARDED for any ring containing
     * the user, while its own fallback calls detectVoteRings() ONLY — so the
     * fallback is shape-safe and the cached path is not. Measured in an
     * isolated container by caching one ring of each shape and running the
     * detector's loop against a member of the endorsement ring:
     *
     *   Undefined array key "strength"
     *   "User is part of a vote ring with 3 members (strength: 0.00)"
     *
     * A warning, and a fabricated 0.00 strength on a ring that has none.
     * That is dormant today ONLY because no host populates the key — which
     * is precisely what this class changes. Persisting the snapshot without
     * normalising the shape would ACTIVATE it, so the two land together.
     *
     * `strength` is therefore always present and NULL for an endorsement
     * ring: null is "this detector does not compute strength", which is true,
     * where 0.0 would assert a measured weakness.
     *
     * @param array<int, array<string, mixed>> $rings
     * @return list<array<string, mixed>>
     */
    private static function normalise(array $rings): array
    {
        $out = [];
        foreach ($rings as $ring) {
            if (!\is_array($ring) || !isset($ring['users']) || !\is_array($ring['users'])) {
                continue;
            }
            $users = array_values(array_map('intval', $ring['users']));
            $kind  = isset($ring['type']) && $ring['type'] === self::KIND_ENDORSEMENT
                ? self::KIND_ENDORSEMENT
                : self::KIND_VOTE;

            $out[] = [
                'users'       => $users,
                'size'        => isset($ring['size']) ? (int) $ring['size'] : \count($users),
                'kind'        => $kind,
                // null, not 0.0 — see the docblock.
                'strength'    => isset($ring['strength']) ? (float) $ring['strength'] : null,
                'detected_at' => isset($ring['detected_at']) ? (string) $ring['detected_at'] : null,
            ];
        }
        return $out;
    }
}
