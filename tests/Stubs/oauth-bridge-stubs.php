<?php
/**
 * OAuth-bridge signed-request test stubs (audit HIGH #4).
 *
 * Loaded ONLY from inside a #[RunTestsInSeparateProcesses] subprocess
 * (OAuthBridgeGateTest). Lets OAuthController::oauthBridgeGate() run without
 * WordPress:
 *
 *   - WP_REST_Request / WP_REST_Response minimal doubles (global) satisfy the
 *     controller's type hints; the request carries settable headers + raw body.
 *   - get_transient / set_transient / delete_transient in AuthSupport's
 *     namespace back the single-use nonce store from
 *     $GLOBALS['__bcc_oauth_transients'].
 *   - BCC_OAUTH_BRIDGE_SECRET is defined so the gate is "configured".
 *
 * All definitions are guarded so a second require / the autoloader leaves
 * them alone.
 */

declare(strict_types=1);

namespace {
    if (!defined('BCC_OAUTH_BRIDGE_SECRET')) {
        define('BCC_OAUTH_BRIDGE_SECRET', 'test-bridge-secret-abc123');
    }

    if (!class_exists('WP_REST_Request', false)) {
        final class WP_REST_Request
        {
            /** @var array<string, string> */
            private array $headers = [];
            private string $body = '';

            public function set_header(string $key, string $value): void
            {
                $this->headers[strtolower($key)] = $value;
            }

            public function get_header(string $key): ?string
            {
                return $this->headers[strtolower($key)] ?? null;
            }

            public function set_body(string $body): void
            {
                $this->body = $body;
            }

            public function get_body(): string
            {
                return $this->body;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        final class WP_REST_Response
        {
            /** @param mixed $data */
            public function __construct(private mixed $data = null, private int $status = 200)
            {
            }

            public function get_status(): int
            {
                return $this->status;
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }
        }
    }
}

namespace BCC\Trust\Core\REST\Auth {
    if (!function_exists('BCC\\Trust\\Core\\REST\\Auth\\get_transient')) {
        function get_transient($key)
        {
            return $GLOBALS['__bcc_oauth_transients'][$key] ?? false;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\REST\\Auth\\set_transient')) {
        function set_transient($key, $value, $ttl = 0)
        {
            $GLOBALS['__bcc_oauth_transients'][$key] = $value;
            return true;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\REST\\Auth\\delete_transient')) {
        function delete_transient($key)
        {
            unset($GLOBALS['__bcc_oauth_transients'][$key]);
            return true;
        }
    }
}
