<?php
/**
 * Stubs for AuthSessionEndpointTest — loaded BEFORE account-prehijack-stubs.php.
 *
 * Subprocess-only. get_current_user_id() reports a logged-in WP cookie session
 * for a DIFFERENT, real user (71), so the test can prove GET /auth/session
 * ignores it and validates only the presented Bearer token.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return 71;
        }
    }
}
