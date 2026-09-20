<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Contracts\CountsHoldingsWithCompleteness;
use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use BCC\Trust\Onchain\ValueObjects\EligibilityVerdict;
use BCC\Trust\Onchain\ValueObjects\HoldingsCount;

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
     * PR 7.14 — the oldest a cached POSITIVE may be and still keep or grant
     * membership, measured from `observed_at` (when the walk that produced it
     * began). Older, or of unknown age, and the evaluator asks the chain. The
     * ERC-1155 index is held to the same limit through its checkpoint's
     * `last_run_at`.
     */
    private const POSITIVE_EVIDENCE_MAX_AGE = DAY_IN_SECONDS;

    /**
     * PR 7.14 — every surface that asks the evaluator gets a hard provider
     * budget: [provider page requests before retries, wall-clock seconds].
     * Each read is charged the fetcher's declared worst case up front. When a
     * read no longer fits, the answer is UNKNOWN (`verification_budget_exhausted`).
     */
    public const SURFACE_JOIN      = 'join';
    public const SURFACE_STANCE    = 'stance';
    public const SURFACE_LISTING   = 'listing';
    public const SURFACE_PROFILE   = 'profile';
    public const SURFACE_RECONCILE = 'reconcile';
    public const SURFACE_REVOKE    = 'revoke';

    private const SURFACE_BUDGETS = [
        self::SURFACE_JOIN      => [30, 10],
        self::SURFACE_STANCE    => [30, 10],
        self::SURFACE_LISTING   => [40, 8],
        self::SURFACE_PROFILE   => [20, 6],
        self::SURFACE_RECONCILE => [60, 15],
        self::SURFACE_REVOKE    => [300, 20],
    ];

    /** Charged per read to a fetcher that does not declare its own worst case. */
    private const UNDECLARED_READ_COST = 10;

    /**
     * Fair continuation for a user's OWN repeated attempts (join, stance).
     *
     * A budget covers a bounded number of wallets per request, and there is no
     * per-chain wallet cap. Without continuation every retry re-read the same
     * first wallets, so a holder whose qualifying wallet came later got 503
     * forever. When a resumable check runs out of budget, the position of the
     * first wallet it could not read is stored here and the next attempt starts
     * there, wrapping around. The key is per (chain, contract); the value is
     * `['offset' => int, 'at' => unix time]`. Entries older than
     * WALLET_ROTATION_MAX_AGE are ignored, at most WALLET_ROTATION_MAX are kept,
     * and a decided verdict clears its entry.
     *
     * Continuation only changes the ORDER wallets are read in. A shortfall is
     * still decided only from one request that read every wallet completely, so
     * running out of budget stays UNKNOWN (503) and can never become a 403.
     */
    private const WALLET_ROTATION_META    = '_bcc_ownership_wallet_rotation';
    private const WALLET_ROTATION_MAX     = 32;
    private const WALLET_ROTATION_MAX_AGE = 7 * DAY_IN_SECONDS;

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
     * A fresh provider budget for one request on one surface.
     *
     * Units are provider page requests before retries (see SURFACE_BUDGETS).
     * An unknown surface gets the join budget — small, never unbounded.
     */
    public static function verificationBudget(string $surface): ProviderRequestBudget
    {
        [$requests, $seconds] = self::SURFACE_BUDGETS[$surface] ?? self::SURFACE_BUDGETS[self::SURFACE_JOIN];

        return new ProviderRequestBudget($requests, $seconds);
    }

    /**
     * Does the user hold at least one token of this collection?
     *
     * Stance path. Returns:
     *   - `int >= 1` → proven: a wallet holds at least this many;
     *   - `0`        → every linked wallet on the chain gave a complete,
     *                  successful answer below one (or none is linked);
     *   - `null`     → not decided: the chain is missing or inactive, there
     *                  is no driver that can count, a read failed, the
     *                  evidence was incomplete, or the budget ran out. Never
     *                  read null as a zero.
     *
     * Reduces {@see eligibilityVerdict()} at a minimum of one, so it shares the
     * same evidence rules and stops at the first wallet that proves ownership.
     * `$resumeAcrossRequests` — see {@see eligibilityVerdict()}.
     */
    public static function ownsAny(
        int $userId,
        string $chainSlug,
        string $contract,
        ?ProviderRequestBudget $budget = null,
        bool $resumeAcrossRequests = false
    ): ?int {
        $verdict = self::eligibilityVerdict(
            $userId,
            $chainSlug,
            $contract,
            1,
            $budget ?? self::verificationBudget(self::SURFACE_STANCE),
            $resumeAcrossRequests
        );

        return $verdict->isUnknown() ? null : ($verdict->bestKnownBalance ?? 0);
    }

    /**
     * Reduce a user's wallet set on one (chain, contract) to a single
     * three-outcome verdict — the one ownership authority. JOIN fails CLOSED
     * on UNKNOWN (never add without proof); the REVOKE sweep fails OPEN on
     * UNKNOWN (never remove without proof).
     *
     * PR 7.14 truth table:
     *   - ELIGIBLE   → some wallet PROVED a count ≥ minBalance. A capped or
     *                  cached count proves "at least N" and is enough.
     *   - INELIGIBLE → the wallet list was read, and every wallet gave a
     *                  COMPLETE, successful count below minBalance. An empty
     *                  wallet list is INELIGIBLE with reason ''.
     *   - UNKNOWN    → anything else, with a bounded reason: the chain is
     *                  missing or inactive (`chain_unavailable`), no driver can
     *                  count (`holdings_driver_unsupported`), a repository read
     *                  failed (`repository_read_failed`), a provider could not
     *                  answer or threw (`provider_unavailable`), a count could
     *                  not prove a shortfall (`evidence_incomplete`), index
     *                  evidence was too old (`evidence_stale`), or the budget
     *                  ran out (`verification_budget_exhausted`).
     *
     * Before this, a missing or inactive chain, a missing driver and a failed
     * wallet query all returned INELIGIBLE — the verdict the sweep removes on.
     *
     * `$budget` bounds provider reads; omitted, the join budget applies.
     *
     * `$resumeAcrossRequests` is for a user's OWN explicit retries (join,
     * stance write): the check starts where that user's previous
     * budget-exhausted check on this (chain, contract) stopped, so repeated
     * attempts eventually read every wallet. See WALLET_ROTATION_META. It is
     * never set by the sweep or by read-only surfaces, which must not write.
     */
    public static function eligibilityVerdict(
        int $userId,
        string $chainSlug,
        string $contract,
        int $minBalance,
        ?ProviderRequestBudget $budget = null,
        bool $resumeAcrossRequests = false
    ): EligibilityVerdict {
        $min = max(1, $minBalance);

        $context = self::chainContext($userId, $chainSlug);
        if (is_string($context)) {
            return EligibilityVerdict::unknownBecause($min, null, $context);
        }
        [$chain, $fetcher, $wallets] = $context;
        $chainId = (int) $chain->id;
        $budget ??= self::verificationBudget(self::SURFACE_JOIN);

        if (!$resumeAcrossRequests || $wallets === []) {
            return self::verdictForWallets($fetcher, $wallets, $contract, $chainId, $min, $budget);
        }

        $key      = self::walletRotationKey($chainId, $contract);
        $rotation = self::readWalletRotation($userId);
        $startAt  = $rotation[$key]['offset'] ?? 0;
        $resumeAt = null;

        $verdict = self::verdictForWallets($fetcher, $wallets, $contract, $chainId, $min, $budget, $startAt, $resumeAt);

        if ($verdict->isBudgetExhausted()) {
            // Only a real step forward is worth a write: no step means even the
            // first wallet did not fit (the wall clock ran out).
            if ($resumeAt !== null && $resumeAt !== $startAt % count($wallets)) {
                $rotation[$key] = ['offset' => $resumeAt, 'at' => time()];
                self::writeWalletRotation($userId, $rotation);
            }
        } elseif (!$verdict->isUnknown() && isset($rotation[$key])) {
            // Decided: the next check starts from the first wallet again.
            unset($rotation[$key]);
            self::writeWalletRotation($userId, $rotation);
        }

        return $verdict;
    }

    private static function walletRotationKey(int $chainId, string $contract): string
    {
        return $chainId . ':' . sha1($contract);
    }

    /**
     * @return array<string, array{offset: int, at: int}> only well-formed, unexpired entries
     */
    private static function readWalletRotation(int $userId): array
    {
        $raw = get_user_meta($userId, self::WALLET_ROTATION_META, true);
        if (!is_array($raw)) {
            return [];
        }

        $now = time();
        $out = [];
        foreach ($raw as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }
            $offset = $entry['offset'] ?? null;
            $at     = $entry['at'] ?? null;
            if (!is_int($offset) || $offset < 0 || !is_int($at) || $at > $now || $now - $at > self::WALLET_ROTATION_MAX_AGE) {
                continue;
            }
            $out[$key] = ['offset' => $offset, 'at' => $at];
        }

        return $out;
    }

    /**
     * @param array<string, array{offset: int, at: int}> $rotation
     */
    private static function writeWalletRotation(int $userId, array $rotation): void
    {
        if ($rotation === []) {
            delete_user_meta($userId, self::WALLET_ROTATION_META);
            return;
        }

        // Newest first, bounded.
        uasort($rotation, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
        update_user_meta($userId, self::WALLET_ROTATION_META, array_slice($rotation, 0, self::WALLET_ROTATION_MAX, true));
    }

    /**
     * The chain, a fetcher that can count holdings on it, and the user's
     * linked wallets there — or the UNKNOWN reason one of them could not be
     * established. None of these failures says anything about what the
     * member holds.
     *
     * @return array{0: ChainRow, 1: FetcherInterface, 2: list<WalletWithChain>}|string
     */
    private static function chainContext(int $userId, string $chainSlug): array|string
    {
        // getBySlug answers from the ACTIVE set, so a missing slug, an
        // inactive chain and an unreadable registry all arrive here as null.
        $chain = ChainRepository::getBySlug($chainSlug);
        if ($chain === null) {
            return EligibilityVerdict::REASON_CHAIN_UNAVAILABLE;
        }

        if (!FetcherFactory::has_driver($chain->chain_type)) {
            return EligibilityVerdict::REASON_DRIVER_UNSUPPORTED;
        }

        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!$fetcher->supports_feature('holdings_count')) {
            return EligibilityVerdict::REASON_DRIVER_UNSUPPORTED;
        }

        try {
            $wallets = self::walletsForUserOnChain($userId, (int) $chain->id);
        } catch (RepositoryReadFailure $e) {
            return EligibilityVerdict::REASON_READ_FAILED;
        }

        return [$chain, $fetcher, $wallets];
    }

    /**
     * `$startAt` rotates the wallet order (wallet `$startAt` is read first,
     * wrapping around). `$resumeAt` is set to the position, in the unrotated
     * list, of the first wallet the budget could not cover — where a resumed
     * check should start next time — or left null when every wallet was read.
     *
     * @param list<WalletWithChain> $wallets
     */
    private static function verdictForWallets(
        FetcherInterface $fetcher,
        array $wallets,
        string $contract,
        int $chainId,
        int $min,
        ProviderRequestBudget $budget,
        int $startAt = 0,
        ?int &$resumeAt = null
    ): EligibilityVerdict {
        if ($wallets === []) {
            // Read successfully, and there is none: a complete answer.
            return EligibilityVerdict::ineligible($min, 0);
        }

        try {
            $tokenStandard = CollectionRepository::findTokenStandardOrThrow($chainId, $contract);
        } catch (RepositoryReadFailure $e) {
            // Without the standard we cannot tell which evidence source applies.
            return EligibilityVerdict::unknownBecause($min, null, EligibilityVerdict::REASON_READ_FAILED);
        }

        $count   = count($wallets);
        $start   = max(0, $startAt) % $count;
        $ordered = array_merge(array_slice($wallets, $start), array_slice($wallets, 0, $start));

        $best   = null;  // highest count any wallet actually showed
        $reason = null;  // why a wallet could not settle the question
        foreach ($ordered as $i => $w) {
            $evidence = self::countFromCacheOrFetch(
                $fetcher,
                (int) $w->id,
                $w->wallet_address,
                $contract,
                $chainId,
                $tokenStandard,
                $budget
            );

            if ($evidence === EligibilityVerdict::REASON_BUDGET_EXHAUSTED) {
                $resumeAt ??= ($start + $i) % $count;
            }

            if (is_string($evidence)) {
                $reason = self::strongerReason($reason, $evidence);
                continue;
            }

            $best     = max($best ?? 0, $evidence->count);
            $decision = $evidence->decide($min);
            if ($decision === true) {
                // Proof in one wallet settles it, whatever the others say.
                return EligibilityVerdict::eligible($min, $evidence->count);
            }
            if ($decision === null) {
                $reason = self::strongerReason($reason, EligibilityVerdict::REASON_EVIDENCE_INCOMPLETE);
            }
        }

        if ($reason !== null) {
            return EligibilityVerdict::unknownBecause($min, $best, $reason);
        }

        // Every wallet answered completely and none reached the bar.
        return EligibilityVerdict::ineligible($min, $best ?? 0);
    }

    /**
     * The reason reported when several wallets could not decide. The first
     * one wins, except that an exhausted budget always surfaces: it is the
     * one a caller acts on (the sweep stops its tick on it).
     */
    private static function strongerReason(?string $current, string $new): string
    {
        return ($current === null || $new === EligibilityVerdict::REASON_BUDGET_EXHAUSTED) ? $new : $current;
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
            // $force=true which is a user-driven refresh). Non-checkpointed
            // chains (Solana/Cosmos) have no walker to be "syncing" against
            // — a read-time success is simply healthy, and the empty label
            // hides the chip (Bug C fix).
            if (!$force) {
                $bypassState = self::usesCheckpointedIndexer($chain)
                    ? self::resolveTransientFallbackState($walletLinkId, (int) $chain->id)
                    : 'healthy';
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
     * Returns a map keyed `"<chain_slug>:<contract>"`, one entry per distinct
     * pair, each reduced from {@see eligibilityVerdict()}'s evidence rules:
     *   - `int >= min` → proven: a wallet holds at least this many;
     *   - `int <  min` → every wallet answered completely and none reached the
     *                    pair's minimum (0 when no wallet is linked);
     *   - `null`       → not decided (chain unavailable, no counting driver,
     *                    failed read, incomplete evidence, budget exhausted).
     *                    Callers must not read this as a zero.
     *
     * Each pair may carry the minimum balance its caller will compare against
     * (default 1) so a wallet that proves it ends the search. When the same
     * pair is asked with different minimums, the highest one is used; a lower
     * minimum is then still answered correctly by any int, and a null stays
     * "not eligible" on every fail-closed surface that uses this.
     *
     * Chain, driver and wallet lookups are amortised across pairs on a chain.
     * Provider reads are bounded by `$budget`; omitted, the listing budget
     * applies. Pairs the budget cannot reach come back null.
     *
     * @param list<array{0: string, 1: string, 2?: int}> $pairs  [chain slug, contract, min balance]
     * @return array<string, ?int>
     */
    public static function ownsAnyMany(int $userId, array $pairs, ?ProviderRequestBudget $budget = null): array
    {
        if ($pairs === [] || $userId <= 0) {
            return [];
        }

        $budget ??= self::verificationBudget(self::SURFACE_LISTING);

        /** @var array<string, array<string, int>> $byChain slug => contract => highest minimum */
        $byChain = [];
        foreach ($pairs as $pair) {
            $chainSlug = (string) ($pair[0] ?? '');
            $contract  = (string) ($pair[1] ?? '');
            if ($chainSlug === '' || $contract === '') {
                continue;
            }
            $min = max(1, (int) ($pair[2] ?? 1));
            $byChain[$chainSlug][$contract] = max($byChain[$chainSlug][$contract] ?? 1, $min);
        }

        $result = [];
        foreach ($byChain as $chainSlug => $contracts) {
            $chainSlug = (string) $chainSlug;
            $context   = self::chainContext($userId, $chainSlug);

            foreach ($contracts as $contract => $min) {
                $contract = (string) $contract;
                $key      = $chainSlug . ':' . $contract;

                if (is_string($context)) {
                    $result[$key] = null;
                    continue;
                }
                [$chain, $fetcher, $wallets] = $context;

                $verdict      = self::verdictForWallets($fetcher, $wallets, $contract, (int) $chain->id, $min, $budget);
                $result[$key] = $verdict->isUnknown() ? null : ($verdict->bestKnownBalance ?? 0);
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

    /**
     * True when this chain is served by the checkpointed V2 walker
     * (NftEthIndexerWorker) — i.e. EVM. Solana (Helius webhook →
     * NftHoldingsIndexer::ingest) and Cosmos (read-time LCD fetch) are
     * event-driven / read-time paths that never create a checkpoint row,
     * so a null checkpoint for them is NORMAL, not "still syncing".
     * Before this predicate, both mapped null-checkpoint → 'syncing' and
     * their galleries showed "Syncing on-chain holdings…" permanently
     * (2026-07-06 audit, Bug C). Their liveness is derived elsewhere:
     * Solana from Helius delivery freshness (F6/X5), Cosmos from
     * read-time 503s on the endpoint — not from this chip.
     *
     * @param ChainRow $chain
     */
    private static function usesCheckpointedIndexer(object $chain): bool
    {
        return (string) $chain->chain_type === 'evm';
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
     * For non-checkpointed chains (Solana event-driven, Cosmos read-time)
     * returns `healthy` — there's no walker checkpoint to be syncing
     * against, so `healthy` is the only state the read path can report;
     * transport failures surface as a 503 on the endpoint, and Solana
     * ingest liveness is F6's Helius-freshness signal, not this chip.
     * Before this, only Cosmos was special-cased and Solana piece-detail
     * showed a permanent "Syncing…" (2026-07-06 audit, Bug C).
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

        $state = self::usesCheckpointedIndexer($chain)
            ? self::resolvePersistentReadState((int) $chain->id)
            : 'healthy';

        $bucket = [];
        self::recordIndexerState($bucket, $chainSlug, $state);

        return [
            'indexer_state'       => $bucket,
            'indexer_state_label' => self::buildIndexerStateLabels($bucket),
        ];
    }

    // ── Internal helpers ───────────────────────────────────────────────────

    /**
     * Walk a wallet's holdings, caching ONLY a complete walk.
     *
     * A provider outage, an open circuit breaker or a refused endpoint makes
     * `list_holdings` return `complete: false` — a PARTIAL view shaped exactly
     * like "this wallet owns nothing". Persisting that is what turned an
     * unavailable LCD into a confident zero: the payload went into a 24h
     * transient, `markHoldingsRefreshed` stamped it as a successful chain
     * read, and `countFromCacheOrFetch` later read the empty list back as a
     * real 0 and denied a holder gate. So an incomplete walk is returned to
     * the caller for display and NOTHING ELSE: no transient, no freshness
     * stamp. The next request retries instead of inheriting the outage.
     *
     * PR 7.14: a stored walk also carries `observed_at` (when the walk began)
     * and `served_from_cache` (whether any page the fetcher used came from
     * its own cache instead of the provider, or the fetcher did not say). The
     * evaluator trusts a cached POSITIVE only while `observed_at` is at most
     * POSITIVE_EVIDENCE_MAX_AGE old and `served_from_cache` is false — see
     * {@see cachedPositiveIsTrusted()}. The returned shape is unchanged.
     *
     * @param ChainRow $chain
     * @return array{items: list<array<string, mixed>>, truncated: bool, complete: bool}|null
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
                /** @var array{items: list<array<string, mixed>>, truncated?: bool, complete?: bool} $cached */
                // REBUILT, not returned as found. Only a complete walk is
                // ever written below, so a hit is complete by construction —
                // but a payload cached by the PREVIOUS release can still be
                // inside its 24h window and carries no flag at all, and an
                // unflagged payload is not evidence of a successful read. So
                // the shape is normalised on the way out and the missing flag
                // reads as NOT complete.
                return [
                    'items'     => $cached['items'],
                    'truncated' => !empty($cached['truncated']),
                    'complete'  => ($cached['complete'] ?? false) === true,
                ];
            }
        }

        if (!FetcherFactory::has_driver($chain->chain_type)) {
            return null;
        }

        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!$fetcher->supports_feature('holdings_list')) {
            // PR 7.14: this used to cache `{items: [], complete: true}` for a
            // day. A driver that cannot enumerate has not read an empty
            // wallet, and the gate read that cached empty back as "owns
            // none". Nothing was read, so nothing is stored or claimed. The
            // re-check on the next load is a local capability test, not a
            // provider call.
            return ['items' => [], 'truncated' => false, 'complete' => false];
        }

        // Paginate until the fetcher reports no more pages or until we hit
        // the per-wallet item cap (whichever comes first). Without this, a
        // wallet with > 500 NFTs would silently see only page 1 cached for
        // 24h. Cap exists so a whale with 10k NFTs doesn't blow up the
        // transient + the picker grid.
        $allItems        = [];
        $cursor          = null;
        $truncated       = false;
        $complete        = true;
        $lastResult      = null;
        // Taken BEFORE the first page, so the stamp is never younger than
        // the oldest page it covers.
        $observedAt      = time();
        $servedFromCache = false;

        for ($pageNum = 0; $pageNum < self::PER_WALLET_PAGE_CAP; $pageNum++) {
            $lastResult = $fetcher->list_holdings($walletAddress, $cursor);
            $items      = $lastResult['items'] ?? [];

            // Fail CLOSED on a missing flag. Every driver in the tree sets
            // it; an absent key means an unknown implementation whose
            // completeness we cannot vouch for, and the cost of being wrong
            // in that direction is one uncached walk — against a wrongly
            // cached zero that survives for a day and denies a gate.
            if (($lastResult['complete'] ?? false) !== true) {
                $complete = false;
            }
            // Same posture for age: an unflagged page may be cached.
            if (($lastResult['served_from_cache'] ?? true) !== false) {
                $servedFromCache = true;
            }

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
            'complete'  => $complete,
        ];

        if (!$complete) {
            // Partial read. Return what we have so the gallery can render
            // it, but store nothing: an unavailable provider must not be
            // able to write a wallet's holdings, and must not be able to
            // claim a successful chain read on the freshness badge.
            \BCC\Core\Log\Logger::warning('[HoldingsService] incomplete holdings walk; not cached', [
                'chain_id'       => (int) ($chain->id ?? 0),
                'wallet_link_id' => $walletLinkId,
                'items_found'    => count($allItems),
            ]);
            return $payload;
        }

        set_transient($cacheKey, $payload + [
            'observed_at'       => $observedAt,
            'served_from_cache' => $servedFromCache,
        ], self::CACHE_TTL);

        // Persist "when did we last hit chain" so the UI can render a
        // freshness badge even after the transient expires. Only stamp on
        // fresh fetches — cache hits above returned early and skipped this.
        WalletRepository::markHoldingsRefreshed($walletLinkId);

        return $payload;
    }

    /**
     * One wallet's holdings WITH the completeness of the read that
     * produced them. The stance panel's evidence source.
     *
     * Everything here already existed; what was missing was a way to ask
     * for it from outside without losing the one fact that matters when
     * the answer is empty — whether the empty is a MEASUREMENT or a
     * FAILURE. {@see fetchWalletHoldings()} has always tracked that as
     * `complete`, has always failed closed on a driver that omits the
     * flag, and has always refused to cache an incomplete walk. This
     * method exposes it; it adds no transport and no caching of its own.
     *
     * For Cosmos that resolves to {@see CosmosFetcher::list_holdings()} —
     * a bounded LCD `tokens{owner}` walk over VERIFIED collections only.
     * No marketplace, no wallet address in any cache key (the transient
     * is keyed by wallet LINK ID), no third party.
     *
     * @param  ChainRow $chain
     * @return array{items: list<array<string, mixed>>, truncated: bool, complete: bool}|null
     *         null when the chain has no driver at all — which is an
     *         unreadable source, not an empty wallet
     */
    public static function walletHoldingsWithCompleteness(int $walletLinkId, string $walletAddress, object $chain): ?array
    {
        if ($walletLinkId <= 0 || $walletAddress === '') {
            return null;
        }

        return self::fetchWalletHoldings($walletLinkId, $walletAddress, $chain, false);
    }

    private static function cacheKey(int $walletLinkId): string
    {
        return 'bcc_holdings_w_' . $walletLinkId;
    }

    /**
     * One wallet's ownership evidence for a target contract: a
     * {@see HoldingsCount}, or the EligibilityVerdict::REASON_* explaining
     * why there is none.
     *
     * Sources, in order:
     *
     *   1. ERC-1155 (`$tokenStandard` matches /1155/i) → the persistent
     *      transfer index. The 721 `balanceOf(address)` selector does not work
     *      on 1155 contracts, and the gate semantic ("any token under the
     *      contract") is what the index aggregates. The index starts at chain
     *      head with no backfill, so ABSENCE proves nothing (`atLeast(0)`); a
     *      positive counts only while the chain's checkpoint is healthy and
     *      ran within POSITIVE_EVIDENCE_MAX_AGE (`evidence_stale` otherwise).
     *      A failed index read is `repository_read_failed`, never a zero.
     *
     *   2. The per-wallet gallery transient → POSITIVE evidence only, and only
     *      while {@see cachedPositiveIsTrusted()}. A cached ZERO is never
     *      decisive: the list may not cover the target (Cosmos walks only the
     *      top verified collections; EVM cannot enumerate at all), and it may
     *      be a day old.
     *
     *   3. The fetcher, after charging `$budget` its declared worst case.
     *      A fetcher implementing {@see CountsHoldingsWithCompleteness} says
     *      whether its count is exact or a lower bound; one that does not is
     *      positive-only (`atLeast`). null or a throw is `provider_unavailable`;
     *      a read that no longer fits the budget is
     *      `verification_budget_exhausted` and is not attempted.
     */
    private static function countFromCacheOrFetch(
        FetcherInterface $fetcher,
        int $walletLinkId,
        string $walletAddress,
        string $contract,
        int $chainId,
        ?string $tokenStandard,
        ProviderRequestBudget $budget
    ): HoldingsCount|string {
        if ($tokenStandard !== null && stripos($tokenStandard, '1155') !== false) {
            if ($walletLinkId <= 0 || $chainId <= 0) {
                return EligibilityVerdict::REASON_EVIDENCE_INCOMPLETE;
            }
            try {
                $byWallet = NftHoldingsRepository::countVisibleByContractOrThrow($chainId, $contract, [$walletLinkId]);
            } catch (RepositoryReadFailure $e) {
                return EligibilityVerdict::REASON_READ_FAILED;
            }
            $indexed = (int) ($byWallet[$walletLinkId] ?? 0);
            if ($indexed <= 0) {
                // A pre-link holding, or a relink under a new wallet id, is
                // simply not in the index.
                return HoldingsCount::atLeast(0);
            }

            return self::transferIndexIsFresh($chainId)
                ? HoldingsCount::atLeast($indexed)
                : EligibilityVerdict::REASON_EVIDENCE_STALE;
        }

        // PR 5b: the cache scan compares identities, so it uses the one
        // chain-aware rule instead of `strtolower()` on both sides.
        //
        // This is NOT simply "make it case-sensitive": that would break EVM,
        // where `0xAB…` and `0xab…` ARE the same contract. Only a per-family
        // rule is correct, which is why the comparison is delegated rather
        // than inlined. For EVM and Cosmos the canonical form is lowercase,
        // so the outcome is byte-identical to the old fold — proven by the
        // 20 Cosmos gate fixtures. For Solana it stops folding a mint into a
        // different key.
        $chainFamily = self::chainFamilyFor($chainId);
        $identity    = $chainFamily === ''
            ? null
            : NftCollectionIdentifier::canonicalize($chainFamily, $contract);

        // A target that cannot be canonicalised means the cached list cannot
        // answer the question. Skip the scan entirely and fall through to the
        // fetcher, which reports UNKNOWN rather than inventing a zero — the
        // cache must never be the thing that manufactures a false negative.
        $cached = ($identity !== null && $identity->isAccepted())
            ? get_transient(self::cacheKey($walletLinkId))
            : false;

        if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
            /** @var string $chainFamily */
            $target  = $identity->canonical();
            $matches = 0;
            foreach ($cached['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemContract = $item['contract_address'] ?? null;
                if (is_string($itemContract)
                    && NftCollectionIdentifier::matches($chainFamily, $target, $itemContract)
                ) {
                    $matches++;
                }
            }

            // A found token proves ownership however the list was assembled —
            // a partial or truncated walk can only have missed MORE. But only
            // while the observation is recent and first-hand: a positive with
            // no bounded age would keep a member in after they sold.
            if ($matches > 0 && self::cachedPositiveIsTrusted($cached)) {
                return HoldingsCount::atLeast($matches);
            }

            // PR 7.14: a zero is no longer answered from here, complete or
            // not. "The list has none" is only "the wallet has none" if the
            // list covers the target, which a gallery walk does not promise.
        }

        $evidenceCapable = $fetcher instanceof CountsHoldingsWithCompleteness;
        $cost            = $evidenceCapable
            ? max(1, $fetcher->max_requests_per_evidence_read())
            : self::UNDECLARED_READ_COST;
        if (!$budget->canSpend($cost)) {
            return EligibilityVerdict::REASON_BUDGET_EXHAUSTED;
        }
        // Charged up front at the worst case, whatever the read then costs.
        $budget->spend($cost);

        // No transient is written on any of these paths, so a failed read can
        // never poison the 24h holdings cache.
        try {
            if ($evidenceCapable) {
                return $fetcher->count_holdings_evidence($walletAddress, $contract)
                    ?? EligibilityVerdict::REASON_PROVIDER_UNAVAILABLE;
            }
            $count = $fetcher->count_holdings($walletAddress, $contract);
        } catch (\Throwable $e) {
            // Bounded: no address, no provider text.
            \BCC\Core\Log\Logger::warning('[HoldingsService] ownership read threw; treated as unavailable', [
                'chain_id'       => $chainId,
                'wallet_link_id' => $walletLinkId,
                'error_class'    => get_class($e),
            ]);
            return EligibilityVerdict::REASON_PROVIDER_UNAVAILABLE;
        }

        // A driver that never vouched for completeness can prove ownership,
        // never its absence.
        return $count === null
            ? EligibilityVerdict::REASON_PROVIDER_UNAVAILABLE
            : HoldingsCount::atLeast($count);
    }

    /**
     * Safeguard 1: may this cached positive keep or grant membership?
     *
     * Only when the walk that produced it (a) was stamped with `observed_at`
     * no more than POSITIVE_EVIDENCE_MAX_AGE ago and not in the future, and
     * (b) read every page from the provider (`served_from_cache === false`),
     * so no page inside it can be older than the stamp. A payload from before
     * PR 7.14 has neither key and is never trusted; it expires within its own
     * 24h TTL.
     *
     * @param array<mixed> $cached
     */
    private static function cachedPositiveIsTrusted(array $cached): bool
    {
        $observedAt = $cached['observed_at'] ?? null;
        if (!is_int($observedAt) || ($cached['served_from_cache'] ?? null) !== false) {
            return false;
        }

        $age = time() - $observedAt;

        return $age >= 0 && $age <= self::POSITIVE_EVIDENCE_MAX_AGE;
    }

    /**
     * May a positive from the ERC-1155 transfer index be trusted? Only while
     * the chain's indexer checkpoint is healthy and last ran within
     * POSITIVE_EVIDENCE_MAX_AGE. A disabled, degraded or silent indexer
     * cannot see a sale.
     */
    private static function transferIndexIsFresh(int $chainId): bool
    {
        $checkpoint = ChainCheckpointRepository::get($chainId);
        if ($checkpoint === null || (string) $checkpoint->state !== ChainCheckpointRepository::STATE_HEALTHY) {
            return false;
        }

        $lastRunAt = $checkpoint->last_run_at ?? null;
        $ranAt     = is_string($lastRunAt) && $lastRunAt !== '' ? strtotime($lastRunAt . ' UTC') : false;
        if ($ranAt === false) {
            return false;
        }

        $age = time() - $ranAt;

        return $age >= 0 && $age <= self::POSITIVE_EVIDENCE_MAX_AGE;
    }

    /**
     * The user's verified wallets on one chain, read FAIL-CLOSED: a failed
     * query throws instead of looking like "no wallets", which the evaluator
     * would otherwise have to read as a complete INELIGIBLE.
     *
     * The order is made fully deterministic here (primary first, then oldest,
     * then lowest id). The query's own ORDER BY leaves wallets linked in the
     * same second in no guaranteed order, and a resumed check (see
     * WALLET_ROTATION_META) needs position N to mean the same wallet on the next
     * request.
     *
     * @return list<WalletWithChain>
     * @throws RepositoryReadFailure when the wallet read did not run
     */
    private static function walletsForUserOnChain(int $userId, int $chainId): array
    {
        $all = WalletRepository::getForUserOrThrow($userId, null, true);
        $filtered = [];
        foreach ($all as $w) {
            if ((int) $w->chain_id === $chainId) {
                $filtered[] = $w;
            }
        }

        usort($filtered, static fn(object $a, object $b): int => [
            -(int) ($a->is_primary ?? 0),
            (string) ($a->created_at ?? ''),
            (int) $a->id,
        ] <=> [
            -(int) ($b->is_primary ?? 0),
            (string) ($b->created_at ?? ''),
            (int) $b->id,
        ]);

        return $filtered;
    }

    /**
     * `wp_bcc_chains.chain_type` for a numeric chain id, or '' if unknown.
     *
     * The family is ALWAYS looked up, never inferred from the shape of an
     * identifier — a 42-char lowercase hex string is a valid EVM contract
     * and a plausible bech32 length, so shape-sniffing would pick the wrong
     * rule sooner or later. `ChainRepository::getById` memoises per request
     * and answers for inactive chains too.
     */
    private static function chainFamilyFor(int $chainId): string
    {
        if ($chainId <= 0) {
            return '';
        }

        $chain = ChainRepository::getById($chainId);

        return $chain === null ? '' : (string) ($chain->chain_type ?? '');
    }
}
