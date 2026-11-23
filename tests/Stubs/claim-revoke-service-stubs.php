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
        /** @var list<array{int, int}> F-334: [pageId, userId] per releaseClaimedPage() */
        public static array $released = [];
        /** @var int post_author a release leaves (0 = freed; anything else = kept or reassigned) */
        public static int $authorAfterRelease = 0;
        /** @var array<int, int> pageId => post_author */
        public static array $postAuthor = [];
        /** @var list<int> pages whose post_author the clear step read */
        public static array $authorReads = [];
        /** @var list<int> pages BonusService::clearForUnownedPage() was called for */
        public static array $cleared = [];
        /** @var list<string> post-commit work in the order it ran */
        public static array $order = [];
        /** @var list<int> F-521: what verifiedEntityClaimIdsForUser() answers */
        public static array $userClaimIds = [];
        /** @var list<array{int, ?string, ?int}> F-521: [userId, wallet, chainId] per id read */
        public static array $idReads = [];
        public static ?\Throwable $idsThrow = null;
        /** F-521: the revoke() call number (1-based) that throws $throw; null = every call */
        public static ?int $throwOnCall = null;
        /** @var list<int> F-521: users deleteForUser() purged */
        public static array $deletedFor = [];

        public static function reset(): void
        {
            self::$released = [];
            self::$authorAfterRelease = 0;
            self::$postAuthor = [];
            self::$authorReads = [];
            self::$cleared = [];
            self::$order = [];
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
            self::$userClaimIds = [];
            self::$idReads = [];
            self::$idsThrow = null;
            self::$throwOnCall = null;
            self::$deletedFor = [];
        }

        public static function maybeFail(string $step): void
        {
            if (isset(self::$fail[$step])) {
                throw new \RuntimeException($step . ' failed');
            }
        }
    }

    if (!function_exists('get_post_field')) {
        function get_post_field(string $field, int $postId): int
        {
            \ClaimRevokeWorld::$authorReads[] = $postId;
            return \ClaimRevokeWorld::$postAuthor[$postId] ?? 5; // the claimant, until a release
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
                if (\ClaimRevokeWorld::$throw !== null
                    && (\ClaimRevokeWorld::$throwOnCall === null || count(\ClaimRevokeWorld::$revokeCalls) === \ClaimRevokeWorld::$throwOnCall)
                ) {
                    throw \ClaimRevokeWorld::$throw;
                }
                return \ClaimRevokeWorld::$result
                    ?? ['revoked' => false, 'user_id' => 0, 'entity_type' => '', 'entity_id' => 0, 'claim_role' => '', 'mirror_page_ids' => [], 'bound_page_ids' => []];
            }

            /** @return list<int> */
            public static function verifiedEntityClaimIdsForUser(int $userId, ?string $walletAddress = null, ?int $chainId = null): array
            {
                \ClaimRevokeWorld::$idReads[] = [$userId, $walletAddress, $chainId];
                if (\ClaimRevokeWorld::$idsThrow !== null) {
                    throw \ClaimRevokeWorld::$idsThrow;
                }
                return \ClaimRevokeWorld::$userClaimIds;
            }

            public static function deleteForUser(int $userId): void
            {
                \ClaimRevokeWorld::$order[]      = 'delete_for_user';
                \ClaimRevokeWorld::$deletedFor[] = $userId;
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
                \ClaimRevokeWorld::$order[]      = 'bonus:' . $pageId;
            }

            public static function clearForUnownedPage(int $pageId): void
            {
                \ClaimRevokeWorld::maybeFail('page_bonus_clear');
                \ClaimRevokeWorld::$cleared[] = $pageId;
                \ClaimRevokeWorld::$order[]   = 'page_bonus_clear:' . $pageId;
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

// F-334: the page-release step reaches PeepSoIntegration through the Plugin.
namespace BCC\Trust\Core {
    if (!class_exists(__NAMESPACE__ . '\\Plugin', false)) {
        final class Plugin
        {
            public static function instance(): self
            {
                return new self();
            }

            public function peepSoIntegration(): object
            {
                return new class {
                    public function releaseClaimedPage(int $pageId, int $userId): bool
                    {
                        \ClaimRevokeWorld::maybeFail('page_release');
                        \ClaimRevokeWorld::$released[]         = [$pageId, $userId];
                        \ClaimRevokeWorld::$postAuthor[$pageId] = \ClaimRevokeWorld::$authorAfterRelease;
                        \ClaimRevokeWorld::$order[]             = 'page_release:' . $pageId;
                        return true;
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
                \ClaimRevokeWorld::maybeFail('audit');
                \ClaimRevokeWorld::$audits[] = [$action, $targetId, $meta, $targetType, $userId];
            }
        }
    }
}
