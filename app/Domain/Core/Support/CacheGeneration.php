<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generation counter in the object cache that a lost key can NOT rewind
 * (F-87 L2).
 *
 * The §5 pattern keys cached reads on a generation number and bumps it on
 * write, so stale entries become unreachable instead of being deleted. That
 * only holds while the number never repeats. A counter that restarts from a
 * fixed value (0 on a missing read, 1 on a failed incr) after its key is
 * evicted, expires or the cache restarts points readers back at whatever is
 * still cached under that old generation, and serves it as current.
 *
 * Here a missing key is SEEDED — on read and on bump alike — from the clock
 * in microseconds, with no expiry. A counter seeded at T and bumped n times
 * holds T + n; a later seed T' can only collide with it if it was bumped once
 * per microsecond for the whole gap. ~1.7e15 fits an int64 and Redis INCR /
 * Memcached increment.
 *
 * ⚠ A missing key does NOT always make incr fail: Redis Object Cache's
 * increment() is INCRBY (or get+set under igbinary), which CREATES the key at
 * the offset and returns 1. So "small" is the signal, not just false: any
 * value below SEED_FLOOR is a rewound or pre-F-87 counter. bump() re-seeds
 * it, and get() never keys a read on it.
 *
 * Use it for the external-object-cache branch. Without a persistent cache the
 * built-in cache is per-request, so the key cannot outlive the entries it
 * guards (and a DB-backed counter in wp_options does not evict).
 */
final class CacheGeneration
{
    /**
     * 1e15 µs ≈ 2001-09-09. Every seed is above it (the clock is ~1.7e15);
     * no counter that started small can reach it.
     */
    public const SEED_FLOOR = 1_000_000_000_000_000;

    /** Current generation for $key, seeding a missing key. Never 0. */
    public static function get(string $key, string $group): int
    {
        $gen = wp_cache_get($key, $group);
        if ($gen === false) {
            wp_cache_add($key, self::seed(), $group, 0);
            $gen = wp_cache_get($key, $group);
        }

        // Below the floor = a counter Redis just re-created at 1 (a bump is
        // about to re-seed it) or a pre-F-87 small counter: either can equal
        // a generation already used, so never key a read on it. Same when
        // the cache refused the write: a fresh value never lands on an old
        // generation (it just never hits). bump() heals the key.
        return $gen !== false && (int) $gen >= self::SEED_FLOOR ? (int) $gen : self::seed();
    }

    /** Move $key past every value it has held; seeds a missing key. */
    public static function bump(string $key, string $group): void
    {
        $result = wp_cache_incr($key, 1, $group);
        if ($result !== false && (int) $result >= self::SEED_FLOOR) {
            return;
        }
        // Missing (incr failed — core's fallback cache), re-created small
        // (Redis INCRBY on a missing key returns 1), or a legacy counter.
        // The clock is above all of them, so this still moves forward;
        // concurrent bumps both land at ~now, and readers never key on the
        // small interim value (get()).
        wp_cache_set($key, self::seed(), $group, 0);
    }

    /** A seed past any value an earlier counter for the key can hold. */
    public static function seed(): int
    {
        return (int) floor(microtime(true) * 1_000_000);
    }
}
