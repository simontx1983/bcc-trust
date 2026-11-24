<?php
/**
 * F-412: the WordPress pieces the bell's row shaping (NotificationViewService
 * ::shapeRow → resolveActor → PublicIdentity::forUser) and the digest body
 * (DigestService::composeBody) read. Fixture: $GLOBALS['__bcc_nah'] =
 *   users => array<int, array{login: string, display: string}>
 *   meta  => array<int, array<string, mixed>>   (bcc_handle and privacy keys)
 *
 * The real PublicIdentity, PrivacySettings and NotificationType run; only
 * storage and URLs are faked.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_userdata')) {
        /** @return object|false */
        function get_userdata($id)
        {
            $u = $GLOBALS['__bcc_nah']['users'][(int) $id] ?? null;
            if ($u === null) {
                return false;
            }
            return (object) ['ID' => (int) $id, 'user_login' => $u['login'], 'display_name' => $u['display']];
        }
    }
    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            $value = $GLOBALS['__bcc_nah']['meta'][$userId][$key] ?? '';
            return $single ? $value : ($value === '' ? [] : [$value]);
        }
    }
    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://cms.example.test' . $path;
        }
    }
    if (!function_exists('get_option')) {
        /** @return mixed */
        function get_option(string $name, $default = false)
        {
            return $default;
        }
    }
    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $text): string
        {
            return trim(strip_tags($text));
        }
    }
}
