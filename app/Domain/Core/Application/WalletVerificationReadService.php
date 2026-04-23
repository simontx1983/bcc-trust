<?php

namespace BCC\Trust\Core\Application;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Contracts\WalletVerificationReadInterface;
use BCC\Core\ServiceLocator;
use BCC\Trust\Core\Database\TableRegistry;

/**
 * Read-only wallet verification service.
 *
 * Wallet identity data comes from bcc_wallet_links via WalletLinkReadInterface
 * (the canonical wallet store, owned by bcc-onchain-signals).
 *
 * Wallet scoring intelligence lives in bcc_onchain_signals via
 * WalletSignalRepository (routed through WalletSignalWriteInterface).
 *
 * Non-wallet verifications (GitHub, X) always come from
 * bcc_trust_user_verifications directly.
 */
class WalletVerificationReadService implements WalletVerificationReadInterface
{
    /** {@inheritdoc} */
    public function hasVerifiedWallet(int $userId): bool
    {
        $links = ServiceLocator::resolveWalletLinkRead()->getLinksForUser($userId);
        return !empty($links);
    }

    /** {@inheritdoc} */
    public function hasVerification(int $userId, string $type): bool
    {
        // Wallet types → delegate to WalletLinkReadInterface (identity store)
        if (strpos($type, 'wallet_') === 0) {
            $chain = substr($type, 7); // 'wallet_ethereum' → 'ethereum'
            // NullWalletLinkRead::hasLink() returns false — same as old fallback.
            return ServiceLocator::resolveWalletLinkRead()->hasLink($userId, $chain);
        }

        // Non-wallet types (github, x, etc.) → query trust-engine table directly
        return \BCC\Trust\Core\Plugin::instance()->verificationRepository()->hasActiveVerificationByType($userId, $type);
    }
}
