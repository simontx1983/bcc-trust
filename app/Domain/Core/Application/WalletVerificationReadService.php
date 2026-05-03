<?php

namespace BCC\Trust\Core\Application;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\ServiceLocator;

/**
 * Read-only wallet verification service.
 *
 * Wallet identity data comes from bcc_wallet_links via WalletLinkReadInterface
 * (the canonical wallet store, owned by bcc-trust's Onchain domain).
 *
 * Wallet scoring intelligence lives in bcc_onchain_signals via
 * WalletSignalRepository (routed through WalletSignalWriteInterface).
 *
 * Non-wallet verifications (GitHub, X) always come from
 * bcc_trust_user_verifications directly.
 *
 * Concrete-only — there's no `WalletVerificationReadInterface`. The
 * service is constructed directly by its two consumers
 * (FeatureAccessService, VoteEligibilityChecker); the cross-plugin
 * ServiceLocator seam was removed when no external consumer materialized.
 */
class WalletVerificationReadService
{
    public function hasVerifiedWallet(int $userId): bool
    {
        $links = ServiceLocator::resolveWalletLinkRead()->getLinksForUser($userId);
        return !empty($links);
    }

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
