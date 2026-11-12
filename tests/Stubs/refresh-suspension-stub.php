<?php
/**
 * bcc-core's Permissions::suspensionState for unit tests of
 * POST /auth/refresh: $GLOBALS['__bcc_suspended'] (true / false / null).
 */

declare(strict_types=1);

namespace BCC\Core\Permissions;

if (!class_exists(__NAMESPACE__ . '\Permissions', false)) {
    final class Permissions
    {
        public static function suspensionState(int $userId): ?bool
        {
            return $GLOBALS['__bcc_suspended'] ?? false;
        }
    }
}
