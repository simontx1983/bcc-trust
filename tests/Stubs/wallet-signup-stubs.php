<?php
/**
 * Extra stubs for WalletSignInVerifyBeforeConsumeTest's wallet-signup cases —
 * loaded FIRST, before wallet-login-throttle-stubs.php and
 * account-prehijack-stubs.php.
 *
 * Subprocess-only. A Plugin whose handle service accepts any handle, and a
 * wp_insert_user that fails (WP_Error) — the tests observe what happened to
 * the challenge up to the user insert, which comes right after the consume.
 */

declare(strict_types=1);

namespace BCC\Trust\Core {
    if (!class_exists(Plugin::class, false)) {
        final class Plugin
        {
            public static function instance(): self
            {
                return new self();
            }

            public function handleService(): object
            {
                return new class {
                    public function validate(string $handle): ?string
                    {
                        return null;
                    }

                    public function isAvailable(string $handle): bool
                    {
                        return true;
                    }
                };
            }
        }
    }
}

namespace BCC\Core\DB {
    // WalletRepository::table() — the reads it serves hit the test $wpdb.
    if (!class_exists(DB::class, false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}

namespace {
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

            /** @return mixed */
            public function get_error_data()
            {
                return null;
            }
        }
    }
    if (!function_exists('wp_generate_password')) {
        function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string
        {
            return str_repeat('x', $length);
        }
    }
    if (!function_exists('wp_insert_user')) {
        /**
         * @param array<string, mixed> $data
         * @return \WP_Error
         */
        function wp_insert_user(array $data)
        {
            $GLOBALS['__bcc_wl']['inserted'][] = (string) $data['user_login'];
            // F-294 tests opt in to a successful insert (user 500) to reach
            // the wallet-link step; everything else keeps the old failure.
            if (!empty($GLOBALS['__bcc_wl']['insert_ok'])) {
                return 500;
            }
            return new \WP_Error('stub_insert_failed', 'stub');
        }
    }
    if (!function_exists('wp_delete_user')) {
        function wp_delete_user(int $id): bool
        {
            $GLOBALS['__bcc_wl']['deleted'][] = $id;
            return true;
        }
    }
}
