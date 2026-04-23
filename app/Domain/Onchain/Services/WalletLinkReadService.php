<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Contracts\WalletLinkReadInterface;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;

/**
 * Exposes bcc_wallet_links data through the WalletLinkReadInterface contract.
 *
 * Registered via the bcc.resolve.wallet_link_read filter so that
 * trust-engine can merge AJAX-verified wallets into its read service
 * without querying another plugin's tables directly.
 */
final class WalletLinkReadService implements WalletLinkReadInterface
{
    /** @return array<string, string[]> Chain slug => wallet addresses */
    public function getLinksForUser(int $userId): array
    {
        $rows = WalletRepository::getForUser($userId);

        $wallets = [];
        foreach ($rows as $row) {
            $chain = $row->chain_slug ?? '';
            $addr  = $row->wallet_address ?? '';
            if ($chain !== '' && $addr !== '') {
                $wallets[$chain][] = $addr;
            }
        }

        return $wallets;
    }

    public function hasLink(int $userId, string $chain): bool
    {
        $chainObj = ChainRepository::getBySlug($chain);
        if (!$chainObj) {
            return false;
        }

        return WalletRepository::hasLinkForChain($userId, (int) $chainObj->id);
    }

    /**
     * @param string[] $chains
     * @return int[]
     */
    public function getUserIdsWithLinks(array $chains, int $limit = 100, int $offset = 0): array
    {
        return WalletRepository::getUserIdsWithChainSlugs($chains, $limit, $offset);
    }
}
