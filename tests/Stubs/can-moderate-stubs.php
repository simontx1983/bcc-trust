<?php

declare(strict_types=1);

/**
 * Recording capability checks for CanModerateFlagTest. Load only in a
 * separate process. User 1 holds `manage_options`; nobody else holds anything.
 */

namespace {
    if (!function_exists('user_can')) {
        function user_can(mixed $user, string $capability, mixed ...$args): bool
        {
            $GLOBALS['__bcc_cm_asked'][] = ['user_can', (int) $user, $capability];
            if ((int) $user === 3) {
                throw new \RuntimeException('capability lookup failed');
            }

            return (int) $user === 1 && $capability === 'manage_options';
        }
    }

    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability, mixed ...$args): bool
        {
            $GLOBALS['__bcc_cm_asked'][] = ['current_user_can', 0, $capability];

            return false;
        }
    }
}
