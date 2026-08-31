<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\NftCollectionPiecesRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Support\MarketplaceLinkBuilder;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use stdClass;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * §3.7 NftPiece view-model assembler (V2 Phase 6 / §H1).
 *
 * Single public entry point: `build($chainSlug, $contract, $tokenId)`.
 * Returns the full §3.7 shape OR throws a typed RuntimeException
 * describing which §4.17 error code the REST endpoint should emit.
 *
 * Why exceptions instead of error tuples: this builder is invoked
 * from one REST endpoint and one (future) admin page; both already
 * map exceptions to a structured response. Plumbing per-step error
 * codes through a tuple would obscure the happy path.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 *
 * @phpstan-type NftPieceArray array{
 *     id: string,
 *     collection: array{
 *         id: int|null,
 *         name: string|null,
 *         creator_handle: string|null,
 *         chain_slug: string,
 *         contract_address: string,
 *         token_standard: string|null,
 *         is_verified: bool
 *     },
 *     token_id: string,
 *     name: string|null,
 *     description: string|null,
 *     image_url: string|null,
 *     image_url_thumb: string|null,
 *     attributes: list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}>,
 *     owner: array{
 *         is_linked: bool
 *     }|null,
 *     owners_count: int,
 *     owners: list<array{}>,
 *     marketplace_links: list<array{name: string, url: string}>,
 *     mint_link: string|null,
 *     permissions: \stdClass,
 *     meta: array{
 *         read_time: bool,
 *         indexer_state: array<string, string>,
 *         indexer_state_label: array<string, string>,
 *         owners_summary_label: ?string
 *     }
 * }
 */
final class NftPieceViewModelBuilder
{
    /** Hard cap on `owners[]`. §3.7 specifies N=10 for ERC-1155. */
    private const OWNERS_LIST_CAP = 10;

    /** §3.7 says `owner` (dominant) + up to 10 in `owners[]` → fetch 11. */
    private const OWNERS_FETCH_LIMIT = 11;

    /** Cache TTL for indexed-chain piece metadata. Matches §4.17 max-age. */
    private const PIECE_CACHE_TTL_SECONDS = 4 * HOUR_IN_SECONDS;

    /** Error codes mapped 1:1 to §4.17 error codes. */
    public const ERROR_INVALID_CHAIN         = 'bcc_invalid_chain';
    public const ERROR_NOT_FOUND             = 'bcc_not_found';
    public const ERROR_UNSUPPORTED_STANDARD  = 'bcc_unsupported_standard';
    public const ERROR_UPSTREAM_UNAVAILABLE  = 'bcc_upstream_unavailable';

    /** Cache provenance — surfaced as `X-BCC-Cache` per §4.17. */
    public const CACHE_FRESH = 'FRESH';
    public const CACHE_STALE = 'STALE';
    public const CACHE_MISS  = 'MISS';

    /**
     * Assemble the §3.7 view-model. Throws PieceBuilderException with
     * a `code` that maps to a §4.17 error code on failure.
     *
     * Returns a tuple `[$viewModel, $cacheState]` where `$cacheState`
     * is `FRESH | STALE | MISS` per §4.17. The §H1 endpoint maps
     * `$cacheState` directly to the `X-BCC-Cache` response header.
     *
     * Cosmos always reports `MISS` (read-time, no persistence).
     * Indexed chains report:
     *   - FRESH — cache hit, `expires_at` in the future
     *   - STALE — cache hit but expired; we synchronously re-fetched
     *     from upstream and the response carries fresh data (the
     *     "stale" qualifier describes the cache state at request
     *     entry, matching the §4.17 stale-while-revalidate semantics)
     *   - MISS  — no cache row; fetched from upstream this request
     *
     * @return array{0: NftPieceArray, 1: self::CACHE_FRESH|self::CACHE_STALE|self::CACHE_MISS}
     * @throws PieceBuilderException
     */
    public static function build(string $chainSlug, string $contractAddress, string $tokenId): array
    {
        // ── 1. Resolve chain ────────────────────────────────────────
        $chain = ChainRepository::getBySlug($chainSlug);
        if ($chain === null) {
            throw new PieceBuilderException(self::ERROR_INVALID_CHAIN, 'Unknown chain.');
        }
        $chainId   = (int) $chain->id;
        $chainType = (string) $chain->chain_type;

        // ── 2. Canonicalize contract per chain ──────────────────────
        // PR 5a: this was a PRIVATE chain-aware normalizer whose answer was
        // thrown away one line later — CollectionRepository::findByChainContract
        // re-lowercased the value unconditionally, so the Solana "preserve
        // case" branch never actually reached the database. Both halves now
        // route through the one shared service, so the decision made here is
        // the decision the query uses.
        $identity = NftCollectionIdentifier::canonicalize($chainType, $contractAddress);
        if (!$identity->isAccepted()) {
            // Not a contract or mint on this chain — a marketplace symbol, a
            // malformed address, or a chain family with no identity rule.
            // This is a 404, not an upstream failure: there is no such piece.
            throw new PieceBuilderException(self::ERROR_NOT_FOUND, 'Collection not indexed.');
        }
        $normalizedContract = $identity->canonical();

        // ── 3. Resolve collection (read-time fallback for Cosmos) ──
        $collectionRow = CollectionRepository::findByChainContract($chainId, $normalizedContract);
        $isCosmos      = ($chainType === 'cosmos');

        if ($collectionRow === null && !$isCosmos) {
            throw new PieceBuilderException(self::ERROR_NOT_FOUND, 'Collection not indexed.');
        }

        // ── 3b. Cosmos cold-cache: read-time `contract_info` for the
        //       collection.name embed when no indexed row exists. One
        //       extra LCD call; cached for an hour inside the fetcher.
        $cosmosFetcher  = null;
        $cosmosColName  = null;
        if ($isCosmos) {
            $maybeFetcher = FetcherFactory::make_for_chain($chain);
            if (!($maybeFetcher instanceof CosmosFetcher)) {
                throw new PieceBuilderException(
                    self::ERROR_UPSTREAM_UNAVAILABLE,
                    'No fetcher driver for chain.'
                );
            }
            $cosmosFetcher = $maybeFetcher;
            if ($collectionRow === null) {
                $info = $cosmosFetcher->fetchContractInfo($normalizedContract);
                if (is_array($info) && is_string($info['name'] ?? null) && $info['name'] !== '') {
                    $cosmosColName = (string) $info['name'];
                }
            }
        }

        // ── 4. Per-token metadata (with cache provenance) ──────────
        [$pieceMeta, $cacheState] = self::resolvePieceMetadata(
            $chain,
            $chainType,
            $chainId,
            $normalizedContract,
            $tokenId,
            $isCosmos
        );

        // ── 5. Token-standard guard (strict allow-list) ────────────
        $tokenStandard = self::resolveTokenStandard($collectionRow, $chainType);
        if ($tokenStandard !== null && !self::isAcceptedStandard($tokenStandard)) {
            throw new PieceBuilderException(
                self::ERROR_UNSUPPORTED_STANDARD,
                'Contract token standard is not supported.'
            );
        }

        // ── 6. Owner resolution ─────────────────────────────────────
        // Cosmos: read-time CW-721 `owner_of` LCD call (5-min cached
        // inside CosmosFetcher). CW-721 is single-owner per token —
        // owners_count = 1, owners[] = []. Returns [null, [], 0] when
        // the LCD call fails or the token does not exist (transport
        // failures aren't cached, so a retry will surface the data).
        [$dominantOwner, $extraOwners, $ownersCount] = $isCosmos
            ? self::resolveCosmosOwner($cosmosFetcher, $chainId, $normalizedContract, $tokenId)
            : self::resolveOwners($chainId, $normalizedContract, $tokenId, $tokenStandard);

        // ── 7. Marketplace links ────────────────────────────────────
        $marketplaceTemplate = is_string($chain->marketplace_template ?? null)
            ? (string) $chain->marketplace_template
            : '';
        $marketplaceLinks = [];
        $marketplaceUrl = MarketplaceLinkBuilder::build($marketplaceTemplate, $normalizedContract, $tokenId);
        if ($marketplaceUrl !== null) {
            $marketplaceLinks[] = [
                'name' => self::marketplaceNameForChain($chainSlug),
                'url'  => $marketplaceUrl,
            ];
        }

        // ── 8. Indexer state (forwarded per §3.6) ───────────────────
        $indexer = HoldingsService::getIndexerStateForChain($chainSlug);

        // ── 9. Compose envelope ─────────────────────────────────────
        $viewModel = [
            'id' => self::buildOpaqueId($chainSlug, $normalizedContract, $tokenId),
            'collection' => self::buildCollectionEmbed(
                $collectionRow,
                $chainSlug,
                $normalizedContract,
                $tokenStandard,
                $cosmosColName
            ),
            'token_id'         => $tokenId,
            'name'             => $pieceMeta['name'],
            'description'      => $pieceMeta['description'],
            'image_url'        => $pieceMeta['image_url'],
            'image_url_thumb'  => $pieceMeta['image_url_thumb'],
            'attributes'       => $pieceMeta['attributes'],
            'owner'            => $dominantOwner,
            'owners_count'     => $ownersCount,
            'owners'           => $extraOwners,
            'marketplace_links' => $marketplaceLinks,
            'mint_link'        => null,        // V2 Phase 6 deferred — gallery surface
            'permissions'      => new stdClass(), // §3.7: empty in V2 Phase 6
            'meta' => [
                'read_time'            => $isCosmos,
                'indexer_state'        => $indexer['indexer_state'],
                'indexer_state_label'  => $indexer['indexer_state_label'],
                'owners_summary_label' => self::buildOwnersSummaryLabel($tokenStandard, $ownersCount),
            ],
        ];

        return [$viewModel, $cacheState];
    }

    /**
     * §3.7 `meta.owners_summary_label` — server-pre-formatted multi-
     * holder summary string. The frontend renders this verbatim and
     * uses its presence (non-null) as the signal to also render
     * `owners[]` co-owner tiles. Single-holder standards always get
     * null. ERC-1155 with `owners_count <= 1` (e.g., a 1155 with
     * effectively one holder) also gets null — the multi-holder UI
     * is only meaningful when there are actually multiple holders.
     *
     * Goes through `_n()` so when i18n ships the locale-correct
     * plural lands here without a frontend change.
     */
    private static function buildOwnersSummaryLabel(?string $tokenStandard, int $ownersCount): ?string
    {
        if (!self::isMultiHolderStandard($tokenStandard) || $ownersCount <= 1) {
            return null;
        }
        return sprintf(
            /* translators: %d is the number of distinct ERC-1155 holders. */
            _n('Held by %d collector', 'Held by %d collectors', $ownersCount, 'bcc-trust'),
            $ownersCount
        );
    }

    /**
     * Resolve §3.7 metadata + the §4.17 cache-state. For indexed
     * chains: try cache, distinguish FRESH vs STALE vs MISS, fall
     * through to fetcher when stale or missing, persist to cache.
     * For Cosmos: read-time only; no persistence (matches the V2
     * Phase 2 asymmetry); always reports MISS.
     *
     * @param ChainRow $chain
     * @return array{0: array{
     *     name: ?string,
     *     description: ?string,
     *     image_url: ?string,
     *     image_url_thumb: ?string,
     *     attributes: list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}>
     * }, 1: self::CACHE_FRESH|self::CACHE_STALE|self::CACHE_MISS}
     * @throws PieceBuilderException
     */
    private static function resolvePieceMetadata(
        object $chain,
        string $chainType,
        int $chainId,
        string $contract,
        string $tokenId,
        bool $isCosmos
    ): array {
        if ($isCosmos) {
            $fetcher = FetcherFactory::make_for_chain($chain);
            if (!($fetcher instanceof CosmosFetcher)) {
                throw new PieceBuilderException(
                    self::ERROR_UPSTREAM_UNAVAILABLE,
                    'No fetcher driver for chain.'
                );
            }
            $meta = $fetcher->fetchTokenMetadata($contract, $tokenId);
            // CosmosFetcher returns the empty shape on transport failure
            // (vs throwing). We treat all-null as "either token does
            // not exist or LCD timed out"; per §4.17 we surface
            // upstream-unavailable since we cannot distinguish.
            if (
                $meta['name'] === null
                && $meta['description'] === null
                && $meta['image_url'] === null
                && $meta['attributes'] === []
            ) {
                throw new PieceBuilderException(
                    self::ERROR_UPSTREAM_UNAVAILABLE,
                    'Could not resolve piece metadata from chain.'
                );
            }
            return [
                [
                    'name'            => $meta['name'],
                    'description'     => $meta['description'],
                    'image_url'       => $meta['image_url'],
                    'image_url_thumb' => $meta['image_url_thumb'],
                    'attributes'      => $meta['attributes'],
                ],
                self::CACHE_MISS,
            ];
        }

        // Indexed chains: cache-aware. The repo returns rows
        // regardless of expiry so we can distinguish FRESH vs STALE.
        $cached = NftCollectionPiecesRepository::findByTriple($chainId, $contract, $tokenId);
        if ($cached !== null && self::isCacheRowFresh($cached)) {
            $attributes = self::decodeAttributes($cached->attributes_json);
            return [
                [
                    'name'            => $cached->name,
                    'description'     => $cached->description,
                    'image_url'       => $cached->image_url,
                    'image_url_thumb' => $cached->image_url_thumb ?? $cached->image_url,
                    'attributes'      => $attributes,
                ],
                self::CACHE_FRESH,
            ];
        }

        // Cache miss OR stale — fetch upstream synchronously. The
        // cache-state distinguishes whether we had a stale row at
        // request entry (STALE) vs no row at all (MISS).
        $cacheState = $cached !== null ? self::CACHE_STALE : self::CACHE_MISS;

        $fetched = self::fetchUpstreamMetadata($chain, $chainType, $contract, $tokenId);
        if ($fetched === null) {
            // If we have a stale row but the upstream failed, fall
            // back to serving the stale data — the §4.17 SWR contract
            // says clients prefer stale-but-served over hard-fail
            // when the cache horizon hasn't fully expired the row.
            if ($cached !== null) {
                $attributes = self::decodeAttributes($cached->attributes_json);
                return [
                    [
                        'name'            => $cached->name,
                        'description'     => $cached->description,
                        'image_url'       => $cached->image_url,
                        'image_url_thumb' => $cached->image_url_thumb ?? $cached->image_url,
                        'attributes'      => $attributes,
                    ],
                    self::CACHE_STALE,
                ];
            }
            throw new PieceBuilderException(self::ERROR_NOT_FOUND, 'Token metadata not found.');
        }

        // Persist (best-effort; failure to write cache must not fail the request).
        $attributesJson = self::encodeAttributes($fetched['attributes']);
        NftCollectionPiecesRepository::upsert(
            $chainId,
            $contract,
            $tokenId,
            [
                'name'            => $fetched['name'],
                'description'     => $fetched['description'],
                'image_url'       => $fetched['image_url'],
                'image_url_thumb' => $fetched['image_url_thumb'],
                'attributes_json' => $attributesJson,
            ],
            self::PIECE_CACHE_TTL_SECONDS
        );

        return [
            [
                'name'            => $fetched['name'],
                'description'     => $fetched['description'],
                'image_url'       => $fetched['image_url'],
                'image_url_thumb' => $fetched['image_url_thumb'],
                'attributes'      => $fetched['attributes'],
            ],
            $cacheState,
        ];
    }

    /**
     * True iff the cache row's `expires_at` is at or after the
     * server's current UTC time. The repo returns rows regardless
     * of freshness — this is the §4.17 FRESH-vs-STALE policy.
     */
    private static function isCacheRowFresh(object $row): bool
    {
        $expiresAt = isset($row->expires_at) && is_string($row->expires_at)
            ? (string) $row->expires_at
            : null;
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }
        $expiresTs = strtotime($expiresAt . ' UTC');
        if ($expiresTs === false) {
            return false;
        }
        return $expiresTs >= time();
    }

    /**
     * Dispatch to the per-chain fetcher's metadata method. Returns
     * null on transport / not-found.
     *
     * @param ChainRow $chain
     * @return array{
     *     name: ?string,
     *     description: ?string,
     *     image_url: ?string,
     *     image_url_thumb: ?string,
     *     attributes: list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}>
     * }|null
     */
    private static function fetchUpstreamMetadata(
        object $chain,
        string $chainType,
        string $contract,
        string $tokenId
    ): ?array {
        $fetcher = FetcherFactory::make_for_chain($chain);

        if ($fetcher instanceof EvmFetcher) {
            $raw = $fetcher->fetchMetadataForToken($contract, $tokenId);
        } elseif ($fetcher instanceof SolanaFetcher) {
            // Solana: mint IS the token; both contract and tokenId
            // carry the same value upstream. We pass `$contract`
            // (which equals `$tokenId` for SPL paths) to the fetcher.
            $raw = $fetcher->fetchMetadataForMint($contract);
        } else {
            return null;
        }

        if ($raw === null) {
            return null;
        }

        return [
            'name'            => is_string($raw['name'] ?? null) ? $raw['name'] : null,
            'description'     => is_string($raw['description'] ?? null) ? $raw['description'] : null,
            'image_url'       => is_string($raw['image_url'] ?? null) ? $raw['image_url'] : null,
            'image_url_thumb' => is_string($raw['image_url_thumb'] ?? null) ? $raw['image_url_thumb'] : null,
            'attributes'      => self::sanitizeAttributes($raw['attributes'] ?? []),
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}>
     */
    private static function sanitizeAttributes(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $traitType = $attr['trait_type'] ?? null;
            $value     = $attr['value']      ?? null;
            if (!is_string($traitType) || $traitType === '' || $value === null) {
                continue;
            }
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $entry = [
                    'trait_type' => $traitType,
                    'value'      => $value,
                ];
                if (isset($attr['rarity_pct']) && is_numeric($attr['rarity_pct'])) {
                    $entry['rarity_pct'] = (float) $attr['rarity_pct'];
                }
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * @return list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}>
     */
    private static function decodeAttributes(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return self::sanitizeAttributes($decoded);
    }

    /**
     * @param list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}> $attributes
     */
    private static function encodeAttributes(array $attributes): ?string
    {
        if ($attributes === []) {
            return '[]';
        }
        $encoded = wp_json_encode($attributes);
        return is_string($encoded) ? $encoded : null;
    }

    /**
     * Resolve dominant owner + holder count for §3.7. Returns
     * `[null, [], 0]` when nothing is known (cold-cache, freshly
     * minted, indexer behind).
     *
     * PRIVACY: element 1 (`owners[]`) is now ALWAYS `[]`. Every field it
     * used to carry — `wallet_address`, `address_short`, `balance` — is a
     * wallet identifier or a holding amount bound to one, and this route
     * is anonymous. The aggregate `owners_count` (element 2) survives
     * because a bare holder count cannot be used to reconstruct a
     * member↔wallet or member↔holding relationship.
     *
     * See docs/wallet-privacy-policy.md.
     *
     * @return array{0: array{is_linked: bool}|null, 1: list<array{}>, 2: int}
     */
    private static function resolveOwners(
        int $chainId,
        string $contract,
        string $tokenId,
        ?string $tokenStandard
    ): array {
        $rows = NftHoldingsRepository::findOwnersByTokenId(
            $chainId,
            $contract,
            $tokenId,
            self::OWNERS_FETCH_LIMIT
        );
        if ($rows === []) {
            return [null, [], 0];
        }

        $first = array_shift($rows);
        $dominant = self::buildDominantOwner($chainId, (string) $first->wallet_address);

        // ERC-1155 used to fan the remaining holders out into `owners[]`
        // as {wallet_address, address_short, balance}. All three are
        // forbidden on a public surface, so the list is gone — we now
        // only COUNT the extra holders to keep `owners_count` accurate.
        // The count is an aggregate and identifies nobody.
        $extraOwnerCount = 0;
        if (self::isMultiHolderStandard($tokenStandard)) {
            $extraOwnerCount = min(count($rows), self::OWNERS_LIST_CAP);
        }

        // owners_count: ERC-721 / CW-721 / SPL → always 1 when known.
        // ERC-1155 → count of distinct rows we observed (capped by
        // fetch limit; if there are >11 holders this under-reports
        // by design — surfacing the exact count requires an extra
        // COUNT(*) query that the V2 Phase 6 endpoint deliberately
        // avoids for latency budget).
        $ownersCount = self::isMultiHolderStandard($tokenStandard)
            ? 1 + $extraOwnerCount // dominant + extras observed
            : 1;

        return [$dominant, [], $ownersCount];
    }

    /**
     * Resolve the Cosmos owner via read-time CW-721 `owner_of` LCD
     * call (5-min cached inside CosmosFetcher). CW-721 is single-
     * owner per token — owners[] is always [] and owners_count is
     * 1 (when the call returns) or 0 (on transport failure / unknown
     * token).
     *
     * Wallet → user resolution uses the same WalletRepository path as
     * ETH/SOL — Cosmos bech32 addresses go through the existing
     * `findUserIdByAddress` lookup. That lookup now feeds ONLY the
     * `is_linked` boolean; no identity is emitted. Not-yet-linked
     * Cosmos wallets surface as `owner.is_linked = false`.
     *
     * @return array{0: array{is_linked: bool}|null, 1: list<array{}>, 2: int}
     */
    private static function resolveCosmosOwner(
        ?CosmosFetcher $fetcher,
        int $chainId,
        string $contract,
        string $tokenId
    ): array {
        if ($fetcher === null) {
            return [null, [], 0];
        }
        $owner = $fetcher->cw721OwnerOf($contract, $tokenId);
        if ($owner === null) {
            return [null, [], 0];
        }
        $dominant = self::buildDominantOwner($chainId, $owner['wallet_address']);
        return [$dominant, [], 1];
    }

    /**
     * Compose the `owner` block for §3.7.
     *
     * PRIVACY — this route is anonymous (`permission_callback` is
     * `__return_true`), so everything here is world-readable.
     *
     * Removed, permanently:
     *   - `wallet_address` / `address_short` — a wallet identifier is
     *     never disclosed to anyone but its owner, in any form.
     *   - `balance` — a holding amount bound to that wallet.
     *   - `user{id,handle,display_name,avatar_url}` — this was the worst
     *     of it. Resolving the holder's BCC identity from a PRIVATE
     *     wallet link published a member↔holding join that the member
     *     never consented to. Verifying a wallet is not consent to
     *     broadcast what it holds; holdings require the explicit opt-in
     *     NFT showcase.
     *
     * What survives is `is_linked` — "some BCC member holds this" — which
     * names nobody and cannot be resolved back to a member. The lookup
     * stays server-side purely to compute that boolean.
     *
     * Note this was also an ENUMERATION primitive: walking token IDs of
     * any indexed collection yielded a wallet→member table.
     *
     * See docs/wallet-privacy-policy.md.
     *
     * @return array{is_linked: bool}
     */
    private static function buildDominantOwner(int $chainId, string $walletAddress): array
    {
        $userId = WalletRepository::findUserIdByAddress($chainId, $walletAddress);

        return [
            'is_linked' => $userId > 0,
        ];
    }

    // resolveUserSummary() removed 2026-07-23. It resolved a holder's BCC
    // identity (id / handle / display_name / avatar_url) from a private
    // wallet link and published it on an anonymous endpoint. Nothing may
    // reintroduce a wallet→member identity lookup on a public surface;
    // see docs/wallet-privacy-policy.md.

    private static function isMultiHolderStandard(?string $tokenStandard): bool
    {
        if ($tokenStandard === null) {
            return false;
        }
        $upper = strtoupper($tokenStandard);
        return str_contains($upper, '1155');
    }

    private static function isAcceptedStandard(string $tokenStandard): bool
    {
        $upper = strtoupper($tokenStandard);
        // Strict allow-list per V2 Phase 6 design decision: only the
        // known NFT standards render. Anything else (fungible tokens,
        // future ERC-NNNN standards we haven't classified, garbage
        // strings) returns 422 `bcc_unsupported_standard`. New chains
        // / standards must extend this list explicitly.
        return $upper === 'ERC-721'
            || $upper === 'ERC-1155'
            || $upper === 'SPL'
            || $upper === 'SPL-NFT'
            || $upper === 'CW-721';
    }

    private static function resolveTokenStandard(?object $collectionRow, string $chainType): ?string
    {
        if ($collectionRow !== null) {
            $std = $collectionRow->token_standard ?? null;
            if (is_string($std) && $std !== '') {
                return $std;
            }
        }
        // Cosmos chains default to CW-721 in this codebase (Cosmos Hub,
        // Injective, and friends). Solana defaults to SPL.
        return match ($chainType) {
            'cosmos' => 'CW-721',
            'solana' => 'SPL-NFT',
            default  => null,
        };
    }

    /**
     * Build the §3.7 `collection` embed. Uses the indexed row when
     * available; falls back to a minimal embed sourced from the chain
     * + caller inputs (Cosmos cold-cache).
     *
     * @return array{
     *     id: int|null,
     *     name: string|null,
     *     creator_handle: string|null,
     *     chain_slug: string,
     *     contract_address: string,
     *     token_standard: string|null,
     *     is_verified: bool
     * }
     */
    private static function buildCollectionEmbed(
        ?object $collectionRow,
        string $chainSlug,
        string $contractAddress,
        ?string $tokenStandard,
        ?string $cosmosColdCacheName = null
    ): array {
        if ($collectionRow === null) {
            return [
                'id'               => null,
                // Cosmos cold-cache: use the read-time `contract_info`
                // name when available so the page header isn't blank.
                // Indexed chains never reach this branch (a missing row
                // throws ERROR_NOT_FOUND earlier in build()).
                'name'             => $cosmosColdCacheName,
                'creator_handle'   => null,
                'chain_slug'       => $chainSlug,
                'contract_address' => $contractAddress,
                'token_standard'   => $tokenStandard,
                'is_verified'      => false,
            ];
        }

        $isVerifiedRaw = $collectionRow->is_verified ?? null;
        $isVerified    = $isVerifiedRaw !== null && (int) $isVerifiedRaw === 1;

        $name = isset($collectionRow->collection_name) && is_string($collectionRow->collection_name)
            ? (string) $collectionRow->collection_name
            : null;

        return [
            'id'               => isset($collectionRow->id) ? (int) $collectionRow->id : null,
            'name'             => $name,
            'creator_handle'   => null, // V2 Phase 6: claim-by-creator not surfaced on piece detail
            'chain_slug'       => $chainSlug,
            'contract_address' => $contractAddress,
            'token_standard'   => $tokenStandard,
            'is_verified'      => $isVerified,
        ];
    }

    /**
     * §3.7 says `id` is opaque, prefixed `nft_piece_<chain>_<short-contract>_<tokenId>`.
     * Frontend treats this as a string; routing uses the triple
     * directly.
     */
    private static function buildOpaqueId(string $chainSlug, string $contractAddress, string $tokenId): string
    {
        $shortContract = strlen($contractAddress) > 10
            ? substr($contractAddress, 0, 8)
            : $contractAddress;
        return sprintf('nft_piece_%s_%s_%s', $chainSlug, $shortContract, $tokenId);
    }

    private static function marketplaceNameForChain(string $chainSlug): string
    {
        return match ($chainSlug) {
            'ethereum' => 'OpenSea',
            'solana'   => 'Magic Eden',
            'cosmos'   => 'Stargaze',
            default    => 'Marketplace',
        };
    }
}
