<?php
/**
 * Stubs for EnvelopeMetaTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses), so
 * the fake FQN classes below cannot shadow real classes in other suites.
 *
 *   - WP_REST_Response / WP_REST_Request / WP_REST_Server: the minimal WP
 *     surface Envelope::wrap() and ApiResponse touch, with real header
 *     storage so the X-Request-Id / Retry-After assertions observe output.
 *   - BCC\Core\Http\RequestContext: a fixed correlation id, so a test can
 *     assert `_meta.request_id` equals the X-Request-Id header.
 *   - BCC\Core\Security\Throttle: allow() and lastDenial() driven by
 *     $GLOBALS['__bcc_envelope_meta_throttle'] (['allow' => bool]).
 */

declare(strict_types=1);

namespace BCC\Core\Http {
    if (!class_exists(RequestContext::class, false)) {
        final class RequestContext
        {
            public const FIXED_ID = 'req-0123456789abcdef';

            public static function requestId(): string
            {
                return self::FIXED_ID;
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            /** @var array{key: string, limit: int, window: int}|null */
            private static ?array $denial = null;

            public static function allow(string $key, int $limit, int $window): bool
            {
                $allow = (bool) ($GLOBALS['__bcc_envelope_meta_throttle']['allow'] ?? true);
                self::$denial = $allow ? null : ['key' => $key, 'limit' => $limit, 'window' => $window];
                return $allow;
            }

            /** @return array{key: string, limit: int, window: int}|null */
            public static function lastDenial(): ?array
            {
                return self::$denial;
            }
        }
    }
}

namespace {
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

            /** @param mixed $data */
            public function set_data($data): void
            {
                $this->data = $data;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function set_status(int $status): void
            {
                $this->status = $status;
            }

            public function header(string $key, string $value, bool $replace = true): void
            {
                $this->headers[$key] = $value;
            }

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }
        }
    }

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            public function __construct(
                private string $route = '/bcc/v1/chains',
                private string $method = 'GET'
            ) {
            }

            public function get_route(): string
            {
                return $this->route;
            }

            public function get_method(): string
            {
                return $this->method;
            }

            public function get_header(string $key): ?string
            {
                return null;
            }
        }
    }

    if (!class_exists('WP_REST_Server', false)) {
        class WP_REST_Server
        {
        }
    }
}
