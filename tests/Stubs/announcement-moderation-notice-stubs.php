<?php
/**
 * Stubs for AnnouncementModerationNoticeDeferralTest ONLY.
 *
 * ⚠ Required from the test's setUp(), i.e. inside its separate process —
 * NEVER at file level. File-level code in a test class file runs in the
 * PARENT process during discovery, so a stub defined there becomes THE
 * class for the whole unit run. That is how a partial Logger stub (only
 * warning()) broke 21 unrelated tests in CI with "Call to undefined method
 * Logger::audit()/info()" (#652 review).
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    if (!class_exists('WP_Post')) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public int $post_author = 0;
        }
    }
    if (!function_exists('get_post')) {
        function get_post($id = null)
        {
            return $GLOBALS['__f307']['posts'][(int) $id] ?? null;
        }
    }
    if (!function_exists('get_post_meta')) {
        function get_post_meta(int $id, string $key = '', bool $single = false)
        {
            return $GLOBALS['__f307']['meta'][$id][$key] ?? '';
        }
    }
    if (!function_exists('update_post_meta')) {
        function update_post_meta(int $id, string $key, $value): bool
        {
            $GLOBALS['__f307']['meta'][$id][$key] = (string) $value;
            return true;
        }
    }
    if (!function_exists('wp_next_scheduled')) {
        /** @param list<mixed> $args */
        function wp_next_scheduled(string $hook, array $args = [])
        {
            foreach ($GLOBALS['__f307']['cron'] as $e) {
                if ($e['hook'] === $hook && $e['args'] === $args) {
                    return $e['at'];
                }
            }
            return false;
        }
    }
    if (!function_exists('wp_schedule_single_event')) {
        /** @param list<mixed> $args */
        function wp_schedule_single_event(int $at, string $hook, array $args = []): bool
        {
            $GLOBALS['__f307']['cron'][] = ['at' => $at, 'hook' => $hook, 'args' => $args];
            return true;
        }
    }
}

namespace BCC\Core\Log {
    // The real Logger writes files under ABSPATH; this child-process stub
    // records instead. Complete method set, so nothing it is ever asked for
    // is missing even inside this process.
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function debug(string $m, array $c = []): void { $GLOBALS['__f307']['logs'][] = $m; }
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void { $GLOBALS['__f307']['logs'][] = $m; }
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void { $GLOBALS['__f307']['logs'][] = $m; }
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void { $GLOBALS['__f307']['logs'][] = $m; }
            /** @param array<string, mixed> $c */
            public static function audit(string $m, array $c = []): void { $GLOBALS['__f307']['logs'][] = $m; }
        }
    }
}
