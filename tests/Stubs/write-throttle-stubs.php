<?php
/**
 * Stubs for WriteSurfaceThrottleTest (subprocess-only).
 *
 * bcc-core's Throttle is doubled at its real FQCN and records every call;
 * get_post / get_current_user_id record whether a handler got past the
 * throttle. Fixture ($GLOBALS['__bcc_wt']):
 *   denied     bool                          Throttle::allow() answers !this
 *   calls      list<array{string,int,int}>   [action, limit, window]
 *   get_post   int                           get_post() call count
 */

declare(strict_types=1);

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null): bool
            {
                $GLOBALS['__bcc_wt']['calls'][] = [$action, $limit, $window];
                return empty($GLOBALS['__bcc_wt']['denied']);
            }
        }
    }
}

namespace {
    if (!function_exists('get_post')) {
        /** @return null */
        function get_post($post = null)
        {
            $GLOBALS['__bcc_wt']['get_post'] = ($GLOBALS['__bcc_wt']['get_post'] ?? 0) + 1;
            return null;
        }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return 7;
        }
    }
    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var mixed */
            public $data;
            public int $status;
            /** @var array<string, string> */
            public array $headers = [];

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
            public function header(string $key, string $value): void
            {
                $this->headers[$key] = $value;
            }
        }
    }
    if (!class_exists('WP_REST_Request')) {
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
}
