<?php
/**
 * bcc-core's Permissions, reduced to the suspension reads a join verdict
 * makes (GroupsService::resolveCanJoin). Per user, from
 * $GLOBALS['__bcc_suspension'][userId]: true suspended, null unknown,
 * absent = not suspended.
 *
 * ⚠ A class stub: load it only in a test that runs in its own process, or a
 * suite that does (#[RunTestsInSeparateProcesses] / #[RunInSeparateProcess]),
 * so it can never shadow another suite's Permissions fake.
 */

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        class Permissions
        {
            public static function suspensionState(?int $user_id = null, bool $allowAdminBypass = true): ?bool
            {
                $states = $GLOBALS['__bcc_suspension'] ?? [];

                return array_key_exists((int) $user_id, $states) ? $states[(int) $user_id] : false;
            }

            public static function is_not_suspended(?int $user_id = null, bool $allowAdminBypass = true): bool
            {
                return self::suspensionState($user_id, $allowAdminBypass) === false;
            }
        }
    }
}
