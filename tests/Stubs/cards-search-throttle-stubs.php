<?php
/**
 * Stubs for CardsSearchThrottleTest and MeValidatorPagesCacheTest.
 *
 * ⚠⚠ SUBPROCESS-ONLY. This declares `\WP_REST_Request`,
 * `\WP_REST_Response`, `\BCC\Core\Security\Throttle` and
 * `\BCC\Core\DB\DB`, plus the global `register_rest_route()` and
 * `get_current_user_id()`. A declaration cannot be undone and the first one
 * wins for the rest of the process, so both consuming tests are
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * Fixture ($GLOBALS['__bcc_cst']):
 *   user_id        int                          get_current_user_id()
 *   throttle_allow bool                         what Throttle::allow() answers
 *   throttle_calls list<array{string,int,int,?string}>  every allow() call
 *   routes         list<array{string,string,array<string,mixed>}>  register_rest_route() calls
 *   rows           list<object>                 what $wpdb->get_results() returns
 */

declare(strict_types=1);

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        class Throttle
        {
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null, bool $countDenied = true): bool
            {
                unset($countDenied);
                $GLOBALS['__bcc_cst']['throttle_calls'][] = [$action, $limit, $window, $key];
                return (bool) ($GLOBALS['__bcc_cst']['throttle_allow'] ?? true);
            }
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(DB::class, false)) {
        class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ApiResponse's envelope reaches ReactionTypeRegistry::all(), which
    // reads an option; the default is the registry's documented path.
    if (!\function_exists('BCC\Trust\Core\Support\get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            unset($key);
            return $default;
        }
    }
}

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_cst']['user_id'] ?? 0);
        }
    }

    if (!function_exists('register_rest_route')) {
        /** @param array<string, mixed> $args */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            unset($override);
            $GLOBALS['__bcc_cst']['routes'][] = [$namespace, $route, $args];
            return true;
        }
    }

    if (!class_exists('WP_REST_Server', false)) {
        class WP_REST_Server
        {
            public const READABLE  = 'GET';
            // HallsEndpoint::register() also adds its write routes.
            public const CREATABLE = 'POST';
            public const DELETABLE = 'DELETE';
        }
    }

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @var array<string, string> */
            private array $headers = [];

            /** @param mixed $data */
            public function __construct(private $data = null, private int $status = 200)
            {
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
                unset($replace);
                $this->headers[$key] = $value;
            }

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }
        }
    }

    final class BccCardsSearchFakeWpdb
    {
        public string $prefix   = 'wp_';
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            unset($args);
            return $sql;
        }

        /** @return list<object> */
        public function get_results(string $sql): array
        {
            unset($sql);
            return $GLOBALS['__bcc_cst']['rows'] ?? [];
        }
    }
}
