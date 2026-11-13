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
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null): bool
            {
                // Records every bucket (the explicit $key when given, else the
                // action); denies when a test sets throttle_deny. With
                // throttle_counting set it COUNTS per bucket like the real
                // limiter (increment, then allow while count <= limit).
                $bucket = $key ?? $action;
                $GLOBALS['__bcc_ph']['throttle_keys'][] = $bucket;
                if (!empty($GLOBALS['__bcc_ph']['throttle_deny'])) {
                    return false;
                }
                if (!empty($GLOBALS['__bcc_ph']['throttle_counting'])) {
                    $GLOBALS['__bcc_ph']['throttle_hits'][$bucket] = ($GLOBALS['__bcc_ph']['throttle_hits'][$bucket] ?? 0) + 1;
                    return $GLOBALS['__bcc_ph']['throttle_hits'][$bucket] <= $limit;
                }
                return true;
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
            $out = [];
            foreach ($GLOBALS['__bcc_ph']['meta'] as $id => $meta) {
                if (($meta[(string) $args['meta_key']] ?? null) === (string) $args['meta_value']) {
                    $out[] = (int) $id;
                }
            }
            return $out;
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
            $GLOBALS['__bcc_ph']['meta'][$userId][$key] = is_array($value) ? $value : (string) $value;
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
            return true;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
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
        function wp_set_auth_cookie(int $id, bool $remember = false): void
        {
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
