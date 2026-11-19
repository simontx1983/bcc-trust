<?php
/**
 * WordPressSignInGateTest doubles — the WordPress pieces the gate touches
 * that envelope-meta-stubs.php and account-prehijack-stubs.php (loaded
 * first) do not define. State lives in $GLOBALS['__bcc_sig']:
 *   - 'admins'  user ids that hold manage_options
 *   - 'current' get_current_user_id()
 *   - 'ajax'    wp_doing_ajax()
 *   - 'hooks'   every add_filter / add_action call, recorded
 *   - 'die' / 'redirect'  the last wp_die() / wp_redirect() /
 *               wp_safe_redirect() call: [where it really went, status]
 *   - 'allowed_redirect_hosts'  hosts that filter adds (none at admin_init:
 *               FrontendRedirect only registers it on rest_api_init)
 *   - 'reset_keys'   user ids get_password_reset_key() minted a key for
 *   - 'reset_throws' make get_password_reset_key() throw
 * wp_die() and both redirects throw SignInGateHalt, so the code after them
 * (an `exit`) never runs in a test. wp_safe_redirect() checks the host the
 * way core's wp_validate_redirect() does: an off-site target becomes
 * admin_url().
 */

declare(strict_types=1);

namespace {
    if (!class_exists('SignInGateHalt', false)) {
        final class SignInGateHalt extends \RuntimeException
        {
        }
    }

    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '')
            {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    if (!function_exists('user_can')) {
        /** @param \WP_User|int $user */
        function user_can($user, string $capability): bool
        {
            $id = $user instanceof \WP_User ? $user->ID : (int) $user;
            return $capability === 'manage_options'
                && in_array($id, $GLOBALS['__bcc_sig']['admins'] ?? [], true);
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_sig']['current'] ?? 0);
        }
    }

    if (!function_exists('wp_doing_ajax')) {
        function wp_doing_ajax(): bool
        {
            return (bool) ($GLOBALS['__bcc_sig']['ajax'] ?? false);
        }
    }

    if (!function_exists('wp_die')) {
        /**
         * @param mixed $message
         * @param mixed $title
         * @param mixed $args
         * @return never
         */
        function wp_die($message = '', $title = '', $args = [])
        {
            $GLOBALS['__bcc_sig']['die'] = [$message, $title];
            throw new \SignInGateHalt('wp_die');
        }
    }

    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return home_url('/wp-admin/' . ltrim($path, '/'));
        }
    }

    if (!function_exists('wp_redirect')) {
        /** @return never */
        function wp_redirect(string $location, int $status = 302, string $by = 'WordPress')
        {
            $GLOBALS['__bcc_sig']['redirect'] = [$location, $status];
            throw new \SignInGateHalt('redirect');
        }
    }

    if (!function_exists('wp_safe_redirect')) {
        /**
         * Core's shape (pluggable.php): wp_validate_redirect($location,
         * admin_url()) keeps a target only on the home host or a host the
         * `allowed_redirect_hosts` filter adds.
         *
         * @return never
         */
        function wp_safe_redirect(string $location, int $status = 302, string $by = 'WordPress')
        {
            $host    = strtolower((string) parse_url($location, PHP_URL_HOST));
            $allowed = array_merge(
                [strtolower((string) parse_url(home_url(), PHP_URL_HOST))],
                $GLOBALS['__bcc_sig']['allowed_redirect_hosts'] ?? []
            );
            if ($host !== '' && !in_array($host, $allowed, true)) {
                $location = admin_url();
            }

            return wp_redirect($location, $status, $by);
        }
    }

    if (!function_exists('get_password_reset_key')) {
        /**
         * Core's shape (user.php, 7.0.2): the `allow_password_reset`
         * filters decide (false: `no_password_reset`; a WP_Error: that
         * error), then a key. Runs the filters add_filter() recorded, in
         * the order they were added.
         *
         * @return string|\WP_Error
         */
        function get_password_reset_key(\WP_User $user)
        {
            if ($GLOBALS['__bcc_sig']['reset_throws'] ?? false) {
                throw new \RuntimeException('get_password_reset_key failed');
            }
            $allow = true;
            foreach ($GLOBALS['__bcc_sig']['hooks'] ?? [] as [$hook, $callback]) {
                if ($hook === 'allow_password_reset') {
                    $allow = $callback($allow, $user->ID);
                }
            }
            if (!$allow) {
                return new \WP_Error('no_password_reset', 'Password reset is not allowed for this user');
            }
            if ($allow instanceof \WP_Error) {
                return $allow;
            }
            $GLOBALS['__bcc_sig']['reset_keys'][] = $user->ID;

            return 'reset-key-for-' . $user->ID;
        }
    }

    if (!function_exists('esc_url')) {
        function esc_url(string $url): string
        {
            return $url;
        }
    }

    if (!function_exists('add_filter')) {
        /** @param mixed $callback */
        function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool
        {
            $GLOBALS['__bcc_sig']['hooks'][] = [$hook, $callback, $priority, $args];
            return true;
        }
    }

    if (!function_exists('add_action')) {
        /** @param mixed $callback */
        function add_action(string $hook, $callback, int $priority = 10, int $args = 1): bool
        {
            $GLOBALS['__bcc_sig']['hooks'][] = [$hook, $callback, $priority, $args];
            return true;
        }
    }
}
