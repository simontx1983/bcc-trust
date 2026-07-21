<?php
/**
 * Fixture-backed stubs for SuspensionGateParityTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses),
 * never in the main test process, so the fake FQN classes below cannot
 * shadow the real bcc-core / bcc-trust classes anywhere else.
 *
 * Fixture shape ($GLOBALS['__bcc_susp_fixture']):
 *   user_id      int              value get_current_user_id() returns
 *   suspended    bool             true → Permissions::is_not_suspended() = false
 *   user_meta    array<int,array> get_user_meta($uid, $key, true) map
 *   bypass_args  list<bool>       records $allowAdminBypass per gate call
 *   repo_reached bool             set when GatedGroupRepository is queried
 *   throttled    bool             true → Throttle::allow() returns false (429)
 *   throttle_calls list<array{string,int,int}> records [key, limit, window]
 */

declare(strict_types=1);

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        class Throttle
        {
            public static function allow(string $key, int $limit, int $window, ?string $bucketKey = null): bool
            {
                $GLOBALS['__bcc_susp_fixture']['throttle_calls'][] = [$key, $limit, $window];
                return empty($GLOBALS['__bcc_susp_fixture']['throttled']);
            }
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        class Permissions
        {
            public static function is_not_suspended(?int $user_id = null, bool $allowAdminBypass = true): bool
            {
                $GLOBALS['__bcc_susp_fixture']['bypass_args'][] = $allowAdminBypass;
                return empty($GLOBALS['__bcc_susp_fixture']['suspended']);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(GatedGroupRepository::class, false)) {
        class GatedGroupRepository
        {
            /** @return list<mixed> */
            public static function listAllGatedGroupConfigs(): array
            {
                $GLOBALS['__bcc_susp_fixture']['repo_reached'] = true;
                return [];
            }
        }
    }
}

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_susp_fixture']['user_id'] ?? 0);
        }
    }

    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $user_id, string $key = '', bool $single = false)
        {
            return $GLOBALS['__bcc_susp_fixture']['user_meta'][$user_id][$key] ?? '';
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /** @return mixed */
            public function get_param(string $key)
            {
                return null;
            }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var mixed */
            public $data;
            public int $status;

            /**
             * @param mixed                $data
             * @param array<string, mixed> $headers
             */
            public function __construct($data = null, int $status = 200, array $headers = [])
            {
                $this->data   = $data;
                $this->status = $status;
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
            }
        }
    }
}
