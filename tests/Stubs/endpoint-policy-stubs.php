<?php

declare(strict_types=1);

/**
 * Minimal GLOBAL-namespace WordPress shims for the endpoint-policy suites.
 *
 * ⚠ WHY THIS IS A FILE AND NOT A FEW LINES IN THE TEST. A test file that
 * already opens `namespace BCC\Trust\Onchain\Tests\Unit;` cannot declare a
 * global function: `function wp_parse_url()` there defines
 * `BCC\Trust\Onchain\Tests\Unit\wp_parse_url`, and PHP resolving an
 * unqualified call inside `BCC\Trust\Onchain\ValueObjects` looks in ITS OWN
 * namespace and then the global one — never in the test's. The guard
 * `function_exists('wp_parse_url')` checks the global name, finds nothing,
 * and cheerfully declares the useless namespaced copy. The symptom is
 * "Call to undefined function BCC\Trust\Onchain\ValueObjects\wp_parse_url()"
 * from a file that appears to define it.
 *
 * Braced namespace blocks are the only way to declare a global function from
 * a file that also needs namespaced ones, so the whole file uses them.
 *
 * ⚠ A file using braced blocks may contain NO code outside them — a bare
 * `require_once` at the top is a fatal parse error, not a warning.
 *
 * @package BCC\Trust\Onchain\Tests\Stubs
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wp/');
    }

    if (!function_exists('wp_parse_url')) {
        /** @return array<string, mixed>|string|int|false|null */
        function wp_parse_url(string $url, int $component = -1)
        {
            return $component === -1 ? parse_url($url) : parse_url($url, $component);
        }
    }

    /** In-memory transient store, so a positive cache can be asserted. */
    final class BccEndpointCache
    {
        /** @var array<string, mixed> */
        public static array $transients = [];

        /** Every set_transient call, in order. Anti-vacuity for "never cached". */
        public static array $writes = [];

        public static function reset(): void
        {
            self::$transients = [];
            self::$writes     = [];
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient(string $key)
        {
            return \BccEndpointCache::$transients[$key] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            \BccEndpointCache::$transients[$key] = $value;
            \BccEndpointCache::$writes[]         = ['key' => $key, 'value' => $value, 'ttl' => $ttl];

            return true;
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (!class_exists('WP_Error')) {
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
                    return (string) ($messages[0] ?? '');
                }

                return '';
            }
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response)
        {
            return is_array($response) ? (int) ($response['code'] ?? 0) : '';
        }
    }

    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }
}

namespace BCC\Core\Http {
    /**
     * The SSRF-hardened client, faked at its production FQN.
     *
     * ⚠ RECORDS ITS ARGS. The suite asserts that the verifier never enables
     * redirects — a claim you cannot make by reading the production default,
     * because the point is that this caller does not override it.
     *
     * ⚠ GUARDED. PHPUnit loads every test into ONE process unless a suite
     * opts out, and two other stub sets already declare this FQN. An
     * unguarded declaration is a fatal error whose message names THIS file
     * while the real cause is load order — which is not a contract. Suites
     * that need the recording behaviour must therefore run in separate
     * processes; suites that only need the class to exist get whichever
     * version loaded first, and must not assert on `$calls`.
     */
    if (!class_exists(SafeHttpClient::class, false)) {
    final class SafeHttpClient
    {
        /** @var list<array{url: string, args: array<string, mixed>}> */
        public static array $calls = [];

        /** @var array<string, mixed>|\WP_Error|null */
        public static $next = null;

        public static function reset(): void
        {
            self::$calls = [];
            self::$next  = null;
        }

        /**
         * @param  array<string, mixed> $args
         * @return array<string, mixed>|\WP_Error
         */
        public static function get(string $url, array $args = [])
        {
            self::$calls[] = ['url' => $url, 'args' => $args];

            return self::$next ?? new \WP_Error('http_request_failed', 'no scripted response');
        }

        /** @param array<string, mixed> $args */
        public static function prepareArgs(string $url, array $args = []): array
        {
            return $args;
        }
    }
    }
}
