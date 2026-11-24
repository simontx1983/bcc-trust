<?php

declare(strict_types=1);

/**
 * A recording WordPress hook API for PeepSoMailSilenceTest. Load only in a
 * separate process: these are global functions.
 *
 *  - add_action() / add_filter() record [type, hook, callback, priority]
 *    in $GLOBALS['__bcc_ms_hooks'];
 *  - apply_filters() runs the recorded filters for a hook in priority order,
 *    as WordPress does;
 *  - wp_clear_scheduled_hook() records the hook in $GLOBALS['__bcc_ms_cleared'].
 */

namespace {
    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            $GLOBALS['__bcc_ms_hooks'][] = ['action', $hook, $callback, $priority];

            return true;
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            $GLOBALS['__bcc_ms_hooks'][] = ['filter', $hook, $callback, $priority];

            return true;
        }
    }

    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
        {
            /** @var list<array{0: string, 1: string, 2: callable, 3: int}> $hooks */
            $hooks = array_values(array_filter(
                $GLOBALS['__bcc_ms_hooks'] ?? [],
                static fn (array $h): bool => $h[0] === 'filter' && $h[1] === $hook
            ));
            usort($hooks, static fn (array $a, array $b): int => $a[3] <=> $b[3]);

            foreach ($hooks as $h) {
                $value = ($h[2])($value, ...$args);
            }

            return $value;
        }
    }

    if (!function_exists('wp_clear_scheduled_hook')) {
        /** @param array<int, mixed> $args */
        function wp_clear_scheduled_hook(string $hook, array $args = []): int
        {
            $GLOBALS['__bcc_ms_cleared'][] = $hook;

            return 0;
        }
    }
}
