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
 * NFT collections: Cosmos SDK chains may have CW-721 NFTs (e.g. Stargaze)
 * but no standardized LCD endpoint exists for discovery. Returns empty.
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

    public function supports_feature(string $feature): bool
    {
        return in_array(
            $feature,
            // V2 Phase 2 added 'holdings_count' + 'holdings_list' for CW-721
            // chains (Stargaze, Injective, Kujira, Dungeon). Curated-only
            // posture means non-NFT-active Cosmos chains naturally produce
            // empty results — no per-chain blocklist needed in this method.
            ['validator', 'delegations', 'dao', 'top_collections', 'holdings_count', 'holdings_list'],
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

    /** CW-721 `tokens` query page size — Stargaze / Injective LCDs allow up to ~30. */
    private const TOKENS_PAGE_SIZE = 30;

    /** wp_cache TTLs. nft_info is static; tokens lists track wallet activity. */
    private const NFT_INFO_CACHE_TTL = 7 * DAY_IN_SECONDS;
    private const TOKENS_CACHE_TTL   = DAY_IN_SECONDS;

    /** Default contract cap when BCC_COSMOS_HOLDINGS_CONTRACT_CAP is undefined. */
    private const DEFAULT_CONTRACT_CAP = 30;

    /**
     * Count tokens this wallet holds in a single CW-721 contract on this chain.
     *
     * Used by the gate fast-path. Walks the `tokens { owner }` query up to
     * PER_CONTRACT_TOKEN_CAP and returns the count. Does NOT fetch per-token
     * metadata — count-only path stays cheap.
     */
    public function count_holdings(string $wallet, string $contract): int
    {
        if ($wallet === '' || $contract === '') {
            return 0;
        }
        $tokenIds = $this->cw721AllTokensForOwner($contract, $wallet);
        return count($tokenIds);
    }

    /**
     * Enumerate this wallet's NFTs across every verified CW-721 contract on
     * this chain. Iterates `CollectionRepository::listVerifiedByChain` and
     * calls `cw721Tokens` per contract; for each token_id, optionally
     * resolves metadata via `cw721NftInfo`.
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
        $verified = \BCC\Trust\Onchain\Repositories\CollectionRepository::listVerifiedByChain($chainId, $cap);
        if ($verified === []) {
            return $empty;
        }

        $items     = [];
        $truncated = false;

        foreach ($verified as $coll) {
            $contract = (string) $coll->contract_address;
            if ($contract === '') {
                continue;
            }

            // Per-contract try/catch — one broken contract can't poison the
            // batch (mirror Phase 1c NftEnrichmentService::runForChain
            // structural isolation pattern).
            try {
                $tokenIds = $this->cw721AllTokensForOwner($contract, $wallet);
                if ($tokenIds === []) {
                    continue;
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
                    $meta = $this->cw721NftInfo($contract, $tokenId);
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
        if ($contractAddress === '') {
            return null;
        }

        $json = wp_json_encode($queryArr);
        if ($json === false) {
            return null;
        }
        // Cosmos SDK wasm module expects the query JSON as URL-safe base64
        // in the path. base64_encode (standard, with padding) is accepted
        // by every Cosmos chain's wasm module.
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $response = $this->lcdGet(
            '/cosmwasm/wasm/v1/contract/' . rawurlencode($contractAddress) . '/smart/' . $encoded
        );
        if (!is_array($response) || !isset($response['data']) || !is_array($response['data'])) {
            return null;
        }
        return $response['data'];
    }

    /**
     * CW-721 `tokens { owner, start_after, limit }` paginated fetch.
     * Returns ONE page of token_ids. Caller is responsible for paginating
     * via {@see cw721AllTokensForOwner}.
     *
     * @return list<string>
     */
    private function cw721Tokens(string $contract, string $owner, ?string $startAfter = null, int $limit = self::TOKENS_PAGE_SIZE): array
    {
        if ($contract === '' || $owner === '') {
            return [];
        }

        // Cache key per locked rule — explicit + readable for debugging.
        // "why does THIS wallet show stale holdings only on Stargaze page 2?"
        // → grep `cw721_tokens_<chain>_<contract>_<wallet>_<cursor>` in
        //   the cache dump and the answer is one row.
        $chainId  = (int) $this->chain->id;
        $cursorKey = $startAfter ?? '';
        $cacheKey = sprintf(
            'cw721_tokens_%d_%s_%s_%s',
            $chainId,
            strtolower($contract),
            strtolower($owner),
            $cursorKey
        );

        $cached = wp_cache_get($cacheKey, 'bcc_onchain');
        if (is_array($cached)) {
            /** @var list<string> $cached */
            return $cached;
        }

        $query = ['tokens' => ['owner' => $owner, 'limit' => max(1, min(100, $limit))]];
        if ($startAfter !== null && $startAfter !== '') {
            $query['tokens']['start_after'] = $startAfter;
        }

        $data = $this->wasmSmartQuery($contract, $query);
        if (!is_array($data) || !isset($data['tokens']) || !is_array($data['tokens'])) {
            return [];
        }

        $out = [];
        foreach ($data['tokens'] as $t) {
            if (is_string($t) && $t !== '') {
                $out[] = $t;
            }
        }

        wp_cache_set($cacheKey, $out, 'bcc_onchain', self::TOKENS_CACHE_TTL);
        return $out;
    }

    /**
     * Walk every page of `cw721Tokens` for a (contract, owner) up to
     * PER_CONTRACT_TOKEN_CAP. Caller's `count_holdings` returns the count;
     * caller's `list_holdings` consumes the token_ids.
     *
     * @return list<string>
     */
    private function cw721AllTokensForOwner(string $contract, string $owner): array
    {
        $all       = [];
        $cursor    = null;
        for ($page = 0; $page < ceil(self::PER_CONTRACT_TOKEN_CAP / self::TOKENS_PAGE_SIZE); $page++) {
            $pageResult = $this->cw721Tokens($contract, $owner, $cursor, self::TOKENS_PAGE_SIZE);
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
     * CW-721 `nft_info { token_id }` — per-token metadata. Returns
     * `{name, image_url, metadata_uri}` (any field nullable).
     *
     * Cached for 7 days because NFT metadata is effectively static (the
     * extension on a given token_id rarely changes after mint).
     *
     * @return array{name: ?string, image_url: ?string, metadata_uri: ?string}
     */
    private function cw721NftInfo(string $contract, string $tokenId): array
    {
        $empty = ['name' => null, 'image_url' => null, 'metadata_uri' => null];
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
        if (is_array($cached) && isset($cached['name'], $cached['image_url'], $cached['metadata_uri'])) {
            /** @var array{name: ?string, image_url: ?string, metadata_uri: ?string} $cached */
            return $cached;
        }

        $data = $this->wasmSmartQuery($contract, ['nft_info' => ['token_id' => $tokenId]]);
        if ($data === null) {
            // Don't cache transport failures — a flaky LCD shouldn't poison
            // a 7-day cache window with empty results.
            return $empty;
        }

        $tokenUri = is_string($data['token_uri'] ?? null) ? (string) $data['token_uri'] : null;
        $extension = is_array($data['extension'] ?? null) ? $data['extension'] : [];
        $name = is_string($extension['name'] ?? null) ? (string) $extension['name'] : null;
        $imageUrl = is_string($extension['image'] ?? null) ? (string) $extension['image'] : null;

        $out = [
            'name'         => $name,
            'image_url'    => $imageUrl,
            'metadata_uri' => $tokenUri,
        ];
        wp_cache_set($cacheKey, $out, 'bcc_onchain', self::NFT_INFO_CACHE_TTL);
        return $out;
    }

    /**
     * Public CW-721 `contract_info` probe used by the admin
     * "Test CW-721 query" button on `VerifyCollectionsPage`. Returns
     * the unwrapped `data` envelope (typically `{name, symbol}`) on
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
        return $this->wasmSmartQuery($contractAddress, ['contract_info' => new \stdClass()]);
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
     * @return array<int, array{validator_address: string, shares: string|null, amount: float|null}>
     */
    public function fetch_delegations(string $delegatorAddress): array
    {
        $response = $this->lcdGet("/cosmos/staking/v1beta1/delegations/{$delegatorAddress}", [
            'pagination.limit' => 500,
        ]);

        if (!$response || empty($response['delegation_responses'])) {
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

    // ── NFT Collections (Stargaze Constellations GraphQL) ─────────────────

    /**
     * Per-wallet collection fetch is not supported on Cosmos LCD.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        return [];
    }

    /**
     * Fetch top NFT collections from Stargaze via the Constellations GraphQL API.
     * Free, no authentication required.
     *
     * This is called for any Cosmos-type chain. Stargaze is the primary Cosmos
     * NFT marketplace — collections from Backbone Labs and other Cosmos projects
     * appear here if listed on Stargaze.
     *
     * @param int $limit Max collections to return.
     * @return array<int, array<string, mixed>> Normalized collection rows for bulkUpsert().
     */
    public function fetch_top_collections(int $limit = 100): array
    {
        // Resolve the Stargaze chain_id. All Cosmos NFT collections are stored
        // under the Stargaze chain since that's where the marketplace data lives.
        $stargaze = ChainRepository::getBySlug('stargaze');
        $chainId  = $stargaze ? (int) $stargaze->id : (int) $this->chain->id;

        $query = 'query TopCollections($limit: Int, $offset: Int, $sortBy: CollectionSort) {
            collections(limit: $limit, offset: $offset, sortBy: $sortBy) {
                collections {
                    contractAddress
                    name
                    floorPriceStars
                    media { visualAssets { lg { url } } }
                    tokenCounts { total listed }
                    stats { volumeTotal numOwners }
                    royaltyInfo { sharePercent }
                }
            }
        }';

        $variables = [
            'limit'  => min($limit, 100),
            'offset' => 0,
            'sortBy' => 'VOLUME_ALL_TIME_DESC',
        ];

        $response = ApiRetry::post('https://graphql.mainnet.stargaze-apis.com/graphql', [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode([
                'query'     => $query,
                'variables' => $variables,
            ]),
        ], [
            'label'    => 'Stargaze top collections',
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[Cosmos Fetcher] Stargaze GraphQL failed: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[Cosmos Fetcher] Stargaze returned ' . $code);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $items = $body['data']['collections']['collections'] ?? [];

        if (empty($items)) {
            return [];
        }

        $collections = [];

        foreach ($items as $item) {
            $addr = $item['contractAddress'] ?? '';
            if (!$addr) {
                continue;
            }

            // floorPriceStars is a decimal string in micro-STARS (already display-ready).
            $floorStars  = isset($item['floorPriceStars']) ? (float) $item['floorPriceStars'] / 1e6 : null;
            // volumeTotal is in micro-STARS.
            $volumeStars = isset($item['stats']['volumeTotal']) ? (float) $item['stats']['volumeTotal'] / 1e6 : null;

            $tokenTotal  = $item['tokenCounts']['total'] ?? null;
            $tokenListed = $item['tokenCounts']['listed'] ?? null;

            $imageUrl = $item['media']['visualAssets']['lg']['url'] ?? null;

            $collections[] = [
                'contract_address'   => $addr,
                'chain_id'           => $chainId,
                'collection_name'    => $item['name'] ?? null,
                'token_standard'     => 'CW-721',
                'total_supply'       => $tokenTotal !== null ? (int) $tokenTotal : null,
                'floor_price'        => $floorStars,
                'floor_currency'     => 'STARS',
                'unique_holders'     => isset($item['stats']['numOwners']) ? (int) $item['stats']['numOwners'] : null,
                'total_volume'       => $volumeStars,
                'listed_percentage'  => ($tokenTotal && $tokenListed)
                    ? round((int) $tokenListed / (int) $tokenTotal * 100, 2)
                    : null,
                'royalty_percentage' => $item['royaltyInfo']['sharePercent'] ?? null,
                'metadata_storage'   => null,
                'image_url'          => $imageUrl,
            ];
        }

        return $collections;
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
