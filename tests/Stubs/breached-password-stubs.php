<?php
/**
 * Stubs for the breached-password check (BreachedPasswordCheck), at the
 * production FQNs of its bcc-core collaborators. Subprocess-only.
 *
 * $GLOBALS['__bcc_hibp']:
 *   response  what SafeHttpClient::get() answers: an array shaped like
 *             wp_remote_get()'s, a \WP_Error, or a callable(string $url)
 *             returning one (may throw). Unset → 200 with one padding row:
 *             "not listed", so a suite that only needs the route to work
 *             sees no breach and no outage.
 *   calls     list<array{url: string, args: array<string, mixed>}>
 *   metrics   list<string>  "subsystem/event" DegradationMetrics::record calls
 *   logs      list<array{0: string, 1: string, 2: array<string, mixed>}> (level, message, context)
 *             — only when this file's Logger is the one loaded
 *
 * Load AFTER a suite's own stubs: every declaration is guarded, so the
 * suite's Logger (and anything else it already declares) wins.
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
            public static function get(string $url, array $args = [])
            {
                $GLOBALS['__bcc_hibp']['calls'][] = ['url' => $url, 'args' => $args];
                $r = $GLOBALS['__bcc_hibp']['response'] ?? null;
                if (is_callable($r)) {
                    $r = $r($url);
                }
                return $r ?? ['response' => ['code' => 200], 'body' => "0000000000000000000000000000000000A:0\r\n"];
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
                $GLOBALS['__bcc_hibp']['metrics'][] = $subsystem . '/' . $event;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<int, mixed> $args */
            public static function __callStatic(string $level, array $args): void
            {
                $GLOBALS['__bcc_hibp']['logs'][] = [$level, (string) ($args[0] ?? ''), (array) ($args[1] ?? [])];
            }
        }
    }
}

namespace {
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '') {}
            public function get_error_code(): string { return $this->code; }
            public function get_error_message(): string { return $this->message; }
        }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        /** Like core: $response['response']['code'], '' for anything else. @return int|string */
        function wp_remote_retrieve_response_code($response)
        {
            return is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : '';
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }
}
