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
    /**
     * Every recorded write goes through here (M1 fault injection). When
     * $GLOBALS['__bcc_prefs_fail_on'] is N, the Nth write fails the way a
     * real DB error does: $wpdb->last_error is set and nothing is persisted.
     *
     * @param array{0: string, 1: string, 2: mixed} $write
     */
    function bcc_prefs_write(array $write): void
    {
        $GLOBALS['__bcc_prefs_write_count'] = ($GLOBALS['__bcc_prefs_write_count'] ?? 0) + 1;
        if ($GLOBALS['__bcc_prefs_write_count'] === ($GLOBALS['__bcc_prefs_fail_on'] ?? 0)) {
            $GLOBALS['wpdb']->last_error = 'Deadlock found when trying to get lock (simulated)';
            return;
        }
        $GLOBALS['__bcc_prefs_writes'][] = $write;
    }

    // The checked-write signal the endpoint reads (M1).
    $GLOBALS['wpdb'] = new class {
        public string $last_error = '';
    };

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
            // Like WordPress: an UNCHANGED value is not written and returns
            // false, which is NOT an error (M1 must not read it as one).
            if (($GLOBALS['__bcc_prefs_meta'][$userId][$key] ?? null) === $value) {
                return false;
            }
            \bcc_prefs_write(['meta', $key, $value]);
            if ($GLOBALS['wpdb']->last_error !== '') {
                return false;
            }
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

namespace BCC\Core\Security {
    // MyProfilePrefsEndpoint::patch takes the per-account 'me_write' bucket
    // (F-134) before validating; these tests are about atomicity, so it
    // always allows. Guarded, and declared before the endpoint class loads.
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Core\Log {
    // M1: a failed write is logged; record it instead.
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_prefs_logs'][] = [$message, $context];
            }
        }
    }
}
