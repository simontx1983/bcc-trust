<?php
/**
 * F-274 stubs, layered on comment-delete-stubs.php: what createComment()
 * touches between the membership gate and the PeepSo write.
 *
 *   - \PeepSo::check_permissions(): answers from $GLOBALS['__bcc_cpr']['perm']
 *     (true = allowed) and records each call;
 *   - BCC\Core\Security\Throttle::allow(): records each call and DENIES, so a
 *     request that gets past the permanent-refusal check stops at the burst
 *     seatbelt (bcc_rate_limited) before any write;
 *   - BCC\Core\Permissions\Permissions::is_not_suspended(): not suspended.
 *
 * Load only from #[RunTestsInSeparateProcesses] classes.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('PeepSo', false)) {
        class PeepSo
        {
            public const PERM_COMMENT = 'comment';

            public static function check_permissions(int $owner, string $action, int $author, bool $allow_logged_out = false, mixed $post = null): bool
            {
                $GLOBALS['__bcc_cpr']['perm_calls'][] = [$owner, $action, $author];

                return (bool) ($GLOBALS['__bcc_cpr']['perm'] ?? true);
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $key, int $limit, int $window): bool
            {
                $GLOBALS['__bcc_cpr']['throttle_calls'][] = $key;

                return false;
            }
        }
    }
}

namespace BCC\Core\Log {
    // The throttle-denied branch logs; a no-op is enough here.
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<mixed> $args */
            public static function __callStatic(string $name, array $args): void
            {
            }
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            public static function is_not_suspended(int $userId, bool $allowAdminBypass = true): bool
            {
                return true;
            }
        }
    }
}
