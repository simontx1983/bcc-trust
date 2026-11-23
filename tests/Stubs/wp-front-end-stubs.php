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

    // F-509: 'author' answers is_author().
    if (!function_exists('is_author')) {
        function is_author(): bool
        {
            return (bool) ($GLOBALS['__bcc_sig']['author'] ?? false);
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
