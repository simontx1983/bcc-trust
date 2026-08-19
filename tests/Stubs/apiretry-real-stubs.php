<?php

declare(strict_types=1);

/**
 * Stubs for exercising the REAL {@see \BCC\Trust\Onchain\Support\ApiRetry}.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * Every other CosmWasm suite fakes ApiRetry at its production FQN and
 * queues canned responses. That is right for testing what the DOMAIN does
 * with a response — and it is exactly why the Dungeon breaker defect
 * survived a green suite: with the real retry loop replaced, nothing in
 * the codebase could observe that a 5xx retried four times and opened the
 * chain breaker.
 *
 * So here ApiRetry is REAL and its collaborators are faked instead:
 *   - OnchainCircuitBreaker records what it was told, so a test can assert
 *     "no failure was recorded" rather than infer it;
 *   - `sleep()` is shadowed INSIDE ApiRetry's own namespace, so the real
 *     backoff is observable as data instead of costing 8 real seconds.
 *
 * PHP resolves an unqualified `sleep()` in the calling namespace first,
 * which is what makes that shadowing work without touching production
 * code.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wp/');
    }

    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            /** @var array<string, list<string>> */
            private array $errors = [];

            public function __construct(string $code = '', string $message = '')
            {
                if ($code !== '') {
                    $this->errors[$code][] = $message;
                }
            }

            public function get_error_message(): string
            {
                foreach ($this->errors as $messages) {
                    return $messages[0] ?? '';
                }

                return '';
            }

            public function get_error_code(): string
            {
                foreach (array_keys($this->errors) as $code) {
                    return (string) $code;
                }

                return '';
            }
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response)
        {
            return is_array($response) ? ($response['response']['code'] ?? 0) : 0;
        }
    }

    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }

    if (!function_exists('wp_remote_retrieve_header')) {
        function wp_remote_retrieve_header($response, string $header)
        {
            return is_array($response) ? ($response['headers'][$header] ?? '') : '';
        }
    }

    if (!function_exists('update_option')) {
        function update_option(string $key, $value, $autoload = null): bool
        {
            \BccApiRetryOptionStore::$options[$key] = $value;

            return true;
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $key, $default = false)
        {
            return \BccApiRetryOptionStore::$options[$key] ?? $default;
        }
    }

    /** Tiny option store so update_option/get_option are observable. */
    final class BccApiRetryOptionStore
    {
        /** @var array<string, mixed> */
        public static array $options = [];

        public static function reset(): void
        {
            self::$options = [];
        }
    }

    /** Build a WP-HTTP-shaped response array. */
    final class BccHttp
    {
        /** @return array<string, mixed> */
        public static function response(int $code, string $body = '', array $headers = []): array
        {
            return [
                'response' => ['code' => $code],
                'body'     => $body,
                'headers'  => $headers,
            ];
        }

        /** The exact cosmos LCD error envelope, as measured on Dungeon. */
        public static function lcdError(string $message, int $code = 500): array
        {
            return self::response($code, (string) json_encode([
                'code'    => 2,
                'message' => $message,
                'details' => [],
            ]));
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<string> */
            public static array $lines = [];

            public static function reset(): void
            {
                self::$lines = [];
            }

            public static function warning(string $m, array $c = []): void
            {
                self::$lines[] = $m;
            }

            public static function error(string $m, array $c = []): void
            {
                self::$lines[] = $m;
            }

            public static function info(string $m, array $c = []): void
            {
                self::$lines[] = $m;
            }

            public static function debug(string $m, array $c = []): void
            {
                self::$lines[] = $m;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Support {

    /**
     * Shadows the global `sleep()` for ApiRetry ONLY.
     *
     * Records what the real backoff would have slept, so a test can assert
     * "this response was not retried" as `[]` rather than as elapsed time.
     */
    if (!function_exists(__NAMESPACE__ . '\\sleep')) {
        function sleep(int $seconds): int
        {
            \BccSleepSpy::$slept[] = $seconds;

            return 0;
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\OnchainCircuitBreaker', false)) {
        /**
         * Records what it is TOLD. Assertions read these lists directly, so
         * "the breaker was not blamed" is observed, never inferred.
         */
        final class OnchainCircuitBreaker
        {
            public static bool $open = false;
            /** @var list<int> */
            public static array $successChains = [];
            /** @var list<int> */
            public static array $failureChains = [];
            /** @var list<int> */
            public static array $probeReleases = [];

            public static function reset(): void
            {
                self::$open          = false;
                self::$successChains = [];
                self::$failureChains = [];
                self::$probeReleases = [];
            }

            public static function isOpen(int $chainId): bool
            {
                return self::$open;
            }

            public static function recordSuccess(int $chainId): void
            {
                self::$successChains[] = $chainId;
            }

            public static function recordFailure(int $chainId): void
            {
                self::$failureChains[] = $chainId;
            }

            public static function releaseProbe(int $chainId): void
            {
                self::$probeReleases[] = $chainId;
            }
        }
    }
}

namespace {
    /** Captures the shadowed sleep() calls. */
    final class BccSleepSpy
    {
        /** @var list<int> */
        public static array $slept = [];

        public static function reset(): void
        {
            self::$slept = [];
        }
    }
}
