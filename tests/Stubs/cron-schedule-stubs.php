<?php

/**
 * Shims for CronService's schedule self-healing tests.
 *
 * Loaded from tests/bootstrap.php rather than from the test file, and that is
 * deliberate. PHPUnit loads every test file into one process, so a shim
 * declared inside a test file only wins if that file happens to load first,
 * and `apply_filters` in this namespace is already declared by two other
 * suites. Alphabetical load order is not a contract.
 *
 * Only FUNCTIONS live here. Class fakes deliberately do not: the Logger and
 * AdvisoryLock doubles differ across suites — some carry reset(), debug() or
 * record() — so a single central version silently removes methods other tests
 * depend on. Those stay in the suites that own them.
 *
 * Every shim is inert until CronHealState::$active is set, and otherwise
 * delegates to whatever global shim the running suite installed, because this
 * namespace is shared with every other service in the plugin.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Support {

    /** Mutable state driving the shims below. */
    final class CronHealState
    {
        /** @var array<string, array{interval:string, next:int}> hook => scheduled event */
        public static array $scheduled = [];
        /** @var array<string, mixed> */
        public static array $options = [];
        /** @var list<array{hook:string, interval:string}> every wp_schedule_event() call */
        public static array $scheduleCalls = [];
        /** @var list<string> every wp_clear_scheduled_hook() call */
        public static array $cleared = [];
        /** @var list<string> hooks the opt-out filter should remove from the owned set */
        public static array $disabled = [];
        public static bool $lockGranted = true;
        public static int $lockReleases = 0;
        public static int $now = 1700000000;
        /** When false the shims defer to real time(), for suites that are not ours. */
        public static bool $active = false;

        public static function reset(): void
        {
            self::$scheduled     = [];
            self::$options       = [];
            self::$scheduleCalls = [];
            self::$cleared       = [];
            self::$disabled      = [];
            self::$lockGranted   = true;
            self::$lockReleases  = 0;
            self::$now           = 1700000000;
            self::$active        = true;
        }

        /** Put every owned hook on the schedule — the "healthy site" baseline. */
        public static function scheduleEverything(): void
        {
            foreach (\BCC\Trust\Core\Services\CronService::ownedJobs() as $hook => $interval) {
                self::$scheduled[$hook] = ['interval' => $interval, 'next' => self::$now + 60];
            }
        }
    }
}

namespace BCC\Trust\Core\Services {

    use BCC\Trust\Tests\Support\CronHealState;

    // PHP resolves an unqualified call to the current namespace before global,
    // so these intercept CronService without touching the global function table.
    //
    // CRITICAL: every other service in this namespace — AttestationService,
    // VoteService, the lot — also calls these unqualified, and their suites run
    // in the same process. So each shim is INERT unless CronHealState::$active
    // is set, and otherwise delegates to whatever global shim that suite
    // installed. Without this the recorded state leaks sideways and takes out
    // several hundred unrelated tests.

    if (!function_exists(__NAMESPACE__ . '\\time')) {
        function time(): int
        {
            return CronHealState::$active ? CronHealState::$now : \time();
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $name, $default = false)
        {
            if (!CronHealState::$active) {
                return \function_exists('get_option') ? \get_option($name, $default) : $default;
            }
            return CronHealState::$options[$name] ?? $default;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\update_option')) {
        /** @param mixed $value */
        function update_option(string $name, $value, ?bool $autoload = null): bool
        {
            if (!CronHealState::$active) {
                return \function_exists('update_option') ? (bool) \update_option($name, $value, $autoload) : true;
            }
            CronHealState::$options[$name] = $value;
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
            if (!CronHealState::$active) {
                return \function_exists('wp_next_scheduled') ? \wp_next_scheduled($hook, $args) : false;
            }
            return isset(CronHealState::$scheduled[$hook])
                ? CronHealState::$scheduled[$hook]['next']
                : false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_schedule_event')) {
        /** @param array<int, mixed> $args */
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
        {
            if (!CronHealState::$active) {
                return \function_exists('wp_schedule_event')
                    ? (bool) \wp_schedule_event($timestamp, $recurrence, $hook, $args)
                    : true;
            }
            CronHealState::$scheduleCalls[]  = ['hook' => $hook, 'interval' => $recurrence];
            CronHealState::$scheduled[$hook] = ['interval' => $recurrence, 'next' => $timestamp];
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_clear_scheduled_hook')) {
        /** @param array<int, mixed> $args */
        function wp_clear_scheduled_hook(string $hook, array $args = []): int
        {
            if (!CronHealState::$active) {
                return \function_exists('wp_clear_scheduled_hook') ? (int) \wp_clear_scheduled_hook($hook, $args) : 0;
            }
            CronHealState::$cleared[] = $hook;
            unset(CronHealState::$scheduled[$hook]);
            return 1;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\apply_filters')) {
        /**
         * Pass-through, matching the shims the other suites in this namespace
         * declare — except that the owned-cron-jobs hook honours the opt-out
         * list, so "an intentionally disabled job is not resurrected" can be
         * tested rather than asserted structurally.
         *
         * @param mixed $value
         * @param mixed ...$args
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            if (!CronHealState::$active) {
                return \function_exists('apply_filters') ? \apply_filters($hook, $value, ...$args) : $value;
            }
            if ($hook === 'bcc_trust_owned_cron_jobs' && is_array($value)) {
                foreach (CronHealState::$disabled as $off) {
                    unset($value[$off]);
                }
            }
            return $value;
        }
    }
}
