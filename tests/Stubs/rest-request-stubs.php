<?php
/**
 * Doubles for BearerAuth::isRestRequest(): which entry point this request
 * came through, and the REST prefix. State lives in $GLOBALS['__bcc_sig'],
 * the same array wp-signin-gate-stubs.php uses:
 *   - 'ajax'         wp_doing_ajax()  (the SAME key wp-signin-gate-stubs.php
 *                    reads, so whichever file defines it first, it answers
 *                    the same)
 *   - 'admin'        is_admin()
 *   - 'cron'         wp_doing_cron()
 *   - 'rest_prefix'  rest_get_url_prefix(), default 'wp-json'
 *
 * Load after account-prehijack-stubs.php (home_url, wp_parse_url). A test
 * sets $_SERVER['SCRIPT_NAME'] and $_SERVER['REQUEST_URI'] itself, as the
 * web server would.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('rest_get_url_prefix')) {
        function rest_get_url_prefix(): string
        {
            return (string) ($GLOBALS['__bcc_sig']['rest_prefix'] ?? 'wp-json');
        }
    }

    if (!function_exists('wp_doing_ajax')) {
        function wp_doing_ajax(): bool
        {
            return (bool) ($GLOBALS['__bcc_sig']['ajax'] ?? false);
        }
    }

    if (!function_exists('is_admin')) {
        function is_admin(): bool
        {
            return (bool) ($GLOBALS['__bcc_sig']['admin'] ?? false);
        }
    }

    if (!function_exists('wp_doing_cron')) {
        function wp_doing_cron(): bool
        {
            return (bool) ($GLOBALS['__bcc_sig']['cron'] ?? false);
        }
    }
}
