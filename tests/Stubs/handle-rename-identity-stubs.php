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
    function bcc_hri_values(int $userId, string $key): array
    {
        $v = $GLOBALS['__bcc_ph']['meta'][$userId][$key] ?? null;
        if ($v === null) {
            return [];
        }
        return is_array($v) ? array_values(array_map('strval', $v)) : [(string) $v];
    }

    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            if ($key === '') {
                // WordPress: every key, each as a list of values.
                $all = [];
                foreach (array_keys($GLOBALS['__bcc_ph']['meta'][$userId] ?? []) as $k) {
                    $all[$k] = bcc_hri_values($userId, (string) $k);
                }
                return $all;
            }
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

// ── F-285 / F-287: what the real signup() and resetPassword() need ─────

namespace BCC\Trust\Core\Security {
    if (!class_exists(AuditLogger::class, false)) {
        final class AuditLogger
        {
            /** @param array<string, mixed> $context */
            public static function log(string $action, int $actorId, array $context = [], string $targetType = '', int $targetId = 0): void
            {
                $GLOBALS['__bcc_ph']['audit'][] = ['action' => $action, 'actor' => $actorId, 'context' => $context, 'target' => $targetId];
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(AccountSecurityMailer::class, false)) {
        final class AccountSecurityMailer
        {
            public static function passwordChanged(int $userId): void {}
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(WalletRepository::class, false)) {
        final class WalletRepository
        {
            /** @return list<object> */
            public static function getForUserOrThrow(int $userId, ?string $walletType = null, bool $verifiedOnly = false): array
            {
                if (!empty($GLOBALS['__bcc_ph']['wallet_read_fails'])) {
                    throw new \RuntimeException('injected wallet read failure');
                }
                return $GLOBALS['__bcc_ph']['wallets'][$userId] ?? [];
            }
        }
    }
}

namespace {
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '') {}
            public function get_error_code(): string { return $this->code; }
            public function get_error_message(): string { return $this->message; }
        }
    }
    if (!function_exists('wp_insert_user')) {
        /**
         * @param array<string, mixed> $data
         * @return int|\WP_Error
         */
        function wp_insert_user(array $data)
        {
            foreach ($GLOBALS['__bcc_ph']['users'] as $u) {
                if (strcasecmp((string) $u['login'], (string) $data['user_login']) === 0) {
                    return new \WP_Error('existing_user_login', 'login taken');
                }
                if (strcasecmp((string) $u['email'], (string) $data['user_email']) === 0) {
                    return new \WP_Error('existing_user_email', 'email taken');
                }
            }
            $id = max(array_merge([100], array_keys($GLOBALS['__bcc_ph']['users']))) + 1;
            $GLOBALS['__bcc_ph']['users'][$id] = [
                'email'      => (string) $data['user_email'],
                'login'      => (string) $data['user_login'],
                'display'    => (string) ($data['display_name'] ?? ''),
                'registered' => time(),
            ];
            $GLOBALS['__bcc_ph']['passwords'][$id] = (string) $data['user_pass'];
            foreach ((array) ($data['meta_input'] ?? []) as $k => $v) {
                $GLOBALS['__bcc_ph']['meta'][$id][$k] = (string) $v;
            }
            return $id;
        }
    }
    if (!function_exists('wp_delete_user')) {
        function wp_delete_user(int $id): bool
        {
            if (!isset($GLOBALS['__bcc_ph']['users'][$id])) {
                return false;
            }
            unset($GLOBALS['__bcc_ph']['users'][$id], $GLOBALS['__bcc_ph']['meta'][$id], $GLOBALS['__bcc_ph']['passwords'][$id]);
            $GLOBALS['__bcc_ph']['deleted'][] = $id;
            return true;
        }
    }
    if (!function_exists('check_password_reset_key')) {
        /** @return \WP_User|\WP_Error */
        function check_password_reset_key(string $key, string $login)
        {
            foreach ($GLOBALS['__bcc_ph']['users'] as $id => $u) {
                if ($u['login'] === $login && ($GLOBALS['__bcc_ph']['reset_keys'][$id] ?? null) === $key) {
                    return new \WP_User((int) $id, $u['email'], $u['login'], $u['display']);
                }
            }
            return new \WP_Error('invalid_key', 'invalid');
        }
    }
    if (!function_exists('reset_password')) {
        function reset_password(\WP_User $user, string $password): void
        {
            $GLOBALS['__bcc_ph']['passwords'][$user->ID] = $password;
            unset($GLOBALS['__bcc_ph']['reset_keys'][$user->ID]);
        }
    }
}
