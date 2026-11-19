<?php
/**
 * Stubs for PostEngagementRepostCountSqlTest (F-346).
 *
 * ⚠⚠ The consuming test is `#[RunTestsInSeparateProcesses]` +
 * `#[PreserveGlobalState(false)]`, and that is why this file exists at all
 * rather than the test leaning on whatever another file happened to define.
 * In a shared process `wp_cache_get` is present only because some OTHER test
 * file declared it — so a test that depended on that would pass or fail
 * depending on suite ORDER, which is the leak this suite warns about
 * elsewhere. An isolated child inherits nothing, so it must bring its own.
 *
 * The cache always MISSES on purpose: `TableRegistry::exists()` should reach
 * its `SHOW TABLES` probe, which is the branch the test's `$wpdb` double
 * answers. A stub that returned a hit would skip the probe and the test would
 * be asserting against a cache rather than against the repository.
 *
 * `HOUR_IN_SECONDS` is WordPress's own constant (3600), referenced by
 * `TableRegistry::EXISTS_CACHE_TTL` and not defined by this suite's
 * bootstrap.
 */

declare(strict_types=1);

namespace {
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!function_exists('wp_cache_get')) {
        /**
         * @param bool|null $found
         * @return mixed always a miss
         */
        function wp_cache_get(string $key, string $group = '', bool $force = false, &$found = null)
        {
            unset($key, $group, $force);
            $found = false;

            return false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        /** @param mixed $data */
        function wp_cache_set(string $key, $data, string $group = '', int $expire = 0): bool
        {
            unset($key, $data, $group, $expire);

            return true;
        }
    }

    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete(string $key, string $group = ''): bool
        {
            unset($key, $group);

            return true;
        }
    }
}
