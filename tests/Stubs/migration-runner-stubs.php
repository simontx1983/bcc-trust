<?php
/**
 * Stubs for MigrationRunnerTest (subprocess-isolated).
 *
 * In-memory wp_options + a controllable \BCC\Core\DB\AdvisoryLock so the
 * runner can be exercised without WordPress or MySQL. Fixtures:
 *   $GLOBALS['__bcc_opts']        — option store
 *   $GLOBALS['__bcc_opt_autoload']— autoload flag captured per update_option
 *   $GLOBALS['__bcc_lock_grant']  — bool: whether acquire() succeeds (default true)
 *   $GLOBALS['__bcc_lock_calls']  — ordered [op, key] log of acquire/release
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_option')) {
        /** @return mixed */
        function get_option(string $name, $default = false)
        {
            return array_key_exists($name, $GLOBALS['__bcc_opts'] ?? [])
                ? $GLOBALS['__bcc_opts'][$name]
                : $default;
        }
    }
    if (!function_exists('update_option')) {
        /** @param mixed $value */
        function update_option(string $name, $value, $autoload = null): bool
        {
            $GLOBALS['__bcc_opts'][$name]         = $value;
            $GLOBALS['__bcc_opt_autoload'][$name] = $autoload;
            return true;
        }
    }
}

namespace BCC\Core\DB {
    /**
     * Controllable advisory-lock stub. Grants unless
     * $GLOBALS['__bcc_lock_grant'] is false; records every call so tests can
     * assert acquire/release pairing.
     */
    class AdvisoryLock
    {
        public static function acquire(string $key, int $timeout = 0): bool
        {
            $GLOBALS['__bcc_lock_calls'][] = ['acquire', $key];
            $grant = $GLOBALS['__bcc_lock_grant'] ?? true;
            return (bool) $grant;
        }

        public static function release(string $key): void
        {
            $GLOBALS['__bcc_lock_calls'][] = ['release', $key];
        }
    }
}
