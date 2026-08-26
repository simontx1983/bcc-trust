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
 *
 * ── EVENT IDENTITY IS (HOOK, ARGS), NOT HOOK ────────────────────────────────
 *
 * WordPress keys a cron event by hook AND by a digest of its serialized
 * arguments: `$crons[$timestamp][$hook][md5(serialize($args))]`. So
 * `wp_next_scheduled('h')` does NOT see an event scheduled as
 * `wp_next_scheduled('h', [7])`, and `wp_clear_scheduled_hook('h')` does NOT
 * remove one.
 *
 * This double used to key on the hook alone, which collapsed every real
 * WordPress identity for a hook into one fake entry. Any test of argument-
 * bearing events — a per-job continuation, a per-user fan-out — would have
 * passed while modelling something WordPress does not do. The store below
 * carries the arguments and matches on both.
 *
 * The KEY is the bare hook string when the argument list is empty, and
 * `hook \0 md5(serialize(args))` otherwise. That is a readability choice, not
 * a semantic one: the identities it distinguishes are exactly the identities
 * WordPress distinguishes, and it keeps the no-argument map (which is every
 * hook CronService owns) legible in a failure diff.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Support {

    /** Mutable state driving the shims below. */
    final class CronHealState
    {
        /**
         * Scheduled events, keyed by {@see eventKey()}.
         *
         * `next` is the earliest pending timestamp — what wp_next_scheduled()
         * returns. `timestamps` is every pending occurrence, because WordPress
         * permits the same (hook, args) at more than one timestamp once they
         * are far enough apart for the single-event duplicate check to allow it.
         *
         * @var array<string, array{hook:string, args:array<int, mixed>, interval:?string, next:int, timestamps:list<int>}>
         */
        public static array $scheduled = [];
        /** @var array<string, mixed> */
        public static array $options = [];
        /** @var list<array{hook:string, interval:string, args:array<int, mixed>}> every wp_schedule_event() call */
        public static array $scheduleCalls = [];
        /** @var list<array{hook:string, timestamp:int, args:array<int, mixed>, scheduled:bool}> every wp_schedule_single_event() call */
        public static array $singleScheduleCalls = [];
        /** @var list<string> every wp_clear_scheduled_hook() call */
        public static array $cleared = [];
        /** @var list<string> hooks the opt-out filter should remove from the owned set */
        public static array $disabled = [];
        public static bool $lockGranted = true;
        public static int $lockReleases = 0;
        public static int $now = 1700000000;
        /** When false the shims defer to real time(), for suites that are not ours. */
        public static bool $active = false;

        /**
         * WordPress rejects a single event scheduled within this many seconds
         * of an existing event for the same (hook, args). Mirrors the
         * `10 * MINUTE_IN_SECONDS` window in wp_schedule_single_event().
         */
        public const SINGLE_EVENT_DUPLICATE_WINDOW = 600;

        public static function reset(): void
        {
            self::$scheduled           = [];
            self::$options             = [];
            self::$scheduleCalls       = [];
            self::$singleScheduleCalls = [];
            self::$cleared             = [];
            self::$disabled            = [];
            self::$lockGranted         = true;
            self::$lockReleases        = 0;
            self::$now                 = 1700000000;
            self::$active              = true;
        }

        /**
         * The store key for one WordPress cron identity.
         *
         * @param array<int, mixed> $args
         */
        public static function eventKey(string $hook, array $args = []): string
        {
            return $args === [] ? $hook : $hook . "\0" . md5(serialize($args));
        }

        /**
         * Add one pending occurrence for a (hook, args) identity.
         *
         * An existing recurring interval is preserved when a later single
         * event lands on the same identity, so the two representations cannot
         * erase each other.
         *
         * @param array<int, mixed> $args
         */
        public static function record(string $hook, array $args, ?string $interval, int $timestamp): void
        {
            $key      = self::eventKey($hook, $args);
            $existing = self::$scheduled[$key] ?? null;

            $timestamps   = $existing['timestamps'] ?? [];
            $timestamps[] = $timestamp;
            sort($timestamps);
            /** @var list<int> $timestamps */
            $timestamps = array_values(array_unique($timestamps));

            self::$scheduled[$key] = [
                'hook'       => $hook,
                'args'       => $args,
                'interval'   => $interval ?? ($existing['interval'] ?? null),
                'next'       => $timestamps[0],
                'timestamps' => $timestamps,
            ];
        }

        /**
         * Every pending timestamp for one identity, earliest first.
         *
         * @param array<int, mixed> $args
         * @return list<int>
         */
        public static function timestampsFor(string $hook, array $args = []): array
        {
            return self::$scheduled[self::eventKey($hook, $args)]['timestamps'] ?? [];
        }

        /** Put every owned hook on the schedule — the "healthy site" baseline. */
        public static function scheduleEverything(): void
        {
            foreach (\BCC\Trust\Core\Services\CronService::ownedJobs() as $hook => $interval) {
                self::record($hook, [], $interval, self::$now + 60);
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
         * Earliest pending timestamp for this exact (hook, args) identity.
         *
         * @param array<int, mixed> $args
         * @return int|false
         */
        function wp_next_scheduled(string $hook, array $args = [])
        {
            if (!CronHealState::$active) {
                return \function_exists('wp_next_scheduled') ? \wp_next_scheduled($hook, $args) : false;
            }
            $event = CronHealState::$scheduled[CronHealState::eventKey($hook, $args)] ?? null;

            return $event === null ? false : $event['next'];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_schedule_event')) {
        /**
         * Recurring events are NOT deduplicated by WordPress — callers guard
         * with wp_next_scheduled(), which is why every scheduler in this plugin
         * does exactly that. The double matches core rather than the callers.
         *
         * @param array<int, mixed> $args
         */
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
        {
            if (!CronHealState::$active) {
                return \function_exists('wp_schedule_event')
                    ? (bool) \wp_schedule_event($timestamp, $recurrence, $hook, $args)
                    : true;
            }
            CronHealState::$scheduleCalls[] = ['hook' => $hook, 'interval' => $recurrence, 'args' => $args];
            CronHealState::record($hook, $args, $recurrence, $timestamp);

            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_schedule_single_event')) {
        /**
         * One-shot event, with core's duplicate rejection.
         *
         * WordPress refuses a single event scheduled within ten minutes of an
         * existing event for the same (hook, args) and returns false without
         * scheduling anything. A continuation chain that reuses one argument
         * list therefore stops dead, silently, with no error anywhere — which
         * is precisely the failure this double exists to let a test catch.
         *
         * @param array<int, mixed> $args
         */
        function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
        {
            if (!CronHealState::$active) {
                return \function_exists('wp_schedule_single_event')
                    ? (bool) \wp_schedule_single_event($timestamp, $hook, $args)
                    : true;
            }

            $next      = wp_next_scheduled($hook, $args);
            $duplicate = $next !== false
                && abs($next - $timestamp) <= CronHealState::SINGLE_EVENT_DUPLICATE_WINDOW;

            CronHealState::$singleScheduleCalls[] = [
                'hook'      => $hook,
                'timestamp' => $timestamp,
                'args'      => $args,
                'scheduled' => !$duplicate,
            ];

            if ($duplicate) {
                return false;
            }

            CronHealState::record($hook, $args, null, $timestamp);

            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_clear_scheduled_hook')) {
        /**
         * Clears every pending occurrence of this exact (hook, args) identity
         * and returns how many were removed — core's contract.
         *
         * An event scheduled WITH arguments survives a no-argument call, the
         * same way it does in WordPress.
         *
         * @param array<int, mixed> $args
         */
        function wp_clear_scheduled_hook(string $hook, array $args = []): int
        {
            if (!CronHealState::$active) {
                return \function_exists('wp_clear_scheduled_hook') ? (int) \wp_clear_scheduled_hook($hook, $args) : 0;
            }
            CronHealState::$cleared[] = $hook;

            $key   = CronHealState::eventKey($hook, $args);
            $event = CronHealState::$scheduled[$key] ?? null;
            if ($event === null) {
                return 0;
            }
            unset(CronHealState::$scheduled[$key]);

            return count($event['timestamps']);
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
