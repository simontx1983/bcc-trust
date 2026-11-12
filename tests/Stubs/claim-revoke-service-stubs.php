<?php

declare(strict_types=1);

/**
 * F-14 ClaimRevokeServiceTest: recording collaborators for
 * ClaimService::revokeClaim(). Load only in a separate process, BEFORE
 * ClaimService is autoloaded, so these declarations win over the real classes.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class ClaimRevokeWorld
    {
        /** @var array{revoked: bool, user_id: int, entity_type: string, entity_id: int, claim_role: string, mirror_page_ids: list<int>}|null */
        public static ?array $result = null;
        public static ?\Throwable $throw = null;
        /** @var list<int> */
        public static array $revokeCalls = [];
        public static ?int $ownerPage = 77;
        /** @var list<array{int, int}> */
        public static array $recomputes = [];
        /** @var list<array<int, mixed>> */
        public static array $actions = [];
        /** @var list<array<int, mixed>> */
        public static array $audits = [];
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, ...$args): void
        {
            \ClaimRevokeWorld::$actions[] = [$hook, ...$args];
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ClaimRepository', false)) {
        final class ClaimRepository
        {
            /** @return array{revoked: bool, user_id: int, entity_type: string, entity_id: int, claim_role: string, mirror_page_ids: list<int>} */
            public static function revoke(int $claimId): array
            {
                \ClaimRevokeWorld::$revokeCalls[] = $claimId;
                if (\ClaimRevokeWorld::$throw !== null) {
                    throw \ClaimRevokeWorld::$throw;
                }
                return \ClaimRevokeWorld::$result
                    ?? ['revoked' => false, 'user_id' => 0, 'entity_type' => '', 'entity_id' => 0, 'claim_role' => '', 'mirror_page_ids' => []];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(__NAMESPACE__ . '\\BonusService', false)) {
        final class BonusService
        {
            public static function recomputeAndApply(int $pageId, int $userId): void
            {
                \ClaimRevokeWorld::$recomputes[] = [$pageId, $userId];
            }
        }
    }
}

namespace BCC\Core {
    if (!class_exists(__NAMESPACE__ . '\\ServiceLocator', false)) {
        final class ServiceLocator
        {
            public static function resolvePageOwnerResolver(): object
            {
                return new class {
                    public function getPageForOwner(int $userId): ?int
                    {
                        return \ClaimRevokeWorld::$ownerPage;
                    }
                };
            }
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(__NAMESPACE__ . '\\AuditLogger', false)) {
        final class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $action, ?int $targetId = null, array $meta = [], ?string $targetType = null, ?int $userId = null): void
            {
                \ClaimRevokeWorld::$audits[] = [$action, $targetId, $meta, $targetType, $userId];
            }
        }
    }
}
