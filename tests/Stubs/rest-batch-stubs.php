<?php
/**
 * Doubles of the WordPress core pieces a `/batch/v1` sub-request goes
 * through, for RestRouteAllowlistTest. Loaded ONLY inside a PHPUnit
 * subprocess (RunTestsInSeparateProcesses).
 *
 * Each one mirrors WordPress 7.0.2 (unit CI has no WordPress core):
 *   - WP_Error: the constructor, add(), add_data(), get_error_code(),
 *     get_error_data() and get_all_error_data() of wp-includes/class-wp-error.php.
 *     Like the real class it has NO get_data() / get_status().
 *   - WP_REST_Response: data, status and headers, as WP_HTTP_Response.
 *   - rest_ensure_response() and rest_convert_error_to_response(): copied
 *     from wp-includes/rest-api.php.
 *   - apply_filters(): runs the callbacks the test's add_filter() double
 *     recorded, in priority order.
 *   - bcc_test_serve_batch(): the per-request tail of
 *     WP_REST_Server::serve_batch_request_v1() and envelope_response().
 *     A filter's non-empty answer skips dispatch; whatever comes back goes
 *     through rest_ensure_response(), and then get_data(), get_status() and
 *     get_headers() are called on it. On a bare WP_Error that is a fatal,
 *     as in core.
 */

declare(strict_types=1);

if (!class_exists('WP_Error', false)) {
    class WP_Error
    {
        /** @var array<string, list<string>> */
        public $errors = [];
        /** @var array<string, mixed> */
        public $error_data = [];
        /** @var array<string, list<mixed>> */
        protected $additional_data = [];

        /** @param mixed $data */
        public function __construct($code = '', $message = '', $data = '')
        {
            if (empty($code)) {
                return;
            }
            $this->add($code, $message, $data);
        }

        /** @param mixed $data */
        public function add($code, $message, $data = ''): void
        {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->add_data($data, $code);
            }
        }

        /** @param mixed $data */
        public function add_data($data, $code = ''): void
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            if (isset($this->error_data[$code])) {
                $this->additional_data[$code][] = $this->error_data[$code];
            }
            $this->error_data[$code] = $data;
        }

        /** @return string|int */
        public function get_error_code()
        {
            $codes = array_keys($this->errors);
            return $codes === [] ? '' : $codes[0];
        }

        /** @return mixed */
        public function get_error_data($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->error_data[$code] ?? null;
        }

        /** @return list<mixed> */
        public function get_all_error_data($code = ''): array
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            $data = $this->additional_data[$code] ?? [];
            if (isset($this->error_data[$code])) {
                $data[] = $this->error_data[$code];
            }
            return $data;
        }
    }
}

if (!class_exists('WP_REST_Response', false)) {
    class WP_REST_Response
    {
        /**
         * @param mixed                $data
         * @param array<string, mixed> $headers
         */
        public function __construct(private mixed $data = null, private int $status = 200, private array $headers = [])
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        /** @return array<string, mixed> */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('rest_ensure_response')) {
    /** wp-includes/rest-api.php, WP 7.0.2 (minus the WP_HTTP_Response branch). */
    function rest_ensure_response(mixed $response): mixed
    {
        if (is_wp_error($response)) {
            return $response;
        }
        if ($response instanceof WP_REST_Response) {
            return $response;
        }
        return new WP_REST_Response($response);
    }
}

if (!function_exists('rest_convert_error_to_response')) {
    /** wp-includes/rest-api.php, WP 7.0.2, verbatim in behaviour. */
    function rest_convert_error_to_response(WP_Error $error): WP_REST_Response
    {
        $status = array_reduce(
            $error->get_all_error_data(),
            static function (int $status, $error_data): int {
                if (is_array($error_data) && isset($error_data['status']) && is_numeric($error_data['status'])) {
                    $status = (int) $error_data['status'];
                }
                return $status;
            },
            500
        );

        $errors = [];
        foreach ((array) $error->errors as $code => $messages) {
            $all_data  = $error->get_all_error_data($code);
            $last_data = array_pop($all_data);

            foreach ((array) $messages as $message) {
                $formatted = ['code' => $code, 'message' => $message, 'data' => $last_data];
                if ($all_data) {
                    $formatted['additional_data'] = $all_data;
                }
                $errors[] = $formatted;
            }
        }

        $data = $errors[0];
        if (count($errors) > 1) {
            array_shift($errors);
            $data['additional_errors'] = $errors;
        }

        return new WP_REST_Response($data, $status);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $callbacks = array_values(array_filter(
            $GLOBALS['__cug']['filters'] ?? [],
            static fn(array $f): bool => $f[0] === $hook
        ));
        usort($callbacks, static fn(array $a, array $b): int => $a[2] <=> $b[2]);
        foreach ($callbacks as [, $cb, , $accepted]) {
            $value = $cb(...array_slice([$value, ...$args], 0, $accepted));
        }
        return $value;
    }
}

if (!function_exists('bcc_test_serve_batch')) {
    /**
     * The per-request tail of WP_REST_Server::serve_batch_request_v1()
     * (WP 7.0.2), with envelope_response() inlined.
     *
     * @param list<WP_REST_Request>              $requests
     * @param callable(WP_REST_Request): mixed   $dispatch what the route itself answers
     */
    function bcc_test_serve_batch(array $requests, callable $dispatch): WP_REST_Response
    {
        $server    = new stdClass();
        $responses = [];
        foreach ($requests as $single_request) {
            $result = apply_filters('rest_pre_dispatch', null, $server, $single_request);

            if (empty($result)) {
                $result = $dispatch($single_request);
            }

            $result = apply_filters('rest_post_dispatch', rest_ensure_response($result), $server, $single_request);

            // envelope_response( $result, false )->get_data()
            $responses[] = [
                'body'    => $result->get_data(),
                'status'  => $result->get_status(),
                'headers' => $result->get_headers(),
            ];
        }

        return new WP_REST_Response(['responses' => $responses], 207);
    }
}
