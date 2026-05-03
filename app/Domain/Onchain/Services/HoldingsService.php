<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public facade for NFT ownership + gallery queries.
 *
 * Consumed by:
 *   - GateService     → ownsAny() for token-gated access checks
 *   - GalleryRenderer → getForUser() for profile gallery
 *   - DiscoveryService → getForUser() + gate JOIN to suggest communities
 *
 * Handles:
 *   - Multi-wallet union (a user with N connected wallets sees unified holdings)
 *   - Per-wallet transient cache (24h TTL)
 *   - Dispatch to the right fetcher per chain
 *
 * Does NOT handle:
 *   - Spam filtering (SpamFilter composes on top — separate PR)
 *   - Image proxy / IPFS gateway fallback (renderer concern)
 *   - Persistent storage (intentional — transient only; revisit if cross-user
 *     queries become necessary)
 *
 * @phpstan-import-type ChainRow from ChainRepository
 * @phpstan-import-type WalletWithChain from WalletRepository
 */
final class HoldingsService
{
    private const CACHE_TTL = DAY_IN_SECONDS;

    /** Hard cap on total NFTs cached per wallet across all paginated pulls. */
    private const PER_WALLET_ITEM_CAP = 2000;

    /** Defensive ceiling on pagination loop iterations. */
    private const PER_WALLET_PAGE_CAP = 10;

    /**
     * Shape returned to consumers.
     *
     * @phpstan-type HoldingItem array{
     *     contract_address: string,
     *     token_id: string,
     *     chain_id: int,
     *     chain_slug: string,
     *     wallet_link_id: int,
     *     wallet_address: string,
     *     collection_name: ?string,
     *     name: ?string,
     *     image_url: ?string,
     *     metadata_uri: ?string,
     *     token_standard: ?string
     * }
     */

    /**
     * Count holdings across all connected wallets for a specific collection.
     *
     * Gate path. Returns the highest single-wallet balance (not the sum),
     * because NFT gates typically mean "owns ≥ N in one wallet" — summing
     * across wallets would let a user split one NFT across two wallets
     * to "double" their count, which is incorrect.
     *
     * Returns 0 if no matching chain is connected or no wallet holds any.
     */
    public static function ownsAny(int $userId, string $chainSlug, string $contract): int
    {
        $chain = ChainRepository::getBySlug($chainSlug);
        if (!$chain || !FetcherFactory::has_driver($chain->chain_type)) {
            return 0;
        }

        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!$fetcher->supports_feature('holdings_count')) {
            return 0;
        }

        $wallets = self::walletsForUserOnChain($userId, (int) $chain->id);
        if (empty($wallets)) {
            return 0;
        }

        $max = 0;
        foreach ($wallets as $w) {
            $count = $fetcher->count_holdings($w->wallet_address, $contract);
            if ($count > $max) {
                $max = $count;
            }
        }

        return $max;
    }

    /**
     * Return the full holdings list for a user, unioned across all their
     * connected wallets. Used by the profile gallery and by discovery.
     *
     * Each wallet is cached independently so adding/removing a wallet
     * invalidates only that entry.
     *
     * @return array{items: list<array<string, mixed>>, truncated: bool, wallets_checked: int, wallets_truncated: int}
     */
    public static function getForUser(int $userId, bool $force = false): array
    {
        $wallets = WalletRepository::getForUser($userId, null, true);

        $items              = [];
        $truncatedCount     = 0;
        $walletsChecked     = 0;
        $seen               = [];

        foreach ($wallets as $w) {
            $chain = ChainRepository::getById((int) $w->chain_id);
            if (!$chain) {
                continue;
            }

            $walletCache = self::fetchWalletHoldings(
                (int) $w->id,
                $w->wallet_address,
                $chain,
                $force
            );

            if ($walletCache === null) {
                continue;
            }

            $walletsChecked++;
            if (!empty($walletCache['truncated'])) {
                $truncatedCount++;
            }

            foreach ($walletCache['items'] ?? [] as $item) {
                // Include chain_id in the dedup key. Without it, the same
                // contract address bridged across chains (e.g. a token on
                // Ethereum + Polygon) would collide and silently drop the
                // second chain's tokens. Matches NftSelectionService::itemKey().
                $key = (int) ($item['chain_id'] ?? 0) . '|'
                     . strtolower($item['contract_address'] . '|' . $item['token_id']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $items[] = array_merge($item, [
                    'chain_slug'     => (string) $chain->slug,
                    'wallet_link_id' => (int) $w->id,
                    'wallet_address' => (string) $w->wallet_address,
                ]);
            }
        }

        return [
            'items'             => $items,
            'truncated'         => $truncatedCount > 0,
            'wallets_checked'   => $walletsChecked,
            'wallets_truncated' => $truncatedCount,
        ];
    }

    /**
     * Clear the transient cache for one wallet. Call on wallet unlink or
     * when the user hits an explicit "refresh my gallery" button.
     */
    public static function invalidateWallet(int $walletLinkId): void
    {
        delete_transient(self::cacheKey($walletLinkId));
    }

    /**
     * Clear the transient cache for every wallet the user has connected.
     */
    public static function invalidateUser(int $userId): void
    {
        foreach (WalletRepository::getForUser($userId, null, true) as $w) {
            self::invalidateWallet((int) $w->id);
        }
    }

    // ── Internal helpers ───────────────────────────────────────────────────

    /**
     * @param ChainRow $chain
     * @return array{items: list<array<string, mixed>>, truncated: bool}|null
     */
    private static function fetchWalletHoldings(
        int $walletLinkId,
        string $walletAddress,
        object $chain,
        bool $force
    ): ?array {
        $cacheKey = self::cacheKey($walletLinkId);

        if (!$force) {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && isset($cached['items'])) {
                /** @var array{items: list<array<string, mixed>>, truncated: bool} $cached */
                return $cached;
            }
        }

        if (!FetcherFactory::has_driver($chain->chain_type)) {
            return null;
        }

        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!$fetcher->supports_feature('holdings_list')) {
            // Cache an empty result so we don't re-check every page load.
            $empty = ['items' => [], 'truncated' => false];
            set_transient($cacheKey, $empty, self::CACHE_TTL);
            return $empty;
        }

        // Paginate until the fetcher reports no more pages or until we hit
        // the per-wallet item cap (whichever comes first). Without this, a
        // wallet with > 500 NFTs would silently see only page 1 cached for
        // 24h. Cap exists so a whale with 10k NFTs doesn't blow up the
        // transient + the picker grid.
        $allItems   = [];
        $cursor     = null;
        $truncated  = false;
        $lastResult = null;

        for ($pageNum = 0; $pageNum < self::PER_WALLET_PAGE_CAP; $pageNum++) {
            $lastResult = $fetcher->list_holdings($walletAddress, $cursor);
            $items      = $lastResult['items'] ?? [];

            foreach ($items as $item) {
                if (count($allItems) >= self::PER_WALLET_ITEM_CAP) {
                    $truncated = true;
                    break 2;
                }
                $allItems[] = $item;
            }

            $cursor   = $lastResult['cursor'] ?? null;
            $hasMore  = !empty($lastResult['truncated']) && $cursor !== null;
            if (!$hasMore) {
                break;
            }
        }

        // If we exited the loop because we hit the page cap with more pages
        // still pending, surface that as truncated so the UI can warn.
        if (!empty($lastResult['truncated']) && $cursor !== null) {
            $truncated = true;
        }

        $payload = [
            'items'     => $allItems,
            'truncated' => $truncated,
        ];

        set_transient($cacheKey, $payload, self::CACHE_TTL);

        // Persist "when did we last hit chain" so the UI can render a
        // freshness badge even after the transient expires. Only stamp on
        // fresh fetches — cache hits above returned early and skipped this.
        WalletRepository::markHoldingsRefreshed($walletLinkId);

        return $payload;
    }

    private static function cacheKey(int $walletLinkId): string
    {
        return 'bcc_holdings_w_' . $walletLinkId;
    }

    /**
     * @return list<WalletWithChain>
     */
    private static function walletsForUserOnChain(int $userId, int $chainId): array
    {
        $all = WalletRepository::getForUser($userId, null, true);
        $filtered = [];
        foreach ($all as $w) {
            if ((int) $w->chain_id === $chainId) {
                $filtered[] = $w;
            }
        }
        return $filtered;
    }
}
