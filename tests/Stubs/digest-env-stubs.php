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
            // findUserIdsByMetaFlag: (metaKey, '1', afterUserId, limit)
            $after = (int) ($this->args[2] ?? 0);
            $limit = (int) ($this->args[3] ?? 0);
            $ids   = array_values(array_filter($GLOBALS['__dg_optins'], static fn (int $id) => $id > $after));
            sort($ids);
            return array_map('strval', array_slice($ids, 0, $limit));
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
            return (string) count($GLOBALS['__dg_optins']);
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
        return $key === 'bcc_handle' ? 'handle' . $id : '';
    }
}
if (!function_exists('wp_mail')) {
    /** @param list<string> $headers */
    function wp_mail(string $to, string $subject, string $body, $headers = []): bool
    {
        $GLOBALS['__dg_mail'][] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => (array) $headers];
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
        return 'https://example.test/wp-json/' . ltrim($path, '/');
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
