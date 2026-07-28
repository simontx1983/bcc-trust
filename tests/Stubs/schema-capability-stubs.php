<?php

/**
 * Object-cache shims for the §J.12 schema-capability tests.
 *
 * TableRegistry::columnExists() deliberately caches only POSITIVE answers, so
 * these tests must be able to inspect what did — and crucially did NOT — reach
 * the cache. `BccTestSchemaCache::raw()` exposes the store for that assertion.
 *
 * Loaded ONLY from inside #[RunTestsInSeparateProcesses] subprocesses (same
 * isolation strategy as object-cache-stubs.php / nft-indexer-stubs.php) so the
 * main PHPUnit process never sees these global definitions.
 */

declare(strict_types=1);

namespace {

    // TableRegistry::EXISTS_CACHE_TTL is `HOUR_IN_SECONDS`, resolved when the
    // class loads. WordPress core is not booted in unit tests, so define it
    // before anything can trigger that autoload.
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!class_exists('BccTestSchemaCache', false)) {
        final class BccTestSchemaCache
        {
            /** @var array<string, mixed> */
            public static array $store = [];

            public static function reset(): void
            {
                self::$store = [];
            }

            /** Raw in-store value, or null when the key was never written. */
            public static function raw(string $key, string $group): mixed
            {
                return self::$store[$group . ':' . $key] ?? null;
            }
        }
    }

    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '')
        {
            $k = $group . ':' . $key;
            return array_key_exists($k, \BccTestSchemaCache::$store)
                ? \BccTestSchemaCache::$store[$k]
                : false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            \BccTestSchemaCache::$store[$group . ':' . $key] = $value;
            return true;
        }
    }

    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete(string $key, string $group = ''): bool
        {
            unset(\BccTestSchemaCache::$store[$group . ':' . $key]);
            return true;
        }
    }

    if (!function_exists('apply_filters')) {
        /**
         * Identity filter — TrustScoreService reads its tunables through
         * apply_filters(). Returning the value unchanged means these tests
         * assert the SHIPPED defaults, not a filtered override.
         *
         * @param mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            return $value;
        }
    }
}
