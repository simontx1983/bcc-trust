<?php

declare(strict_types=1);

/**
 * Just enough WordPress for `NftEnrichmentService::register()` to be CALLED
 * and observed.
 *
 * The point of the test that uses this is that register() schedules
 * NOTHING, so the scheduler functions are recorders rather than fakes with
 * behaviour: if the retired method ever starts scheduling again, the
 * recorder is what notices.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (!class_exists('BccEnrichmentCronState')) {
        final class BccEnrichmentCronState
        {
            /** @var array<string, array{ts: int, interval: string}> */
            public static array $scheduled = [];

            /** Every wp_schedule_event() call, whether or not it landed. */
            public static int $scheduleCalls = 0;

            public static function reset(): void
            {
                self::$scheduled     = [];
                self::$scheduleCalls = 0;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!function_exists(__NAMESPACE__ . '\\wp_next_scheduled')) {
        function wp_next_scheduled(string $hook, array $args = []): int|false
        {
            return \BccEnrichmentCronState::$scheduled[$hook]['ts'] ?? false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_schedule_event')) {
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
        {
            // ⚠ COUNTED BEFORE THE GUARD. A register() that called this and
            // had the call rejected would still be a register() that tried,
            // and "it tried" is the regression.
            \BccEnrichmentCronState::$scheduleCalls++;
            \BccEnrichmentCronState::$scheduled[$hook] = ['ts' => $timestamp, 'interval' => $recurrence];

            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_unschedule_event')) {
        function wp_unschedule_event(int $timestamp, string $hook, array $args = []): bool
        {
            unset(\BccEnrichmentCronState::$scheduled[$hook]);

            return true;
        }
    }
}
