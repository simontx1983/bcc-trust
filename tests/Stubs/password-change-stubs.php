<?php
/**
 * Password-change JWT-revocation test stubs (audit H-2 residual).
 *
 * Loaded ONLY from inside a #[RunTestsInSeparateProcesses] subprocess
 * (PasswordChangeRevokesTokensTest). Lets UserLifecycleService's
 * onProfileUpdate / onPasswordReset run without WordPress:
 *
 *   - $GLOBALS['__bcc_pw_fresh_user']  = the WP_User get_userdata() returns
 *     (its user_pass is the "current" hash after a profile save).
 *   - $GLOBALS['__bcc_token_versions'] = [userId => ['bcc_token_version' => int]]
 *     backs get_user_meta / update_user_meta in JwtToken's namespace, so a
 *     JwtToken::revokeAllForUser() call is observable as a bumped counter.
 *
 * All definitions are guarded so a second require / the autoloader leaves
 * them alone.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_User', false)) {
        final class WP_User
        {
            public int $ID = 0;
            public string $user_pass = '';
        }
    }
}

namespace BCC\Trust\Core\Services {
    // onProfileUpdate reads the post-save user + fires a quest signal.
    if (!function_exists('BCC\\Trust\\Core\\Services\\get_userdata')) {
        function get_userdata($userId)
        {
            return $GLOBALS['__bcc_pw_fresh_user'] ?? false;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\do_action')) {
        function do_action($hook, ...$args): void
        {
        }
    }
}

namespace BCC\Trust\Core\Support {
    // JwtToken::revokeAllForUser() increments the per-user token version.
    if (!function_exists('BCC\\Trust\\Core\\Support\\get_user_meta')) {
        function get_user_meta($userId, $key, $single = false)
        {
            return $GLOBALS['__bcc_token_versions'][(int) $userId][$key] ?? '';
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Support\\update_user_meta')) {
        function update_user_meta($userId, $key, $value)
        {
            $GLOBALS['__bcc_token_versions'][(int) $userId][$key] = $value;
            return true;
        }
    }
}
