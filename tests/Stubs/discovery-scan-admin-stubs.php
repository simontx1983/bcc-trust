<?php

/**
 * Shims for DiscoveryScanAdminActionsTest.
 *
 * The handler is an HTTP shell, so the things worth testing are the REFUSALS:
 * a GET, a bad nonce, a missing operator, a non-administrator. Every one of
 * those ends in `wp_die()` or a redirect, and both terminate the request in
 * production — so both are modelled as THROWN control-flow exceptions here.
 * That is what lets a test assert "the write never happened" rather than
 * merely "the function returned".
 *
 * ⚠ The service is NOT stubbed away. `DiscoveryRunService` is the thing that
 * re-checks the operator and owns the state machine; replacing it would make
 * every authorization assertion a test of the double. Its DEPENDENCIES are
 * stubbed instead, so the real service logic runs.
 */

declare(strict_types=1);

namespace {

    if (!class_exists('BccScanAdminDied', false)) {
        /** Thrown in place of wp_die(): the request is over. */
        final class BccScanAdminDied extends \RuntimeException
        {
            public function __construct(public readonly string $title, public readonly int $status)
            {
                parent::__construct($title);
            }
        }
    }

    if (!class_exists('BccScanAdminRedirected', false)) {
        /** Thrown in place of the PRG redirect: the request is over. */
        final class BccScanAdminRedirected extends \RuntimeException
        {
            /** @param array<string, string|int> $args */
            public function __construct(public readonly array $args)
            {
                parent::__construct('redirect');
            }
        }
    }

    if (!class_exists('BccScanAdminState', false)) {
        final class BccScanAdminState
        {
            public static int $currentUserId = 0;
            public static bool $sessionCan = true;

            /** Users that exist, by id => capability. */
            /** @var array<int, bool> */
            public static array $users = [];

            /** Nonces that verify. */
            /** @var list<string> */
            public static array $validNonces = [];

            /** @var list<array{action: string, targetType: string, targetId: int|null, meta: array<string, mixed>}> */
            public static array $audits = [];

            /** Every provider/HTTP call attempted — must stay EMPTY. */
            /** @var list<string> */
            public static array $httpCalls = [];

            public static function reset(): void
            {
                self::$currentUserId = 7;
                self::$sessionCan    = true;
                self::$users         = [7 => true];
                self::$validNonces   = [];
                self::$audits        = [];
                self::$httpCalls     = [];
                $_POST               = [];
                $_SERVER['REQUEST_METHOD'] = 'POST';
            }
        }
    }
}

namespace BCC\Trust\Onchain\Admin {

    use BccScanAdminDied;
    use BccScanAdminRedirected;
    use BccScanAdminState;

    if (!function_exists(__NAMESPACE__ . '\\current_user_can')) {
        function current_user_can(string $cap): bool
        {
            return BccScanAdminState::$sessionCan && $cap === 'manage_options';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return BccScanAdminState::$currentUserId;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_die')) {
        /** @param array<string, int> $args */
        function wp_die(string $message = '', string $title = '', array $args = []): never
        {
            throw new BccScanAdminDied($title !== '' ? $title : $message, (int) ($args['response'] ?? 0));
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\check_admin_referer')) {
        function check_admin_referer(string $action, string $queryArg = '_wpnonce'): bool
        {
            // Mirrors core: a failure wp_die()s with 403 and never returns, so
            // a forged nonce cannot reach the write path.
            if (!in_array($action, BccScanAdminState::$validNonces, true)) {
                throw new BccScanAdminDied('Forbidden', 403);
            }

            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\current_action')) {
        function current_action(): string
        {
            return (string) ($GLOBALS['__bcc_scan_current_action'] ?? '');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\esc_html__')) {
        function esc_html__(string $text, string $domain = ''): string
        {
            return $text;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_safe_redirect')) {
        function wp_safe_redirect(string $location, int $status = 302): bool
        {
            throw new BccScanAdminRedirected(['location' => $location]);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . $path;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\add_query_arg')) {
        /** @param array<string, string|int> $args */
        function add_query_arg(array $args, string $url): string
        {
            throw new BccScanAdminRedirected($args);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\add_action')) {
        function add_action(string $hook, mixed $cb, int $priority = 10, int $accepted = 1): bool
        {
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return $value;
        }
    }
}

namespace BCC\Trust\Onchain\Services {

    use BccScanAdminState;

    if (!function_exists(__NAMESPACE__ . '\\get_userdata')) {
        /** @return object|false */
        function get_userdata(int $userId)
        {
            return array_key_exists($userId, BccScanAdminState::$users)
                ? (object) ['ID' => $userId]
                : false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\user_can')) {
        function user_can(int $userId, string $cap): bool
        {
            return (BccScanAdminState::$users[$userId] ?? false) && $cap === 'manage_options';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_get')) {
        /** ⚠ Records and refuses. A provider call from an admin POST is a bug. */
        function wp_remote_get(string $url, array $args = []): array
        {
            BccScanAdminState::$httpCalls[] = $url;

            return ['response' => ['code' => 500]];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_post')) {
        function wp_remote_post(string $url, array $args = []): array
        {
            BccScanAdminState::$httpCalls[] = $url;

            return ['response' => ['code' => 500]];
        }
    }
}
