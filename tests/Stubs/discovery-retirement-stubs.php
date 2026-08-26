<?php

/**
 * Shims for AutomaticNftDiscoveryRetiredTest.
 *
 * Two independent cron stores, on purpose:
 *
 *   - {@see \BCC\Trust\Tests\Support\CronHealState}, already loaded from
 *     tests/bootstrap.php, backs the `BCC\Trust\Onchain\Services` shims so
 *     ChainRefreshService::init() can be driven and its schedule inspected.
 *   - {@see \BccRetirementState} backs the GLOBAL shims the cleanup
 *     migration reaches, because that file lives outside any namespace.
 *
 * They are separate so a test can seed pre-existing events for the
 * migration WITHOUT those events looking like something plugin
 * initialization scheduled.
 *
 * Event identity is (hook, args) in both, matching WordPress — the
 * migration's whole correctness argument turns on
 * `wp_clear_scheduled_hook($hook)` clearing the empty-argument identity
 * and leaving others alone.
 */

declare(strict_types=1);

namespace {

    if (!class_exists('BccRetirementState', false)) {
        /** Cron store for the GLOBAL shims the migration calls. */
        final class BccRetirementState
        {
            /** @var array<string, array{hook: string, args: array<int, mixed>, interval: string, next: int}> */
            public static array $events = [];

            /** Hooks whose clear is a no-op, to exercise the fail-closed path. */
            /** @var list<string> */
            public static array $refuseToClear = [];

            public static function reset(): void
            {
                self::$events        = [];
                self::$refuseToClear = [];
            }

            /** @param array<int, mixed> $args */
            public static function key(string $hook, array $args = []): string
            {
                return $args === [] ? $hook : $hook . "\0" . md5(serialize($args));
            }

            /** @param array<int, mixed> $args */
            public static function schedule(string $hook, array $args, string $interval, int $next = 1700003600): void
            {
                self::$events[self::key($hook, $args)] = [
                    'hook'     => $hook,
                    'args'     => $args,
                    'interval' => $interval,
                    'next'     => $next,
                ];
            }

            /**
             * @param array<int, mixed> $args
             * @return int|false
             */
            public static function next(string $hook, array $args = [])
            {
                return self::$events[self::key($hook, $args)]['next'] ?? false;
            }
        }
    }

    if (!function_exists('wp_next_scheduled')) {
        /**
         * @param array<int, mixed> $args
         * @return int|false
         */
        function wp_next_scheduled(string $hook, array $args = [])
        {
            return \BccRetirementState::next($hook, $args);
        }
    }

    if (!function_exists('wp_clear_scheduled_hook')) {
        /** @param array<int, mixed> $args */
        function wp_clear_scheduled_hook(string $hook, array $args = []): int
        {
            if (in_array($hook, \BccRetirementState::$refuseToClear, true)) {
                return 0;
            }
            $key = \BccRetirementState::key($hook, $args);
            if (!isset(\BccRetirementState::$events[$key])) {
                return 0;
            }
            unset(\BccRetirementState::$events[$key]);

            return 1;
        }
    }

    if (!defined('BCC_TRUST_MIGRATION_COMPLETE')) {
        define('BCC_TRUST_MIGRATION_COMPLETE', 'complete');
    }
    if (!defined('BCC_TRUST_MIGRATION_INCOMPLETE')) {
        define('BCC_TRUST_MIGRATION_INCOMPLETE', 'incomplete');
    }

    require_once dirname(__DIR__, 2) . '/includes/database/unschedule-automatic-nft-discovery.php';
}

namespace BCC\Trust\Onchain\Services {

    use BCC\Trust\Tests\Support\CronHealState;

    // ChainRefreshService calls these unqualified, so a shim in its own
    // namespace intercepts it without touching the global table the
    // migration uses.

    if (!function_exists(__NAMESPACE__ . '\\add_action')) {
        function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\add_filter')) {
        function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_next_scheduled')) {
        /**
         * @param array<int, mixed> $args
         * @return int|false
         */
        function wp_next_scheduled(string $hook, array $args = [])
        {
            $event = CronHealState::$scheduled[CronHealState::eventKey($hook, $args)] ?? null;

            return $event === null ? false : $event['next'];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_schedule_event')) {
        /** @param array<int, mixed> $args */
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
        {
            CronHealState::record($hook, $args, $recurrence, $timestamp);

            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_clear_scheduled_hook')) {
        /** @param array<int, mixed> $args */
        function wp_clear_scheduled_hook(string $hook, array $args = []): int
        {
            unset(CronHealState::$scheduled[CronHealState::eventKey($hook, $args)]);

            return 1;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\time')) {
        function time(): int
        {
            return CronHealState::$now;
        }
    }
}
