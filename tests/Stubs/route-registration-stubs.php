<?php
/**
 * Stubs for RestDestinationValidationTest — capture what the REST
 * endpoints declare to WordPress so the arg schema (the thing that makes
 * WP reject a non-scalar / non-integer group_id before the handler runs)
 * can be asserted without a running WordPress.
 *
 * `register_rest_route` is defined in the endpoints' own namespace
 * (BCC\Trust\Core\REST) so the unqualified call is intercepted; captured
 * registrations land in $GLOBALS['__bcc_routes']. WP_REST_Server is a
 * global constant holder (the endpoints `use WP_REST_Server`).
 *
 * Subprocess-only; guarded.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_REST_Server', false)) {
        class WP_REST_Server
        {
            public const READABLE  = 'GET';
            public const CREATABLE = 'POST';
            public const EDITABLE  = 'POST, PUT, PATCH';
            public const DELETABLE = 'DELETE';
        }
    }
}

namespace BCC\Trust\Core\REST {
    if (!function_exists('BCC\\Trust\\Core\\REST\\register_rest_route')) {
        /**
         * @param array<string, mixed> $args
         */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['__bcc_routes'][] = [
                'namespace' => $namespace,
                'route'     => $route,
                'args'      => $args,
            ];
            return true;
        }
    }
}
