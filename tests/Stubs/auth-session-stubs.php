<?php
/**
 * Stubs for AuthSessionEndpointTest — loaded BEFORE account-prehijack-stubs.php.
 *
 * Subprocess-only. get_current_user_id() reports a logged-in WP cookie session
 * for a DIFFERENT, real user (71), so the test can prove GET /auth/session
 * ignores it and validates only the presented Bearer token.
 */

declare(strict_types=1);

namespace BCC\Core\Permissions {
    // F-305: the suspension flag on auth responses. $GLOBALS['__bcc_susp']
    // maps user id → true (suspended) | false | null (UNKNOWN: the read
    // failed). Absent → false.
    if (!class_exists(__NAMESPACE__ . '\Permissions', false)) {
        final class Permissions
        {
            public static function suspensionState(?int $user_id = null, bool $allowAdminBypass = true): ?bool
            {
                $map = $GLOBALS['__bcc_susp'] ?? [];
                return array_key_exists((int) $user_id, $map) ? $map[(int) $user_id] : false;
            }
        }
    }
}

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return 71;
        }
    }
}
