<?php

declare(strict_types=1);

/**
 * Minimal priority-aware WP filter API for AnnouncementKsesWindowTest.
 *
 * Semantics copied from core where the test depends on them: has_filter()
 * returns the PRIORITY (int) or false; remove_filter() needs the same
 * callback and priority; apply_filters() runs callbacks in priority order.
 * Only loaded in a separate process.
 *
 *   $GLOBALS['__bcc_kw_filters'] array<string, array<int, list<callable>>>
 */

$GLOBALS['__bcc_kw_filters'] = [];
$GLOBALS['__bcc_kw_stub']    = true;

if (!function_exists('add_filter')) {
    function add_filter(string $hook, $cb, int $prio = 10, int $args = 1): bool
    {
        $GLOBALS['__bcc_kw_filters'][$hook][$prio][] = $cb;
        return true;
    }
}

if (!function_exists('has_filter')) {
    /** @return int|false */
    function has_filter(string $hook, $cb = false)
    {
        foreach ($GLOBALS['__bcc_kw_filters'][$hook] ?? [] as $prio => $cbs) {
            foreach ($cbs as $c) {
                if ($c === $cb) {
                    return (int) $prio;
                }
            }
        }
        return false;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, $cb, int $prio = 10): bool
    {
        foreach ($GLOBALS['__bcc_kw_filters'][$hook][$prio] ?? [] as $i => $c) {
            if ($c === $cb) {
                unset($GLOBALS['__bcc_kw_filters'][$hook][$prio][$i]);
                if ($GLOBALS['__bcc_kw_filters'][$hook][$prio] === []) {
                    unset($GLOBALS['__bcc_kw_filters'][$hook][$prio]);
                }
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value, ...$args)
    {
        $byPrio = $GLOBALS['__bcc_kw_filters'][$hook] ?? [];
        ksort($byPrio);
        foreach ($byPrio as $cbs) {
            foreach ($cbs as $cb) {
                $value = $cb($value, ...$args);
            }
        }
        return $value;
    }
}
