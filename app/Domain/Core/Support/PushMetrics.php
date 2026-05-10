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
