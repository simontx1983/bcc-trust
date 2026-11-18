<?php
/**
 * Stubs for CanClaimPermissionTest (F-301). Subprocess-only: required from
 * setUp() of a #[RunTestsInSeparateProcesses] class, never at file scope.
 *
 * $GLOBALS['__bcc_susp'] maps user id → true | false | null (UNKNOWN).
 */

declare(strict_types=1);

namespace BCC\Core\Permissions {
    if (!class_exists(__NAMESPACE__ . '\Permissions', false)) {
        final class Permissions
        {
            public static function suspensionState(?int $user_id = null, bool $allowAdminBypass = true): ?bool
            {
                $GLOBALS['__bcc_susp_bypass'][] = $allowAdminBypass;
                $map = $GLOBALS['__bcc_susp'] ?? [];
                return array_key_exists((int) $user_id, $map) ? $map[(int) $user_id] : false;
            }
        }
    }
}
