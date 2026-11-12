<?php
/**
 * Stubs for HandleRenameIdentityTest (F-280) — loaded BEFORE
 * account-prehijack-stubs.php, which supplies WP_User, WP_REST_Request /
 * WP_REST_Response, wp_check_password, is_email, Throttle and Logger.
 *
 * What this file adds is a MULTI-VALUE user-meta model, because a member can
 * hold several retired handles (one bcc_handle_retired row each):
 *   $GLOBALS['__bcc_ph']['meta'][$userId][$key] = string | list<string>
 * plus the lookups the handle code uses: get_users by meta (with `number`
 * and `exclude`), add/delete_user_meta, username_exists, and the rename
 * endpoint's gates (current user, suspension, filters).
 */

declare(strict_types=1);

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            public static function is_not_suspended(?int $user_id = null, bool $allowAdminBypass = true): bool
            {
                return true;
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
    if (!function_exists('apply_filters')) {
        /**
         * @param mixed $value
         * @param mixed ...$args
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            return $value;
        }
    }

    /** @return list<string> */
    /** @return list<mixed> */
    function bcc_hri_values(int $userId, string $key): array
    {
        $v = $GLOBALS['__bcc_ph']['meta'][$userId][$key] ?? null;
        if ($v === null) {
            return [];
        }
        // A keyed array is ONE stored value (WP serializes it); a list models
        // several rows of a multi-value key (add_user_meta).
        if (is_array($v) && !array_is_list($v)) {
            return [$v];
        }
        return is_array($v) ? array_values(array_map('strval', $v)) : [(string) $v];
    }

    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            $values = bcc_hri_values($userId, $key);
            if ($single) {
                return $values[0] ?? '';
            }
            return $values;
        }
    }
    if (!function_exists('update_user_meta')) {
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            $GLOBALS['__bcc_ph']['meta'][$userId][$key] = is_array($value) ? $value : (string) $value;
            return true;
        }
    }
    if (!function_exists('add_user_meta')) {
        /**
         * @param mixed $value
         * @return int|false
         */
        function add_user_meta(int $userId, string $key, $value, bool $unique = false)
        {
            if (!empty($GLOBALS['__bcc_ph']['fail_add_meta'])) {
                return false;
            }
            $values   = bcc_hri_values($userId, $key);
            $values[] = (string) $value;
            $GLOBALS['__bcc_ph']['meta'][$userId][$key] = $values;
            return count($values);
        }
    }
    if (!function_exists('delete_user_meta')) {
        /** @param mixed $value */
        function delete_user_meta(int $userId, string $key, $value = ''): bool
        {
            $values = bcc_hri_values($userId, $key);
            if ($values === []) {
                return false;
            }
            $kept = $value === ''
                ? []
                : array_values(array_filter($values, static fn (string $v): bool => $v !== (string) $value));
            if ($kept === []) {
                unset($GLOBALS['__bcc_ph']['meta'][$userId][$key]);
            } else {
                $GLOBALS['__bcc_ph']['meta'][$userId][$key] = $kept;
            }
            return count($kept) !== count($values);
        }
    }
    if (!function_exists('get_users')) {
        /**
         * meta_key + meta_value (exact, case-insensitive like the column
         * collation), `number`, `exclude`, fields=ID.
         *
         * @param array<string, mixed> $args
         * @return list<int>
         */
        function get_users(array $args = []): array
        {
            $key     = (string) ($args['meta_key'] ?? '');
            $value   = strtolower((string) ($args['meta_value'] ?? ''));
            $number  = (int) ($args['number'] ?? -1);
            $exclude = array_map('intval', (array) ($args['exclude'] ?? []));
            $out     = [];
            $ids     = array_keys($GLOBALS['__bcc_ph']['meta']);
            sort($ids);
            foreach ($ids as $id) {
                if (in_array((int) $id, $exclude, true)) {
                    continue;
                }
                foreach (bcc_hri_values((int) $id, $key) as $v) {
                    if (strtolower($v) === $value) {
                        $out[] = (int) $id;
                        break;
                    }
                }
                if ($number > 0 && count($out) >= $number) {
                    break;
                }
            }
            return $out;
        }
    }
    if (!function_exists('username_exists')) {
        /** @return int|false */
        function username_exists(string $login)
        {
            foreach ($GLOBALS['__bcc_ph']['users'] as $id => $u) {
                if (strcasecmp((string) $u['login'], $login) === 0) {
                    return (int) $id;
                }
            }
            return false;
        }
    }
}
