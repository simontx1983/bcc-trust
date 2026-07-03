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
 * Cosmos Hub post-Stargaze-migration, Injective) but no standardized LCD
 * endpoint exists for discovery. Returns empty.
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
                // list_holdings is the gallery (cold) path — a failed or
                // empty contract is simply omitted from the gallery (the
                // pre-fail-open behaviour). The count (gate) path above is
                // where the null vs empty distinction is load-bearing.
                $tokenIds = $this->cw721AllTokensForOwner($contract, $wallet);
                if ($tokenIds === null || $tokenIds === []) {
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
        // Cosmos SDK wasm module expects the query JSON as base64 in the
        // path. Two requirements:
        //   1. URL-safe alphabet (`-_` instead of `+/`) — a literal `/` in
        //      the encoded string would split the URL path at the wrong
        //      segment boundary.
        //   2. Padding `=` MUST be preserved — strict cosmos-sdk LCD
        //      parsers return 400 "illegal base64 data" on unpadded input
        //      (observed on Stargaze pre-migration); cosmos-hub's LCD
        //      happens to be lenient, but we can't rely on that across
        //      all chains.
        $encoded = strtr(base64_encode($json), '+/', '-_');

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

        // wasmSmartQuery returns null on transport / non-200 / unparseable
        // (it threads through lcdGet which inherits the per-chain
        // CircuitBreaker — a breaker-open chain yields null here). Surface
        // that as a failed page so the page-walk can distinguish "couldn't
        // verify" from "verified empty," and do NOT cache it (caching a
        // failed query would poison the TTL window with a false empty).
        $data = $this->wasmSmartQuery($contract, $query);
        if ($data === null) {
            return null;
        }
        if (!isset($data['tokens']) || !is_array($data['tokens'])) {
            // Successful response with no `tokens` envelope — treat as a
            // genuine empty page (owns none under this contract).
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
     * marketplace indexer covering all chains. Injective uses on-chain
     * enumeration of the Talis Collection Whitelist contract (no API key, no
     * third-party dependency — DappRadar shut down in November 2025). Other
     * curated Cosmos NFT chains explicitly return an empty list — manual
     * curation via the "Add Cosmos collection" admin form fills the gap.
     *
     * History: Stargaze had a Constellations-GraphQL discovery path here
     * until the 2026 Stargaze → Cosmos Hub migration killed the public API
     * (indexer access went partner-only). Hub collections are curated
     * manually; there is no public Hub-wide NFT indexer to replace it.
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
                return [];
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
