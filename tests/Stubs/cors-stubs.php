<?php
/**
 * Stubs for CorsFinalAuthorityTest.
 *
 * The point of these tests is ORDERING and HEADER STATE, so the stubs
 * model both faithfully rather than no-op'ing:
 *
 *   - `HeaderRecorder` reproduces PHP's real header table semantics:
 *     `header()` replaces every same-name line by default, `header(..., false)`
 *     appends, `header_remove()` drops every line with that name,
 *     `headers_list()` returns the lines in order.
 *
 *   - `Hooks` reproduces WordPress's filter ordering: ascending priority,
 *     then registration order within a priority. That is the whole reason
 *     the production bug existed, so a stub that ignored ordering would
 *     test nothing.
 *
 *   - `WpCore` reproduces WP 7.0.2's `rest_send_cors_headers()`
 *     (wp-includes/rest-api.php) byte-for-byte in behaviour, plus the two
 *     `Access-Control-*` headers `WP_REST_Server::serve_request()` sends
 *     unconditionally before dispatch.
 *
 * WordPress functions are faked at their fully-qualified names inside
 * `BCC\Trust\Core\Support` so PHP's namespace fallback resolves the
 * production code's unqualified `header()` / `header_remove()` /
 * `headers_list()` calls to these instead of the PHP built-ins (which
 * cannot be redeclared globally).
 *
 * `status_header()` throws `PreflightTerminated` instead of returning:
 * `CorsHandler::handlePreflight()` calls `exit` two statements later, and
 * `exit` cannot be intercepted. Throwing at `status_header` gives the test
 * a seam that has already observed every header the preflight emits.
 *
 * Subprocess-only; guarded.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Support {
    if (!class_exists(HeaderRecorder::class, false)) {
        /** Faithful model of PHP's response-header table. */
        final class HeaderRecorder
        {
            /** @var list<string> */
            private static array $lines = [];

            public static function reset(): void
            {
                self::$lines = [];
            }

            public static function send(string $header, bool $replace = true): void
            {
                $name = self::nameOf($header);
                if ($replace && $name !== '') {
                    self::dropByName($name);
                }
                self::$lines[] = $header;
            }

            public static function remove(?string $name = null): void
            {
                if ($name === null) {
                    self::$lines = [];
                    return;
                }
                self::dropByName($name);
            }

            /** @return list<string> */
            public static function lines(): array
            {
                return self::$lines;
            }

            /**
             * Every value emitted for a header name, in order. An empty list
             * means the header is absent; more than one entry means a
             * duplicate survived.
             *
             * @return list<string>
             */
            public static function values(string $name): array
            {
                $out = [];
                foreach (self::$lines as $line) {
                    if (strcasecmp(self::nameOf($line), $name) === 0) {
                        $out[] = trim(substr($line, strlen(self::nameOf($line)) + 1));
                    }
                }
                return $out;
            }

            /** Convenience: the single value, or null when absent. */
            public static function value(string $name): ?string
            {
                $values = self::values($name);
                return $values === [] ? null : $values[0];
            }

            private static function dropByName(string $name): void
            {
                $kept = [];
                foreach (self::$lines as $line) {
                    if (strcasecmp(self::nameOf($line), $name) !== 0) {
                        $kept[] = $line;
                    }
                }
                self::$lines = $kept;
            }

            private static function nameOf(string $line): string
            {
                $pos = strpos($line, ':');
                return $pos === false ? trim($line) : trim(substr($line, 0, $pos));
            }
        }
    }

    if (!class_exists(Hooks::class, false)) {
        /** Minimal WP hook registry with real priority + registration ordering. */
        final class Hooks
        {
            /** @var array<string, list<array{priority: int, seq: int, cb: callable, args: int}>> */
            private static array $hooks = [];
            private static int $seq = 0;

            public static function reset(): void
            {
                self::$hooks = [];
                self::$seq   = 0;
            }

            public static function add(string $hook, callable $cb, int $priority, int $args): void
            {
                self::$hooks[$hook][] = [
                    'priority' => $priority,
                    'seq'      => self::$seq++,
                    'cb'       => $cb,
                    'args'     => $args,
                ];
            }

            /** @return list<int> registered priorities for a hook, in registration order */
            public static function priorities(string $hook): array
            {
                return array_map(
                    static fn (array $e): int => $e['priority'],
                    self::$hooks[$hook] ?? []
                );
            }

            /** @return mixed */
            public static function apply(string $hook, mixed ...$args)
            {
                $entries = self::$hooks[$hook] ?? [];
                usort(
                    $entries,
                    static fn (array $a, array $b): int
                        => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]
                );

                $value = $args[0] ?? null;
                foreach ($entries as $entry) {
                    $call  = array_slice(array_merge([$value], array_slice($args, 1)), 0, $entry['args']);
                    $value = ($entry['cb'])(...$call);
                }

                return $value;
            }

            /** @return list<mixed> */
            public static function callbacksFor(string $hook): array
            {
                return self::$hooks[$hook] ?? [];
            }
        }
    }

    if (!class_exists(WpCore::class, false)) {
        /**
         * WP 7.0.2 core behaviour, reproduced.
         *
         * `sendCorsHeaders` is `rest_send_cors_headers()` verbatim
         * (wp-includes/rest-api.php:797) — unconditional reflection of any
         * Origin, credentials on, Vary appended not replaced.
         *
         * `serveRequestPreamble` is the pair of `Access-Control-*` headers
         * `WP_REST_Server::serve_request()` sends before dispatch, which are
         * emitted regardless of whether an Origin was supplied.
         */
        final class WpCore
        {
            /** @return mixed */
            public static function sendCorsHeaders(mixed $value = null)
            {
                $origin = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])
                    ? $_SERVER['HTTP_ORIGIN']
                    : '';
                if ($origin !== '') {
                    HeaderRecorder::send('Access-Control-Allow-Origin: ' . $origin);
                    HeaderRecorder::send('Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE');
                    HeaderRecorder::send('Access-Control-Allow-Credentials: true');
                    HeaderRecorder::send('Vary: Origin', false);
                }
                return $value;
            }

            public static function serveRequestPreamble(): void
            {
                HeaderRecorder::send('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, Link');
                HeaderRecorder::send('Access-Control-Allow-Headers: Authorization, X-WP-Nonce, Content-Disposition, Content-MD5, Content-Type');
            }
        }
    }

    if (!class_exists(PreflightTerminated::class, false)) {
        /** Stands in for the `exit` at the end of handlePreflight(). */
        final class PreflightTerminated extends \RuntimeException
        {
            public function __construct(public readonly int $status)
            {
                parent::__construct('preflight terminated with ' . $status);
            }
        }
    }
}

namespace {
    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            public function __construct(private string $route = '', private string $method = 'GET')
            {
            }

            public function get_route(): string
            {
                return $this->route;
            }

            public function get_method(): string
            {
                return $this->method;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            public function __construct(private string $matchedRoute = '')
            {
            }

            public function get_matched_route(): string
            {
                return $this->matchedRoute;
            }
        }
    }
}

namespace BCC\Trust\Core\Support {

    use BCC\Trust\Core\Tests\Support\HeaderRecorder;
    use BCC\Trust\Core\Tests\Support\Hooks;
    use BCC\Trust\Core\Tests\Support\PreflightTerminated;

    if (!function_exists('BCC\\Trust\\Core\\Support\\header')) {
        function header(string $header, bool $replace = true, int $response_code = 0): void
        {
            HeaderRecorder::send($header, $replace);
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Support\\header_remove')) {
        function header_remove(?string $name = null): void
        {
            HeaderRecorder::remove($name);
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Support\\headers_list')) {
        /** @return list<string> */
        function headers_list(): array
        {
            return HeaderRecorder::lines();
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Support\\status_header')) {
        function status_header(int $code, string $description = ''): void
        {
            throw new PreflightTerminated($code);
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Support\\add_action')) {
        function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
        {
            Hooks::add($hook, $cb, $priority, $args);
            return true;
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Support\\add_filter')) {
        function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
        {
            Hooks::add($hook, $cb, $priority, $args);
            return true;
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Support\\wp_parse_url')) {
        /** @return array<string, int|string>|string|int|false|null */
        function wp_parse_url(string $url, int $component = -1)
        {
            return parse_url($url, $component);
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Support\\rest_get_url_prefix')) {
        function rest_get_url_prefix(): string
        {
            return 'wp-json';
        }
    }
}
