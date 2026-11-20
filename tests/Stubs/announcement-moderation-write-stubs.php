<?php

declare(strict_types=1);

/**
 * Environment for AnnouncementModerationWriteRefusalTest (separate process
 * only — required from setUp(), NEVER at file level; see
 * announcement-moderation-notice-stubs.php for why).
 *
 * Reuses the archive test's fake $wpdb + meta store, and adds a recorder for
 * the one post write edit() could reach, so "wrote nothing" is asserted
 * rather than inferred from an undefined-function fatal.
 *
 *   $GLOBALS['__bcc_arch_post_writes'] list<array<string, mixed>>
 */

require_once __DIR__ . '/announcement-archive-stubs.php';

$GLOBALS['__bcc_arch_post_writes'] = [];

// Minimal pass-throughs so a mutated edit() (refusal removed) reaches the
// post write and fails the "no post write" assertion, not an undefined
// function.
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data)
    {
        return $data;
    }
}
if (!function_exists('wp_slash')) {
    function wp_slash($value)
    {
        return $value;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}
if (!function_exists('has_filter')) {
    function has_filter($hook_name, $callback = false)
    {
        return false;
    }
}

if (!function_exists('wp_update_post')) {
    /** @param array<string, mixed> $post */
    function wp_update_post($post = [], bool $wp_error = false, bool $fire_after_hooks = true)
    {
        $GLOBALS['__bcc_arch_post_writes'][] = (array) $post;
        // F-361 — the superglobals AT the write. The guard restores them in
        // a `finally`, so a check after the call sees the original whether
        // the pin ran or not.
        $GLOBALS['__bcc_pin_at_write'][] = [
            'fn'   => 'wp_update_post',
            'get'  => $_GET,
            'post' => $_POST,
        ];
        return (int) (((array) $post)['ID'] ?? 0);
    }
}
