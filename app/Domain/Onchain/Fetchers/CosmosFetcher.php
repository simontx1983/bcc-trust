<?php

namespace BCC\Trust\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\Bech32;

/**
 * Cosmos Chain Fetcher
 *
 * Fetches validator and DAO data from Cosmos SDK chains via LCD REST API.
 * Supports any Cosmos SDK chain (cosmoshub, osmosis, akash, juno, etc.).
 *
 * NFT collections: Cosmos SDK chains may have CW-721 NFTs (e.g. the
 * Cosmos Hub post-Stargaze-migration, Injective). Discovery is
 * chain-native — Injective enumerates the Talis whitelist contract, every
 * other chain enumerates contracts by CW-721 wasm code ID over the same
 * LCD (`/cosmwasm/wasm/v1/code/{id}/contracts`). No third-party indexer.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
class CosmosFetcher implements FetcherInterface
{
    /** @var ChainRow */
    private object $chain;
    private string $rest_url;
    private int    $decimals;
    private int $timeout = 15;

    /**
     * Static caches keyed by chain ID — shared across instances within the
     * same PHP process so enrichment batches don't re-fetch identical data.
     *
     * @var array<int, array<int, array<string, mixed>>> Bonded validators sorted by tokens desc.
     */
    private static array $validatorListCache = [];

    /** @param ChainRow $chain */
    public function __construct(object $chain)
    {
        $this->chain    = $chain;
        $rest           = $chain->rest_url ?? $chain->rpc_url;
        $this->rest_url = rtrim($rest ?? '', '/');
        $this->decimals = (int) ($chain->decimals ?? 6);
    }

    /** @return ChainRow */
    public function get_chain(): object
    {
        return $this->chain;
    }

    public function last_fetch_error(): ?string
    {
        // Stub — wire to actual apiGet error tracking when a real
        // transport-failure UX gap surfaces here.
        return null;
    }

    public function supports_feature(string $feature): bool
    {
        return in_array(
            $feature,
            // V2 Phase 2 added 'holdings_count' + 'holdings_list' for CW-721
            // chains (Cosmos Hub, Injective, Kujira, Dungeon). Curated-only
            // posture means non-NFT-active Cosmos chains naturally produce
            // empty results — no per-chain blocklist needed in this method.
            //
            // 'collection' (V1 wallet-link discovery) rides the Stargaze
            // marketplace indexer and therefore only yields rows on the
            // Cosmos Hub — fetch_collections itself gates on the slug, so
            // advertising the feature chain-wide stays harmless (other
            // cosmos chains return [] and WalletSeedService moves on).
            ['validator', 'delegations', 'dao', 'collection', 'top_collections', 'holdings_count', 'holdings_list'],
            true
        );
    }

    // ── Validator Fetching ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function fetch_validator(string $address): array
    {
        $valoper = $this->ensureValoperPrefix($address);

        // Reuse the cached bonded set when available (populated by
        // fetch_all_validators or a prior enrichment call in the same
        // cron batch). Falls back to an individual LCD call only if
        // the validator isn't in the bonded cache (e.g. unbonded).
        $val = $this->findInBondedCache($valoper);

        if (!$val) {
            $response = $this->lcdGet("/cosmos/staking/v1beta1/validators/{$valoper}");
            if (!$response || !isset($response['validator'])) {
                return [];
            }
            $val = $response['validator'];
        }

        $delegations = $this->lcdGet("/cosmos/staking/v1beta1/validators/{$valoper}/delegations", [
            'pagination.limit'       => 1,
            'pagination.count_total' => 'true',
        ]);
        $delegator_count = (int) ($delegations['pagination']['total'] ?? 0);

        $uptime            = $this->fetchUptime($val);
        $voting_power_rank = $this->fetchVotingPowerRank($valoper);

        $commission_rate = isset($val['commission']['commission_rates']['rate'])
            ? round((float) $val['commission']['commission_rates']['rate'] * 100, 2)
            : null;

        $total_stake = isset($val['tokens'])
            ? $this->tokensToDisplay($val['tokens'])
            : null;

        $self_stake   = $this->fetchSelfDelegation($valoper);
        $status       = $this->parseStatus($val['status'] ?? '');
        $jailed_count = $this->fetchJailedCount($val);

        return [
            'operator_address'         => $valoper,
            'chain_id'                 => (int) $this->chain->id,
            'moniker'                  => $val['description']['moniker'] ?? null,
            'identity'                 => self::cleanIdentity($val['description']['identity'] ?? null),
            'status'                   => $status,
            'commission_rate'          => $commission_rate,
            'total_stake'              => $total_stake,
            'self_stake'               => $self_stake,
            'delegator_count'          => $delegator_count,
            'uptime_30d'               => $uptime,

            'jailed_count'             => $jailed_count,
            'voting_power_rank'        => $voting_power_rank,
        ];
    }

    /**
     * Normalize a Cosmos `description.identity` value (a Keybase 16-hex
     * key suffix). Returns a trimmed, length-capped string or null. The
     * KeybaseLogoResolver does the strict format validation — here we
     * only keep the column safe (≤64 chars) and collapse empties to null.
     */
    private static function cleanIdentity(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        return function_exists('mb_substr') ? mb_substr($raw, 0, 64) : substr($raw, 0, 64);
    }

    /**
     * Enrichment-optimized fetch that skips expensive API calls when possible:
     *
     *  - self_stake:  skipped if total_stake unchanged (stake changes are rare)
     *  - uptime_30d:  skipped if fetched < 24h ago (missed_blocks moves slowly)
     *
     * @param string  $address     Validator operator address.
     * @param ?object $existingRow DB row from onchain_validators.
     * @return array<string, mixed> Same shape as fetch_validator().
     */
    public function enrich_validator(string $address, ?object $existingRow = null): array
    {
        $valoper = $this->ensureValoperPrefix($address);

        $val = $this->findInBondedCache($valoper);

        if (!$val) {
            $response = $this->lcdGet("/cosmos/staking/v1beta1/validators/{$valoper}");
            if (!$response || !isset($response['validator'])) {
                return [];
            }
            $val = $response['validator'];
        }

        $voting_power_rank = $this->fetchVotingPowerRank($valoper);

        $commission_rate = isset($val['commission']['commission_rates']['rate'])
            ? round((float) $val['commission']['commission_rates']['rate'] * 100, 2)
            : null;

        $total_stake = isset($val['tokens'])
            ? $this->tokensToDisplay($val['tokens'])
            : null;

        // Age of the existing row — used by all skip-if-fresh checks below.
        $fetchedAt = $existingRow->fetched_at ?? null;
        $rowAge    = $fetchedAt ? (time() - strtotime($fetchedAt)) : PHP_INT_MAX;

        // Deterministic jitter (0.0–1.0) seeded from the operator address.
        // Spreads refresh times evenly across the window so validators don't
        // all expire on the same cron tick (thundering herd prevention).
        $jitter = (float) (crc32($valoper) & 0x7FFFFFFF) / 0x7FFFFFFF;

        // ── Self-delegation: skip if total_stake unchanged ──────────────
        $previousStake = $existingRow ? (float) ($existingRow->total_stake ?? 0) : 0.0;
        $previousSelf  = $existingRow ? ($existingRow->self_stake ?? null) : null;
        // Treat 0 as "never fetched" — no bonded validator has 0 self-delegation.
        $stakeChanged  = $previousSelf === null
            || (float) $previousSelf === 0.0
            || $total_stake === null
            || abs($total_stake - $previousStake) > 0.01;

        if ($stakeChanged) {
            $self_stake = $this->fetchSelfDelegation($valoper);
        } else {
            $self_stake = (float) $previousSelf;
        }

        // ── Delegator count: skip if fetched < 5–9 days ago ─────────────
        // Base window 7 days ± 2 days of jitter per validator.
        $previousDelegators = $existingRow ? ($existingRow->delegator_count ?? null) : null;
        $delegatorsTtl      = (int) ((5 + $jitter * 4) * DAY_IN_SECONDS);
        // Treat 0 as "never fetched" — active validators always have >= 1 delegator (self).
        $delegatorsStale    = $previousDelegators === null || (int) $previousDelegators === 0 || $rowAge >= $delegatorsTtl;

        if ($delegatorsStale) {
            $delegations = $this->lcdGet("/cosmos/staking/v1beta1/validators/{$valoper}/delegations", [
                'pagination.limit'       => 1,
                'pagination.count_total' => 'true',
            ]);
            $delegator_count = (int) ($delegations['pagination']['total'] ?? 0);
        } else {
            $delegator_count = (int) $previousDelegators;
        }

        // ── Uptime: skip if fetched < 18–30h ago ────────────────────────
        // Base window 24h ± 6h of jitter per validator.
        $previousUptime = $existingRow ? ($existingRow->uptime_30d ?? null) : null;
        $uptimeTtl      = (int) ((18 + $jitter * 12) * HOUR_IN_SECONDS);
        // Treat 0 as "never fetched" — a bonded validator with 0% uptime would be
        // slashed/jailed long before we see it. Fixes stale 0.00 from bulkUpsert bug.
        $uptimeStale    = $previousUptime === null || (float) $previousUptime === 0.0 || $rowAge >= $uptimeTtl;

        if ($uptimeStale) {
            $uptime = $this->fetchUptime($val);
        } else {
            $uptime = (float) $previousUptime;
        }

        $status       = $this->parseStatus($val['status'] ?? '');
        $jailed_count = $this->fetchJailedCount($val);

        return [
            'operator_address'         => $valoper,
            'chain_id'                 => (int) $this->chain->id,
            'moniker'                  => $val['description']['moniker'] ?? null,
            'identity'                 => self::cleanIdentity($val['description']['identity'] ?? null),
            'status'                   => $status,
            'commission_rate'          => $commission_rate,
            'total_stake'              => $total_stake,
            'self_stake'               => $self_stake,
            'delegator_count'          => $delegator_count,
            'uptime_30d'               => $uptime,

            'jailed_count'             => $jailed_count,
            'voting_power_rank'        => $voting_power_rank,
        ];
    }

    /**
     * Look up a validator in the cached bonded set.
     * Returns the raw LCD array or null if not found.
     *
     * @return array<string, mixed>|null
     */
    private function findInBondedCache(string $valoper): ?array
    {
        $vals = $this->getBondedValidators();

        foreach ($vals as $v) {
            if (($v['operator_address'] ?? '') === $valoper) {
                return $v;
            }
        }

        return null;
    }

    // ── Bulk Validator Fetching ────────────────────────────────────────────

    /**
     * Fetch ALL active validators from the chain's bonded set.
     *
     * Uses the paginated LCD staking endpoint to get up to 500 validators
     * in a single call. Returns lightweight rows (no per-validator uptime
     * or governance calls — those are expensive and done on refresh).
     *
     * @return array<int, array<string, mixed>> Array of validator data arrays ready for bulkUpsert.
     */
    public function fetch_all_validators(): array
    {
        // Reuse the cached bonded set (also populates cache for enrichment).
        $vals = $this->getBondedValidators();

        if (empty($vals)) {
            return [];
        }

        $results = [];
        foreach ($vals as $rank => $val) {
            $commission = isset($val['commission']['commission_rates']['rate'])
                ? round((float) $val['commission']['commission_rates']['rate'] * 100, 2)
                : null;

            $results[] = [
                'operator_address'         => $val['operator_address'],
                'chain_id'                 => (int) $this->chain->id,
                'moniker'                  => $val['description']['moniker'] ?? null,
                'identity'                 => self::cleanIdentity($val['description']['identity'] ?? null),
                'status'                   => $this->parseStatus($val['status'] ?? ''),
                'commission_rate'          => $commission,
                'total_stake'              => isset($val['tokens']) ? $this->tokensToDisplay($val['tokens']) : null,
                'self_stake'               => null,  // Expensive per-validator call — populated on refresh
                'delegator_count'          => null,  // Same — populated on refresh
                'uptime_30d'               => null,  // Same — populated on refresh

                'jailed_count'             => ($val['jailed'] ?? false) ? 1 : 0,
                'voting_power_rank'        => $rank + 1,
            ];
        }

        return $results;
    }

    // ── Holdings (CW-721 NFT ownership, V2 Phase 2) ───────────────────────

    /** Per-(wallet, contract) defensive page-walk ceiling (matches EVM/SOL pattern). */
    private const PER_CONTRACT_TOKEN_CAP = 100;
    private const DEFAULT_SIGNED_BLOCKS_WINDOW = 10000;

    /** CW-721 `tokens` query page size — Cosmos LCDs commonly cap at ~30. */
    private const TOKENS_PAGE_SIZE = 30;

    /** wp_cache TTLs. nft_info is static; tokens lists track wallet activity. */
    private const NFT_INFO_CACHE_TTL = 7 * DAY_IN_SECONDS;
    private const TOKENS_CACHE_TTL   = DAY_IN_SECONDS;

    /** Default contract cap when BCC_COSMOS_HOLDINGS_CONTRACT_CAP is undefined. */
    private const DEFAULT_CONTRACT_CAP = 30;

    /** Max discovery rows one wallet can land on the Verify queue. */
    private const DISCOVERY_COLLECTION_CAP = 50;

    /**
     * Count tokens this wallet holds in a single CW-721 contract on this chain.
     *
     * Used by the gate fast-path. Walks the `tokens { owner }` query up to
     * PER_CONTRACT_TOKEN_CAP and returns the count. Does NOT fetch per-token
     * metadata — count-only path stays cheap.
     */
    public function count_holdings(string $wallet, string $contract): ?int
    {
        if ($wallet === '' || $contract === '') {
            // Empty input is a definite "no holdings," not an LCD outage.
            return 0;
        }
        // cw721AllTokensForOwner returns null when the LCD query FAILED
        // (transport error / non-200 / unparseable). A SUCCESSFUL query
        // for a wallet that owns nothing returns an empty list → 0. We
        // surface null straight through so the caller fails open rather
        // than reading a breaker-open LCD as "owns none."
        $tokenIds = $this->cw721AllTokensForOwner($contract, $wallet);
        return $tokenIds === null ? null : count($tokenIds);
    }

    /**
     * Enumerate this wallet's NFTs across every verified CW-721 contract on
     * this chain. Iterates `CollectionRepository::listVerifiedByChain` and
     * calls `cw721Tokens` per contract; for each token_id, optionally
     * resolves metadata via `fetchTokenMetadata`.
     *
     * Curated-only posture: only `is_verified = 1` collections are
     * iterated. A user holding NFTs from a non-verified contract sees them
     * missing — see plan §"Decisions locked" for the framing.
     *
     * The `$cursor` parameter is the FetcherInterface contract; for Cosmos
     * we don't paginate across contracts (the per-(wallet, contract) cap
     * bounds each one). Returns null cursor on success — `truncated` flips
     * true when any contract hit PER_CONTRACT_TOKEN_CAP.
     *
     * @return array{items: list<array{contract_address: string, token_id: string, chain_id: int, collection_name: ?string, name: ?string, image_url: ?string, metadata_uri: ?string, token_standard: ?string}>, truncated: bool, cursor: ?string}
     */
    public function list_holdings(string $wallet, ?string $cursor = null): array
    {
        $empty = ['items' => [], 'truncated' => false, 'cursor' => null];
        if ($wallet === '') {
            return $empty;
        }

        $chainId = (int) $this->chain->id;
        $cap     = self::contractCap();
        // Gallery iterates every KNOWN collection — verified first, then
        // unverified discovery rows (verified ones win the cap when a
        // wallet's chain has more known contracts than the cap allows).
        // A user's own assets should never silently vanish just because
        // the operator hasn't verified the collection yet; the caller
        // annotates each item with `collection_verified` so the UI can
        // dim rather than hide. GATING is unaffected: ownsAny/
        // count_holdings resolve per gate contract and gates only exist
        // on verified collections.
        $known = \BCC\Trust\Onchain\Repositories\CollectionRepository::listKnownByChain($chainId, $cap);
        if ($known === []) {
            return $empty;
        }

        // ── Phase A: parallel first-page discovery ───────────────────────
        // Every known collection's `tokens{owner}` FIRST page is fetched
        // concurrently (one same-host batch, waves of 12), replacing the
        // old ~330ms × 30 sequential walk. Per contract we produce a
        // list<string>|null first page with the SAME semantics as
        // cw721Tokens (null = query failed / breaker-open; [] = owns none),
        // and we WRITE each success back to the shared cw721_tokens cache
        // so warm gallery loads and the single-path count_holdings stay
        // consistent. Cache HITS are served locally and never batched.
        $firstPages = $this->discoverFirstPages($known, $wallet);

        $items     = [];
        $truncated = false;

        foreach ($known as $coll) {
            $contract = (string) $coll->contract_address;
            if ($contract === '') {
                continue;
            }

            // Per-contract try/catch — one broken contract can't poison the
            // batch (mirror Phase 1c NftEnrichmentService::runForChain
            // structural isolation pattern).
            try {
                $firstPage = $firstPages[$contract] ?? null;
                // list_holdings is the gallery (cold) path — a failed or
                // empty contract is simply omitted from the gallery (the
                // pre-fail-open behaviour). The count (gate) path is where
                // the null vs empty distinction is load-bearing.
                if ($firstPage === null || $firstPage === []) {
                    continue;
                }

                // ── Phase B: finish the walk only when needed ────────────
                // The first page filled to the page size → this wallet may
                // hold more under this contract; complete the (rare) walk
                // sequentially. cw721AllTokensForOwner re-reads page 0 from
                // the cache we just warmed in Phase A (no re-fetch), then
                // paginates. We defer to its result fully so the null-means-
                // omit fail-open semantics stay byte-identical to the old
                // sequential path. When the first page is short (< page
                // size) it IS the complete set — no walk needed.
                if (count($firstPage) >= self::TOKENS_PAGE_SIZE) {
                    $walked = $this->cw721AllTokensForOwner($contract, $wallet);
                    if ($walked === null || $walked === []) {
                        continue;
                    }
                    $tokenIds = $walked;
                } else {
                    $tokenIds = $firstPage;
                }

                if (count($tokenIds) >= self::PER_CONTRACT_TOKEN_CAP) {
                    $truncated = true;
                }

                $collectionName = is_string($coll->collection_name ?? null)
                    ? (string) $coll->collection_name
                    : null;
                $collectionImage = is_string($coll->image_url ?? null)
                    ? (string) $coll->image_url
                    : null;

                foreach ($tokenIds as $tokenId) {
                    $meta = $this->fetchTokenMetadata($contract, $tokenId);
                    $items[] = [
                        'contract_address' => $contract,
                        'token_id'         => $tokenId,
                        'chain_id'         => $chainId,
                        'collection_name'  => $collectionName,
                        'name'             => $meta['name'] ?? null,
                        'image_url'        => $meta['image_url'] ?? $collectionImage,
                        'metadata_uri'     => $meta['metadata_uri'] ?? null,
                        'token_standard'   => 'CW-721',
                    ];
                }
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::warning('[CosmosFetcher] cw721 contract failed', [
                    'chain_id' => $chainId,
                    'contract' => $contract,
                    'error'    => $e->getMessage(),
                ]);
                continue;
            }
        }

        return [
            'items'     => $items,
            'truncated' => $truncated,
            'cursor'    => null,
        ];
    }

    /**
     * Phase A helper: resolve the FIRST `tokens{owner}` page for every
     * known collection, using the shared cw721_tokens cache and ONE
     * concurrent same-host batch for the misses.
     *
     * Returns a contract → (list<string>|null) map with cw721Tokens'
     * fail-open semantics per contract:
     *   - list<string> (possibly empty) → successful first page (empty =
     *     owns none under this contract);
     *   - null → the first-page query FAILED (transport / non-200 /
     *     breaker-open) — caller omits the collection from the gallery but
     *     MUST NOT read it as "owns none".
     *
     * Cache HITS are served without a network call; MISSES are batched and
     * each SUCCESS is written back to the same cw721_tokens key + TTL
     * (empty cached, null NOT cached) so warm loads and count_holdings read
     * identical rows.
     *
     * @param array<int, object> $known collection rows (contract_address, …)
     * @return array<string, list<string>|null> keyed by contract_address
     */
    private function discoverFirstPages(array $known, string $wallet): array
    {
        /** @var array<string, list<string>|null> $result */
        $result = [];
        /** @var array<string, string> $missPaths contract → LCD path */
        $missPaths = [];

        foreach ($known as $coll) {
            $contract = (string) ($coll->contract_address ?? '');
            if ($contract === '' || isset($result[$contract]) || isset($missPaths[$contract])) {
                continue;
            }

            $cacheKey = $this->cw721TokensCacheKey($contract, $wallet, null);
            $cached   = wp_cache_get($cacheKey, 'bcc_onchain');
            if (is_array($cached)) {
                /** @var list<string> $cached */
                $result[$contract] = $cached;
                continue;
            }

            $path = self::cw721TokensPath($contract, $wallet, null, self::TOKENS_PAGE_SIZE);
            if ($path === null) {
                // Unencodable / empty — treat as a failed first page.
                $result[$contract] = null;
                continue;
            }
            $missPaths[$contract] = $path;
        }

        if ($missPaths === []) {
            return $result;
        }

        // ONE same-host batch for all the misses. Index alignment is
        // preserved by iterating the ordered contract → path map.
        $contracts = array_keys($missPaths);
        $decoded   = $this->lcdGetBatch(array_values($missPaths));

        foreach ($contracts as $i => $contract) {
            // lcdGetBatch returns the FULL decoded LCD JSON (or null); the
            // wasm smart-query wraps the payload under `data` exactly like
            // wasmSmartQuery unwraps for the single path. A non-null
            // response without a well-formed `data` envelope is a failed/
            // malformed query → null (never "owns none").
            $response = $decoded[$i] ?? null;
            if ($response === null || !isset($response['data']) || !is_array($response['data'])) {
                $result[$contract] = null;
                continue;
            }
            $tokenIds = self::parseCw721TokensData($response['data']);
            $result[$contract] = $tokenIds;

            // Write successes back to the shared cache (empty cached; null
            // NOT cached — a failed query must never poison the TTL window
            // with a false empty, matching cw721Tokens).
            if ($tokenIds !== null) {
                $cacheKey = $this->cw721TokensCacheKey($contract, $wallet, null);
                wp_cache_set($cacheKey, $tokenIds, 'bcc_onchain', self::TOKENS_CACHE_TTL);
            }
        }

        return $result;
    }

    // ── CW-721 query helpers (V2 Phase 2, private) ────────────────────────

    /**
     * Generic CosmWasm contract-state smart query.
     *
     * Threads through {@see lcdGet} so it inherits ApiRetry + per-chain
     * CircuitBreaker behaviour. Wire format mirrors
     * {@see \BCC\Trust\Core\Services\wallet\BlockchainQueryService::isCosmosNftHolder}
     * (different domain, can't call directly because it bypasses the
     * breaker — see §11 scan finding).
     *
     * Returns the unwrapped `data` envelope on success, null on transport
     * / non-200 / unparseable JSON.
     *
     * @param array<string, mixed> $queryArr  e.g. ['tokens' => ['owner' => '...']]
     * @return array<string, mixed>|null
     */
    private function wasmSmartQuery(string $contractAddress, array $queryArr): ?array
    {
        $path = self::wasmSmartQueryPath($contractAddress, $queryArr);
        if ($path === null) {
            return null;
        }

        $response = $this->lcdGet($path);
        if (!is_array($response) || !isset($response['data']) || !is_array($response['data'])) {
            return null;
        }
        return $response['data'];
    }

    /**
     * Build the LCD smart-query PATH for a CosmWasm contract state query.
     *
     * Single source of truth for the base64/url-safe path encoding so the
     * single-request {@see wasmSmartQuery} and the batch {@see lcdGetBatch}
     * consumers share ONE encoding (§11 — no duplicated base64 logic).
     * Returns the leading-slash path (no host); the caller prefixes
     * `$this->rest_url`. Returns null on an empty contract or unencodable
     * query.
     *
     * Cosmos SDK wasm module expects the query JSON as base64 in the path:
     *   1. URL-safe alphabet (`-_` instead of `+/`) — a literal `/` in the
     *      encoded string would split the URL path at the wrong segment
     *      boundary.
     *   2. Padding `=` MUST be preserved — strict cosmos-sdk LCD parsers
     *      return 400 "illegal base64 data" on unpadded input (observed on
     *      Stargaze pre-migration); cosmos-hub's LCD happens to be lenient,
     *      but we can't rely on that across all chains.
     *
     * @param array<string, mixed> $queryArr e.g. ['tokens' => ['owner' => '...']]
     */
    private static function wasmSmartQueryPath(string $contractAddress, array $queryArr): ?string
    {
        if ($contractAddress === '') {
            return null;
        }

        $json = wp_json_encode($queryArr);
        if ($json === false) {
            return null;
        }

        $encoded = strtr(base64_encode($json), '+/', '-_');

        return '/cosmwasm/wasm/v1/contract/' . rawurlencode($contractAddress) . '/smart/' . $encoded;
    }

    /**
     * Build the CW-721 `tokens { owner, start_after, limit }` query array —
     * shared by the single-request {@see cw721Tokens} and the batch
     * {@see cw721TokensPath} so the wire shape can never drift between the
     * cold gallery path and the count/gate path.
     *
     * @return array{tokens: array{owner: string, limit: int, start_after?: string}}
     */
    private static function cw721TokensQuery(string $owner, ?string $startAfter, int $limit): array
    {
        $query = ['tokens' => ['owner' => $owner, 'limit' => max(1, min(100, $limit))]];
        if ($startAfter !== null && $startAfter !== '') {
            $query['tokens']['start_after'] = $startAfter;
        }
        return $query;
    }

    /**
     * LCD path for a CW-721 first-/next-page `tokens{owner}` query on a
     * given contract. Reuses {@see wasmSmartQueryPath} for the encoding so
     * the batch discovery pass and the single `cw721Tokens` produce byte-
     * identical paths for the same (contract, owner, cursor, limit).
     */
    private static function cw721TokensPath(string $contract, string $owner, ?string $startAfter = null, int $limit = self::TOKENS_PAGE_SIZE): ?string
    {
        if ($contract === '' || $owner === '') {
            return null;
        }
        return self::wasmSmartQueryPath($contract, self::cw721TokensQuery($owner, $startAfter, $limit));
    }

    /**
     * Cache key for a single `cw721Tokens` page — shared by the single-
     * request path and the batch discovery pass so a warm gallery load and
     * the single-path {@see count_holdings} read the SAME cached rows.
     */
    private function cw721TokensCacheKey(string $contract, string $owner, ?string $startAfter): string
    {
        return sprintf(
            'cw721_tokens_%d_%s_%s_%s',
            (int) $this->chain->id,
            strtolower($contract),
            strtolower($owner),
            $startAfter ?? ''
        );
    }

    /**
     * Parse a CW-721 `tokens` smart-query `data` envelope into a list of
     * token_id strings. Null-in → null (preserve the failed-query signal);
     * a successful envelope with no/empty `tokens` → `[]` (owns none).
     *
     * @param array<string, mixed>|null $data unwrapped `data` from the smart query
     * @return list<string>|null
     */
    private static function parseCw721TokensData(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }
        if (!isset($data['tokens']) || !is_array($data['tokens'])) {
            return [];
        }
        $out = [];
        foreach ($data['tokens'] as $t) {
            if (is_string($t) && $t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * CW-721 `tokens { owner, start_after, limit }` paginated fetch.
     * Returns ONE page of token_ids. Caller is responsible for paginating
     * via {@see cw721AllTokensForOwner}.
     *
     * Return contract (fail-open distinction):
     *   - `list<string>` (possibly empty) → SUCCESSFUL query. An empty
     *     list means "this page genuinely has no tokens for the owner."
     *   - `null` → the LCD smart-query FAILED (transport error, non-200,
     *     unparseable body, or breaker-open). Caller must treat this as
     *     "unknown," never as "owns none."
     *
     * @return list<string>|null
     */
    private function cw721Tokens(string $contract, string $owner, ?string $startAfter = null, int $limit = self::TOKENS_PAGE_SIZE): ?array
    {
        if ($contract === '' || $owner === '') {
            return [];
        }

        // Cache key per locked rule — explicit + readable for debugging.
        // "why does THIS wallet show stale holdings only on page 2?"
        // → grep `cw721_tokens_<chain>_<contract>_<wallet>_<cursor>` in
        //   the cache dump and the answer is one row. Shares the key with
        //   the batch discovery pass (cw721TokensCacheKey) so a warm
        //   gallery load and this single/gate path never diverge.
        $cacheKey = $this->cw721TokensCacheKey($contract, $owner, $startAfter);

        $cached = wp_cache_get($cacheKey, 'bcc_onchain');
        if (is_array($cached)) {
            /** @var list<string> $cached */
            return $cached;
        }

        $query = self::cw721TokensQuery($owner, $startAfter, $limit);

        // wasmSmartQuery returns null on transport / non-200 / unparseable
        // (it threads through lcdGet which inherits the per-chain
        // CircuitBreaker — a breaker-open chain yields null here). Surface
        // that as a failed page so the page-walk can distinguish "couldn't
        // verify" from "verified empty," and do NOT cache it (caching a
        // failed query would poison the TTL window with a false empty).
        $out = self::parseCw721TokensData($this->wasmSmartQuery($contract, $query));
        if ($out === null) {
            return null;
        }

        wp_cache_set($cacheKey, $out, 'bcc_onchain', self::TOKENS_CACHE_TTL);
        return $out;
    }

    /**
     * Walk every page of `cw721Tokens` for a (contract, owner) up to
     * PER_CONTRACT_TOKEN_CAP. Caller's `count_holdings` returns the count;
     * caller's `list_holdings` consumes the token_ids.
     *
     * Return contract (fail-open distinction):
     *   - `list<string>` (possibly empty) → the walk COMPLETED. An empty
     *     list means the owner genuinely holds none under this contract.
     *   - `null` → an LCD page query FAILED before we could finish (and
     *     before any early cap-return), so we could not verify holdings.
     *     A partial walk can't be trusted as a low/zero count, so we
     *     surface UNKNOWN rather than an undercount.
     *
     * Note: the early cap-return (≥ PER_CONTRACT_TOKEN_CAP) short-circuits
     * BEFORE any later-page failure can matter — once we know the owner
     * holds at least the cap, the gate verdict is already decided.
     *
     * @return list<string>|null
     */
    private function cw721AllTokensForOwner(string $contract, string $owner): ?array
    {
        $all       = [];
        $cursor    = null;
        for ($page = 0; $page < ceil(self::PER_CONTRACT_TOKEN_CAP / self::TOKENS_PAGE_SIZE); $page++) {
            $pageResult = $this->cw721Tokens($contract, $owner, $cursor, self::TOKENS_PAGE_SIZE);
            if ($pageResult === null) {
                // LCD query failed mid-walk — cannot complete verification.
                return null;
            }
            if ($pageResult === []) {
                break;
            }
            foreach ($pageResult as $tokenId) {
                $all[] = $tokenId;
                if (count($all) >= self::PER_CONTRACT_TOKEN_CAP) {
                    return $all;
                }
            }
            if (count($pageResult) < self::TOKENS_PAGE_SIZE) {
                break; // Last page (less-than-full result).
            }
            $cursor = $pageResult[count($pageResult) - 1];
        }
        return $all;
    }

    /**
     * CW-721 `nft_info { token_id }` — per-token metadata.
     *
     * Returns the §3.7 metadata superset: `name`, `description`,
     * `image_url`, `image_url_thumb`, `metadata_uri`, `attributes[]`.
     * Any field may be null; `attributes[]` is `[]` when no traits.
     *
     * Renamed + promoted to public in V2 Phase 6 (§H1) to match the
     * §4.17 contract verb (`fetchTokenMetadata`); the §H1 view-model
     * builder calls this directly read-time (Cosmos is read-time +
     * V1-transient per pattern-registry, no persistence).
     *
     * Cached for 7 days because NFT metadata is effectively static (the
     * extension on a given token_id rarely changes after mint). Empty
     * result is returned on transport failure WITHOUT caching so a
     * flaky LCD doesn't poison the 7-day window.
     *
     * @return array{
     *     name: ?string,
     *     description: ?string,
     *     image_url: ?string,
     *     image_url_thumb: ?string,
     *     metadata_uri: ?string,
     *     attributes: list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}>
     * }
     */
    public function fetchTokenMetadata(string $contract, string $tokenId): array
    {
        $empty = [
            'name'            => null,
            'description'     => null,
            'image_url'       => null,
            'image_url_thumb' => null,
            'metadata_uri'    => null,
            'attributes'      => [],
        ];
        if ($contract === '' || $tokenId === '') {
            return $empty;
        }

        $chainId  = (int) $this->chain->id;
        $cacheKey = sprintf(
            'cw721_nft_info_%d_%s_%s',
            $chainId,
            strtolower($contract),
            $tokenId
        );

        $cached = wp_cache_get($cacheKey, 'bcc_onchain');
        if (is_array($cached) && array_key_exists('name', $cached) && array_key_exists('attributes', $cached)) {
            /** @var array{name: ?string, description: ?string, image_url: ?string, image_url_thumb: ?string, metadata_uri: ?string, attributes: list<array{trait_type: string, value: string|int|float|bool, rarity_pct?: float}>} $cached */
            return $cached;
        }

        $data = $this->wasmSmartQuery($contract, ['nft_info' => ['token_id' => $tokenId]]);
        if ($data === null) {
            // Don't cache transport failures — a flaky LCD shouldn't poison
            // a 7-day cache window with empty results.
            return $empty;
        }

        $tokenUri  = is_string($data['token_uri'] ?? null) && $data['token_uri'] !== ''
            ? (string) $data['token_uri']
            : null;
        $extension = is_array($data['extension'] ?? null) ? $data['extension'] : [];
        $name = is_string($extension['name'] ?? null) && $extension['name'] !== ''
            ? (string) $extension['name']
            : null;
        $description = is_string($extension['description'] ?? null) && $extension['description'] !== ''
            ? (string) $extension['description']
            : null;
        $imageUrl = is_string($extension['image'] ?? null) && $extension['image'] !== ''
            ? (string) $extension['image']
            : null;

        // CW-721 has no canonical thumbnail field; mirror image_url per §3.7.
        $imageThumb = $imageUrl;

        // attributes[]: CW-721 standard puts these under
        // `extension.attributes[]` (most CW-721 contracts follow the
        // OpenSea convention). Defensive map; malformed entries dropped
        // silently.
        $attributes = [];
        $rawAttrs   = is_array($extension['attributes'] ?? null) ? $extension['attributes'] : [];
        foreach ($rawAttrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $traitType = $attr['trait_type'] ?? null;
            $value     = $attr['value']      ?? null;
            if (!is_string($traitType) || $traitType === '' || $value === null) {
                continue;
            }
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $attributes[] = [
                    'trait_type' => $traitType,
                    'value'      => $value,
                ];
            }
        }

        $out = [
            'name'            => $name,
            'description'     => $description,
            'image_url'       => $imageUrl,
            'image_url_thumb' => $imageThumb,
            'metadata_uri'    => $tokenUri,
            'attributes'      => $attributes,
        ];
        wp_cache_set($cacheKey, $out, 'bcc_onchain', self::NFT_INFO_CACHE_TTL);
        return $out;
    }

    /**
     * CW-721 collection-info smart query with a modern-variant fallback.
     *
     * Classic cw721 (≤ v0.18: Injective/Talis, most deployed contracts)
     * answers `contract_info`. cw721 v0.19+ renamed the variant to
     * `get_collection_info_and_extension` — the Stargaze collections
     * re-instantiated on the Cosmos Hub (cw721_migration, 2026) reject
     * `contract_info` outright with an unknown-variant error. Both shapes
     * carry top-level `name` / `symbol`, so callers parse one envelope.
     *
     * The LCD returns the unknown-variant error as a non-200, which
     * `wasmSmartQuery` folds into null — indistinguishable from a
     * transport failure. The fallback therefore fires on ANY null; on a
     * genuinely dead LCD the second call just fails the same way (and the
     * per-chain CircuitBreaker absorbs the doubled failure count).
     *
     * @return array<string, mixed>|null
     */
    private function cw721CollectionInfoQuery(string $contractAddress): ?array
    {
        $data = $this->wasmSmartQuery($contractAddress, ['contract_info' => new \stdClass()]);
        if ($data !== null) {
            return $data;
        }
        return $this->wasmSmartQuery(
            $contractAddress,
            ['get_collection_info_and_extension' => new \stdClass()]
        );
    }

    /**
     * Public CW-721 collection-info probe used by the admin
     * "Test CW-721 query" button on `VerifyCollectionsPage`. Returns
     * the unwrapped `data` envelope (typically `{name, symbol, …}`) on
     * success, null on transport / non-200 / non-CW-721 contracts.
     *
     * Catches three operator-facing failure modes pre-verify:
     *   1. Mis-pasted contract address (wasm 404 → null)
     *   2. Non-CW-721 contract (wasm responds but data shape mismatches)
     *   3. Chain has no CosmWasm enabled (Crypto.org → 501 → null)
     *
     * Stays public so it can be invoked from the admin layer without
     * exposing the generic `wasmSmartQuery` helper.
     *
     * @return array<string, mixed>|null
     */
    public function testCw721ContractInfo(string $contractAddress): ?array
    {
        return $this->cw721CollectionInfoQuery($contractAddress);
    }

    /**
     * V2 Phase 6 (§H1) — read-time CW-721 `contract_info` for the
     * NftPiece collection embed when no `bcc_onchain_collections` row
     * exists (Cosmos cold-cache).
     *
     * Returns the typed shape the §3.7 builder expects:
     *
     *     {name: ?string, symbol: ?string}
     *
     * Cached for 1 hour (collection_info is stable but not as static
     * as per-token metadata; admins can re-deploy a contract in
     * principle, though it's rare). Returns null on transport failure
     * WITHOUT caching so a flaky LCD doesn't poison the window.
     *
     * @return array{name: ?string, symbol: ?string}|null
     */
    public function fetchContractInfo(string $contract): ?array
    {
        if ($contract === '') {
            return null;
        }

        $chainId  = (int) $this->chain->id;
        $cacheKey = sprintf('cw721_contract_info_%d_%s', $chainId, strtolower($contract));

        $cached = wp_cache_get($cacheKey, 'bcc_onchain');
        if (is_array($cached) && array_key_exists('name', $cached) && array_key_exists('symbol', $cached)) {
            /** @var array{name: ?string, symbol: ?string} $cached */
            return $cached;
        }

        $data = $this->cw721CollectionInfoQuery($contract);
        if ($data === null) {
            // Don't cache transport failures.
            return null;
        }

        $name = is_string($data['name'] ?? null) && $data['name'] !== ''
            ? (string) $data['name']
            : null;
        $symbol = is_string($data['symbol'] ?? null) && $data['symbol'] !== ''
            ? (string) $data['symbol']
            : null;

        $out = ['name' => $name, 'symbol' => $symbol];
        wp_cache_set($cacheKey, $out, 'bcc_onchain', HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * V2 Phase 6 (§H1) — read-time CW-721 `owner_of` for the §3.7
     * NftPiece owner block. Cosmos has no persistent holdings index
     * (intentionally asymmetric per the V2 Phase 2 design); this
     * wraps the LCD round-trip with a 5-minute cache so a single
     * piece-detail render doesn't multiply LCD calls under burst.
     *
     * Returns the wallet address that owns `tokenId` on `contract`,
     * or null when the token does not exist / LCD timed out.
     *
     * Cache TTL is intentionally short (5m) — owners do change
     * (transfers, sales) and a stale "owner" reading would be
     * misleading on the piece detail page. The piece-metadata cache
     * (7d) is fine because metadata is effectively immutable; owner
     * is not.
     *
     * @return array{wallet_address: string}|null
     */
    public function cw721OwnerOf(string $contract, string $tokenId): ?array
    {
        if ($contract === '' || $tokenId === '') {
            return null;
        }

        $chainId  = (int) $this->chain->id;
        $cacheKey = sprintf(
            'cw721_owner_of_%d_%s_%s',
            $chainId,
            strtolower($contract),
            $tokenId
        );

        $cached = wp_cache_get($cacheKey, 'bcc_onchain');
        if (is_array($cached) && array_key_exists('wallet_address', $cached)) {
            /** @var array{wallet_address: string} $cached */
            return $cached;
        }

        $data = $this->wasmSmartQuery($contract, [
            'owner_of' => ['token_id' => $tokenId],
        ]);
        if ($data === null) {
            // Don't cache transport failures.
            return null;
        }

        $owner = is_string($data['owner'] ?? null) && $data['owner'] !== ''
            ? (string) $data['owner']
            : null;
        if ($owner === null) {
            return null;
        }

        $out = ['wallet_address' => $owner];
        wp_cache_set($cacheKey, $out, 'bcc_onchain', 5 * MINUTE_IN_SECONDS);
        return $out;
    }

    /**
     * Resolve the per-refresh contract cap. Defaults to
     * DEFAULT_CONTRACT_CAP (30) when the env constant is undefined.
     */
    private static function contractCap(): int
    {
        if (defined('BCC_COSMOS_HOLDINGS_CONTRACT_CAP')) {
            $cap = (int) constant('BCC_COSMOS_HOLDINGS_CONTRACT_CAP');
            if ($cap > 0 && $cap <= 200) {
                return $cap;
            }
        }
        return self::DEFAULT_CONTRACT_CAP;
    }

    // ── Delegations (by delegator account) ─────────────────────────────────

    /**
     * Fetch the set of validators this account delegates to.
     *
     * One paginated LCD call. Capped at 500 entries — well above any realistic
     * delegator's validator count, and the LCD endpoint's own page cap.
     *
     * Legacy shape: collapses transport failures to []. Callers that must
     * distinguish "no delegations" from "could not check" (the delegator-
     * community gate, which fails CLOSED on UNKNOWN) use
     * {@see fetch_delegations_result} instead.
     *
     * @return array<int, array{validator_address: string, shares: string|null, amount: float|null}>
     */
    public function fetch_delegations(string $delegatorAddress): array
    {
        return $this->fetch_delegations_result($delegatorAddress) ?? [];
    }

    /**
     * Nullable variant of {@see fetch_delegations}: preserves the
     * transport-failure signal instead of collapsing it to [].
     *
     *   - null → LCD unreachable / non-200 / unparseable (UNKNOWN —
     *            the caller must NOT read this as "no delegations").
     *   - []   → the LCD answered and the account delegates to nothing.
     *   - rows → the account's live delegation set.
     *
     * This distinction is load-bearing for ValidatorGroupGateService /
     * ValidatorGroupRevokeService: an LCD hiccup must fail-closed a join
     * (503, retry) and must NEVER trigger a revoke.
     *
     * @return array<int, array{validator_address: string, shares: string|null, amount: float|null}>|null
     */
    public function fetch_delegations_result(string $delegatorAddress): ?array
    {
        $response = $this->lcdGet("/cosmos/staking/v1beta1/delegations/{$delegatorAddress}", [
            'pagination.limit' => 500,
        ]);

        if ($response === null) {
            // Transport failure — preserve UNKNOWN.
            return null;
        }

        if (empty($response['delegation_responses'])) {
            return [];
        }

        $rows = [];
        foreach ($response['delegation_responses'] as $item) {
            $validator = $item['delegation']['validator_address'] ?? null;
            if (!$validator) {
                continue;
            }

            $shares    = $item['delegation']['shares'] ?? null;
            $rawAmount = $item['balance']['amount'] ?? null;

            $rows[] = [
                'validator_address' => $validator,
                'shares'            => is_string($shares) ? $shares : null,
                'amount'            => is_string($rawAmount) ? $this->tokensToDisplay($rawAmount) : null,
            ];
        }

        return $rows;
    }

    // ── NFT Collections (per-chain discovery) ─────────────────────────────

    /**
     * V1 wallet-link discovery: which CW-721 collections does this
     * wallet hold?
     *
     * The LCD cannot answer this (wasmd has no owner→contracts index),
     * so the Cosmos Hub rides the Stargaze marketplace indexer via
     * {@see StargazeMarketplaceApi::profileCollections}. Other cosmos
     * chains return [] — no indexer covers them.
     *
     * Called from WalletSeedService at wallet-verify time; rows land in
     * wp_bcc_onchain_collections as source='discovery', is_verified=0
     * (schema defaults), so a linked wallet's collections surface on the
     * Verify Collections queue instead of staying invisible until the
     * operator happens to know them. Verification stays operator-gated:
     * a discovery row grants nothing until the admin flips it (and the
     * Test CW-721 probe validates the contract against the LCD first).
     *
     * Best-effort by design: API unreachable → [] (the seed path treats
     * discovery as an enhancement, never a link-time dependency).
     *
     * @return array<int, array<string, mixed>> Normalized rows for CollectionRepository::upsert().
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        $chainId = $chainId ?: (int) $this->chain->id;

        if ((string) ($this->chain->slug ?? '') !== 'cosmos') {
            return [];
        }

        $rollup = \BCC\Trust\Onchain\Support\StargazeMarketplaceApi::profileCollections($walletAddress);
        if ($rollup === null || $rollup === []) {
            return [];
        }

        // Cap discovery per wallet — a junk-stuffed wallet shouldn't
        // flood the admin queue. Largest holdings first: those are the
        // collections the user demonstrably cares about.
        usort($rollup, static fn(array $a, array $b): int => $b['owned_count'] <=> $a['owned_count']);
        $rollup = array_slice($rollup, 0, self::DISCOVERY_COLLECTION_CAP);

        $collections = [];
        foreach ($rollup as $c) {
            // Spam gate — parity with EvmFetcher's discovery pipeline
            // (minus the upstream isSpam field Alchemy provides there).
            // NftSpamFilter folds in the operator rule table: RULE_DENY
            // drops unconditionally — this is what makes admin-hidden
            // collections STAY hidden across rediscovery — RULE_ALLOW
            // bypasses the name heuristics.
            if (\BCC\Trust\Onchain\Services\NftSpamFilter::isSpam($chainId, $c['contract_address'], $c['collection_name'])) {
                continue;
            }
            $collections[] = [
                'contract_address'   => $c['contract_address'],
                'collection_name'    => $c['collection_name'],
                'chain_id'           => $chainId,
                'token_standard'     => 'CW-721',
                'total_supply'       => $c['total_supply'],
                'floor_price'        => null,
                'floor_currency'     => null,
                'total_volume'       => null,
                'unique_holders'     => null,
                'listed_percentage'  => null,
                'royalty_percentage' => null,
                'metadata_storage'   => null,
                'image_url'          => $c['image_url'],
            ];
        }

        return $collections;
    }

    /**
     * Per-chain dispatcher for "top NFT collections" discovery.
     *
     * Each Cosmos chain needs its own data source — there is no Cosmos-wide
     * marketplace indexer covering all chains.
     *
     *   - Injective keeps on-chain enumeration of the Talis Collection
     *     Whitelist contract (no API key, no third-party dependency —
     *     DappRadar shut down in November 2025). A curated whitelist is
     *     higher-signal than raw code-ID enumeration, so it wins here.
     *   - Every other Cosmos chain falls through to chain-native wasmd
     *     code-ID enumeration ({@see fetchTopCollectionsViaCodeIdEnumeration}).
     *
     * History: Stargaze had a Constellations-GraphQL discovery path here
     * until the 2026 Stargaze → Cosmos Hub migration killed the public API
     * (indexer access went partner-only). The migrated collections are all
     * still on-chain — they were re-instantiated from a handful of CW-721
     * code IDs — so `/cosmwasm/wasm/v1/code/{id}/contracts` replaces the
     * lost indexer without any third party.
     *
     * @param int $limit Max collections to return.
     * @return array<int, array<string, mixed>> Normalized rows for CollectionRepository::bulkUpsert().
     */
    public function fetch_top_collections(int $limit = 100): array
    {
        $slug = (string) ($this->chain->slug ?? '');

        switch ($slug) {
            case 'injective':
                return $this->fetchTopCollectionsInjectiveViaTalisWhitelist($limit);
            default:
                return $this->fetchTopCollectionsViaCodeIdEnumeration($limit);
        }
    }

    /**
     * Injective top NFT collections via on-chain enumeration of the Talis
     * Collection Whitelist contract.
     *
     * Talis Protocol's whitelist contract (inj1s6areevunx3u32gnn5dpnxg40tpuf8hzurpvka,
     * label "Talis Whitelist", code_id 1099) returns the list of every approved
     * Talis NFT contract on Injective via the `{whitelist: {limit, start_after}}`
     * smart query. Returns up to 30 entries per page (server-side cap). We walk
     * the list up to BCC_TALIS_WHITELIST_PAGE_CAP pages per cron cycle (default
     * 20 pages = 600 contracts) and enrich each address with `contract_info`
     * for the collection name. No API key needed; reuses the existing
     * `wasmSmartQuery` helper and `fetchContractInfo` (cached) so per-address
     * costs are bounded across cycles.
     *
     * The whitelist exposes NO ranking / volume / holder-count signal — entries
     * are sorted lexicographically by contract address. New rows land with
     * `is_verified=0`; the existing VerifyCollectionsPage admin gate handles
     * curation (this is the posture DappRadar would have served before its
     * November 2025 shutdown).
     *
     * Override the contract address via BCC_TALIS_WHITELIST_CONTRACT and the
     * page cap via BCC_TALIS_WHITELIST_PAGE_CAP.
     *
     * @param int $limit Max collections to return (caller-side cap, applied
     *                   after the page walk).
     * @return array<int, array<string, mixed>>
     */
    private function fetchTopCollectionsInjectiveViaTalisWhitelist(int $limit): array
    {
        $chainId = (int) $this->chain->id;

        $whitelistContract = defined('BCC_TALIS_WHITELIST_CONTRACT')
            ? (string) BCC_TALIS_WHITELIST_CONTRACT
            : 'inj1s6areevunx3u32gnn5dpnxg40tpuf8hzurpvka';
        $pageCap = defined('BCC_TALIS_WHITELIST_PAGE_CAP')
            ? max(1, (int) BCC_TALIS_WHITELIST_PAGE_CAP)
            : 20;
        $pageSize = 30;

        $contracts = [];
        $cursor    = null;

        for ($page = 0; $page < $pageCap; $page++) {
            $params = ['limit' => $pageSize];
            if ($cursor !== null) {
                $params['start_after'] = $cursor;
            }

            $resp = $this->wasmSmartQuery($whitelistContract, ['whitelist' => $params]);
            if (!is_array($resp)) {
                \BCC\Core\Log\Logger::warning(
                    '[Cosmos Fetcher] Talis whitelist query failed at page ' . $page
                );
                break;
            }

            $batch = $resp['whitelist'] ?? [];
            if (!is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $addr) {
                if (is_string($addr) && $addr !== '') {
                    $contracts[] = $addr;
                }
            }

            if (count($batch) < $pageSize) {
                break;
            }

            $last = end($batch);
            if (!is_string($last) || $last === '') {
                break;
            }
            $cursor = $last;
        }

        if ($contracts === []) {
            return [];
        }

        $contracts = array_slice($contracts, 0, max(1, $limit));

        $collections = [];
        foreach ($contracts as $contract) {
            $info = $this->fetchContractInfo($contract);
            if ($info === null) {
                continue;
            }
            $name = $info['name'] ?? null;
            $collections[] = [
                'contract_address'   => $contract,
                'chain_id'           => $chainId,
                'collection_name'    => is_string($name) ? $name : null,
                'token_standard'     => 'CW-721',
                'total_supply'       => null,
                'floor_price'        => null,
                'floor_currency'     => null,
                'unique_holders'     => null,
                'total_volume'       => null,
                'listed_percentage'  => null,
                'royalty_percentage' => null,
                'metadata_storage'   => null,
                'image_url'          => null,
            ];
        }

        return $collections;
    }

    // ── CW-721 discovery via wasmd code-ID enumeration ───────────────────────

    /**
     * Server-side page size for `/cosmwasm/wasm/v1/code/{id}/contracts`.
     * wasmd caps a page at 100 regardless of the `pagination.limit` asked
     * for, so requesting more just wastes the round trip.
     */
    private const CW721_PAGE_SIZE = 100;

    /** Pages walked per cron cycle when BCC_CW721_PAGE_CAP is undefined. */
    private const CW721_DEFAULT_PAGE_CAP = 5;

    /** Curated rows sampled to learn a chain's CW-721 code IDs. */
    private const CW721_CODE_ID_SAMPLE = 25;

    /** Code IDs never change once a wasm binary is stored — cache hard. */
    private const CW721_CODE_IDS_TTL = 7 * DAY_IN_SECONDS;

    /**
     * Shorter TTL for a probed-but-empty result (chain has curated rows but
     * none resolved a code ID — e.g. no CosmWasm module). Keeps the wasted
     * probe to once a day while letting a newly-curated collection start
     * discovery within a day rather than a week.
     */
    private const CW721_CODE_IDS_EMPTY_TTL = DAY_IN_SECONDS;

    /** Per-contract code-ID cache: `contract_info.code_id` is immutable. */
    private const CW721_CODE_ID_TTL = 7 * DAY_IN_SECONDS;

    private const CW721_CODE_IDS_TRANSIENT_PREFIX = 'bcc_trust_cw721_code_ids_';

    private const CW721_SCAN_OPTION_PREFIX = 'bcc_trust_cw721_scan_';

    /**
     * Chain-native CW-721 collection discovery via wasmd code-ID enumeration.
     *
     * Replaces the Constellations/Stargaze indexer that went partner-only
     * after the 2026 Stargaze → Cosmos Hub migration. The migrated
     * collections were re-instantiated on the Hub from a handful of CW-721
     * code IDs, and wasmd will happily list every contract instantiated from
     * a code ID over the SAME LCD already configured in
     * `wp_bcc_chains.rest_url` — no API key, no third party, no indexer:
     *
     *     GET /cosmwasm/wasm/v1/contract/{addr}            → contract_info.code_id
     *     GET /cosmwasm/wasm/v1/code/{id}/contracts        → contracts[] + next_key
     *
     * Measured on the Hub: the "Bad Kids" contract resolves to code ID 434,
     * and that code ID enumerates 3,431 contracts across 35 pages — i.e. the
     * whole migrated Stargaze universe.
     *
     * Because 3,431 contracts cannot be enriched in one cron cycle, the walk
     * is BOUNDED (BCC_CW721_PAGE_CAP pages per cycle, default 5 = 500
     * contracts) and RESUMABLE: the {code_id, next_key} cursor is persisted
     * per chain in an autoload=false option, so each cycle continues where
     * the last one stopped instead of re-walking page 1 forever. When every
     * code ID is exhausted the cursor wraps to the first, so contracts
     * instantiated since the last sweep are still picked up.
     *
     * Rows land `is_verified = 0` and are gate-irrelevant until an operator
     * verifies them on the Verify Collections page. That curated-only
     * posture is exactly what makes broad discovery safe.
     *
     * @param int $limit Max collections to return this cycle.
     * @return array<int, array<string, mixed>> Normalized rows for CollectionRepository::bulkUpsert().
     */
    private function fetchTopCollectionsViaCodeIdEnumeration(int $limit): array
    {
        if (!self::cw721DiscoveryEnabled()) {
            return [];
        }

        $chainId = (int) ($this->chain->id ?? 0);
        if ($chainId <= 0) {
            return [];
        }

        $limit = max(1, $limit);

        $codeIds = $this->cw721CodeIdsForChain();
        if ($codeIds === []) {
            // No curated collections to learn from and no operator override:
            // this chain discovers nothing. That is the correct outcome, not
            // an error — the "Add Cosmos collection" admin form seeds the
            // first row and the next sweep bootstraps from it.
            return [];
        }

        $pageCap = self::cw721PageCap();
        $start   = self::resolveScanStart($codeIds, self::readScanState($chainId));
        $codeId  = $start['code_id'];
        $key     = $start['next_key'];

        $collections = [];

        for ($page = 0; $page < $pageCap; $page++) {
            $pageStartKey = $key;
            $result       = $this->enumerateContractsForCodeId($codeId, $pageStartKey);

            // Skip already-stored contracts BEFORE enrichment and upsert.
            // This is a CORRECTNESS requirement, not an optimization:
            // bulkUpsert's ON DUPLICATE KEY UPDATE assigns
            // collection_name = VALUES(collection_name) and
            // image_url = VALUES(image_url), and discovery rows carry NULL
            // for both — re-upserting an operator-curated row would wipe its
            // name and artwork. (source='manual' survives; the display
            // fields do not.)
            $fresh = $this->filterUnknownContracts($chainId, $result['contracts']);

            $stoppedMidPage = false;
            foreach ($fresh as $contract) {
                if (count($collections) >= $limit) {
                    $stoppedMidPage = true;
                    break;
                }

                // A contract instantiated from a CW-721 code ID is not
                // necessarily a live CW-721 (failed instantiations, non-NFT
                // forks). `contract_info` answering is the natural filter.
                $info = $this->fetchContractInfo($contract);
                if ($info === null) {
                    continue;
                }

                $name = $info['name'];
                // Row shape mirrors fetchTopCollectionsInjectiveViaTalisWhitelist
                // verbatim so bulkUpsert sees one shape from this fetcher.
                $collections[] = [
                    'contract_address'   => $contract,
                    'chain_id'           => $chainId,
                    'collection_name'    => is_string($name) ? $name : null,
                    'token_standard'     => 'CW-721',
                    'total_supply'       => null,
                    'floor_price'        => null,
                    'floor_currency'     => null,
                    'unique_holders'     => null,
                    'total_volume'       => null,
                    'listed_percentage'  => null,
                    'royalty_percentage' => null,
                    'metadata_storage'   => null,
                    'image_url'          => null,
                ];
            }

            if ($stoppedMidPage) {
                // Leave the cursor ON this page so its remainder is picked up
                // next cycle rather than skipped. The rows we already emitted
                // will be filtered out as known on the re-walk.
                $key = $pageStartKey;
                break;
            }

            $advanced = self::nextScanState($codeIds, $codeId, $result['next_key']);
            $codeId   = $advanced['code_id'];
            $key      = $advanced['next_key'];

            if (count($collections) >= $limit) {
                break;
            }
        }

        self::writeScanState($chainId, $codeId, $key);

        return $collections;
    }

    /**
     * Kill switch. Default ON — discovery is bounded, resumable and lands
     * unverified rows only, so it is safe to run broadly. Define
     * BCC_CW721_DISCOVERY_ENABLED as false to stop it dead.
     */
    private static function cw721DiscoveryEnabled(): bool
    {
        if (!defined('BCC_CW721_DISCOVERY_ENABLED')) {
            return true;
        }
        return (bool) BCC_CW721_DISCOVERY_ENABLED;
    }

    /** Pages walked per cycle (mirrors the BCC_TALIS_WHITELIST_PAGE_CAP idiom). */
    private static function cw721PageCap(): int
    {
        if (defined('BCC_CW721_PAGE_CAP')) {
            return max(1, (int) BCC_CW721_PAGE_CAP);
        }
        return self::CW721_DEFAULT_PAGE_CAP;
    }

    /**
     * The CW-721 code IDs worth enumerating on THIS chain.
     *
     * Two sources, in order:
     *
     *   1. BCC_CW721_CODE_IDS — a JSON object mapping chain slug → list of
     *      code IDs, e.g. `{"cosmos":[434]}`. This is the bootstrap path for
     *      a chain that has no curated collection to learn from yet.
     *   2. Otherwise, derived from what the operator ALREADY curated: sample
     *      up to 25 known rows and resolve each contract's code ID. NFT
     *      collections cluster on very few code IDs (one per launchpad
     *      version), so a small sample finds essentially all of them.
     *
     * Cached per chain — code IDs are effectively static.
     *
     * @return list<int>
     */
    private function cw721CodeIdsForChain(): array
    {
        $override = self::cw721CodeIdOverride((string) ($this->chain->slug ?? ''));
        if ($override !== null) {
            return $override;
        }

        $chainId   = (int) ($this->chain->id ?? 0);
        $transient = self::CW721_CODE_IDS_TRANSIENT_PREFIX . $chainId;

        $cached = get_transient($transient);
        if (is_array($cached)) {
            return self::normalizeCodeIds($cached);
        }

        $known = \BCC\Trust\Onchain\Repositories\CollectionRepository::listKnownByChain(
            $chainId,
            self::CW721_CODE_ID_SAMPLE
        );
        if ($known === []) {
            // Nothing to probe — zero cost, so nothing worth caching either
            // (and the operator's first curated row takes effect at once).
            return [];
        }

        $codeIds = [];
        foreach ($known as $row) {
            $contract = (string) ($row->contract_address ?? '');
            if ($contract === '') {
                continue;
            }
            $codeId = $this->fetchWasmCodeId($contract);
            if ($codeId !== null) {
                $codeIds[] = $codeId;
            }
        }

        $codeIds = self::normalizeCodeIds($codeIds);

        set_transient(
            $transient,
            $codeIds,
            $codeIds === [] ? self::CW721_CODE_IDS_EMPTY_TTL : self::CW721_CODE_IDS_TTL
        );

        return $codeIds;
    }

    /**
     * Operator override for this chain's CW-721 code IDs.
     *
     * Returns null when the constant is absent OR carries no entry for this
     * slug — both mean "fall through to derivation". Returns a (possibly
     * empty) list when the slug IS present, so an operator can explicitly
     * pin discovery off for one chain with `{"akash":[]}`.
     *
     * @return list<int>|null
     */
    private static function cw721CodeIdOverride(string $slug): ?array
    {
        if ($slug === '' || !defined('BCC_CW721_CODE_IDS')) {
            return null;
        }

        /** @var mixed $raw */
        $raw = BCC_CW721_CODE_IDS;
        $map = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($map)) {
            return null;
        }

        $entry = $map[$slug] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        return self::normalizeCodeIds($entry);
    }

    /**
     * PURE. Coerce a mixed list into a deterministic, deduplicated,
     * ascending list of positive code IDs.
     *
     * @param array<mixed> $raw
     * @return list<int>
     */
    private static function normalizeCodeIds(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            if (!is_int($value) && !is_string($value) && !is_float($value)) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                // Keyed by id → dedupe; sort() below reindexes to a list.
                $ids[$id] = $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Resolve a contract's wasm code ID via `/cosmwasm/wasm/v1/contract/{addr}`.
     *
     * NOTE this is NOT {@see fetchContractInfo}: that one is the CW-721
     * `contract_info` SMART query (name/symbol from contract state). This is
     * the wasm module's own metadata for the instantiated contract, and it is
     * the only one carrying `code_id`.
     *
     * Cached for 7 days — a contract's code ID only changes on migration,
     * which is rare enough that a week of staleness costs nothing.
     */
    private function fetchWasmCodeId(string $contract): ?int
    {
        if ($contract === '') {
            return null;
        }

        $chainId  = (int) ($this->chain->id ?? 0);
        $cacheKey = sprintf('cw721_code_id_%d_%s', $chainId, strtolower($contract));

        $cached = wp_cache_get($cacheKey, 'bcc_onchain');
        if (is_int($cached) && $cached > 0) {
            return $cached;
        }

        $data = $this->lcdGet('/cosmwasm/wasm/v1/contract/' . rawurlencode($contract));

        $codeId = self::parseWasmCodeId($data);
        if ($codeId === null) {
            // Don't cache transport failures / non-wasm contracts.
            return null;
        }

        wp_cache_set($cacheKey, $codeId, 'bcc_onchain', self::CW721_CODE_ID_TTL);

        return $codeId;
    }

    /**
     * PURE. `{contract_info: {code_id: "434", …}}` → 434.
     *
     * wasmd serialises uint64 as a JSON STRING, so the cast is load-bearing.
     *
     * @param array<string, mixed>|null $data
     */
    private static function parseWasmCodeId(?array $data): ?int
    {
        if ($data === null) {
            return null;
        }

        $info = $data['contract_info'] ?? null;
        if (!is_array($info)) {
            return null;
        }

        $raw = $info['code_id'] ?? null;
        if (!is_int($raw) && !is_string($raw) && !is_float($raw)) {
            return null;
        }

        $codeId = (int) $raw;

        return $codeId > 0 ? $codeId : null;
    }

    /**
     * One page of `/cosmwasm/wasm/v1/code/{id}/contracts`.
     *
     * Never throws: a transport failure, a non-200 or an unparseable body all
     * collapse to `{contracts: [], next_key: null}`, which the caller reads
     * as "this code ID is done" and advances past. Worst case a blip costs
     * one page until the cursor wraps around — acceptable for a discovery
     * backfill that re-sweeps every cycle.
     *
     * @return array{contracts: list<string>, next_key: string|null}
     */
    private function enumerateContractsForCodeId(int $codeId, ?string $startKey): array
    {
        if ($codeId <= 0) {
            return ['contracts' => [], 'next_key' => null];
        }

        $params = ['pagination.limit' => self::CW721_PAGE_SIZE];
        if ($startKey !== null && $startKey !== '') {
            $params['pagination.key'] = $startKey;
        }

        $data = $this->lcdGet('/cosmwasm/wasm/v1/code/' . $codeId . '/contracts', $params);

        return self::parseContractsPage($data);
    }

    /**
     * PURE. `{contracts: [...], pagination: {next_key: "…"}}` → the page.
     *
     * An absent, null or empty-string `next_key` all mean "last page" — the
     * LCD is inconsistent about which it sends, so all three normalize to
     * null.
     *
     * @param array<string, mixed>|null $data
     * @return array{contracts: list<string>, next_key: string|null}
     */
    private static function parseContractsPage(?array $data): array
    {
        $empty = ['contracts' => [], 'next_key' => null];

        if ($data === null) {
            return $empty;
        }

        $raw = $data['contracts'] ?? null;
        if (!is_array($raw)) {
            return $empty;
        }

        $contracts = [];
        foreach ($raw as $addr) {
            if (is_string($addr) && $addr !== '') {
                $contracts[] = $addr;
            }
        }

        $nextKey   = null;
        $pagination = $data['pagination'] ?? null;
        if (is_array($pagination)) {
            $candidate = $pagination['next_key'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $nextKey = $candidate;
            }
        }

        return ['contracts' => $contracts, 'next_key' => $nextKey];
    }

    /**
     * Drop contracts this chain already has a collection row for.
     *
     * Reuses {@see CollectionRepository::verifiedMapForContracts}, whose map
     * contains a key for EVERY contract that has a row (the bool payload is
     * the verification flag we don't need here). Presence-as-known is exact
     * and bounded by the page size — strictly safer than a LIMITed
     * "all known addresses" query, which could truncate the known set and
     * silently re-admit a curated row into the clobber path.
     *
     * @param list<string> $contracts
     * @return list<string>
     */
    private function filterUnknownContracts(int $chainId, array $contracts): array
    {
        if ($contracts === []) {
            return [];
        }

        $known = \BCC\Trust\Onchain\Repositories\CollectionRepository::verifiedMapForContracts(
            $chainId,
            $contracts
        );

        return self::rejectKnownContracts($contracts, $known);
    }

    /**
     * PURE. The anti-clobber filter: any address already present in the known
     * map is dropped before enrichment or upsert.
     *
     * Both sides are lowercased defensively — the schema stores canonical
     * lowercase (EVM), and Cosmos bech32 is lowercase by construction, but a
     * mixed-case address arriving from an LCD must not slip past the filter.
     *
     * @param list<string>        $contracts
     * @param array<string, bool> $knownMap keyed by lowercase contract address
     * @return list<string>
     */
    private static function rejectKnownContracts(array $contracts, array $knownMap): array
    {
        $normalizedKnown = [];
        foreach ($knownMap as $address => $_ignored) {
            $normalizedKnown[strtolower((string) $address)] = true;
        }

        $out  = [];
        $seen = [];
        foreach ($contracts as $contract) {
            $lower = strtolower($contract);
            if ($lower === '' || isset($normalizedKnown[$lower]) || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $out[]        = $contract;
        }

        return $out;
    }

    // ── Resumable scan cursor ────────────────────────────────────────────────

    /**
     * PURE. Where this cycle picks up.
     *
     * A stored code ID that is no longer in the resolved list (operator
     * changed the override, or the sample resolved differently) is discarded
     * and the sweep restarts at the first code ID.
     *
     * @param non-empty-list<int>                                       $codeIds
     * @param array{code_id: int, next_key: string|null}|null           $state
     * @return array{code_id: int, next_key: string|null}
     */
    private static function resolveScanStart(array $codeIds, ?array $state): array
    {
        if ($state !== null && in_array($state['code_id'], $codeIds, true)) {
            return ['code_id' => $state['code_id'], 'next_key' => $state['next_key']];
        }

        return ['code_id' => $codeIds[0], 'next_key' => null];
    }

    /**
     * PURE. The cursor state machine.
     *
     *   - `$pageNextKey` non-null → more pages under this code ID; stay put.
     *   - `$pageNextKey` null     → this code ID is exhausted; advance to the
     *                               next code ID in the list.
     *   - last code ID exhausted  → wrap to the first, so contracts
     *                               instantiated since the last sweep are
     *                               still discovered.
     *
     * @param non-empty-list<int> $codeIds
     * @return array{code_id: int, next_key: string|null}
     */
    private static function nextScanState(array $codeIds, int $currentCodeId, ?string $pageNextKey): array
    {
        if ($pageNextKey !== null && $pageNextKey !== '') {
            return ['code_id' => $currentCodeId, 'next_key' => $pageNextKey];
        }

        $index = array_search($currentCodeId, $codeIds, true);
        if ($index === false) {
            return ['code_id' => $codeIds[0], 'next_key' => null];
        }

        $next = ((int) $index + 1) % count($codeIds);

        return ['code_id' => $codeIds[$next], 'next_key' => null];
    }

    /**
     * Persisted scan cursor for a chain.
     *
     * Options API (not raw $wpdb) keeps §1 satisfied without a repository for
     * what is a single scalar blob. autoload=false — cron-only state has no
     * business on every page load.
     *
     * @return array{code_id: int, next_key: string|null}|null
     */
    private static function readScanState(int $chainId): ?array
    {
        $raw = get_option(self::CW721_SCAN_OPTION_PREFIX . $chainId, null);
        if (!is_array($raw)) {
            return null;
        }

        $codeId = $raw['code_id'] ?? null;
        if (!is_int($codeId) && !is_string($codeId)) {
            return null;
        }
        $codeId = (int) $codeId;
        if ($codeId <= 0) {
            return null;
        }

        $nextKey = $raw['next_key'] ?? null;
        $nextKey = is_string($nextKey) && $nextKey !== '' ? $nextKey : null;

        return ['code_id' => $codeId, 'next_key' => $nextKey];
    }

    private static function writeScanState(int $chainId, int $codeId, ?string $nextKey): void
    {
        update_option(
            self::CW721_SCAN_OPTION_PREFIX . $chainId,
            [
                'code_id'    => $codeId,
                'next_key'   => $nextKey,
                'updated_at' => time(),
            ],
            false
        );
    }

    // ── Internal Helpers ─────────────────────────────────────────────────────

    /**
     * @param array<string, string|int> $params
     * @return array<string, mixed>|null
     */
    private function lcdGet(string $path, array $params = []): ?array
    {
        $chainId = (int) ($this->chain->id ?? 0);

        $url = $this->rest_url . $path;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = ApiRetry::get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ], [
            'label'    => 'Cosmos LCD ' . $path,
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[Cosmos Fetcher] LCD error for ' . $path . ': ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[Cosmos Fetcher] LCD ' . $code . ' for ' . $path);
            return null;
        }

        // API call tracking is handled by ApiRetry::request() for all fetchers.

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    /** Batch timeout for the parallel discovery wave — some LCD queries are
     * slow, so we run well above the primitive's 3s default but under its
     * 30s hard cap. Kept as a constant so the tuning is one grep away. */
    private const BATCH_LCD_TIMEOUT = 10;

    /**
     * Concurrent same-host LCD GET for N paths on THIS chain.
     *
     * Given N LCD paths (leading-slash, e.g. from {@see cw721TokensPath}),
     * builds the full same-host URLs (`$this->rest_url . $path`) and fetches
     * them concurrently via {@see ApiRetry::getBatchSameHost} (which wraps
     * the curl_multi primitive + per-chain CircuitBreaker). Returns an
     * index-aligned array of decoded `array|null` with the SAME null
     * semantics as {@see lcdGet}: a non-200, a transport WP_Error, or an
     * unparseable body all decode to null for THAT index only. A per-URL
     * mix of 200s and 404s is normal (some contracts 404 a `tokens` query);
     * the breaker treats the batch as a host success as long as the host
     * answered at all.
     *
     * @param list<string> $paths leading-slash LCD paths, all on this chain's host
     * @return array<int, array<string, mixed>|null> index-aligned with $paths
     */
    private function lcdGetBatch(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $chainId = (int) ($this->chain->id ?? 0);
        $urls    = [];
        foreach ($paths as $path) {
            $urls[] = $this->rest_url . $path;
        }

        $responses = ApiRetry::getBatchSameHost(
            $urls,
            [
                'timeout' => self::BATCH_LCD_TIMEOUT,
                'headers' => ['Accept' => 'application/json'],
            ],
            [
                'label'    => 'Cosmos LCD batch',
                'chain_id' => $chainId,
            ]
        );

        $out = [];
        foreach (array_keys($urls) as $i) {
            $res = $responses[$i] ?? null;
            $out[$i] = $this->decodeBatchEntry($res);
        }
        return $out;
    }

    /**
     * Decode one {@see ApiRetry::getBatchSameHost} entry into the same
     * shape {@see lcdGet} returns: null on WP_Error / non-200 / unparseable,
     * otherwise the decoded array.
     *
     * @param array{code: int, body: string}|\WP_Error|null $entry
     * @return array<string, mixed>|null
     */
    private function decodeBatchEntry($entry): ?array
    {
        // A success entry is array{code,body}; a failure is a \WP_Error
        // object (SSRF block / transport / breaker-open) or null (missing).
        if (!is_array($entry)) {
            return null;
        }

        $code = (int) ($entry['code'] ?? 0);
        if ($code !== 200) {
            return null;
        }

        $data = json_decode((string) ($entry['body'] ?? ''), true);
        return is_array($data) ? $data : null;
    }

    private function tokensToDisplay(string $amount): float
    {
        // Bigint-safe divide: string amounts can exceed PHP float precision (2^53)
        // for 18-decimal Cosmos chains. Use BCMath when available so the divide
        // happens on the raw string BEFORE the final float cast, preserving as
        // many significant digits as PHP's float can hold.
        if (function_exists('bcdiv') && preg_match('/^-?\d+$/', $amount) === 1) {
            $divisor = bcpow('10', (string) (int) $this->decimals);
            $display = bcdiv($amount, $divisor, 6);
            return (float) $display;
        }
        return round((float) $amount / pow(10, $this->decimals), 6);
    }

    private function ensureValoperPrefix(string $address): string
    {
        if (strpos($address, 'valoper') !== false) {
            return $address;
        }

        // Use bech32_prefix from chain config (DB-driven, no code change needed
        // to add new Cosmos chains). Falls back to hardcoded map for chains
        // where the config hasn't been populated yet.
        $bech32Prefix = $this->chain->bech32_prefix ?? null;

        // Validate: bech32 HRPs are strictly lowercase alpha. A bad DB value
        // here would produce broken addresses that silently fail LCD lookups.
        if ($bech32Prefix && !preg_match('/^[a-z]+$/', $bech32Prefix)) {
            $bech32Prefix = null; // fall through to hardcoded map
        }

        if ($bech32Prefix) {
            $pos = strpos($address, '1');
            if ($pos !== false) {
                $existingPrefix = substr($address, 0, $pos);
                return str_replace($existingPrefix . '1', $bech32Prefix . 'valoper1', $address);
            }
        }

        return $address;
    }

    private function parseStatus(string $status): string
    {
        $map = [
            'BOND_STATUS_BONDED'    => 'active',
            'BOND_STATUS_UNBONDED'  => 'inactive',
            'BOND_STATUS_UNBONDING' => 'inactive',
        ];

        return $map[$status] ?? 'unknown';
    }

    /** @param array<string, mixed> $val */
    private function fetchUptime(array $val): ?float
    {
        $cons_key = $val['consensus_pubkey']['key'] ?? null;
        if (!$cons_key) {
            return null;
        }

        // Derive the valcons address from the consensus pubkey so we can
        // match against the signing_infos "address" field.
        // Cosmos SDK: valcons_address = bech32(prefix + "valcons", SHA256(pubkey)[:20])
        $cons_address = $this->deriveConsensusAddress($cons_key, $val['operator_address'] ?? '');
        if (!$cons_address) {
            return null;
        }

        // Use the single-validator signing info endpoint (no pagination needed).
        $signing_info = $this->lcdGet("/cosmos/slashing/v1beta1/signing_infos/{$cons_address}");

        if (!$signing_info || !isset($signing_info['val_signing_info'])) {
            return null;
        }

        $missed = (int) ($signing_info['val_signing_info']['missed_blocks_counter'] ?? 0);

        // Cosmos `signed_blocks_window` varies per chain (e.g. Osmosis 10k,
        // Injective 20k). 10k is the most common default; chains with a
        // different window will read inaccurately until we fetch
        // `/cosmos/slashing/v1beta1/params` per chain (deferred).
        $window = self::DEFAULT_SIGNED_BLOCKS_WINDOW;
        $uptime = round((1 - ($missed / $window)) * 100, 2);

        return max(0, min(100, $uptime));
    }

    /**
     * Derive the bech32 consensus address (valcons) from a base64-encoded
     * ed25519 consensus pubkey.
     *
     * Cosmos SDK derivation:
     *   1. base64_decode(pubkey) → 32 raw bytes
     *   2. SHA-256 hash → 32 bytes
     *   3. Take first 20 bytes (the "address bytes")
     *   4. Bech32-encode with "{chain_prefix}valcons" HRP
     */
    private function deriveConsensusAddress(string $base64PubKey, string $operatorAddress): ?string
    {
        $raw = base64_decode($base64PubKey, true);
        if ($raw === false || strlen($raw) !== 32) {
            return null;
        }

        // SHA-256 hash, take first 20 bytes (standard Cosmos address derivation)
        $hash = hash('sha256', $raw, true);
        $addr_bytes = substr($hash, 0, 20);

        // Derive the valcons HRP from the operator address.
        // e.g. cosmosvaloper1... → cosmos, osmovaloper1... → osmo
        $hrp = $this->getValconsHrp($operatorAddress);
        if (!$hrp) {
            return null;
        }

        return $this->bech32Encode($hrp, $addr_bytes);
    }

    /**
     * Extract the valcons HRP from an operator address.
     * cosmosvaloper1... → cosmosvalcons
     * osmovaloper1...   → osmovalcons
     */
    private function getValconsHrp(string $operatorAddress): ?string
    {
        $pos = strpos($operatorAddress, 'valoper1');
        if ($pos === false) {
            return null;
        }
        return substr($operatorAddress, 0, $pos) . 'valcons';
    }

    // ── Bech32 encoding/decoding (delegates to shared Support\Bech32) ───

    private function bech32Encode(string $hrp, string $data): string
    {
        return Bech32::encode($hrp, $data);
    }

    private function fetchSelfDelegation(string $valoper): ?float
    {
        // Derive the account address by decoding the valoper bech32 to raw bytes
        // and re-encoding with the account HRP. str_replace('valoper','') produces
        // an invalid checksum — bech32 checksums cover the HRP.
        $self_addr = $this->valoperToAccountAddress($valoper);
        if (!$self_addr) {
            return null;
        }

        $delegation = $this->lcdGet("/cosmos/staking/v1beta1/validators/{$valoper}/delegations/{$self_addr}");

        if ($delegation && isset($delegation['delegation_response']['balance']['amount'])) {
            return $this->tokensToDisplay($delegation['delegation_response']['balance']['amount']);
        }

        return null;
    }

    /**
     * Convert a valoper address to its account address.
     * e.g. cosmosvaloper1abc... → cosmos1xyz... (same raw bytes, different HRP + checksum)
     */
    public function valoperToAccountAddress(string $valoper): ?string
    {
        $pos = strpos($valoper, 'valoper1');
        if ($pos === false) {
            return null;
        }

        $accountHrp = substr($valoper, 0, $pos); // "cosmos", "osmo", "akash", etc.
        $rawBytes   = $this->bech32Decode($valoper);
        if ($rawBytes === null) {
            return null;
        }

        return $this->bech32Encode($accountHrp, $rawBytes);
    }

    /**
     * Decode a bech32 address to raw address bytes (20 bytes for standard Cosmos addresses).
     * Returns null on invalid input or failed checksum verification.
     */
    private function bech32Decode(string $bech32): ?string
    {
        return Bech32::decodeToBytes($bech32);
    }

    /**
     * Get the bonded validator set for this chain, sorted by tokens desc.
     * Cached per chain ID — fetched once per PHP process.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getBondedValidators(): array
    {
        $chainId = (int) $this->chain->id;

        if (isset(self::$validatorListCache[$chainId])) {
            return self::$validatorListCache[$chainId];
        }

        $response = $this->lcdGet('/cosmos/staking/v1beta1/validators', [
            'status'           => 'BOND_STATUS_BONDED',
            'pagination.limit' => 500,
        ]);

        if (!$response || empty($response['validators'])) {
            self::$validatorListCache[$chainId] = [];
            return [];
        }

        $vals = $response['validators'];
        usort($vals, function ($a, $b) {
            return bccomp($b['tokens'] ?? '0', $a['tokens'] ?? '0');
        });

        self::$validatorListCache[$chainId] = $vals;
        return $vals;
    }

    private function fetchVotingPowerRank(string $valoper): ?int
    {
        $vals = $this->getBondedValidators();

        foreach ($vals as $i => $v) {
            if (($v['operator_address'] ?? '') === $valoper) {
                return $i + 1;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $val */
    private function fetchJailedCount(array $val): int
    {
        $jailed = $val['jailed'] ?? false;
        return $jailed ? 1 : 0;
    }
}
