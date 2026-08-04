<?php
/**
 * Namespaced WP-function fakes for FeedHydrationPipeline unit tests.
 *
 * hydrateViewerPermissions() calls current_user_can('manage_options')
 * (the can_delete admin branch, delete-post feature 2026-08-03). The
 * pure unit seam resolves it in the pipeline's own namespace, so a
 * guarded fake here keeps the test DB/WP-free. Toggle admin-ness via
 * $GLOBALS['__bcc_feed_is_admin'] (default false).
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Feed {
    if (!function_exists(__NAMESPACE__ . '\\current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return (bool) ($GLOBALS['__bcc_feed_is_admin'] ?? false);
        }
    }
}
