<?php
/**
 * Stubs for MyNotificationPrefsEndpoint route tests. Layers on top of
 * route-handler-stubs.php (WP_REST_Request / WP_REST_Response + the
 * REST-namespace get_current_user_id fixture): adds an in-memory
 * usermeta store at the Support-namespace FQNs that NotificationPrefs'
 * unqualified get_user_meta / update_user_meta calls resolve to.
 *
 * Fixture global: $GLOBALS['__bcc_prefs_meta'][$userId][$metaKey] = '0'|'1'.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support {
    if (!function_exists('BCC\\Trust\\Core\\Support\\get_user_meta')) {
        /**
         * @return mixed '' when absent — mirrors WP's single-value read.
         */
        function get_user_meta(int $userId, string $key, bool $single = false)
        {
            unset($single);
            return $GLOBALS['__bcc_prefs_meta'][$userId][$key] ?? '';
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\Support\\update_user_meta')) {
        /**
         * @param mixed $value
         */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            $GLOBALS['__bcc_prefs_meta'][$userId][$key] = (string) $value;
            return true;
        }
    }
}
