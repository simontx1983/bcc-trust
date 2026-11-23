<?php
/**
 * WordPressFrontEndGateTest doubles: the two WordPress functions the gate
 * calls that wp-signin-gate-stubs.php and the stubs it builds on (loaded
 * first) do not define. State lives in $GLOBALS['__bcc_sig'] beside theirs:
 *   - 'feed'     is_feed()
 *   - 'removed'  every remove_action() call, recorded
 */

declare(strict_types=1);

namespace {
    if (!function_exists('is_feed')) {
        function is_feed(): bool
        {
            return (bool) ($GLOBALS['__bcc_sig']['feed'] ?? false);
        }
    }

    // F-509: 'author' answers is_author() (kept so a test can show the gate
    // does NOT rely on it); 'query_vars' answers get_query_var() (default
    // ''); '404' answers is_404(); 'logged_in' answers is_user_logged_in().
    if (!function_exists('is_author')) {
        function is_author(): bool
        {
            return (bool) ($GLOBALS['__bcc_sig']['author'] ?? false);
        }
    }

    if (!function_exists('get_query_var')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_query_var(string $var, $default = '')
        {
            return $GLOBALS['__bcc_sig']['query_vars'][$var] ?? $default;
        }
    }

    if (!function_exists('is_404')) {
        function is_404(): bool
        {
            return (bool) ($GLOBALS['__bcc_sig']['404'] ?? false);
        }
    }

    if (!function_exists('is_user_logged_in')) {
        function is_user_logged_in(): bool
        {
            return (bool) ($GLOBALS['__bcc_sig']['logged_in'] ?? false);
        }
    }

    if (!function_exists('remove_action')) {
        /** @param mixed $callback */
        function remove_action(string $hook, $callback, int $priority = 10): bool
        {
            $GLOBALS['__bcc_sig']['removed'][] = [$hook, $callback, $priority];
            return true;
        }
    }
}
