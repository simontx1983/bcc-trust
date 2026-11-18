<?php
/**
 * Stubs for BlockingOptionPinnedTest (F-295). Subprocess-only: required
 * from setUp() of a #[RunTestsInSeparateProcesses] class, never at file
 * scope. A tiny hook registry, so the test can apply the REAL registered
 * callback the way PeepSoConfigSettings::get_option() does.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
        {
            $GLOBALS['__f295_hooks'][$hook][] = [$cb, $priority, $args];
            return true;
        }
    }
    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
        {
            return add_filter($hook, $cb, $priority, $args);
        }
    }
    if (!function_exists('apply_filters')) {
        /**
         * @param mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value)
        {
            foreach ($GLOBALS['__f295_hooks'][$hook] ?? [] as [$cb]) {
                $value = $cb($value);
            }
            return $value;
        }
    }
}
