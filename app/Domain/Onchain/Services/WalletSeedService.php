<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\DelegationRepository;
use BCC\Trust\Onchain\Repositories\SignalRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Support\ChainSupport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Seeds on-chain data when a user first verifies a wallet.
 *
 * Populates signal data, validator data (Cosmos), and NFT collection
 * data so the UI is never empty immediately after verification.
 *
 * Hooked to: bcc_wallet_verified
 */
final class WalletSeedService
{
    /**
     * Handle the bcc_wallet_verified action.
     */
    public static function onWalletVerified(int $userId, string $chain, string $address): void
    {
        // Per-request guard: prevent double processing.
        static $processed = [];
        $key = $userId . ':' . $chain . ':' . strtolower($address);
        if (isset($processed[$key])) {
            return;
        }
        $processed[$key] = true;

        if (!in_array($chain, ChainSupport::supported(), true)) {
            return;
        }

        // If we already have a stored row for this wallet, skip the API call.
        if (SignalRepository::get_permanent($address, $chain)) {
            return;
        }

        self::seedSignals($userId, $chain, $address);
        self::seedEntities($userId, $chain, $address);
    }

    /**
     * Fetch and store signal data for a newly verified wallet.
     *
     * Delegates to SignalRefreshService to avoid duplicating the
     * fetch -> score -> upsert pipeline. When no page exists yet
     * (pageId = 0), the refresh service stores the signal row but
     * skips bonus recalculation.
     */
    private static function seedSignals(int $userId, string $chain, string $address): void
    {
        $pageId = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);

        SignalRefreshService::fetchAndStoreWallet($userId, $pageId, $chain, $address);
    }

    /**
     * Seed validator and collection data for a newly verified wallet.
     *
     * Idempotent: skips API calls if entities already exist for this wallet_link.
     * Uses an advisory lock to prevent concurrent cron invocations from double-seeding.
     */
    private static function seedEntities(int $userId, string $chain, string $address): void
    {
        $chainObj = ChainRepository::getBySlug($chain);
        if (!$chainObj || !FetcherFactory::has_driver($chainObj->chain_type)) {
            return;
        }

        $fetcher = FetcherFactory::make_for_chain($chainObj);

        // Find the wallet_link row for this address.
        $walletLink = null;
        $wallets = WalletRepository::getForUser($userId);
        foreach ($wallets as $w) {
            if (strtolower($w->wallet_address) === strtolower($address)) {
                $walletLink = $w;
                break;
            }
        }

        if (!$walletLink) {
            return;
        }

        $walletLinkId = (int) $walletLink->id;

        // Advisory lock prevents concurrent cron runs from double-seeding the same wallet.
        $lockKey = 'bcc_seed_entities_' . $walletLinkId;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lockKey, 0)) {
            return;
        }

        try {
            // Seed validator data (Cosmos chains) — skip if already seeded.
            if ($fetcher->supports_feature('validator')) {
                if (!ValidatorRepository::existsForWalletLink($walletLinkId)) {
                    $validatorData = $fetcher->fetch_validator($address);
                    if (!empty($validatorData)) {
                        ValidatorRepository::upsert($validatorData, $walletLinkId, HOUR_IN_SECONDS);
                    }
                }
            }

            // Seed delegation set (which validators this wallet stakes to).
            // Skip if already seeded — refreshes are owned by a separate
            // scheduler, not the verify-time seed path.
            if ($fetcher->supports_feature('delegations')) {
                if (!DelegationRepository::existsForWalletLink($walletLinkId)) {
                    $delegations = $fetcher->fetch_delegations($address);
                    if (!empty($delegations)) {
                        DelegationRepository::replaceForWalletLink(
                            $walletLinkId,
                            (int) $chainObj->id,
                            $delegations,
                            6 * HOUR_IN_SECONDS
                        );
                    }
                }
            }

            // Seed NFT collection data (all chains) — skip if already seeded.
            if ($fetcher->supports_feature('collection')) {
                if (!CollectionRepository::existsForWalletLink($walletLinkId)) {
                    $collections = $fetcher->fetch_collections($address, (int) $chainObj->id);
                    // #212: never discard the result — a lost write must be
                    // countable, not invisible.
                    $persisted = CollectionPersistBatch::persist($collections, $walletLinkId, 4 * HOUR_IN_SECONDS);
                    if (!CollectionPersistBatch::allPersisted($persisted)) {
                        \BCC\Core\Log\Logger::warning('[Onchain] Wallet seed could not persist every collection', [
                            'wallet_link_id' => $walletLinkId,
                            'chain_id'       => (int) $chainObj->id,
                        ] + $persisted);
                    }
                    if (!empty($collections) && (int) $walletLink->post_id > 0) {
                        CollectionService::invalidate((int) $walletLink->post_id);
                    }
                }
            }
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lockKey);
        }
    }
}
