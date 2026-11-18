<?php
/**
 * Stubs for LoginCaptchaStepUpTest — loaded after handle-rename-identity-stubs.php
 * (WP_Error) and BEFORE account-prehijack-stubs.php.
 *
 * Subprocess-only. No real HTTP ever leaves a test:
 *   - SafeHttpClient::post records each call in $GLOBALS['__bcc_cap']['posts']
 *     (url + args) and returns $GLOBALS['__bcc_cap']['reply'] — a WP-shaped
 *     response array, or a WP_Error;
 *   - wp_remote_retrieve_response_code / _body read that array;
 *   - DegradationMetrics::record appends "<subsystem>:<event>" to
 *     $GLOBALS['__bcc_ph']['metrics'].
 */

declare(strict_types=1);

namespace BCC\Core\Http {
    if (!class_exists(SafeHttpClient::class, false)) {
        final class SafeHttpClient
        {
            /**
             * @param array<string, mixed> $args
             * @return array<string, mixed>|\WP_Error
             */
            public static function post(string $url, array $args = [])
            {
                $GLOBALS['__bcc_cap']['posts'][] = ['url' => $url, 'args' => $args];
                return $GLOBALS['__bcc_cap']['reply'] ?? new \WP_Error('http_request_failed', 'no reply configured');
            }
        }
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(DegradationMetrics::class, false)) {
        final class DegradationMetrics
        {
            public static function record(string $subsystem, string $event = 'activation'): void
            {
                $GLOBALS['__bcc_ph']['metrics'][] = $subsystem . ':' . $event;
            }
        }
    }
}

namespace {
    if (!function_exists('wp_remote_retrieve_response_code')) {
        /** @param array<string, mixed>|\WP_Error $response @return int|string */
        function wp_remote_retrieve_response_code($response)
        {
            return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        /** @param array<string, mixed>|\WP_Error $response */
        function wp_remote_retrieve_body($response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }
}
