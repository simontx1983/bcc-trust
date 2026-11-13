<?php
/**
 * F-303: a scripted BlockchainQueryService at its REAL FQN, so
 * ClaimService::matchCollection() resolves it the way production resolves
 * the real class, with no RPC.
 *
 * Defined ONLY if the real class has not been autoloaded in this process, so
 * require it from setUp() of a #[RunTestsInSeparateProcesses] class, before
 * anything touches the real one. Every call is recorded in
 * $GLOBALS['__bcc_bqs_calls'], so a test can prove the stub was really
 * reached: with the old, wrong namespace in ClaimService, class_exists() is
 * false, nothing is called, and those assertions fail.
 *
 * $GLOBALS['__bcc_bqs_roles']: lowercased wallet address => 'creator' |
 * 'holder' | 'none'.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\wallet;

if (!class_exists(BlockchainQueryService::class, false)) {
    final class BlockchainQueryService
    {
        public static function getEthRole(string $walletAddress, string $contractAddress): string
        {
            $GLOBALS['__bcc_bqs_calls'][] = ['evm', $walletAddress, $contractAddress];

            return $GLOBALS['__bcc_bqs_roles'][strtolower($walletAddress)] ?? 'none';
        }

        public static function getSolanaRole(string $walletAddress, string $mintAddress): string
        {
            $GLOBALS['__bcc_bqs_calls'][] = ['solana', $walletAddress, $mintAddress];

            return $GLOBALS['__bcc_bqs_roles'][strtolower($walletAddress)] ?? 'none';
        }
    }
}
