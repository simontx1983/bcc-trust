<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Contracts\WalletLinkWriteInterface;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

/**
 * Write service for bcc_wallet_links, exposed via WalletLinkWriteInterface.
 *
 * Allows trust-engine to write wallet records to the canonical store
 * without direct cross-plugin table access.
 */
final class WalletLinkWriteService implements WalletLinkWriteInterface
{
    public function linkWallet(
        int $userId,
        string $chainSlug,
        string $walletAddress,
        int $postId = 0,
        string $walletType = 'user',
        string $label = ''
    ): int {
        // Resolve chain slug → chain_id
        $chain = ChainRepository::getBySlug($chainSlug);
        if (!$chain) {
            return 0;
        }

        $chainId = (int) $chain->id;

        // Atomic insert-or-find: uses INSERT ... ON DUPLICATE KEY UPDATE
        // against the UNIQUE KEY (user_id, chain_id, wallet_address).
        // Eliminates the TOCTOU race between exists() check and insert().
        $result = WalletRepository::insertOrFind([
            'user_id'        => $userId,
            'post_id'        => $postId,
            'wallet_address' => $walletAddress,
            'chain_id'       => $chainId,
            'wallet_type'    => $walletType,
            'label'          => $label,
        ]);

        $walletLinkId = $result['id'];

        if (!$walletLinkId) {
            return 0;
        }

        // Mark verified immediately (the caller already verified the sig).
        // Called on BOTH the inserted and the found path: if a prior attempt
        // created the row via insertOrFind but died before verifying, the
        // retry hits ODK-UPDATE (inserted=false) and this heals the row.
        // verify() is idempotent (verified_at IS NULL guard), so re-linking
        // an already-verified wallet is a harmless no-op.
        WalletRepository::verify($walletLinkId);

        if ($result['inserted']) {
            // Auto-set primary if first wallet on this chain for this user
            $chainCount = WalletRepository::countForUserByChain($userId, $chainId);
            if ($chainCount <= 1) {
                WalletRepository::setPrimary($walletLinkId, $userId);
            }
        }

        return $walletLinkId;
    }

    public function unlinkWallet(int $userId, string $chainSlug, string $walletAddress): bool
    {
        $chain = ChainRepository::getBySlug($chainSlug);
        if (!$chain) {
            return false;
        }

        $walletLinkId = WalletRepository::findIdByUserChainAddress(
            $userId,
            (int) $chain->id,
            $walletAddress
        );

        if (!$walletLinkId) {
            return false;
        }

        return WalletRepository::delete($walletLinkId, $userId);
    }
}
