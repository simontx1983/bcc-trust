<?php

declare(strict_types=1);

/**
 * Fakes for the endpoint switch's ADMIN surface: the two POST routes and the
 * read-only confirmation panel.
 *
 * Deliberately separate from `endpoint-transition-stubs.php`, which fakes the
 * service's collaborators. Here the SERVICE is what gets counted, because what
 * is under test is whether a request ever reaches it.
 *
 * Real here: `CosmosEndpointReview` (so the panel finds a pending review the
 * way production does), `CosmosEndpointPolicy` and `EndpointDescriptor` (so the
 * panel's redaction decision is the real one, which is the point of the
 * leak test).
 *
 * @package BCC_Trust
 * @subpackage Tests
 */

namespace {

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!class_exists('BccEndpointAdminState', false)) {
    final class BccEndpointAdminState
    {
        public static bool $can = true;

        public static int $userId = 7;

        public static string $validNonceAction = '';

        /** @var list<string> */
        public static array $nonceChecks = [];

        /** @var array<int, object> */
        public static array $chains = [];

        /** @var array<string, mixed> */
        public static array $transients = [];

        /** @var list<array{chain: int, target: string}> */
        public static array $reviewCalls = [];

        /** @var list<array{chain: int, review: string}> */
        public static array $executeCalls = [];

        public static function reset(): void
        {
            self::$can              = true;
            self::$userId           = 7;
            self::$validNonceAction = '';
            self::$nonceChecks      = [];
            self::$chains           = [];
            self::$transients       = [];
            self::$reviewCalls      = [];
            self::$executeCalls     = [];
        }

        public static function seedChain(int $id, ?string $restUrl, string $slug = 'cosmos'): void
        {
            self::$chains[$id] = (object) [
                'id'        => (string) $id,
                'slug'      => $slug,
                'rest_url'  => $restUrl,
                'is_active' => 1,
            ];
        }
    }
}

if (!class_exists('BccAdminDie', false)) {
    /** `wp_die()` terminates; throwing lets a test assert where it stopped. */
    final class BccAdminDie extends \RuntimeException
    {
        public int $status = 0;
    }
}

if (!class_exists('BccAdminRedirect', false)) {
    final class BccAdminRedirect extends \RuntimeException
    {
        /** @var array<string, string> */
        public array $args = [];

        public function __construct(public string $url = '')
        {
            parent::__construct('redirect: ' . $url);

            $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
            if ($query !== '') {
                parse_str($query, $parsed);
                /** @var array<string, string> $parsed */
                $this->args = $parsed;
            }
        }
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []): void
    {
        $e = new \BccAdminDie(is_string($message) ? $message : 'died');
        $e->status = (int) (is_array($args) ? ($args['response'] ?? 0) : 0);

        throw $e;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return \BccEndpointAdminState::$can;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return \BccEndpointAdminState::$userId;
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action = -1, $queryArg = '_wpnonce')
    {
        \BccEndpointAdminState::$nonceChecks[] = (string) $action;

        if ((string) $action !== \BccEndpointAdminState::$validNonceAction) {
            $e = new \BccAdminDie('nonce_failed:' . (string) $action);
            $e->status = 403;

            throw $e;
        }

        return true;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        throw new \BccAdminRedirect($location);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES);
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $t): string
    {
        return htmlspecialchars($t, ENT_QUOTES);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $t): string
    {
        return htmlspecialchars($t, ENT_QUOTES);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $k): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)) ?? '';
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . $path;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(...$args): string
    {
        $map = is_array($args[0]) ? $args[0] : [$args[0] => $args[1] ?? ''];

        return 'https://example.test/wp-admin/admin.php?' . http_build_query($map);
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '-1', string $name = '_wpnonce'): void
    {
        echo '<input type="hidden" name="' . $name . '" value="nonce">';
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'bcc-test-salt-' . $scheme;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string
    {
        static $n = 0;
        $n++;

        return substr(str_repeat('p' . $n . 'z', $length), 0, $length);
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return \BccEndpointAdminState::$transients[$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $ttl = 0): bool
    {
        \BccEndpointAdminState::$transients[$key] = $value;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset(\BccEndpointAdminState::$transients[$key]);

        return true;
    }
}

if (!function_exists('__')) {
    function __(string $t, string $d = ''): string
    {
        return $t;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $t, string $d = ''): string
    {
        return $t;
    }
}

}

namespace BCC\Trust\Onchain\Repositories {

if (!class_exists(ChainRepository::class, false)) {
    final class ChainRepository
    {
        public static function getById(int $id): ?object
        {
            return \BccEndpointAdminState::$chains[$id] ?? null;
        }

        public static function table(): string
        {
            return 'wp_bcc_chains';
        }
    }
}

}
