<?php
/**
 * PushMetrics — V2 Phase 1 §P1.F observability counters.
 *
 * Four outcome buckets, recorded per (outcome, event_type) per UTC
 * hour. Mirrors `DisputeNotificationService::recordMailFailure()` so
 * the storage pattern is consistent across BCC subsystems.
 *
 * Buckets:
 *   - attempt   — every dispatch send try (denominator)
 *   - success   — provider accepted (2xx)
 *   - tombstone — provider returned 404/410, subscription deleted
 *   - failure   — anything else (5xx, network error, malformed, etc.)
 *
 * Storage:
 *   - Persistent object cache (Redis / Memcached): atomic via
 *     wp_cache_add + wp_cache_incr.
 *   - Transient fallback: non-atomic RMW. Documented trade-off for
 *     sites without persistent caching — under contention some
 *     increments may be lost. Acceptable for an observability metric.
 *
 * Bucket key format: `bcc_push_{outcome}_{eventType}_{YYYYMMDDHH}` (UTC).
 *
 * Without this instrumentation we'd think push is working when it
 * isn't. Push systems fail silently in many ways — expired
 * subscriptions, browser permission revokes, payload size limits.
 *
 * @package BCC\Trust\Core\Support
 * @since V2 Phase 1
 * @status alive — V2 Phase 1 §P1.F push-delivery observability counters.
 *                 Consumers: PushDispatcher (write path) + admin "Push
 *                 delivery stats" tab (read path). Per-(outcome, event_type)
 *                 hourly buckets; atomic via wp_cache_add/incr with
 *                 transient fallback. Phase B V-18 classification 2026-05-09.
 *                 See docs/pattern-registry.md "Phase B inventory addendum".
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PushMetrics
{
    public const OUTCOME_ATTEMPT   = 'attempt';
    public const OUTCOME_SUCCESS   = 'success';
    public const OUTCOME_TOMBSTONE = 'tombstone';
    public const OUTCOME_FAILURE   = 'failure';

    /** @var list<string> */
    public const ALL_OUTCOMES = [
        self::OUTCOME_ATTEMPT,
        self::OUTCOME_SUCCESS,
        self::OUTCOME_TOMBSTONE,
        self::OUTCOME_FAILURE,
    ];

    private const KEY_PREFIX  = 'bcc_push_';
    private const CACHE_GROUP = 'bcc_push_metrics';
    private const TTL         = 7200; // 2 hours; admin tab only displays current + previous hour.

    public static function recordAttempt(string $eventType): void
    {
        self::increment(self::OUTCOME_ATTEMPT, $eventType);
    }

    public static function recordSuccess(string $eventType): void
    {
        self::increment(self::OUTCOME_SUCCESS, $eventType);
    }

    public static function recordTombstone(string $eventType): void
    {
        self::increment(self::OUTCOME_TOMBSTONE, $eventType);
    }

    public static function recordFailure(string $eventType): void
    {
        self::increment(self::OUTCOME_FAILURE, $eventType);
    }

    /**
     * Snapshot every (outcome, eventType) counter for a UTC hour bucket.
     * Used by the admin "Push delivery stats" tab.
     *
     * @return array<string, array<string, int>> outcome => eventType => count
     */
    public static function readSnapshot(int $timestamp): array
    {
        $hour = gmdate('YmdH', $timestamp);

        $snapshot = [];
        foreach (self::ALL_OUTCOMES as $outcome) {
            $snapshot[$outcome] = [];
            foreach (NotificationPrefs::PUSH_TYPES as $eventType) {
                $snapshot[$outcome][$eventType] = self::readBucket(
                    self::buildKey($outcome, $eventType, $hour)
                );
            }
        }
        return $snapshot;
    }

    /**
     * The same snapshot, plus whether any counter was actually RECORDED.
     *
     * ── WHY readSnapshot() IS NOT CHANGED ───────────────────────────────
     * It returns a plain outcome→type→int map and is public. Tracing callers
     * first found exactly two, both in the admin Push tab — so reshaping it
     * would be safe today, which is not a reason to change a public
     * contract. This is additive and admin-specific; readSnapshot() still
     * means precisely what it meant.
     *
     * ── THE DISTINCTION IT ADDS ─────────────────────────────────────────
     * readBucket() returns 0 for a bucket that is ABSENT and 0 for a bucket
     * that genuinely counted nothing. The tab therefore rendered "0 sends"
     * for an hour in which nothing was recorded at all — before the first
     * push of a new hour, on a fresh install, after the 2-hour TTL expired,
     * or where the counters never reach the reader.
     *
     * ⚠ That last case is not hypothetical. increment() prefers
     * wp_cache_incr when wp_using_ext_object_cache() and otherwise falls
     * back to a transient, and readBucket() mirrors that order — so the
     * counters DO survive without a persistent object cache. (The Vote Rings
     * snapshot has no such fallback, which is why its tab can never show a
     * ring on a host without one. Same family of problem, different
     * outcome, and worth stating because the two look identical from the
     * outside.)
     *
     * `available` is deliberately NOT called `ok`: an hour with no recorded
     * pushes is not an error, and dressing it as one would train operators
     * to ignore it.
     *
     * @return array{available: bool, buckets: array<string, array<string, int>>}
     */
    public static function readSnapshotForAdmin(int $timestamp): array
    {
        $hour      = gmdate('YmdH', $timestamp);
        $buckets   = [];
        $anyPresent = false;

        foreach (self::ALL_OUTCOMES as $outcome) {
            $buckets[$outcome] = [];
            foreach (NotificationPrefs::PUSH_TYPES as $eventType) {
                [$value, $present] = self::readBucketWithPresence(
                    self::buildKey($outcome, $eventType, $hour)
                );
                $buckets[$outcome][$eventType] = $value;
                $anyPresent = $anyPresent || $present;
            }
        }

        return ['available' => $anyPresent, 'buckets' => $buckets];
    }

    /**
     * A bucket's value AND whether it existed, in the same storage order
     * readBucket() uses.
     *
     * ⚠ The order matters and is not cosmetic: with an external object cache
     * a bucket lives there, and only the transient is consulted otherwise.
     * Reading them in the other order would report a stale transient over a
     * live counter.
     *
     * @return array{0: int, 1: bool}
     */
    private static function readBucketWithPresence(string $key): array
    {
        if (wp_using_ext_object_cache()) {
            $cached = wp_cache_get($key, self::CACHE_GROUP);
            if ($cached !== false) {
                return [(int) $cached, true];
            }
        }

        $value = get_transient($key);

        return $value === false ? [0, false] : [(int) $value, true];
    }

    /**
     * Total sends in a hour as a single number — useful for "is anything
     * happening at all?" health checks.
     */
    public static function totalAttemptsForHour(int $timestamp): int
    {
        $hour = gmdate('YmdH', $timestamp);
        $total = 0;
        foreach (NotificationPrefs::PUSH_TYPES as $eventType) {
            $total += self::readBucket(self::buildKey(self::OUTCOME_ATTEMPT, $eventType, $hour));
        }
        return $total;
    }

    private static function buildKey(string $outcome, string $eventType, string $hour): string
    {
        return self::KEY_PREFIX . $outcome . '_' . $eventType . '_' . $hour;
    }

    private static function increment(string $outcome, string $eventType): void
    {
        $key = self::buildKey($outcome, $eventType, gmdate('YmdH'));

        if (wp_using_ext_object_cache()) {
            // wp_cache_add seeds the bucket atomically on first hit.
            // wp_cache_incr is atomic INCR on subsequent hits.
            if (wp_cache_add($key, 1, self::CACHE_GROUP, self::TTL)) {
                return;
            }
            $incr = wp_cache_incr($key, 1, self::CACHE_GROUP);
            if ($incr !== false) {
                return;
            }
            // Cache backend hiccup — fall through to transient so the
            // increment is not lost entirely.
        }

        $current = (int) get_transient($key);
        set_transient($key, $current + 1, self::TTL);
    }

    private static function readBucket(string $key): int
    {
        if (wp_using_ext_object_cache()) {
            $cached = wp_cache_get($key, self::CACHE_GROUP);
            if ($cached !== false) {
                return (int) $cached;
            }
        }
        $value = get_transient($key);
        return $value === false ? 0 : (int) $value;
    }
}
