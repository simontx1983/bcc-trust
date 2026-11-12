<?php

declare(strict_types=1);

/**
 * bcc-core collaborators for the digest tests only (separate process).
 * $GLOBALS['__dg_suspended'] list<int> — suspended user ids.
 * $GLOBALS['__dg_log']       list<array{string, string}> — level, message.
 */

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            public static function is_not_suspended(?int $user_id = null, bool $allowAdminBypass = true): bool
            {
                return !in_array((int) $user_id, $GLOBALS['__dg_suspended'] ?? [], true);
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function warning(string $msg, array $ctx = []): void
            {
                $GLOBALS['__dg_log'][] = ['warning', $msg, $ctx];
            }

            /** @param array<string, mixed> $ctx */
            public static function info(string $msg, array $ctx = []): void
            {
                $GLOBALS['__dg_log'][] = ['info', $msg, $ctx];
            }

            /** @param array<string, mixed> $ctx */
            public static function error(string $msg, array $ctx = []): void
            {
                $GLOBALS['__dg_log'][] = ['error', $msg, $ctx];
            }
        }
    }
}
