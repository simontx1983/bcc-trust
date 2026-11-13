<?php
/**
 * Stubs for PendingEmailChangeTest — loaded BEFORE account-prehijack-stubs.php.
 *
 * Subprocess-only. Records side effects in $GLOBALS['__bcc_ph']:
 *   audit       list<string>  AuditLogger::log event names
 *   audit_meta  array<string, array<string, mixed>>  last meta per event
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
                $GLOBALS['__bcc_ph']['audit_meta'][$event] = $meta;
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

            public static function accountDeleted(string $email, string $displayName): void
            {
                $GLOBALS['__bcc_ph']['canary'][] = 'accountDeleted:' . $email;
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
    // F-294: a tiny filter registry, so wp_update_user() can ask what core
    // asks before its own "Email Changed" notice.
    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
        {
            $GLOBALS['__bcc_ph']['filters'][$hook][] = $cb;
            return true;
        }
        function remove_filter(string $hook, callable $cb, int $priority = 10): bool
        {
            $list = $GLOBALS['__bcc_ph']['filters'][$hook] ?? [];
            $GLOBALS['__bcc_ph']['filters'][$hook] = array_values(array_filter($list, static fn ($c) => $c !== $cb));
            return true;
        }
        /** @param mixed $value @return mixed */
        function apply_filters(string $hook, $value, ...$args)
        {
            foreach ($GLOBALS['__bcc_ph']['filters'][$hook] ?? [] as $cb) {
                $value = $cb($value, ...$args);
            }
            return $value;
        }
    }
    if (!function_exists('__return_false')) {
        function __return_false(): bool
        {
            return false;
        }
    }
    if (!function_exists('wp_update_user')) {
        /**
         * Like core: an email change asks `send_email_change_email` whether
         * to mail the OLD address; the answer is recorded, nothing is sent.
         *
         * @param array<string, mixed> $data
         */
        function wp_update_user(array $data): int
        {
            $id = (int) $data['ID'];
            if (isset($data['user_email'])) {
                $GLOBALS['__bcc_ph']['core_email_notice'][] = (bool) apply_filters('send_email_change_email', true, [], $data);
                $GLOBALS['__bcc_ph']['users'][$id]['email'] = (string) $data['user_email'];
            }
            return $id;
        }
    }
    if (!function_exists('wp_clear_auth_cookie')) {
        function wp_clear_auth_cookie(): void
        {
        }
    }
}
