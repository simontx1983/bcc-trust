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

if (!function_exists('wp_update_post')) {
    /** @param array<string, mixed> $post */
    function wp_update_post($post = [], bool $wp_error = false, bool $fire_after_hooks = true)
    {
        $GLOBALS['__bcc_arch_post_writes'][] = (array) $post;
        return (int) (((array) $post)['ID'] ?? 0);
    }
}
