<?php
/**
 * Object-cache doubles for the hot-feed cache-isolation test.
 *
 * ⚠⚠ WHY THIS IS ITS OWN FILE AND NOT AN ADDITION TO AN EXISTING STUB.
 * `wp_cache_get()` / `wp_cache_set()` are already declared by two other
 * harnesses in this repository — the integration bootstrap and
 * `breaker-real-stubs.php`. A `function_exists`-guarded function definition
 * is PROCESS-GLOBAL and `setUp()` cannot undo it, so whichever file loads
 * first wins for the rest of the process. This repository has been bitten by
 * that twice: a competing `WP_Post` stub, and an `add_action()` no-op that
 * silently emptied another suite's recorded subscriber list.
 *
 * So this file is loaded ONLY by `HotFeedCacheIsolationTest`, which runs
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`. That is
 * the one thing which makes a process-global declaration safe, and the test
 * PROVES the isolation is in force rather than assuming it.
 *
 * The store is deliberately dumb: a flat array keyed by "group\0key", so a
 * test can read back exactly what the production code wrote and nothing is
 * normalised on the way through.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('BccHotFeedCacheStore', false)) {
        final class BccHotFeedCacheStore
        {
            /** @var array<string, mixed> */
            public static array $store = [];

            /** Every set() call, in order, as "group\0key". */
            public static array $writes = [];

            public static function reset(): void
            {
                self::$store  = [];
                self::$writes = [];
            }

            public static function id(string $key, string $group): string
            {
                return $group . "\0" . $key;
            }
        }
    }

    if (!function_exists('wp_cache_get')) {
        /**
         * @param  string $key
         * @return mixed
         */
        function wp_cache_get($key, string $group = '', bool $force = false, &$found = null)
        {
            $id    = BccHotFeedCacheStore::id((string) $key, $group);
            $found = array_key_exists($id, BccHotFeedCacheStore::$store);

            return $found ? BccHotFeedCacheStore::$store[$id] : false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        /**
         * @param string $key
         * @param mixed  $value
         */
        function wp_cache_set($key, $value, string $group = '', int $expire = 0): bool
        {
            $id = BccHotFeedCacheStore::id((string) $key, $group);

            BccHotFeedCacheStore::$store[$id]  = $value;
            BccHotFeedCacheStore::$writes[]    = $id;

            return true;
        }
    }
}
