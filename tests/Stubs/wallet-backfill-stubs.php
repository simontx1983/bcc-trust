<?php
/**
 * Stubs for WalletBackfillStatusTest (subprocess-isolated).
 *
 * Exercises the refactored bcc_trust_backfill_wallet_placeholder_emails()
 * — which now returns a migration status — without WordPress or MySQL.
 *
 * Fixtures:
 *   $GLOBALS['__bcc_salt']          — wp_salt('auth') return
 *   $GLOBALS['__bcc_get_users_pages']— offset => list<\WP_User>
 *   $GLOBALS['__bcc_email_exists']  — callable(string): int|false (default false)
 *   $GLOBALS['__bcc_updates']       — captured $wpdb->update() calls
 *   $GLOBALS['__bcc_update_return'] — $wpdb->update() return (default 1)
 */

declare(strict_types=1);

namespace {
    if (!defined('BCC_TRUST_MIGRATION_COMPLETE'))   { define('BCC_TRUST_MIGRATION_COMPLETE', 'complete'); }
    if (!defined('BCC_TRUST_MIGRATION_INCOMPLETE')) { define('BCC_TRUST_MIGRATION_INCOMPLETE', 'incomplete'); }

    if (!class_exists('WP_User', false)) {
        final class WP_User
        {
            public int $ID = 0;
            public string $user_email = '';
            public function __construct(int $id, string $email)
            {
                $this->ID = $id;
                $this->user_email = $email;
            }
        }
    }

    if (!function_exists('wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            $s = $GLOBALS['__bcc_salt'] ?? 'unit-salt';
            return is_string($s) ? $s : '';
        }
    }

    if (!function_exists('get_users')) {
        /** @param array<string, mixed> $args @return list<\WP_User> */
        function get_users(array $args): array
        {
            $GLOBALS['__bcc_get_users_args'][] = $args;
            $offset = (int) ($args['offset'] ?? 0);
            /** @var array<int, list<\WP_User>> $pages */
            $pages = $GLOBALS['__bcc_get_users_pages'] ?? [];
            return $pages[$offset] ?? [];
        }
    }

    if (!function_exists('email_exists')) {
        /** @return int|false */
        function email_exists(string $email)
        {
            $cb = $GLOBALS['__bcc_email_exists'] ?? null;
            return is_callable($cb) ? $cb($email) : false;
        }
    }

    if (!function_exists('clean_user_cache')) {
        function clean_user_cache(int $id): void
        {
            $GLOBALS['__bcc_clean_cache'][] = $id;
        }
    }

    if (!isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = new class {
            public string $users = 'wp_users';
            /**
             * @param array<string, mixed> $data
             * @param array<string, mixed> $where
             * @return int|false
             */
            public function update(string $table, array $data, array $where)
            {
                $GLOBALS['__bcc_updates'][] = ['where' => $where, 'data' => $data];
                return $GLOBALS['__bcc_update_return'] ?? 1;
            }
        };
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists('BCC\\Trust\\Core\\Services\\AccountRecoveryService', false)) {
        final class AccountRecoveryService
        {
            public const PLACEHOLDER_EMAIL_DOMAIN = 'noreply.bcc.local';
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void {}
        }
    }
}
