<?php
/**
 * F-441: WordPress's lazy current-user resolution, for HarnessActAsTest.
 *
 * Load AFTER envelope-meta-stubs, account-prehijack-stubs and
 * wp-signin-gate-stubs, the same set WordPressSignInGateTest uses. Those
 * record add_filter() calls in $GLOBALS['__bcc_sig']['hooks'] and read the
 * current user from $GLOBALS['__bcc_sig']['current']. This adds the one
 * piece they leave out:
 *
 *   wp_get_current_user(): as core's _wp_get_current_user(). When the
 *   global `$current_user` is empty, it runs `determine_current_user` (in
 *   priority order, starting from false) and keeps the answer. So the real
 *   BearerAuth::authenticateBearer() decides who the request is, from the
 *   real Authorization header, exactly as it does in WordPress.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('wp_get_current_user')) {
        function wp_get_current_user(): \WP_User
        {
            $cached = $GLOBALS['current_user'] ?? null;
            if ($cached instanceof \WP_User) {
                return $cached;
            }

            $hooks = array_values(array_filter(
                $GLOBALS['__bcc_sig']['hooks'] ?? [],
                static fn (array $h): bool => $h[0] === 'determine_current_user'
            ));
            usort($hooks, static fn (array $a, array $b): int => $a[2] <=> $b[2]);

            $id = false;
            foreach ($hooks as $hook) {
                $id = ($hook[1])($id);
            }
            $id = is_int($id) && $id > 0 ? $id : 0;

            $u = $GLOBALS['__bcc_ph']['users'][$id] ?? ['email' => '', 'login' => '', 'display' => ''];
            $GLOBALS['current_user']          = new \WP_User($id, $u['email'], $u['login'], $u['display']);
            $GLOBALS['__bcc_sig']['current'] = $id;

            return $GLOBALS['current_user'];
        }
    }
}
