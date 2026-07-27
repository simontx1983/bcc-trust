<?php
/**
 * Stubs for CanonicalHandlesStatusTest (subprocess-isolated).
 *
 * Fixtures:
 *   $GLOBALS['__bcc_ch_pages']   — offset => list<int> user ids (fields=>ID)
 *   $GLOBALS['__bcc_ch_validate']— callable(string): ?string (null = valid)
 *   $GLOBALS['__bcc_ch_assigned']— captured update_user_meta handle writes
 */

declare(strict_types=1);

namespace {
    if (!defined('BCC_TRUST_MIGRATION_COMPLETE'))   { define('BCC_TRUST_MIGRATION_COMPLETE', 'complete'); }
    if (!defined('BCC_TRUST_MIGRATION_INCOMPLETE')) { define('BCC_TRUST_MIGRATION_INCOMPLETE', 'incomplete'); }

    if (!function_exists('get_users')) {
        /** @param array<string, mixed> $args @return list<int> */
        function get_users(array $args): array
        {
            $offset = (int) ($args['offset'] ?? 0);
            /** @var array<int, list<int>> $pages */
            $pages = $GLOBALS['__bcc_ch_pages'] ?? [];
            return $pages[$offset] ?? [];
        }
    }

    if (!function_exists('get_userdata')) {
        /** @return object */
        function get_userdata(int $id)
        {
            return (object) [
                'ID'           => $id,
                'display_name' => 'Member ' . $id,
                'user_login'   => 'u_member' . $id,
            ];
        }
    }

    if (!function_exists('sanitize_title')) {
        function sanitize_title(string $s): string
        {
            $s = strtolower(trim($s));
            $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
            return trim($s, '-');
        }
    }

    if (!function_exists('update_user_meta')) {
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            $GLOBALS['__bcc_ch_assigned'][$userId] = $value;
            return true;
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists('BCC\\Trust\\Core\\Services\\HandleService', false)) {
        final class HandleService
        {
            public const META_HANDLE = 'bcc_handle';
            public const MIN_LENGTH  = 3;
            public const MAX_LENGTH  = 20;

            /** @return string|null null = valid; a string = rejection reason */
            public function validate(string $handle): ?string
            {
                $cb = $GLOBALS['__bcc_ch_validate'] ?? null;
                return is_callable($cb) ? $cb($handle) : null;
            }

            public function isAvailable(string $handle): bool
            {
                $cb = $GLOBALS['__bcc_ch_available'] ?? null;
                return is_callable($cb) ? (bool) $cb($handle) : true;
            }
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
