<?php
/**
 * Stubs for AccountPreHijackTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses), so
 * the guarded global functions and fake FQN classes below cannot leak into
 * other suites.
 *
 * A tiny in-memory WordPress, held in $GLOBALS['__bcc_ph']:
 *   users       array<int, array{email: string, login: string, display: string}>
 *   meta        array<int, array<string, string>>   user meta
 *   transients  array<string, mixed>
 *   mail        list<array{to: string, subject: string, html: string}>  BccMailer::send calls
 *   actions     list<string>                         do_action hook names
 *
 * Real code under test: OAuthController::oauthLogin (incl. the signed bridge
 * gate), EmailVerificationController::resendVerification, AuthSupport's
 * verification helpers, and AuthMailer's verification template.
 */

declare(strict_types=1);

namespace {
    // F-462: JwtToken reads token state through TokenStateRepository; these
    // suites test token behaviour, so they get its stand-in (see the file).
    require_once __DIR__ . '/token-state-stubs.php';
}

namespace BCC\Core\Permissions {
    // F-305: every auth response carries `suspended`
    // (AuthSupport::resolveSuspended). $GLOBALS['__bcc_susp'] maps user id →
    // true | false | null (UNKNOWN); absent → false. auth-session-stubs.php
    // defines the same double when it is loaded first.
    if (!class_exists(__NAMESPACE__ . '\Permissions', false)) {
        final class Permissions
        {
            public static function suspensionState(?int $user_id = null, bool $allowAdminBypass = true): ?bool
            {
                $map = $GLOBALS['__bcc_susp'] ?? [];
                return array_key_exists((int) $user_id, $map) ? $map[(int) $user_id] : false;
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null, bool $countDenied = true): bool
            {
                // Records every bucket (the explicit $key when given, else the
                // action); denies when a test sets throttle_deny. With
                // throttle_counting set it COUNTS per bucket like the real
                // limiter (increment, then allow while count <= limit), and
                // like it (F-290) gives a denied call's slot back when the
                // caller passes countDenied = false. It also records each
                // bucket's limit and window (seconds), so a test can pin them.
                $bucket = $key ?? $action;
                $GLOBALS['__bcc_ph']['throttle_keys'][] = $bucket;
                $GLOBALS['__bcc_ph']['throttle_count_denied'][$bucket] = $countDenied;
                $GLOBALS['__bcc_ph']['throttle_limits'][$bucket] = $limit;
                $GLOBALS['__bcc_ph']['throttle_windows'][$bucket] = $window;
                if (!empty($GLOBALS['__bcc_ph']['throttle_deny'])) {
                    return false;
                }
                if (!empty($GLOBALS['__bcc_ph']['throttle_counting'])) {
                    $GLOBALS['__bcc_ph']['throttle_hits'][$bucket] = ($GLOBALS['__bcc_ph']['throttle_hits'][$bucket] ?? 0) + 1;
                    $allowed = $GLOBALS['__bcc_ph']['throttle_hits'][$bucket] <= $limit;
                    if (!$allowed && !$countDenied) {
                        $GLOBALS['__bcc_ph']['throttle_hits'][$bucket]--;
                    }
                    return $allowed;
                }
                return true;
            }

            /** F-290: give back one slot of the bucket allow() used; never below zero. */
            public static function release(string $action, int $window = 60, ?string $key = null): void
            {
                $bucket = $key ?? $action;
                $GLOBALS['__bcc_ph']['throttle_releases'][] = $bucket;
                if (($GLOBALS['__bcc_ph']['throttle_hits'][$bucket] ?? 0) > 0) {
                    $GLOBALS['__bcc_ph']['throttle_hits'][$bucket]--;
                }
            }

            /** F-281: the caller's address; tests set client_ip. */
            public static function clientIp(): string
            {
                return (string) ($GLOBALS['__bcc_ph']['client_ip'] ?? '203.0.113.7');
            }

            /** @return null */
            public static function lastDenial()
            {
                return null;
            }
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(AdvisoryLock::class, false)) {
        final class AdvisoryLock
        {
            /** Records 'acquire:<key>' / 'release:<key>'; lock_deny makes acquire fail. */
            public static function acquire(string $key, int $timeout = 0): bool
            {
                $GLOBALS['__bcc_ph']['locks'][] = 'acquire:' . $key;
                return empty($GLOBALS['__bcc_ph']['lock_deny']);
            }

            public static function release(string $key): void
            {
                $GLOBALS['__bcc_ph']['locks'][] = 'release:' . $key;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function audit(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_ph']['audit_log'][] = [$message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_ph']['log_errors'][] = $message;
            }
        }
    }
}

namespace BCC\Core\Mail {
    if (!class_exists(BccMailer::class, false)) {
        final class BccMailer
        {
            public static function send(string $to, string $subject, string $html, string $failureEvent = ''): bool
            {
                $GLOBALS['__bcc_ph']['mail'][] = ['to' => $to, 'subject' => $subject, 'html' => $html];
                return true;
            }
        }
    }
}

namespace {
    if (!defined('BCC_OAUTH_BRIDGE_SECRET')) {
        define('BCC_OAUTH_BRIDGE_SECRET', 'test-bridge-secret-prehijack');
    }
    if (!defined('BCC_FRONTEND_ORIGIN')) {
        define('BCC_FRONTEND_ORIGIN', 'https://app.example.test');
    }

    if (!class_exists('WP_User', false)) {
        class WP_User
        {
            public int $ID;
            public string $user_email;
            public string $user_login;
            public string $display_name;
            public string $user_pass = 'stub-hash';
            /** GMT DATETIME, from the fixture's 'registered' (unix time) when set. */
            public string $user_registered = '';

            public function __construct(int $id, string $email, string $login, string $display)
            {
                $this->ID           = $id;
                $this->user_email   = $email;
                $this->user_login   = $login;
                $this->display_name = $display;
            }
        }
    }

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @var array<string, mixed> */
            private array $params;
            /** @var array<string, string> */
            private array $headers = [];
            private string $body;

            /** @param array<string, mixed> $params */
            public function __construct(array $params = [], ?string $body = null)
            {
                $this->params = $params;
                $this->body   = $body ?? (string) json_encode($params);
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }

            public function set_header(string $key, string $value): void
            {
                $this->headers[strtolower($key)] = $value;
            }

            public function get_header(string $key): ?string
            {
                return $this->headers[strtolower($key)] ?? null;
            }

            public function get_body(): string
            {
                return $this->body;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @var mixed */
            private $data;
            private int $status;
            /** @var array<string, string> */
            private array $headers = [];

            /** @param mixed $data */
            public function __construct($data = null, int $status = 200)
            {
                $this->data   = $data;
                $this->status = $status;
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function header(string $key, string $value, bool $replace = true): void
            {
                $this->headers[$key] = $value;
            }
        }
    }

    // ── users + meta ────────────────────────────────────────────────────
    if (!function_exists('get_user_by')) {
        /** @return \WP_User|false */
        function get_user_by(string $field, $value)
        {
            foreach ($GLOBALS['__bcc_ph']['users'] as $id => $u) {
                if ($field === 'email' && strtolower($u['email']) === strtolower((string) $value)) {
                    return new \WP_User($id, $u['email'], $u['login'], $u['display']);
                }
                if ($field === 'id' && (int) $id === (int) $value) {
                    return new \WP_User($id, $u['email'], $u['login'], $u['display']);
                }
            }
            return false;
        }
    }
    if (!function_exists('email_exists')) {
        /** @return int|false */
        function email_exists(string $email)
        {
            foreach ($GLOBALS['__bcc_ph']['users'] as $id => $u) {
                if (strtolower($u['email']) === strtolower($email)) {
                    return (int) $id;
                }
            }
            return false;
        }
    }
    if (!function_exists('wp_update_user')) {
        /**
         * @param array<string, mixed> $data
         * @return int
         */
        function wp_update_user(array $data)
        {
            $id = (int) $data['ID'];
            if (isset($data['user_email'])) {
                $GLOBALS['__bcc_ph']['users'][$id]['email'] = (string) $data['user_email'];
            }
            return $id;
        }
    }
    if (!function_exists('is_wp_error')) {
        /** @param mixed $thing */
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }
    if (!function_exists('wp_check_password')) {
        function wp_check_password(string $password, string $hash, int $userId = 0): bool
        {
            return ($GLOBALS['__bcc_ph']['passwords'][$userId] ?? null) === $password;
        }
    }
    // F-294 item 8: forgot-password for an unknown address pays the reset
    // key's own cost (wp_generate_password + wp_fast_hash). The same doubles
    // as handle-rename-identity-stubs, so whichever file loads first, a
    // test sees the same records.
    if (empty($GLOBALS['__bcc_no_fast_hash']) && !function_exists('wp_fast_hash')) {
        function wp_fast_hash(string $message): string
        {
            $GLOBALS['__bcc_ph']['fast_hashes'] = ($GLOBALS['__bcc_ph']['fast_hashes'] ?? 0) + 1;
            return 'fast:' . $message;
        }
    }
    if (!function_exists('wp_generate_password')) {
        function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string
        {
            $GLOBALS['__bcc_ph']['generated'][] = $length . ($special ? '+special' : '') . ($extra ? '+extra' : '');
            return str_repeat('k', $length);
        }
    }
    if (!function_exists('get_userdata')) {
        /** @return \WP_User|false */
        function get_userdata($id)
        {
            $u = $GLOBALS['__bcc_ph']['users'][(int) $id] ?? null;
            if ($u === null) {
                return false;
            }
            $user = new \WP_User((int) $id, $u['email'], $u['login'], $u['display']);
            if (isset($u['registered'])) {
                $user->user_registered = gmdate('Y-m-d H:i:s', (int) $u['registered']);
            }
            return $user;
        }
    }
    if (!function_exists('get_users')) {
        /**
         * Only the shape findUserByOauthProvider uses: meta_key + meta_value.
         *
         * @param array<string, mixed> $args
         * @return list<int>
         */
        function get_users(array $args = []): array
        {
            // Like MySQL under WordPress's default (`_ci`) collation, a
            // meta_value match ignores letter case.
            $out = [];
            foreach ($GLOBALS['__bcc_ph']['meta'] as $id => $meta) {
                $value = $meta[(string) $args['meta_key']] ?? null;
                if (is_string($value) && strtolower($value) === strtolower((string) $args['meta_value'])) {
                    $out[] = (int) $id;
                }
            }
            // Like core's WP_User_Query: `search` on `search_columns`
            // ['user_email'], where a leading or trailing `*` is a LIKE
            // wildcard. It's applied BEFORE `number`, as in SQL. Letter case
            // is ignored, as under the column's `_ci` collation.
            if (isset($args['search']) && ($args['search_columns'] ?? []) === ['user_email']) {
                $term     = (string) $args['search'];
                $leading  = str_starts_with($term, '*');
                $trailing = str_ends_with($term, '*');
                $needle   = strtolower(trim($term, '*'));
                $out = array_values(array_filter($out, static function (int $id) use ($needle, $leading, $trailing): bool {
                    $mail = strtolower((string) ($GLOBALS['__bcc_ph']['users'][$id]['email'] ?? ''));
                    return match (true) {
                        $leading && $trailing => str_contains($mail, $needle),
                        $leading              => str_ends_with($mail, $needle),
                        $trailing             => str_starts_with($mail, $needle),
                        default               => $mail === $needle,
                    };
                }));
            }
            // Like core: `orderby` ID (else the seeded order) and a `number` cap.
            if (($args['orderby'] ?? '') === 'ID') {
                sort($out);
                if (strtoupper((string) ($args['order'] ?? 'ASC')) === 'DESC') {
                    $out = array_reverse($out);
                }
            }
            $number = (int) ($args['number'] ?? 0);
            return $number > 0 ? array_slice($out, 0, $number) : $out;
        }
    }
    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return $GLOBALS['__bcc_ph']['meta'][$userId][$key] ?? '';
        }
    }
    if (!function_exists('update_user_meta')) {
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            // F-495: $GLOBALS['__bcc_ph']['fail_update'][$key] = true makes the
            // write of that key fail (false, nothing stored), as a failed
            // UPDATE does in core.
            if (!empty($GLOBALS['__bcc_ph']['fail_update'][$key])) {
                $GLOBALS['__bcc_ph']['failed_updates'][] = $key;
                return false;
            }
            $GLOBALS['__bcc_ph']['meta'][$userId][$key] = is_array($value) ? $value : (string) $value;
            $GLOBALS['__bcc_ph']['updates'][] = $key;
            return true;
        }
    }
    if (!function_exists('delete_user_meta')) {
        /** @param mixed $value */
        function delete_user_meta(int $userId, string $key, $value = ''): bool
        {
            unset($GLOBALS['__bcc_ph']['meta'][$userId][$key]);
            return true;
        }
    }

    if (!function_exists('wp_cache_delete')) {
        /** @param int|string $key */
        function wp_cache_delete($key, string $group = ''): bool
        {
            $GLOBALS['__bcc_ph']['cache_deletes'][] = $group . ':' . $key;
            return true;
        }
    }

    // ── transients ──────────────────────────────────────────────────────
    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return $GLOBALS['__bcc_ph']['transients'][$key] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            $GLOBALS['__bcc_ph']['transients'][$key] = $value;
            $GLOBALS['__bcc_ph']['transient_ttls'][$key] = $ttl;
            return true;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            // A test sets delete_transient_fails to make a delete fail (the
            // value stays, as with a cache that refused the write).
            if (!empty($GLOBALS['__bcc_ph']['delete_transient_fails'])) {
                return false;
            }
            unset($GLOBALS['__bcc_ph']['transients'][$key]);
            return true;
        }
    }

    // ── misc WP ─────────────────────────────────────────────────────────
    if (!function_exists('wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            return 'test-salt-prehijack';
        }
    }
    if (!function_exists('sanitize_email')) {
        function sanitize_email(string $email): string
        {
            return trim($email);
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $s): string
        {
            return trim($s);
        }
    }
    if (!function_exists('is_email')) {
        /** @return string|false */
        function is_email(string $email)
        {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
        }
    }
    if (!function_exists('do_action')) {
        /** @param mixed ...$args */
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['__bcc_ph']['actions'][] = $hook;
        }
    }
    if (!function_exists('wp_generate_uuid4')) {
        function wp_generate_uuid4(): string
        {
            return bin2hex(random_bytes(16));
        }
    }
    if (!function_exists('wp_set_current_user')) {
        /** @return null */
        function wp_set_current_user(int $id)
        {
            return null;
        }
    }
    if (!function_exists('wp_set_auth_cookie')) {
        // Recorded so a test can pin that no BCC sign-in sets one (H3).
        function wp_set_auth_cookie(int $id, bool $remember = false): void
        {
            $GLOBALS['__bcc_ph']['auth_cookies'][] = $id;
        }
    }
    // WordPress-side credentials ended by POST /auth/logout-everywhere (H3).
    if (!class_exists('WP_Session_Tokens', false)) {
        class WP_Session_Tokens
        {
            private function __construct(private int $userId)
            {
            }

            public static function get_instance(int $userId): self
            {
                return new self($userId);
            }

            public function destroy_all(): void
            {
                $GLOBALS['__bcc_ph']['wp_sessions_destroyed'][] = $this->userId;
            }
        }
    }
    if (!class_exists('WP_Application_Passwords', false)) {
        class WP_Application_Passwords
        {
            public static function delete_all_application_passwords(int $userId): int
            {
                $GLOBALS['__bcc_ph']['app_passwords_deleted'][] = $userId;
                return 0;
            }
        }
    }
    if (!function_exists('get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            return $GLOBALS['__bcc_ph']['options'][$key] ?? $default;
        }
    }
    if (!function_exists('get_bloginfo')) {
        function get_bloginfo(string $show = ''): string
        {
            return 'BCC';
        }
    }
    if (!function_exists('site_url')) {
        function site_url(string $path = ''): string
        {
            return 'https://cms.example.test' . $path;
        }
    }
    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://cms.example.test' . $path;
        }
    }
    if (!function_exists('wp_parse_url')) {
        /** @return mixed */
        function wp_parse_url(string $url, int $component = -1)
        {
            return parse_url($url, $component);
        }
    }
}
