<?php

declare(strict_types=1);

/**
 * F-469 (L1): an in-memory object cache for RateLimiterUserInfoCacheTest.
 * Load only in a separate process. A miss answers false; a stored value,
 * null included, is answered as stored (WordPress object-cache semantics).
 */

namespace {
    if (!function_exists('wp_cache_get')) {
        function wp_cache_get(string $key, string $group = '', bool $force = false, mixed &$found = null): mixed
        {
            $k = $group . ':' . $key;
            $found = array_key_exists($k, $GLOBALS['__rl_cache'] ?? []);

            return $found ? $GLOBALS['__rl_cache'][$k] : false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
        {
            $GLOBALS['__rl_cache'][$group . ':' . $key] = $data;

            return true;
        }
    }
}
