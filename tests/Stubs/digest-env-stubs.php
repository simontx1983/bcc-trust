<?php

declare(strict_types=1);

/**
 * Environment for the digest unit tests (separate process only).
 *
 *   $GLOBALS['__dg_options']  array<string, mixed>       wp_options
 *   $GLOBALS['__dg_optins']   list<int>                  email_digest = 1 user ids
 *   $GLOBALS['__dg_unread']   array<int, list<object>>   unread rows per user
 *   $GLOBALS['__dg_users']    array<int, string>         user id => email
 *   $GLOBALS['__dg_banned']   list<int>                  PeepSo role 'ban'
 *   $GLOBALS['__dg_mail']     list<array{to: string, subject: string, body: string, headers: list<string>}>
 *   $GLOBALS['__dg_mail_ok']  bool                       wp_mail() return value
 *   $GLOBALS['__dg_cron']     array<string, int>         hook => next timestamp
 */

$GLOBALS['__dg_options'] = [];
$GLOBALS['__dg_optins']  = [];
$GLOBALS['__dg_unread']  = [];
$GLOBALS['__dg_users']   = [];
$GLOBALS['__dg_banned']  = [];
$GLOBALS['__dg_mail']    = [];
$GLOBALS['__dg_mail_ok'] = true;
$GLOBALS['__dg_cron']    = [];
$GLOBALS['__dg_digest_prefs'] = [];

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!class_exists('WP_User', false)) {
    class WP_User
    {
        public int $ID = 0;
        public string $user_login = '';
        public string $user_email = '';
    }
}

if (!class_exists('WP_REST_Request', false)) {
    class WP_REST_Request
    {
        public function __construct(private array $params)
        {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Response', false)) {
    class WP_REST_Response
    {
        public array $headers = [];

        public function __construct(mixed $data, public int $status)
        {
        }

        public function header(string $key, string $value): void
        {
            $this->headers[$key] = $value;
        }
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback): void
    {
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text);
    }
}

if (!class_exists('DigestFakeWpdb')) {
    final class DigestFakeWpdb
    {
        public string $prefix   = 'wp_';
        public string $usermeta = 'wp_usermeta';
        public string $last_error = '';
        /** @var list<mixed> */
        private array $args = [];
        /** @var list<string> */
        public array $queries = [];

        public function prepare(string $sql, ...$args): string
        {
            $this->args = $args;
            return $sql;
        }

        /** @return list<string> */
        public function get_col(string $sql): array
        {
            $this->queries[] = $sql;
            if (str_contains($sql, 'SELECT DISTINCT CAST(meta_value AS BINARY)')) {
                if (!empty($GLOBALS['__dg_gate_fail'])) {
                    $this->last_error = 'simulated read failure';
                    return [];
                }

                $this->last_error = '';
                $id = (int) ($this->args[0] ?? 0);
                $key = (string) ($this->args[1] ?? '');
                return array_slice(array_values(array_unique($GLOBALS['__dg_digest_prefs'][$id][$key] ?? [])), 0, 2);
            }

            // findUserIdsByMetaFlag: (metaKey, '1', afterUserId, limit)
            $after = (int) ($this->args[2] ?? 0);
            $limit = (int) ($this->args[3] ?? 0);
            $ids   = array_values(array_filter($GLOBALS['__dg_optins'], static fn (int $id) => $id > $after));
            if (str_contains($sql, 'SELECT DISTINCT user_id')) {
                $ids = array_values(array_unique($ids));
            }
            sort($ids);
            return array_map('strval', array_slice($ids, 0, $limit));
        }

        /** @var mixed */
        public $last_result = [];

        /**
         * F-296: NotificationRepository's block reads (query() + last_result).
         * No blocks in these digest tests.
         *
         * @return int
         */
        public function query(string $sql)
        {
            $this->queries[] = $sql;
            $this->last_error  = '';
            $this->last_result = [];
            return 0;
        }

        /** @return list<object> */
        public function get_results(string $sql): array
        {
            $userId = (int) ($this->args[0] ?? 0);
            $limit  = (int) ($this->args[count($this->args) - 1] ?? 0);
            return array_slice($GLOBALS['__dg_unread'][$userId] ?? [], 0, $limit);
        }

        public function get_var(string $sql)
        {
            if (!empty($GLOBALS['__dg_count_fail'])) {
                $this->last_error = 'simulated read failure';
                return null;
            }
            return (string) count(str_contains($sql, 'COUNT(DISTINCT user_id)')
                ? array_unique($GLOBALS['__dg_optins'])
                : $GLOBALS['__dg_optins']);
        }
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return array_key_exists($key, $GLOBALS['__dg_options']) ? $GLOBALS['__dg_options'][$key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool
    {
        $GLOBALS['__dg_options'][$key] = $value;
        return true;
    }
}
if (!function_exists('wp_timezone')) {
    function wp_timezone(): \DateTimeZone
    {
        return new \DateTimeZone('America/Chicago');
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata(int $id)
    {
        if (!isset($GLOBALS['__dg_users'][$id])) {
            return false;
        }
        $u = new \WP_User();
        $u->ID = $id;
        $u->user_login = 'login' . $id;
        $u->user_email = $GLOBALS['__dg_users'][$id];
        return $u;
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta(int $id, string $key = '', bool $single = false)
    {
        if ($key === 'bcc_handle') {
            return 'handle' . $id;
        }
        $values = $GLOBALS['__dg_digest_prefs'][$id][$key] ?? [];
        return $single ? ($values[0] ?? '') : $values;
    }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta(int $id, string $key, string $value): bool
    {
        $values = $GLOBALS['__dg_digest_prefs'][$id][$key] ?? [];
        if (!empty($GLOBALS['__dg_preference_write_fails']) || array_values(array_unique($values)) === [$value]) {
            return false;
        }
        $GLOBALS['__dg_digest_prefs'][$id][$key] = array_fill(0, max(1, count($values)), $value);
        if ($key === 'bcc_notif_pref_email_digest' && $value === '0') {
            $GLOBALS['__dg_optins'] = array_values(array_filter($GLOBALS['__dg_optins'], static fn (int $candidate): bool => $candidate !== $id));
        }
        return true;
    }
}
if (!function_exists('wp_mail')) {
    /** @param list<string> $headers */
    function wp_mail(string $to, string $subject, string $body, $headers = []): bool
    {
        $GLOBALS['__dg_mail'][] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => (array) $headers];
        if (isset($GLOBALS['__dg_after_mail'])) {
            ($GLOBALS['__dg_after_mail'])();
        }
        return $GLOBALS['__dg_mail_ok'];
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $s): string
    {
        return strip_tags($s);
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'test-salt';
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return !empty($GLOBALS['__dg_query_url'])
            ? 'https://example.test/?rest_route=/' . ltrim($path, '/')
            : 'https://example.test/wp-json/' . ltrim($path, '/');
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = [])
    {
        return $GLOBALS['__dg_cron'][$hook] ?? false;
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $ts, string $recurrence, string $hook, array $args = []): bool
    {
        $GLOBALS['__dg_cron'][$hook] = $ts;
        return true;
    }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): int
    {
        $had = isset($GLOBALS['__dg_cron'][$hook]);
        unset($GLOBALS['__dg_cron'][$hook]);
        return $had ? 1 : 0;
    }
}

if (!class_exists('PeepSoUser')) {
    final class PeepSoUser
    {
        private function __construct(private int $id)
        {
        }

        public static function get_instance(int $id): self
        {
            return new self($id);
        }

        public function get_user_role(): string
        {
            return in_array($this->id, $GLOBALS['__dg_banned'], true) ? 'ban' : 'member';
        }
    }
}
