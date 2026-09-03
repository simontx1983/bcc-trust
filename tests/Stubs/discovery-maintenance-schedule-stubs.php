<?php

/**
 * Shims for DiscoveryMaintenanceScheduleTest.
 *
 * ⚠ NO NEW CRON STORE. The backing state is the existing
 * {@see \BCC\Trust\Tests\Support\CronHealState}, already loaded from
 * tests/bootstrap.php — it models WordPress faithfully in the ways this
 * fix turns on:
 *
 *   • event identity is (hook, args), so a no-argument recurring event is
 *     one identity and `wp_clear_scheduled_hook($hook)` clears exactly it;
 *   • `wp_schedule_event()` does NOT deduplicate — core doesn't either.
 *     Every scheduler in this plugin guards with `wp_next_scheduled()`, and
 *     `AsyncDispatcher::registerRecurring()` is the shared guard. A double
 *     that silently deduplicated would make the idempotency test vacuous:
 *     it would pass whether or not the guard existed.
 *
 * What is NEW here is only the NAMESPACE COVERAGE. PHP resolves an
 * unqualified call to the current namespace first, so the shims must exist
 * where the production code lives:
 *
 *   • `BCC\Core\Cron`            — AsyncDispatcher::registerRecurring()
 *                                  calls wp_next_scheduled/wp_schedule_event
 *   • `BCC\Trust\Onchain\Workers` — DiscoveryRunMaintenance::register()
 *                                  calls add_action
 *
 * Handler registrations land in {@see \BccMaintenanceHookState} so a test can
 * count them per (hook, callback) the way WordPress's callback ids do — that
 * is what proves the callback is not bound twice.
 */

declare(strict_types=1);

namespace {

    if (!class_exists('BccMaintenanceHookState', false)) {
        /** Records add_action() calls, keyed the way WordPress ids callbacks. */
        final class BccMaintenanceHookState
        {
            /** @var list<array{hook: string, id: string, priority: int, accepted: int}> */
            public static array $actions = [];

            public static function reset(): void
            {
                self::$actions = [];
            }

            /**
             * WordPress' `_wp_filter_build_unique_id()` for the shapes this
             * plugin uses: a [class, method] array becomes "Class::method",
             * so re-registering the SAME callback replaces rather than
             * appends. A closure gets a per-object id, so two closures are
             * two subscribers even when their bodies are identical — which
             * is exactly why the maintenance handler moved off a closure.
             *
             * @param mixed $callback
             */
            public static function callbackId($callback): string
            {
                if (is_string($callback)) {
                    return $callback;
                }
                if (is_array($callback) && count($callback) === 2) {
                    $target = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
                    return $target . '::' . (string) $callback[1];
                }
                if ($callback instanceof \Closure) {
                    return 'closure#' . spl_object_id($callback);
                }
                return 'unknown#' . md5(serialize($callback));
            }

            /** Distinct subscribers bound to a hook — duplicates collapse by id. */
            public static function subscriberIds(string $hook): array
            {
                $ids = [];
                foreach (self::$actions as $a) {
                    if ($a['hook'] === $hook) {
                        $ids[$a['id']] = true;
                    }
                }
                return array_keys($ids);
            }

            /** Raw add_action() call count — does NOT collapse duplicates. */
            public static function callCount(string $hook): int
            {
                $n = 0;
                foreach (self::$actions as $a) {
                    if ($a['hook'] === $hook) {
                        $n++;
                    }
                }
                return $n;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Workers {

    use BccMaintenanceHookState;

    if (!function_exists(__NAMESPACE__ . '\\add_action')) {
        /** @param mixed $callback */
        function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            BccMaintenanceHookState::$actions[] = [
                'hook'     => $hook,
                'id'       => BccMaintenanceHookState::callbackId($callback),
                'priority' => $priority,
                'accepted' => $acceptedArgs,
            ];

            return true;
        }
    }
}

namespace BCC\Core\Cron {

    use BCC\Trust\Tests\Support\CronHealState;

    /**
     * ⚠ The REAL AsyncDispatcher, not a double.
     *
     * `registerRecurring()` IS the idempotency guard this fix depends on, so
     * stubbing it would make every duplicate-registration assertion vacuous —
     * it would pass against a class that scheduled unconditionally. Only the
     * WordPress functions BENEATH it are shimmed (below), which is what lets
     * the test observe real scheduled-event state.
     *
     * Loaded from the adjacent bcc-core checkout, the same layout CI builds
     * (see .github/workflows/ci.yml — both PHP jobs check bcc-core out to
     * app/public/wp-content/plugins/bcc-core, adjacent to this plugin) and the
     * same resolution tests/Integration/bootstrap.php already uses.
     */
    if (!class_exists(AsyncDispatcher::class, false)) {
        $bccCoreAsyncDispatcher = dirname(__DIR__, 2) . '/../bcc-core/src/Cron/AsyncDispatcher.php';

        if (is_file($bccCoreAsyncDispatcher)) {
            require_once $bccCoreAsyncDispatcher;
        }
        unset($bccCoreAsyncDispatcher);
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
        /**
         * Mirrors core: recurring events are NOT deduplicated here. The guard
         * lives in registerRecurring(), and that is what the test exercises.
         *
         * @param array<int, mixed> $args
         */
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
        {
            CronHealState::$scheduleCalls[] = ['hook' => $hook, 'interval' => $recurrence, 'args' => $args];
            CronHealState::record($hook, $args, $recurrence, $timestamp);

            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\time')) {
        function time(): int
        {
            return CronHealState::$now;
        }
    }
}
