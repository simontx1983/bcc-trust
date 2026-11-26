<?php

declare(strict_types=1);

/**
 * F-521 WalletDisconnectRevokesClaimsTest: recording collaborators for the
 * real BonusService::handleWalletDisconnect(). Load only in a separate
 * process, from setUp(), BEFORE BonusService's collaborators are autoloaded,
 * so these declarations win over the real classes. Never declare them at a
 * test file's top level: that runs in the PARENT process and leaks into the
 * whole unit run.
 *
 * State lives in $GLOBALS['__f521']: `calls` (the recorded calls in order),
 * `revoke_throws` and `chain_id`; C1b-2: `current_user` (what
 * get_current_user_id() answers), `initiator` (what initiatorFor() answers),
 * `initiator_reads` and `actors` (the actor each revoke was given).
 */

namespace BCC\Trust\Onchain\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__f521']['current_user'] ?? 0);
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ClaimService', false)) {
        final class ClaimService
        {
            /** @return array{0: string, 1: int|null} */
            public static function initiatorFor(int $subjectUserId, int $currentUserId): array
            {
                $GLOBALS['__f521']['initiator_reads'][] = [$subjectUserId, $currentUserId];

                return $GLOBALS['__f521']['initiator'] ?? ['system', null];
            }

            public static function revokeAllForUser(int $userId, string $reason, ?string $walletAddress = null, ?int $chainId = null, string $actorKind = 'system', ?int $actorId = null): int
            {
                $GLOBALS['__f521']['calls'][] = ['revokeAllForUser', $userId, $reason, $walletAddress, $chainId];
                $GLOBALS['__f521']['actors'][] = [$actorKind, $actorId];
                if ($GLOBALS['__f521']['revoke_throws']) {
                    throw new \RuntimeException('revoke failed');
                }

                return 1;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ChainRepository', false)) {
        final class ChainRepository
        {
            public static function resolveId(string $slug): ?int
            {
                return $GLOBALS['__f521']['chain_id'];
            }

            public static function resolveIdAnyState(string $slug): ?int
            {
                return $GLOBALS['__f521']['chain_id_any'] ?? null;
            }
        }
    }
    if (!class_exists(__NAMESPACE__ . '\\ClaimRepository', false)) {
        final class ClaimRepository
        {
            public static function purgeDisconnectedWallet(int $userId, string $walletAddress, ?int $chainId, string $chainSlug): int
            {
                $GLOBALS['__f521']['calls'][] = ['purgeDisconnectedWallet', $userId, $walletAddress, $chainId, $chainSlug];

                return 1;
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
                        return null; // no own page: the recompute is skipped
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
            public static function error(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function audit(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function debug(string $m, array $c = []): void {}
        }
    }
}
