<?php
/**
 * Doubles for GroupLeaveGuardTest: the REAL leave handlers of
 * MyGroupsEndpoint, HolderGroupsEndpoint and ValidatorGroupsEndpoint (and
 * GroupLeaveGuard), with bcc-core and the gate-config lookups faked.
 *
 * Fixture ($GLOBALS['__bcc_lg']):
 *   status       the caller's gm_user_status (null = no row)
 *   read_failed  getMembershipStatusOrFail returns false
 *   leave_result what PeepSoGroupWriter::leave returns (default true)
 *   leaves       list<[user, group]> leave() calls
 *
 * Gate configs exist for every id; the MyGroups context is a plain open
 * user group. Safe only inside #[RunTestsInSeparateProcesses].
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
}

namespace BCC\Trust\Onchain\Repositories {
    // Declared before suspension-gate-stubs.php so these win.
    if (!class_exists(GatedGroupRepository::class, false)) {
        class GatedGroupRepository
        {
            public static function getGateConfig(int $groupId): ?object
            {
                return (object) ['group_id' => $groupId];
            }

            /** @return list<mixed> */
            public static function listAllGatedGroupConfigs(): array
            {
                return [];
            }
        }
    }

    if (!class_exists(ValidatorGroupRepository::class, false)) {
        class ValidatorGroupRepository
        {
            public static function getGateConfig(int $groupId): ?object
            {
                return (object) ['group_id' => $groupId];
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/suspension-gate-stubs.php';
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(GroupContextResolver::class, false)) {
        final class GroupContextResolver
        {
            public function forGroup(int $groupId): ?\BCC\Trust\Core\ValueObjects\GroupContext
            {
                return new \BCC\Trust\Core\ValueObjects\GroupContext(
                    $groupId,
                    \BCC\Trust\Core\ValueObjects\GroupType::User,
                    \BCC\Trust\Core\ValueObjects\PeepSoPrivacy::Open,
                    false,
                    null,
                    null,
                    null
                );
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
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

namespace BCC\Trust\Core\Security {
    if (!class_exists(AuditLogger::class, false)) {
        class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $action, ?int $targetId = null, array $meta = [], ?string $targetType = null, ?int $userId = null): void
            {
                $GLOBALS['__bcc_lg']['audits'][] = [$action, $targetId];
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoGroupRepository::class, false)) {
        final class PeepSoGroupRepository
        {
            public static function getMembershipStatus(int $userId, int $groupId): ?string
            {
                $status = $GLOBALS['__bcc_lg']['status'] ?? null;
                return is_string($status) ? $status : null;
            }

            public static function getMembershipStatusOrFail(int $userId, int $groupId): string|null|false
            {
                if (!empty($GLOBALS['__bcc_lg']['read_failed'])) {
                    return false;
                }
                return self::getMembershipStatus($userId, $groupId);
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoGroupWriter::class, false)) {
        final class PeepSoGroupWriter
        {
            public static function leave(int $userId, int $groupId): bool
            {
                $GLOBALS['__bcc_lg']['leaves'][] = [$userId, $groupId];
                return $GLOBALS['__bcc_lg']['leave_result'] ?? true;
            }
        }
    }
}
