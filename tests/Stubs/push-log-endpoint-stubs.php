<?php
/**
 * Stubs for PushLogNoEndpointTest ONLY.
 *
 * ⚠ Required from the test's setUp(), inside its separate process, BEFORE
 * PushMetrics and Logger are autoloaded, so these declarations win. Never at
 * file level: a class declared there becomes THE class for the whole run.
 *
 * Logger records every line in `$GLOBALS['__push_log']`.
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    if (!function_exists('wp_parse_url')) {
        /** @return mixed */
        function wp_parse_url(string $url, int $component = -1)
        {
            return parse_url($url, $component);
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function warning(string $msg, array $ctx = []): void
            {
                $GLOBALS['__push_log'][] = ['warning', $msg, $ctx];
            }

            /** @param array<string, mixed> $ctx */
            public static function error(string $msg, array $ctx = []): void
            {
                $GLOBALS['__push_log'][] = ['error', $msg, $ctx];
            }

            /** @param array<string, mixed> $ctx */
            public static function info(string $msg, array $ctx = []): void
            {
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!class_exists(__NAMESPACE__ . '\\PushMetrics', false)) {
        final class PushMetrics
        {
            public static function recordAttempt(string $eventType): void {}
            public static function recordSuccess(string $eventType): void {}
            public static function recordTombstone(string $eventType): void {}
            public static function recordFailure(string $eventType): void {}
        }
    }
}
