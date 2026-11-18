<?php
/**
 * WordPressSignInGateTest doubles — the WordPress pieces the gate touches
 * that envelope-meta-stubs.php and account-prehijack-stubs.php (loaded
 * first) do not define. State lives in $GLOBALS['__bcc_sig']:
 *   - 'admins'  user ids that hold manage_options
 *   - 'current' get_current_user_id()
 *   - 'ajax'    wp_doing_ajax()
 *   - 'hooks'   every add_filter / add_action call, recorded
 *   - 'die' / 'redirect'  the last wp_die() / wp_safe_redirect() call
 * wp_die() and wp_safe_redirect() throw SignInGateHalt, so the code after
 * them (an `exit`) never runs in a test.
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

    if (!function_exists('wp_safe_redirect')) {
        /** @return never */
        function wp_safe_redirect(string $location, int $status = 302, string $by = 'WordPress')
        {
            $GLOBALS['__bcc_sig']['redirect'] = [$location, $status];
            throw new \SignInGateHalt('redirect');
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
