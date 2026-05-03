<?php
/**
 * Wallet Verification Service
 *
 * Orchestrates the full wallet verification flow:
 *   1. Verify the signature (chain-specific)
 *   2. Query public RPC to determine wallet role (Holder / Creator / Team)
 *   3. Calculate trust impact
 *   4. Persist via WalletRepository
 *
 * @package BCC\Trust\Core
 * @subpackage Services/Wallet
 */

namespace BCC\Trust\Core\Services\wallet;

use BCC\Core\Contracts\WalletLinkReadInterface;
use BCC\Core\ServiceLocator;
use BCC\Trust\Core\Repositories\WalletSignalRepository;

if (!defined('ABSPATH')) {
    exit;
}

class WalletVerificationService {

    /**
     * WordPress cron hook for async blockchain RPC role checks.
     * Registered in bootstrap.php; scheduled after signature verification.
     */
    public const CHAIN_CHECK_HOOK = 'bcc_trust_wallet_chain_check';

    // Trust boost values per role (added to page score, range 0–100)
    public const TRUST_BOOST = [
        BlockchainQueryService::ROLE_CREATOR => 20.0,
        BlockchainQueryService::ROLE_TEAM    => 15.0,
        BlockchainQueryService::ROLE_HOLDER  =>  8.0,
        BlockchainQueryService::ROLE_NONE    =>  0.0,
    ];

    // Fraud reduction per role (subtracted from fraud score)
    public const FRAUD_REDUCTION = [
        BlockchainQueryService::ROLE_CREATOR => 30,
        BlockchainQueryService::ROLE_TEAM    => 20,
        BlockchainQueryService::ROLE_HOLDER  => 10,
        BlockchainQueryService::ROLE_NONE    =>  0,
    ];

    public function __construct() {
    }

    /** Max age (seconds) for a scheduled chain check to still be valid. */
    private const CHAIN_CHECK_MAX_AGE = 3600; // 1 hour

    /**
     * ASYNC PHASE: Complete the blockchain RPC role check.
     *
     * Called by WordPress cron via the bcc_trust_wallet_chain_check hook.
     * Queries public RPC endpoints to determine the wallet's role, then
     * updates the stored record with the actual role and trust scores.
     *
     * @param int    $userId
     * @param string $chain
     * @param string $walletAddress
     * @param string $contractAddress
     * @param array<string, mixed> $extra
     */
    public function completeChainCheck(
        int    $userId,
        string $chain,
        string $walletAddress,
        string $contractAddress,
        array  $extra = []
    ): void {
        // Freshness guard: if the cron event fired much later than scheduled
        // (e.g. WP-Cron backlog, server downtime), discard stale callbacks
        // so we don't write outdated blockchain state into scoring data.
        $scheduledAt = (int) ($extra['scheduled_at'] ?? 0);
        if ($scheduledAt > 0 && (time() - $scheduledAt) > self::CHAIN_CHECK_MAX_AGE) {
            \BCC\Core\Log\Logger::info('[bcc-trust] chain_check_stale', [
                'user_id'      => $userId,
                'chain'        => $chain,
                'scheduled_at' => $scheduledAt,
                'age_seconds'  => time() - $scheduledAt,
            ]);
            return;
        }

        // Verify the wallet link still exists in the canonical store.
        // Must check hasRealService() — NullWalletLinkRead::hasLink() returns
        // false, which would incorrectly abort every call as "disconnected".
        if (ServiceLocator::hasRealService(WalletLinkReadInterface::class)
            && !ServiceLocator::resolveWalletLinkRead()->hasLink($userId, $chain)
        ) {
            return; // Disconnected between schedule and execution
        }

        // Query blockchain RPC for role (this is the slow part)
        $role = $this->queryRole($chain, $walletAddress, $contractAddress, $extra);

        // Calculate trust impact
        $trustBoost     = self::TRUST_BOOST[$role]     ?? 0.0;
        $fraudReduction = self::FRAUD_REDUCTION[$role] ?? 0;

        // Write scoring intelligence to bcc_onchain_signals (identity lives in bcc_wallet_links)
        WalletSignalRepository::upsert(
            $userId,
            $chain,
            $walletAddress,
            $role,
            $trustBoost,
            $fraudReduction,
            $contractAddress,
            $extra
        );

        // Flag user's pages for score recalculation if trust impact changed
        if ($trustBoost > 0 || $fraudReduction > 0) {
            $this->flagPagesForRecalculation($userId);
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] ' . "BCC Trust wallet: chain check complete chain={$chain} address={$walletAddress} role={$role}", []);
    }

    /**
     * Flag all pages owned by this user for deferred score recalculation.
     */
    private function flagPagesForRecalculation(int $userId): void {
        \BCC\Trust\Core\Plugin::instance()->scoreRepository()->flagOwnerPagesForRecalculation($userId);
    }

    /*
    |--------------------------------------------------------------------------
    | PRIVATE HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $extra
     */
    private function queryRole(
        string $chain,
        string $walletAddress,
        string $contractAddress,
        array $extra
    ): string {
        switch ($chain) {
            case 'ethereum':
                return BlockchainQueryService::getEthRole($walletAddress, $contractAddress);

            case 'solana':
                return BlockchainQueryService::getSolanaRole($walletAddress, $contractAddress);

            case 'cosmos':
                $restUrl = $extra['rest_url'] ?? '';
                return BlockchainQueryService::getCosmosRole(
                    $walletAddress,
                    $contractAddress,
                    $restUrl ?: 'https://rest.cosmos.directory/cosmoshub'
                );

            default:
                return BlockchainQueryService::ROLE_NONE;
        }
    }
}
