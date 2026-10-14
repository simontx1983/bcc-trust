<?php
/**
 * Stubs for AnnouncementCommentGuardsTest (subprocess-only).
 *
 * The guards are pure functions of their inputs plus three WordPress
 * lookups — `get_post_type`, `get_comment` and the filter registry. Those
 * are doubled so the REAL guard callbacks can be driven directly, rather
 * than a reimplementation of them.
 *
 * ⚠ WHAT THIS CANNOT DO, STATED SO NO READER OVERCLAIMS: WordPress core is
 * not loadable in this suite, so the core permission ladders
 * (`check_is_post_type_allowed`, `check_read_post_permission`, the
 * `post_password_required` branch, the `'note' !==` short-circuit) are NOT
 * executed here. Those were verified by READING WordPress 7.0.2 source in
 * this environment; the assertions below cover the code bcc-trust owns —
 * the registration arguments and the four guard callbacks — plus, in the
 * integration suite, the real SQL the exclusion produces.
 *
 * Fixtures:
 *   $GLOBALS['__bcc_ag_post_types'] array<int, string>  postId → post_type
 *   $GLOBALS['__bcc_ag_comments']   array<int, object>   commentId → comment row
 *   $GLOBALS['__bcc_ag_filters']    array<string, list<array{cb:mixed,prio:int,args:int}>>
 *   $GLOBALS['__bcc_ag_actions']    array<string, list<array{cb:mixed,prio:int,args:int}>>
 *   $GLOBALS['__bcc_ag_post_types_registered'] array<string, array<string,mixed>>
 */

declare(strict_types=1);

namespace {

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, $cb, int $prio = 10, int $args = 1): bool
        {
            $GLOBALS['__bcc_ag_filters'][$hook][] = ['cb' => $cb, 'prio' => $prio, 'args' => $args];
            return true;
        }
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, $cb, int $prio = 10, int $args = 1): bool
        {
            $GLOBALS['__bcc_ag_actions'][$hook][] = ['cb' => $cb, 'prio' => $prio, 'args' => $args];
            return true;
        }
    }

    if (!function_exists('register_post_type')) {
        /** @param array<string, mixed> $args */
        function register_post_type(string $type, array $args = [])
        {
            $GLOBALS['__bcc_ag_post_types_registered'][$type] = $args;
            return (object) ['name' => $type];
        }
    }

    if (!function_exists('get_post_type')) {
        /** @param mixed $post */
        function get_post_type($post = null)
        {
            $map = $GLOBALS['__bcc_ag_post_types'] ?? [];
            return $map[(int) $post] ?? false;
        }
    }

    if (!function_exists('get_comment')) {
        /** @param mixed $id */
        function get_comment($id = null, string $output = 'OBJECT')
        {
            $map = $GLOBALS['__bcc_ag_comments'] ?? [];
            return $map[(int) $id] ?? null;
        }
    }
}
