<?php

declare(strict_types=1);

/**
 * F-14 ClaimRevokeServiceTest: recording collaborators for
 * ClaimService::revokeClaim(). Load only in a separate process, BEFORE
 * ClaimService is autoloaded, so these declarations win over the real classes.
 *
 * Each collaborator can be told to throw ($fail[<step>]) so the post-commit
 * isolation (F-14 PR B1) can be proven step by step.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class ClaimRevokeWorld
    {
        /** @var array{revoked: bool, user_id: int, entity_type: string, entity_id: int, claim_role: string, mirror_page_ids: list<int>, bound_page_ids: list<int>}|null */
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
        /** @var list<int> */
        public static array $dirty = [];
        /** @var list<array{string, array<string, mixed>}> */
        public static array $errors = [];
        /** @var array<string, true> step => throw */
        public static array $fail = [];

        public static function reset(): void
        {
            self::$result = null;
            self::$throw = null;
            self::$revokeCalls = [];
            self::$ownerPage = 77;
            self::$recomputes = [];
            self::$actions = [];
            self::$audits = [];
            self::$dirty = [];
            self::$errors = [];
            self::$fail = [];
        }

        public static function maybeFail(string $step): void
        {
            if (isset(self::$fail[$step])) {
                throw new \RuntimeException($step . ' failed');
            }
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, ...$args): void
        {
            \ClaimRevokeWorld::maybeFail($hook);
            \ClaimRevokeWorld::$actions[] = [$hook, ...$args];
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ClaimRepository', false)) {
        final class ClaimRepository
        {
            /** @return array{revoked: bool, user_id: int, entity_type: string, entity_id: int, claim_role: string, mirror_page_ids: list<int>, bound_page_ids: list<int>} */
            public static function revoke(int $claimId): array
            {
                \ClaimRevokeWorld::$revokeCalls[] = $claimId;
                if (\ClaimRevokeWorld::$throw !== null) {
                    throw \ClaimRevokeWorld::$throw;
                }
                return \ClaimRevokeWorld::$result
                    ?? ['revoked' => false, 'user_id' => 0, 'entity_type' => '', 'entity_id' => 0, 'claim_role' => '', 'mirror_page_ids' => [], 'bound_page_ids' => []];
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
                \ClaimRevokeWorld::maybeFail('bonus');
                \ClaimRevokeWorld::$recomputes[] = [$pageId, $userId];
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(__NAMESPACE__ . '\\PageReadModelSync', false)) {
        final class PageReadModelSync
        {
            public static function markDirty(int $pageId): void
            {
                \ClaimRevokeWorld::maybeFail('read_model');
                \ClaimRevokeWorld::$dirty[] = $pageId;
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

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void { \ClaimRevokeWorld::$errors[] = [$m, $c]; }
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
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
                \ClaimRevokeWorld::maybeFail('audit');
                \ClaimRevokeWorld::$audits[] = [$action, $targetId, $meta, $targetType, $userId];
            }
        }
    }
}
