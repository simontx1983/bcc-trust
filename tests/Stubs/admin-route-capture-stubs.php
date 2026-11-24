<?php
/**
 * Stubs for AdminCapabilitySingleSourceTest ONLY: just enough WordPress for
 * a controller's route registration to run, recording every route.
 *
 * ⚠⚠ SUBPROCESS-ONLY. Declares the global `register_rest_route()` and
 * `\WP_REST_Server`. A declaration cannot be undone, so the consuming test
 * is `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * Recorded in $GLOBALS['__bcc_arc']: list<array{0: string, 1: string, 2: array<mixed>}>
 * (namespace, route, args) per call.
 */

declare(strict_types=1);

namespace {
    // DisputeController's route args read it at registration (bcc-trust.php).
    if (!defined('BCC_DISPUTES_MIN_REASON_LENGTH')) {
        define('BCC_DISPUTES_MIN_REASON_LENGTH', 20);
    }
    if (!defined('BCC_DISPUTES_MAX_REASON_LENGTH')) {
        define('BCC_DISPUTES_MAX_REASON_LENGTH', 1000);
    }

    if (!class_exists('WP_REST_Server', false)) {
        class WP_REST_Server
        {
            public const READABLE   = 'GET';
            public const CREATABLE  = 'POST';
            public const EDITABLE   = 'POST, PUT, PATCH';
            public const DELETABLE  = 'DELETE';
            public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
        }
    }

    if (!function_exists('register_rest_route')) {
        /** @param array<mixed> $args */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            unset($override);
            $GLOBALS['__bcc_arc'][] = [$namespace, $route, $args];
            return true;
        }
    }
}
