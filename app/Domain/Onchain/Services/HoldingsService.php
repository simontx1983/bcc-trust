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
     * Returns:
     *   - `int >= 0` → highest REAL single-wallet balance (0 if no wallet
     *                  holds any, OR if the chain/driver is unsupported or
     *                  no wallet is connected — those are definite zeros).
     *   - `null`     → every wallet that COULD have qualified failed to
     *                  verify (provider outage). Caller must fail open /
     *                  fail closed per its own posture — never read null
     *                  as a real zero.
     *
     * For a three-way verdict (ELIGIBLE / INELIGIBLE / UNKNOWN) prefer
     * {@see eligibilityVerdict}; this method is the raw nullable balance.
     */
    public static function ownsAny(int $userId, string $chainSlug, string $contract): ?int
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

        $max     = null; // highest REAL count seen
        $sawNull = false; // at least one wallet couldn't verify
        foreach ($wallets as $w) {
            $count = self::countFromCacheOrFetch(
                $fetcher,
                (int) $w->id,
                $w->wallet_address,
                $contract,
                $chainId,
                $tokenStandard
            );
            if ($count === null) {
                $sawNull = true;
                continue;
            }
            if ($max === null || $count > $max) {
                $max = $count;
            }
        }

        // If we have any real count, that's the answer (a real ≥ threshold
        // wallet makes the unknown wallets moot). If every wallet failed,
        // return null (UNKNOWN). $max === null && !$sawNull can't happen
        // (the empty-wallets case returned 0 above).
        if ($max !== null) {
            return $max;
        }
        return $sawNull ? null : 0;
    }

    /**
     * Reduce a user's wallet set on one (chain, contract) to a single
     * three-outcome verdict. This is the canonical gate decision used by
     * both the JOIN path (fail CLOSED on UNKNOWN — never add during an
     * outage) and the REVOKE sweep (fail OPEN on UNKNOWN — never evict on
     * a hiccup; the next sweep retries).
     *
     * Outcomes:
     *   - ELIGIBLE   → some wallet's REAL count ≥ minBalance.
     *   - INELIGIBLE → every wallet returned a REAL count and none reached
     *                  minBalance (certain non-qualification).
     *   - UNKNOWN    → no wallet reached minBalance AND at least one wallet
     *                  could not be verified (provider error).
     *
     * Unsupported chain / no connected wallet → INELIGIBLE (a definite
     * "not a holder here," not an outage).
     */
    public static function eligibilityVerdict(
        int $userId,
        string $chainSlug,
        string $contract,
        int $minBalance
    ): \BCC\Trust\Onchain\ValueObjects\EligibilityVerdict {
        $min = max(1, $minBalance);

        $chain = ChainRepository::getBySlug($chainSlug);
        if (!$chain || !FetcherFactory::has_driver($chain->chain_type)) {
            return \BCC\Trust\Onchain\ValueObjects\EligibilityVerdict::ineligible($min, 0);
        }

        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!$fetcher->supports_feature('holdings_count')) {
            return \BCC\Trust\Onchain\ValueObjects\EligibilityVerdict::ineligible($min, 0);
        }

        $wallets = self::walletsForUserOnChain($userId, (int) $chain->id);
        if (empty($wallets)) {
            return \BCC\Trust\Onchain\ValueObjects\EligibilityVerdict::ineligible($min, 0);
        }

        $chainId       = (int) $chain->id;
        $tokenStandard = CollectionRepository::findTokenStandard($chainId, $contract);

        $bestReal = null; // highest REAL count
        $sawNull  = false;
        foreach ($wallets as $w) {
            $count = self::countFromCacheOrFetch(
                $fetcher,
                (int) $w->id,
                $w->wallet_address,
                $contract,
                $chainId,
                $tokenStandard
            );
            if ($count === null) {
                $sawNull = true;
                continue;
            }
            if ($bestReal === null || $count > $bestReal) {
                $bestReal = $count;
            }
            if ($count >= $min) {
                // A real wallet clears the bar — definitively eligible,
                // regardless of any unknown siblings.
                return \BCC\Trust\Onchain\ValueObjects\EligibilityVerdict::eligible($min, $count);
            }
        }

        // No wallet cleared the bar.
        if ($sawNull) {
            // Some wallet couldn't be verified — we can't be SURE they
            // don't qualify. UNKNOWN.
            return \BCC\Trust\Onchain\ValueObjects\EligibilityVerdict::unknown($min, $bestReal);
        }

        // Every wallet returned a real count and none reached the bar —
        // certain non-qualification.
        return \BCC\Trust\Onchain\ValueObjects\EligibilityVerdict::ineligible($min, $bestReal ?? 0);
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
            'items'             => self::annotateCollectionVerified($items),
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
     * Annotate every gallery item with `collection_verified` — whether
     * the operator has verified the item's collection (Verify
     * Collections page). The frontend dims unverified items ("community
     * not yet activated") instead of the pre-2026-07 behaviour of
     * either hiding them (Cosmos) or rendering them indistinguishable
     * from activated ones (EVM/SOL).
     *
     * DISPLAY-ONLY: nothing gates on this flag — group gating resolves
     * per-contract through ownsAny/count_holdings against verified
     * collections. A holding whose contract has no collections row at
     * all annotates false (unverified is the safe default).
     *
     * One bounded lookup per chain present in the response
     * ({@see CollectionRepository::verifiedMapForContracts}).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function annotateCollectionVerified(array $items): array
    {
        if ($items === []) {
            return $items;
        }

        $contractsByChain = [];
        foreach ($items as $item) {
            $chainId  = (int) ($item['chain_id'] ?? 0);
            $contract = strtolower((string) ($item['contract_address'] ?? ''));
            if ($chainId > 0 && $contract !== '') {
                $contractsByChain[$chainId][$contract] = true;
            }
        }

        $verifiedMaps = [];
        foreach ($contractsByChain as $chainId => $contracts) {
            $verifiedMaps[$chainId] = CollectionRepository::verifiedMapForContracts(
                $chainId,
                array_keys($contracts)
            );
        }

        foreach ($items as &$item) {
            $chainId  = (int) ($item['chain_id'] ?? 0);
            $contract = strtolower((string) ($item['contract_address'] ?? ''));
            $item['collection_verified'] = (bool) ($verifiedMaps[$chainId][$contract] ?? false);
        }
        unset($item);

        return $items;
    }

    /**
     * Batched ownership check across multiple (chain, contract) pairs.
     *
     * Returns a map keyed `"<chain_slug>:<contract>"` of nullable
     * max-balance ints (same semantics as ownsAny — highest REAL
     * single-wallet balance, not sum):
     *   - `int >= 0` → real count (0 = definite none / unsupported chain /
     *                  no connected wallet).
     *   - `null`     → every candidate wallet failed to verify (UNKNOWN);
     *                  caller must not read this as a zero.
     * Unknown keys are absent from the result.
     *
     * Amortizes ChainRepository, FetcherFactory, and walletsForUserOnChain
     * lookups across all pairs sharing a chain. The per-(wallet, contract)
     * RPC count_holdings call is unavoidable but is the same as the
     * unbatched path.
     *
     * @param list<array{0: string, 1: string}> $pairs
     * @return array<string, ?int>
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
                // Unsupported chain — definite 0, not an outage.
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
                $max     = null;
                $sawNull = false;
                foreach ($wallets as $w) {
                    $count = self::countFromCacheOrFetch(
                        $fetcher,
                        (int) $w->id,
                        $w->wallet_address,
                        $contract,
                        $chainId,
                        $tokenStandard
                    );
                    if ($count === null) {
                        $sawNull = true;
                        continue;
                    }
                    if ($max === null || $count > $max) {
                        $max = $count;
                    }
                }
                // Any real count wins; all-failed → null (UNKNOWN).
                $result[$key] = $max !== null ? $max : ($sawNull ? null : 0);
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

    /**
     * Per-chain indexer state for the V2 Phase 6 §H1 NFT-piece detail
     * endpoint. Returns the same `meta.indexer_state` /
     * `meta.indexer_state_label` block shape the gallery
     * (`getForUser`) emits, scoped to ONE chain — the piece-detail
     * view doesn't span wallets so the per-wallet rollup is moot.
     *
     * For Cosmos (read-time, no checkpoint) returns `healthy` — the
     * contract treats Cosmos as always-fresh because there's no
     * indexer to be syncing against.
     *
     * @return array{indexer_state: array<string, string>, indexer_state_label: array<string, string>}
     */
    public static function getIndexerStateForChain(string $chainSlug): array
    {
        if ($chainSlug === '') {
            return ['indexer_state' => [], 'indexer_state_label' => []];
        }

        $chain = ChainRepository::getBySlug($chainSlug);
        if ($chain === null) {
            return ['indexer_state' => [], 'indexer_state_label' => []];
        }

        // Cosmos has no persistent indexer; healthy is the only state
        // the read-time path can be in (transport failures surface as
        // a 503 on the endpoint, not as a degraded-state chip).
        if ((string) $chain->chain_type === 'cosmos') {
            $state = 'healthy';
        } else {
            $state = self::resolvePersistentReadState((int) $chain->id);
        }

        $bucket = [];
        self::recordIndexerState($bucket, $chainSlug, $state);

        return [
            'indexer_state'       => $bucket,
            'indexer_state_label' => self::buildIndexerStateLabels($bucket),
        ];
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
    ): ?int {
        if ($tokenStandard !== null && stripos($tokenStandard, '1155') !== false) {
            if ($walletLinkId <= 0 || $chainId <= 0) {
                return 0;
            }
            // ERC-1155 reads the persistent transfer index (DB), never an
            // RPC — there is no provider-outage failure mode here, so a 0
            // is always a real 0.
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
            // truncated, AND zero matches. A cache HIT is by definition a
            // previously-SUCCESSFUL list walk, so its count is real.
            if (!$truncated || $matches > 0) {
                return $matches;
            }
        }

        // Cache miss (or truncated-with-zero-matches whale fallback).
        // Fresh RPC — threads the fetcher's nullable fail-open result:
        //   null  → provider could not verify (UNKNOWN) — propagate null,
        //           and (critically) do NOT write any transient, so a
        //           failed fetch never poisons the 24h holdings cache.
        //   int   → real count (0 included).
        // count_holdings is a read-only RPC; it writes no transient on
        // its own, so the no-poison guarantee holds simply by NOT
        // caching the result here.
        return $fetcher->count_holdings($walletAddress, $contract);
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
