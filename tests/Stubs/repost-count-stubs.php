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

namespace BCC\Core\Log {
    /**
     * ⚠ ADDED WHEN origin/main WAS MERGED IN, not part of the original
     * harness. `TableRegistry::exists()` routes its read-failure path through
     * `GuardsReadFailures`, which logs — so a test that DELIBERATELY fails a
     * read now reaches `Logger::error()`. Without this the suite died with
     * "Class BCC\Core\Log\Logger not found" from inside the table guard,
     * BEFORE the repository issued any SQL, which is why the failures showed
     * up as empty captured queries and null counts rather than as a missing
     * class.
     *
     * ⚠⚠ ALL FOUR METHODS, deliberately. A partial Logger stub is a known
     * trap in this suite: one that defined only `warning()` once broke 21
     * unrelated tests with "undefined method Logger::audit()". The real class
     * has exactly info/error/warning/audit, so this mirrors all of them even
     * though these tests only trip `error()`.
     */
    if (!\class_exists('BCC\Core\Log\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                unset($message, $context);
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                unset($message, $context);
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                unset($message, $context);
            }

            /** @param array<string, mixed> $context */
            public static function audit(string $message, array $context = []): void
            {
                unset($message, $context);
            }
        }
    }
}
