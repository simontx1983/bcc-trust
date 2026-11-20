<?php
/**
 * Stubs for PageDiscoveryChainAppliedTest (F-439). Load ONLY from a
 * #[RunTestsInSeparateProcesses] class's setUp(): these declare global
 * functions and bcc-core classes that must never reach an in-process test.
 */

declare(strict_types=1);

namespace BCC\Core\DB {
    // bcc-core is not autoloaded in the unit context; stub the resolver.
    if (!class_exists(__NAMESPACE__ . '\\DB', false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(__NAMESPACE__ . '\\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            /** @var list<array{0: string, 1: string}> */
            public static array $recorded = [];

            public static function record(string $subsystem, string $event): void
            {
                self::$recorded[] = [$subsystem, $event];
            }
        }
    }
}

namespace {
    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '')
        {
            return $GLOBALS['__bcc_cache'][$group][$key] ?? false;
        }
    }
    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            $GLOBALS['__bcc_cache'][$group][$key] = $value;
            return true;
        }
    }
    if (!function_exists('apply_filters')) {
        /** @param mixed $value @return mixed */
        function apply_filters(string $hook, $value, ...$args)
        {
            return $value;
        }
    }
    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $s): string
        {
            return trim($s);
        }
    }
}
