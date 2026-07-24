<?php
/**
 * Fixture-backed stubs for the wallet-privacy regression suite
 * (WalletPrivacyEgressTest).
 *
 * Subprocess-only (RunTestsInSeparateProcesses). Defines, at their FQNs
 * and BEFORE the autoloader can resolve the real ones:
 *   - BCC\Core\Log\Logger                            — captures violation logs
 *                                                      so the test can assert
 *                                                      no address is ever written
 *   - BCC\Trust\Onchain\Repositories\WalletRepository — records whether a read
 *                                                      happened at all, so the
 *                                                      non-self short-circuit is
 *                                                      provable (not just its output)
 *
 * Fixtures:
 *   $GLOBALS['__bcc_wp_logs']        = list<array{level,message,context}>
 *   $GLOBALS['__bcc_wp_wallet_rows'] = list<object>   rows getForUser returns
 *   $GLOBALS['__bcc_wp_wallet_reads']= int            getForUser call count
 *   $GLOBALS['__bcc_wp_user_by_addr']= array<string,int> address => user id
 */

declare(strict_types=1);

namespace BCC\Core\Log {
    if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_wp_logs'][] = [
                    'level'   => 'error',
                    'message' => $message,
                    'context' => $context,
                ];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_wp_logs'][] = [
                    'level'   => 'warning',
                    'message' => $message,
                    'context' => $context,
                ];
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_wp_logs'][] = [
                    'level'   => 'info',
                    'message' => $message,
                    'context' => $context,
                ];
            }

            /** @param array<string, mixed> $context */
            public static function audit(string $event, array $context = []): void
            {
                $GLOBALS['__bcc_wp_logs'][] = [
                    'level'   => 'audit',
                    'message' => $event,
                    'context' => $context,
                ];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists('BCC\\Trust\\Onchain\\Repositories\\WalletRepository', false)) {
        final class WalletRepository
        {
            /**
             * Records the read so a test can prove the non-self branch never
             * reaches the database at all.
             *
             * @return list<object>
             */
            public static function getForUser(int $userId, ?string $chain = null, bool $verifiedOnly = false): array
            {
                $GLOBALS['__bcc_wp_wallet_reads'] = ($GLOBALS['__bcc_wp_wallet_reads'] ?? 0) + 1;
                /** @var list<object> $rows */
                $rows = $GLOBALS['__bcc_wp_wallet_rows'] ?? [];
                return $rows;
            }

            public static function findUserIdByAddress(int $chainId, string $address): int
            {
                /** @var array<string, int> $map */
                $map = $GLOBALS['__bcc_wp_user_by_addr'] ?? [];
                return $map[strtolower($address)] ?? 0;
            }

            /**
             * @param list<int> $userIds
             * @return array<int, int>
             */
            public static function getVerifiedCountsForUsers(array $userIds): array
            {
                /** @var array<int, int> $counts */
                $counts = $GLOBALS['__bcc_wp_verified_counts'] ?? [];
                return $counts;
            }
        }
    }
}
