<?php
/**
 * Stubs for WalletUnlinkSessionTest — loaded BEFORE account-prehijack-stubs.php
 * so these fake FQN classes win over the autoloader.
 *
 * Subprocess-only (RunTestsInSeparateProcesses). In-memory wallet links in
 * $GLOBALS['__bcc_wu']:
 *   wallets      array<int, object{id, user_id, chain_id, chain_slug, wallet_address, verified_at}>
 *   current_user int    get_current_user_id()
 *   read_fails   bool   getForUserOrThrow() throws
 *   side_effects list<string>  NFT cleanup / audit / mail / calls
 *
 * The real WalletRepository against a real table is covered by
 * tests/Integration/WalletUnlinkLoginLookupIntegrationTest.php.
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(WalletRepository::class, false)) {
        final class WalletRepository
        {
            public static function getById(int $id): ?object
            {
                return $GLOBALS['__bcc_wu']['wallets'][$id] ?? null;
            }

            public static function delete(int $id, int $userId): bool
            {
                $w = $GLOBALS['__bcc_wu']['wallets'][$id] ?? null;
                if ($w === null || (int) $w->user_id !== $userId) {
                    return false;
                }
                unset($GLOBALS['__bcc_wu']['wallets'][$id]);
                return true;
            }

            /**
             * @param list<int> $userIds
             * @return array<int, int>
             */
            public static function getVerifiedCountsForUsers(array $userIds): array
            {
                $out = [];
                foreach ($GLOBALS['__bcc_wu']['wallets'] as $w) {
                    if (in_array((int) $w->user_id, $userIds, true) && !empty($w->verified_at)) {
                        $out[(int) $w->user_id] = ($out[(int) $w->user_id] ?? 0) + 1;
                    }
                }
                return $out;
            }

            /** @return list<object> */
            public static function getForUserOrThrow(int $userId): array
            {
                if (!empty($GLOBALS['__bcc_wu']['read_fails'])) {
                    throw new \RuntimeException('read did not run');
                }
                return array_values(array_filter(
                    $GLOBALS['__bcc_wu']['wallets'],
                    static fn(object $w): bool => (int) $w->user_id === $userId
                ));
            }

            public static function findUserIdByAddress(int $chainId, string $address): int
            {
                foreach ($GLOBALS['__bcc_wu']['wallets'] as $w) {
                    if ((int) $w->chain_id === $chainId && $w->wallet_address === $address) {
                        return (int) $w->user_id;
                    }
                }
                return 0;
            }
        }
    }

    if (!class_exists(NftHoldingsRepository::class, false)) {
        final class NftHoldingsRepository
        {
            public static function deleteForWalletLink(int $id): void
            {
                $GLOBALS['__bcc_wu']['side_effects'][] = 'holdings:' . $id;
            }
        }
    }

    if (!class_exists(NftSelectionRepository::class, false)) {
        final class NftSelectionRepository
        {
            public static function deleteForWalletLink(int $id, int $userId): void
            {
                $GLOBALS['__bcc_wu']['side_effects'][] = 'selection:' . $id;
            }
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(AuditLogger::class, false)) {
        final class AuditLogger
        {
            /** @param array<string, mixed> $meta */
            public static function log(string $event, int $objectId, array $meta = [], string $type = '', int $actor = 0): void
            {
                $GLOBALS['__bcc_wu']['side_effects'][] = 'audit:' . $event;
            }
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(AccountSecurityMailer::class, false)) {
        final class AccountSecurityMailer
        {
            public static function walletUnlinked(int $userId, string $chain, string $address): void
            {
                $GLOBALS['__bcc_wu']['side_effects'][] = 'mail:wallet_unlinked';
            }
        }
    }
}

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_wu']['current_user'] ?? 0);
        }
    }
}
