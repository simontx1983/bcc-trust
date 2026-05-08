<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
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
     * Token-standard branch:
     *   - ERC-721 / unknown → existing path: gallery transient cache,
     *     falling through to `EvmFetcher::count_holdings()` (eth_call
     *     `balanceOf(address)`) on miss.
     *   - ERC-1155          → reads from `NftHoldingsRepository::countVisibleByContract()`
     *     (the persistent transfer index, populated by EvmFetcher's
     *     `fetch_transfers_since` ingestion). Skips eth_call entirely
     *     because ERC-1155 uses `balanceOf(address, uint256)` which
     *     requires a token_id — and the chosen gate semantic is
     *     "any token under the contract" so per-token-id RPC isn't
     *     applicable. Trade-off: indexer-cadence freshness lag (~1 min);
     *     acceptable for the soft-gate UX.
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

        $chainId       = (int) $chain->id;
        $tokenStandard = CollectionRepository::findTokenStandard($chainId, $contract);

        $max = 0;
        foreach ($wallets as $w) {
            $count = self::countFromCacheOrFetch(
                $fetcher,
                (int) $w->id,
                $w->wallet_address,
                $contract,
                $chainId,
                $tokenStandard
            );
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
     * V2 Phase 1c read-path swap (load-bearing):
     *   - Per-wallet-per-chain decision. A fully-indexed-and-enriched
     *     ETH wallet returns persisted rows; the same user's freshly-
     *     connected SOL wallet falls through to the V1 transient path.
     *   - Persistent path requires three things: (a) checkpoint state
     *     `healthy`, (b) `enriched_at IS NOT NULL` row exists, (c)
     *     `$force === false`. Any failure → V1 transient.
     *   - `meta.indexer_state[chain_slug]` reports per-chain status to
     *     the frontend so it can render a "Syncing…" chip when the
     *     persistent path was bypassed for an indexer reason (state ≠
     *     healthy or no enriched rows yet). Per §S the human-readable
     *     label is server-pre-formatted in
     *     `meta.indexer_state_label[chain_slug]`.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     truncated: bool,
     *     wallets_checked: int,
     *     wallets_truncated: int,
     *     meta: array{
     *         indexer_state: array<string, string>,
     *         indexer_state_label: array<string, string>
     *     }
     * }
     */
    public static function getForUser(int $userId, bool $force = false): array
    {
        $wallets = WalletRepository::getForUser($userId, null, true);

        $items              = [];
        $truncatedCount     = 0;
        $walletsChecked     = 0;
        $seen               = [];
        $indexerState       = [];

        foreach ($wallets as $w) {
            $chain = ChainRepository::getById((int) $w->chain_id);
            if (!$chain) {
                continue;
            }

            $chainSlug = (string) $chain->slug;
            $walletLinkId = (int) $w->id;

            // Per-wallet-per-chain swap decision.
            $persistedItems = $force ? null : self::tryReadPersistent($walletLinkId, $chain);
            if ($persistedItems !== null) {
                $walletState = self::resolvePersistentReadState((int) $chain->id);
                self::recordIndexerState($indexerState, $chainSlug, $walletState);

                $walletsChecked++;
                foreach ($persistedItems as $item) {
                    $key = (int) ($item['chain_id'] ?? 0) . '|'
                         . strtolower($item['contract_address'] . '|' . $item['token_id']);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $items[] = array_merge($item, [
                        'chain_slug'     => $chainSlug,
                        'wallet_link_id' => $walletLinkId,
                        'wallet_address' => (string) $w->wallet_address,
                    ]);
                }
                continue;
            }

            // Fall through: V1 transient path. Tag the chain as syncing
            // when the bypass reason is indexer-related (vs. just
            // $force=true which is a user-driven refresh).
            if (!$force) {
                $bypassState = self::resolveTransientFallbackState($walletLinkId, (int) $chain->id);
                self::recordIndexerState($indexerState, $chainSlug, $bypassState);
            }

            $walletCache = self::fetchWalletHoldings(
                $walletLinkId,
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
                    'chain_slug'     => $chainSlug,
                    'wallet_link_id' => $walletLinkId,
                    'wallet_address' => (string) $w->wallet_address,
                ]);
            }
        }

        return [
            'items'             => $items,
            'truncated'         => $truncatedCount > 0,
            'wallets_checked'   => $walletsChecked,
            'wallets_truncated' => $truncatedCount,
            'meta'              => [
                'indexer_state'       => $indexerState,
                'indexer_state_label' => self::buildIndexerStateLabels($indexerState),
            ],
        ];
    }

    /**
     * Batched ownership check across multiple (chain, contract) pairs.
     *
     * Returns a map keyed `"<chain_slug>:<contract>"` of max-balance ints
     * (same semantics as ownsAny — highest single-wallet balance, not
     * sum). Pairs that resolve to unsupported chains return 0; unknown
     * keys are absent from the result.
     *
     * Amortizes ChainRepository, FetcherFactory, and walletsForUserOnChain
     * lookups across all pairs sharing a chain. The per-(wallet, contract)
     * RPC count_holdings call is unavoidable but is the same as the
     * unbatched path.
     *
     * @param list<array{0: string, 1: string}> $pairs
     * @return array<string, int>
     */
    public static function ownsAnyMany(int $userId, array $pairs): array
    {
        if ($pairs === [] || $userId <= 0) {
            return [];
        }

        $byChain = [];
        foreach ($pairs as $pair) {
            $chainSlug = (string) ($pair[0] ?? '');
            $contract  = (string) ($pair[1] ?? '');
            if ($chainSlug === '' || $contract === '') {
                continue;
            }
            $byChain[$chainSlug][] = $contract;
        }

        $result = [];
        foreach ($byChain as $chainSlug => $contracts) {
            $chain = ChainRepository::getBySlug($chainSlug);
            if (!$chain || !FetcherFactory::has_driver($chain->chain_type)) {
                foreach ($contracts as $c) {
                    $result[$chainSlug . ':' . $c] = 0;
                }
                continue;
            }

            $fetcher = FetcherFactory::make_for_chain($chain);
            if (!$fetcher->supports_feature('holdings_count')) {
                foreach ($contracts as $c) {
                    $result[$chainSlug . ':' . $c] = 0;
                }
                continue;
            }

            $chainId = (int) $chain->id;
            $wallets = self::walletsForUserOnChain($userId, $chainId);
            foreach ($contracts as $contract) {
                $key = $chainSlug . ':' . $contract;
                if ($wallets === []) {
                    $result[$key] = 0;
                    continue;
                }
                $tokenStandard = CollectionRepository::findTokenStandard($chainId, $contract);
                $max = 0;
                foreach ($wallets as $w) {
                    $count = self::countFromCacheOrFetch(
                        $fetcher,
                        (int) $w->id,
                        $w->wallet_address,
                        $contract,
                        $chainId,
                        $tokenStandard
                    );
                    if ($count > $max) {
                        $max = $count;
                    }
                }
                $result[$key] = $max;
            }
        }

        return $result;
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

    // ── V2 Phase 1c read-path swap helpers ─────────────────────────────────

    /**
     * Try the persistent path. Returns a list of normalized holdings
     * items (matching the fetcher item shape) when the swap criteria
     * are met; null when caller should fall through to the V1
     * transient path.
     *
     * Three gates:
     *   1. Checkpoint state must be `healthy` for this chain.
     *   2. Wallet must have at least one enriched, visible row on
     *      this chain (`enriched_at IS NOT NULL` AND status IN (0,1)).
     *   3. Caller must not have set $force = true.
     *
     * @param ChainRow $chain
     * @return list<array<string, mixed>>|null
     */
    private static function tryReadPersistent(int $walletLinkId, object $chain): ?array
    {
        $chainId = (int) $chain->id;
        if ($walletLinkId <= 0 || $chainId <= 0) {
            return null;
        }

        $checkpoint = ChainCheckpointRepository::get($chainId);
        if ($checkpoint === null) {
            return null;
        }
        if ((string) $checkpoint->state !== ChainCheckpointRepository::STATE_HEALTHY) {
            return null;
        }
        if (!NftHoldingsRepository::walletHasAnyEnriched($walletLinkId, $chainId)) {
            return null;
        }

        $rows = NftHoldingsRepository::findVisibleEnrichedForWallet($walletLinkId, $chainId);
        if ($rows === []) {
            return null;
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'contract_address' => (string) $row->contract_address,
                'token_id'         => (string) $row->token_id,
                'chain_id'         => (int) $row->chain_id,
                'collection_name'  => $row->collection_name !== null ? (string) $row->collection_name : null,
                'name'             => $row->name !== null ? (string) $row->name : null,
                'image_url'        => $row->image_url !== null ? (string) $row->image_url : null,
                'metadata_uri'     => $row->metadata_uri !== null ? (string) $row->metadata_uri : null,
                'token_standard'   => $row->token_standard !== null ? (string) $row->token_standard : null,
            ];
        }
        return $items;
    }

    /**
     * Resolve the indexer state we'd like the frontend to see when
     * the persistent read succeeded for this chain.
     */
    private static function resolvePersistentReadState(int $chainId): string
    {
        $checkpoint = ChainCheckpointRepository::get($chainId);
        if ($checkpoint === null) {
            return 'syncing';
        }
        $state = (string) $checkpoint->state;
        if ($state === ChainCheckpointRepository::STATE_HEALTHY) {
            return 'healthy';
        }
        if ($state === ChainCheckpointRepository::STATE_DEGRADED
            || $state === ChainCheckpointRepository::STATE_BREAKER_OPEN) {
            return 'degraded';
        }
        return 'syncing';
    }

    /**
     * Resolve the indexer state when we fell through to the V1
     * transient path. Distinguishes "the indexer hasn't seen this
     * wallet yet" (syncing) from "the indexer is broken" (degraded).
     */
    private static function resolveTransientFallbackState(int $walletLinkId, int $chainId): string
    {
        $checkpoint = ChainCheckpointRepository::get($chainId);
        if ($checkpoint === null) {
            return 'syncing';
        }
        $state = (string) $checkpoint->state;
        if ($state === ChainCheckpointRepository::STATE_DEGRADED
            || $state === ChainCheckpointRepository::STATE_BREAKER_OPEN) {
            return 'degraded';
        }
        if ($state !== ChainCheckpointRepository::STATE_HEALTHY) {
            return 'syncing';
        }
        // Healthy chain, but no enriched rows for this wallet yet —
        // either the wallet was just connected (cold-start) or the
        // enrichment scheduler hasn't caught up.
        return NftHoldingsRepository::walletHasAnyEnriched($walletLinkId, $chainId)
            ? 'healthy'
            : 'syncing';
    }

    /**
     * Track per-chain state, escalating monotonically: degraded beats
     * syncing beats healthy. So if one wallet on a chain reads
     * healthy and another reads syncing, the chain reports syncing.
     *
     * @param array<string, string> &$indexerState
     */
    private static function recordIndexerState(array &$indexerState, string $chainSlug, string $state): void
    {
        if ($chainSlug === '') {
            return;
        }
        $existing = $indexerState[$chainSlug] ?? null;
        if ($existing === null || self::stateRank($state) > self::stateRank($existing)) {
            $indexerState[$chainSlug] = $state;
        }
    }

    private static function stateRank(string $state): int
    {
        return [
            'healthy'  => 0,
            'syncing'  => 1,
            'degraded' => 2,
        ][$state] ?? 1;
    }

    /**
     * Server-pre-formatted human-readable labels for `meta.indexer_state`.
     * Per §S the frontend renders these verbatim and never invents copy
     * from the enum value.
     *
     * Filterable via `bcc_holdings_indexer_state_label` so the copy
     * can be tuned without a redeploy.
     *
     * @param array<string, string> $indexerState
     * @return array<string, string>
     */
    private static function buildIndexerStateLabels(array $indexerState): array
    {
        $defaults = [
            'healthy'  => '',                                    // no chip when everything is fine
            'syncing'  => 'Syncing on-chain holdings…',
            'degraded' => 'On-chain indexer is degraded — showing cached holdings.',
        ];

        $out = [];
        foreach ($indexerState as $chainSlug => $state) {
            $label = $defaults[$state] ?? '';
            $filtered = apply_filters('bcc_holdings_indexer_state_label', $label, $state, $chainSlug);
            $out[$chainSlug] = is_string($filtered) ? $filtered : $label;
        }
        return $out;
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
     * Count target-contract holdings for a single wallet — cache-first
     * with RPC fallback on cache miss.
     *
     * The gallery cache (set by getForUser/fetchWalletHoldings, 24h TTL,
     * keyed per wallet_link) carries the enumerated NFT items for that
     * wallet. For a token-gate check, counting target-contract matches
     * in that already-cached list avoids a fresh RPC roundtrip per gate
     * call — the dominant cost on profile/discovery renders + the
     * reconcile sweep.
     *
     * Tradeoffs vs the old direct-RPC path:
     *   - Cache hit: returns cached count. Up-to-24h stale; for soft-gate
     *     (suggest-don't-auto-join) semantics this lag is acceptable.
     *     If the user just acquired an NFT they'll appear ineligible
     *     until next gallery refresh — they can hit the gallery refresh
     *     button (force=true) or wait for the daily transient expiry.
     *   - Cache hit, items truncated, 0 matches: a whale's target NFT
     *     could live in the truncated tail. We fall through to RPC for
     *     this case so whales aren't false-negatives.
     *   - Cache miss: RPC fallback (same as the old path).
     *
     * ERC-1155 short-circuit: when `$tokenStandard` matches /1155/i,
     * skip the gallery transient and the eth_call fallback entirely
     * and read from the persistent NFT-holdings index. The 721 RPC
     * selector `balanceOf(address)` does not work on 1155 contracts
     * (those expose `balanceOf(address, uint256)` and would revert /
     * return 0), and the gate semantic is "any token under contract"
     * which the index already aggregates via SUM(balance).
     */
    private static function countFromCacheOrFetch(
        \BCC\Trust\Onchain\Contracts\FetcherInterface $fetcher,
        int $walletLinkId,
        string $walletAddress,
        string $contract,
        int $chainId,
        ?string $tokenStandard
    ): int {
        if ($tokenStandard !== null && stripos($tokenStandard, '1155') !== false) {
            if ($walletLinkId <= 0 || $chainId <= 0) {
                return 0;
            }
            $byWallet = NftHoldingsRepository::countVisibleByContract($chainId, $contract, [$walletLinkId]);
            return (int) ($byWallet[$walletLinkId] ?? 0);
        }

        $cached = get_transient(self::cacheKey($walletLinkId));
        if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
            $target  = strtolower($contract);
            $matches = 0;
            foreach ($cached['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemContract = $item['contract_address'] ?? null;
                if (is_string($itemContract) && strtolower($itemContract) === $target) {
                    $matches++;
                }
            }

            $truncated = !empty($cached['truncated']);
            // Trust the cache when complete, OR when it has any matches
            // (any matches means the user already passes a default
            // min_balance=1 gate — fine even if the truncated tail
            // would have produced more matches). Only fall through to
            // RPC for the false-negative-on-whales case: cache present,
            // truncated, AND zero matches.
            if (!$truncated || $matches > 0) {
                return $matches;
            }
        }

        // Cache miss (or truncated-with-zero-matches whale fallback).
        // Fresh RPC — same path as the pre-cache implementation.
        return (int) $fetcher->count_holdings($walletAddress, $contract);
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
