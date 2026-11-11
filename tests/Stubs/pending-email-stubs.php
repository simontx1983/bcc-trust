<?php
/**
 * Stubs for PendingEmailChangeTest — loaded BEFORE account-prehijack-stubs.php.
 *
 * Subprocess-only. Records side effects in $GLOBALS['__bcc_ph']:
 *   audit       list<string>  AuditLogger::log event names
 *   canary      list<string>  AccountSecurityMailer calls ("emailChanged:old>new", …)
 *   current_user int          get_current_user_id()
 *   passwords   array<int, string>  wp_check_password() plain-text fixture
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Security {
    if (!class_exists(AuditLogger::class, false)) {
        final class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $event, int $objectId, array $meta = [], string $type = '', int $actor = 0): void
            {
                $GLOBALS['__bcc_ph']['audit'][] = $event;
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(AccountSecurityMailer::class, false)) {
        final class AccountSecurityMailer
        {
            public static function emailChanged(int $userId, string $oldEmail, string $newEmail): void
            {
                $GLOBALS['__bcc_ph']['canary'][] = 'emailChanged:' . $oldEmail . '>' . $newEmail;
            }

            public static function recoveryEmailConfirmed(int $userId, string $newEmail): void
            {
                $GLOBALS['__bcc_ph']['canary'][] = 'recoveryEmailConfirmed:' . $newEmail;
            }

            public static function passwordChanged(int $userId): void
            {
                $GLOBALS['__bcc_ph']['canary'][] = 'passwordChanged:' . $userId;
            }

            public static function sessionsRevokedAll(int $userId): void
            {
                $GLOBALS['__bcc_ph']['canary'][] = 'sessionsRevokedAll:' . $userId;
            }
        }
    }
}

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_ph']['current_user'] ?? 0);
        }
    }
    if (!function_exists('wp_set_password')) {
        function wp_set_password(string $password, int $userId): void
        {
            $GLOBALS['__bcc_ph']['passwords'][$userId] = $password;
        }
    }
    if (!function_exists('wp_clear_auth_cookie')) {
        function wp_clear_auth_cookie(): void
        {
        }
    }
}
