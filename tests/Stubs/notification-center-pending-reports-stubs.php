<?php
/**
 * Stubs for NotificationCenterPendingReportsTest ONLY.
 *
 * ⚠ Required from the test's setUp(), inside its separate process — never
 * at file level, where they would run in the PARENT process at discovery and
 * leak into the whole unit run (BCC_FRONTEND_ORIGIN in particular would
 * change every CORS/redirect test that reads it).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!defined('BCC_FRONTEND_ORIGIN')) {
    define('BCC_FRONTEND_ORIGIN', 'https://front.example, regex:^https://preview-.*$');
}
if (!function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return $GLOBALS['__f315_transients'][$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $ttl = 0): bool
    {
        $GLOBALS['__f315_transients'][$key] = $value;
        return true;
    }
}
