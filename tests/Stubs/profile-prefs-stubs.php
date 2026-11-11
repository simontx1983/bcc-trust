<?php
/**
 * Doubles for driving the real MyProfilePrefsEndpoint::patch() (F-97).
 *
 * Every write the endpoint can make is RECORDED in $GLOBALS['__bcc_prefs_writes']
 * as [kind, key, value]: user meta, the PeepSo profile row and the mentor
 * opt-in. "No write" is therefore an assertable claim, which is the whole
 * point of an all-or-nothing PATCH.
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope: PHPUnit loads test files in the parent process too.
 *
 * PeepSoUser is NOT declared here. A test that needs PeepSo active requires
 * profile-prefs-peepso-stub.php, so "PeepSo missing" is the default.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @var array<string, string> */
            public array $headers = [];

            /** @param mixed $data */
            public function __construct(private $data = null, private int $status = 200)
            {
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function header(string $key, string $value, bool $replace = true): void
            {
                $this->headers[$key] = $value;
            }
        }
    }
}

namespace BCC\Trust\Core\REST {
    if (!function_exists(__NAMESPACE__ . '\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_prefs_user'] ?? 0);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return $GLOBALS['__bcc_prefs_meta'][$userId][$key] ?? '';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\update_user_meta')) {
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            $GLOBALS['__bcc_prefs_writes'][]          = ['meta', $key, $value];
            $GLOBALS['__bcc_prefs_meta'][$userId][$key] = $value;

            return true;
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!function_exists(__NAMESPACE__ . '\get_option')) {
        /**
         * @param  mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            return $default;
        }
    }
}
