<?php
/**
 * Stubs for CardsSearchIncompleteTest (F-421): the REAL
 * CardsSearchEndpoint::handle() over three faked bcc-search verticals.
 *
 * ⚠⚠ SUBPROCESS-ONLY. This declares `\WP_REST_Request`, `\WP_REST_Response`,
 * `\WP_REST_Server`, the global `rest_do_request()` and
 * `BCC\Trust\Core\Support\get_option()`. A declaration cannot be undone and
 * the first one wins for the rest of the process, so the consuming test is
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * Why not cards-search-throttle-stubs.php: its WP_REST_Request takes the
 * params array as its FIRST constructor argument (its two tests rely on
 * that), while this endpoint builds its upstream requests with WordPress's
 * own `new WP_REST_Request('GET', '/bcc/v1/search')`. It also has no
 * set_param(), get_route() or is_error().
 *
 * Fixture ($GLOBALS['__bcc_csu']):
 *   upstream  array<string, array{status: int, results: list<array<string, mixed>>}>
 *             what each vertical answers, keyed by route ('/bcc/v1/search',
 *             '/bcc/v1/search/groups', '/bcc/v1/search/users')
 *   calls     list<array{string, array<string, mixed>}>  every rest_do_request(): route, params
 */

declare(strict_types=1);

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
    if (!class_exists('WP_REST_Server', false)) {
        class WP_REST_Server
        {
            public const READABLE = 'GET';
        }
    }

    if (!class_exists('WP_REST_Request', false)) {
        /** WordPress's constructor shape: method, then route. */
        class WP_REST_Request
        {
            /** @var array<string, mixed> */
            private array $params = [];

            public function __construct(private string $method = '', private string $route = '')
            {
            }

            public function get_method(): string
            {
                return $this->method;
            }

            public function get_route(): string
            {
                return $this->route;
            }

            /** @param mixed $value */
            public function set_param(string $key, $value): void
            {
                $this->params[$key] = $value;
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }

            /** @return array<string, mixed> */
            public function get_params(): array
            {
                return $this->params;
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

            /** As WP_HTTP_Response::is_error(): any status of 400 or above. */
            public function is_error(): bool
            {
                return $this->status >= 400;
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

    if (!function_exists('rest_do_request')) {
        /**
         * A vertical's answer from the fixture. A failure has bcc-search's own
         * shape (a 503 when degraded, a 429 when the shared `search` throttle
         * bucket is spent); a success carries `results`.
         */
        function rest_do_request(WP_REST_Request $request): WP_REST_Response
        {
            $route = $request->get_route();
            $GLOBALS['__bcc_csu']['calls'][] = [$route, $request->get_params()];

            $answer = $GLOBALS['__bcc_csu']['upstream'][$route] ?? null;
            if (!is_array($answer)) {
                throw new \LogicException("unexpected upstream call: {$route}");
            }
            $status = (int) $answer['status'];

            return $status >= 400
                ? new WP_REST_Response(['code' => 'bcc_search_unavailable', 'message' => 'Search unavailable.', 'data' => ['status' => $status]], $status)
                : new WP_REST_Response(['results' => $answer['results']], $status);
        }
    }
}
