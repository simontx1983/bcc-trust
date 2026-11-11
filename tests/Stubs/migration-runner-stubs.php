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

    // F-151: the runner backs a failed migration off through a transient.
    // Expiry is recorded, not enforced — a test "lets the hour pass" by
    // deleting the entry.
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $name)
        {
            return $GLOBALS['__bcc_transients'][$name]['value'] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $name, $value, int $expiration = 0): bool
        {
            $GLOBALS['__bcc_transients'][$name] = ['value' => $value, 'expiration' => $expiration];
            return true;
        }
    }
    // bcc_trust_migration_step_failed() logs $wpdb->last_error.
    if (!isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = (object) ['last_error' => ''];
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
