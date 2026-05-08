<?php

namespace BCC\Trust\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\ApiRetry;

/**
 * EVM Chain Fetcher
 *
 * Fetches NFT collection data from EVM chains via Etherscan-compatible APIs.
 * Uses the ERC-721/1155 token transfer endpoint to discover collections
 * created by an address, then enriches with supply and holder data.
 *
 * Requires BCC_ETHERSCAN_API_KEY defined in wp-config.php.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 *
 * @phpstan-type EtherscanNftTransfer object{
 *     from?: string,
 *     to?: string,
 *     contractAddress?: string,
 *     tokenName?: string
 * }
 */
class EvmFetcher implements FetcherInterface
{
    private const HTTP_TIMEOUT = 12;
    private const ALCHEMY_MAX_COUNT = 1000; // alchemy_getAssetTransfers per-page cap (spike 1)

    /** @var ChainRow */
    private object $chain;

    /** @param ChainRow $chain */
    public function __construct(object $chain)
    {
        $this->chain = $chain;
    }

    /** @return ChainRow */
    public function get_chain(): object
    {
        return $this->chain;
    }

    public function supports_feature(string $feature): bool
    {
        // 'holdings_list' deliberately omitted — EVM gallery requires
        // Alchemy/Moralis, committed in a later PR. count_holdings uses
        // plain eth_call balanceOf() and works with any RPC endpoint.
        return in_array($feature, ['collection', 'top_collections', 'holdings_count'], true);
    }

    /** @return array<string, mixed> */
    public function fetch_validator(string $address): array
    {
        return []; // EVM chains don't expose validator data via Etherscan API
    }

    /** @return array<int, array{validator_address: string, shares?: string|null, amount?: float|null}> */
    public function fetch_delegations(string $delegatorAddress): array
    {
        return []; // No native account-level delegation on EVM.
    }

    // ── Holdings (per-wallet ERC-721 balance) ──────────────────────────────

    /**
     * ERC-721 balanceOf(address) via eth_call. Works with any EVM RPC
     * endpoint — admin must configure chain->rpc_url with a full API key
     * (the seeded Alchemy URLs ship with /v2/ placeholder and will fail
     * until a key is appended).
     *
     * ERC-721 only by design. ERC-1155 contracts expose
     * `balanceOf(address, uint256)` which requires a token_id and a
     * different selector (0x00fdd58e); answering "owns any token in
     * the contract" via RPC would need either `balanceOfBatch` over
     * a known token-id list or per-id calls — neither composes cleanly
     * here. The holder gate sidesteps this entirely by routing 1155
     * lookups through `NftHoldingsRepository::countVisibleByContract()`
     * (the persistent transfer index, see HoldingsService::countFromCacheOrFetch).
     */
    public function count_holdings(string $wallet, string $contract): int
    {
        $addr = strtolower($wallet);
        if (!preg_match('/^0x[a-f0-9]{40}$/', $addr)) {
            return 0;
        }

        $to = strtolower($contract);
        if (!preg_match('/^0x[a-f0-9]{40}$/', $to)) {
            return 0;
        }

        // balanceOf(address) selector = first 4 bytes of keccak256("balanceOf(address)")
        $selector = '70a08231';
        $paddedWallet = str_pad(substr($addr, 2), 64, '0', STR_PAD_LEFT);
        $data = '0x' . $selector . $paddedWallet;

        $result = $this->ethCall($to, $data);
        if ($result === null) {
            return 0;
        }

        $hex = ltrim(substr($result, 2), '0');
        if ($hex === '') {
            return 0;
        }

        // Realistic NFT balances fit in PHP_INT_MAX comfortably.
        // A pathological return > 2^63 would wrap, but that's
        // a broken contract, not user data worth preserving.
        return (int) hexdec($hex);
    }

    /**
     * Full NFT enumeration on EVM needs Alchemy getNFTs / Moralis / similar.
     * Stubbed until the provider decision lands — see HoldingsService.
     *
     * @return array{items: list<array{contract_address: string, token_id: string, chain_id: int, collection_name: ?string, name: ?string, image_url: ?string, metadata_uri: ?string, token_standard: ?string}>, truncated: bool, cursor: ?string}
     */
    public function list_holdings(string $wallet, ?string $cursor = null): array
    {
        return ['items' => [], 'truncated' => false, 'cursor' => null];
    }

    /**
     * Fetch ERC-721/1155 Transfer events between two block heights via
     * Alchemy's `alchemy_getAssetTransfers`. Used by NftEthIndexerWorker
     * to ingest confirmation-gated mints/transfers/burns.
     *
     * Per spike 1: each call costs 120 CU flat regardless of category
     * filter or maxCount. The worker tracks CU usage in
     * `wp_bcc_chain_checkpoints.cu_used_today`; this method does NOT
     * track it (separation of concerns — fetcher only fetches).
     *
     * Pagination: a non-null `page_key` in the return value means more
     * results exist; the caller passes it back via `$pageKey` to
     * continue. `page_key === null` means the range is fully drained.
     *
     * Returns events in the indexer's normalized TransferEvent shape:
     * see NftHoldingsIndexer phpstan-type for the contract.
     *
     * @return array{transfers: list<array<string, mixed>>, page_key: string|null}
     */
    public function fetch_transfers_since(int $fromBlock, int $toBlock, ?string $pageKey = null): array
    {
        $empty = ['transfers' => [], 'page_key' => null];

        $rpcUrl = (string) ($this->chain->rpc_url ?? '');
        if (!$rpcUrl || str_ends_with($rpcUrl, '/v2/')) {
            return $empty;
        }
        if ($fromBlock < 0 || $toBlock < $fromBlock) {
            return $empty;
        }

        $chainId = (int) $this->chain->id;

        $params = [
            'fromBlock'        => '0x' . dechex($fromBlock),
            'toBlock'          => '0x' . dechex($toBlock),
            'category'         => ['erc721', 'erc1155'],
            'withMetadata'     => true,
            'excludeZeroValue' => false,
            'maxCount'         => '0x' . dechex(self::ALCHEMY_MAX_COUNT),
            'order'            => 'asc',
        ];
        if (is_string($pageKey) && $pageKey !== '') {
            $params['pageKey'] = $pageKey;
        }

        $body = wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'alchemy_getAssetTransfers',
            'params'  => [$params],
        ]);

        $response = ApiRetry::post($rpcUrl, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $body,
            'sslverify' => true,
        ], [
            'label'    => 'EVM alchemy_getAssetTransfers',
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] alchemy_getAssetTransfers error: ' . $response->get_error_message());
            return $empty;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || !isset($json['result']) || !is_array($json['result'])) {
            return $empty;
        }

        $rawTransfers = $json['result']['transfers'] ?? [];
        $newPageKey   = $json['result']['pageKey'] ?? null;
        if (!is_array($rawTransfers)) {
            return $empty;
        }

        $transfers = [];
        foreach ($rawTransfers as $t) {
            if (!is_array($t)) {
                continue;
            }
            $contract = strtolower((string) ($t['rawContract']['address'] ?? ''));
            $tokenId  = (string) ($t['tokenId'] ?? '');
            if ($contract === '' || $tokenId === '') {
                continue;
            }

            $category = (string) ($t['category'] ?? '');
            $tokenStd = $category === 'erc721' ? 'ERC-721'
                : ($category === 'erc1155' ? 'ERC-1155' : null);

            $blockNumHex = (string) ($t['blockNum'] ?? '0x0');
            $blockNum    = $blockNumHex !== '' ? (int) hexdec(substr($blockNumHex, 2)) : 0;

            // For 1155, value is amount; for 721, value is 1 (or 0 in some
            // edge cases — coerce to 1 since holding a 721 means balance=1).
            $rawValue = $t['value'] ?? 1;
            $amount   = $category === 'erc1155'
                ? max(1, (int) (is_numeric($rawValue) ? $rawValue : 1))
                : 1;

            $blockTimestamp = (string) ($t['metadata']['blockTimestamp'] ?? '');
            $confirmedAt    = self::isoToMysqlUtc($blockTimestamp);

            // Alchemy returns hex token_id; convert to decimal for storage
            // consistency (standard practice — exchanges/marketplaces
            // store token_id as decimal strings).
            $tokenIdDecimal = self::hexToDecimalString($tokenId);

            $transfers[] = [
                'chain_id'         => $chainId,
                'contract_address' => $contract,
                'token_id'         => $tokenIdDecimal,
                'token_standard'   => $tokenStd,
                'from_address'     => strtolower((string) ($t['from'] ?? '')),
                'to_address'       => strtolower((string) ($t['to'] ?? '')),
                'amount'           => $amount,
                'block_number'     => $blockNum,
                'confirmed_at'     => $confirmedAt,
                'collection_name'  => isset($t['asset']) ? (string) $t['asset'] : null,
            ];
        }

        return [
            'transfers' => $transfers,
            'page_key'  => is_string($newPageKey) && $newPageKey !== '' ? $newPageKey : null,
        ];
    }

    /**
     * Fetch enrichment metadata for a single (contract, tokenId) pair
     * via Alchemy's `getNFTMetadata` JSON-RPC method. Used by the
     * V2 Phase 1c NftEnrichmentService to backfill name + image_url
     * + collection_name on persistent holdings rows.
     *
     * Returns null on transport / 4xx / 5xx; non-null with
     * partially-empty fields on success (Alchemy sometimes returns a
     * row with no media — we persist what's there and let the
     * gallery render with a placeholder if image_url is null).
     *
     * @return array{name: ?string, image_url: ?string, metadata_uri: ?string, collection_name: ?string}|null
     */
    public function fetchMetadataForToken(string $contract, string $tokenId): ?array
    {
        $rpcUrl = (string) ($this->chain->rpc_url ?? '');
        if (!$rpcUrl || str_ends_with($rpcUrl, '/v2/')) {
            return null;
        }
        $contractLc = strtolower($contract);
        if (!preg_match('/^0x[a-f0-9]{40}$/', $contractLc) || $tokenId === '') {
            return null;
        }

        // Alchemy expects token_id in either decimal or hex; pass our
        // canonical decimal representation and let Alchemy normalize.
        $body = wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'alchemy_getNFTMetadata',
            'params'  => [[
                'contractAddress' => $contractLc,
                'tokenId'         => $tokenId,
                'refreshCache'    => false,
            ]],
        ]);
        if ($body === false) {
            return null;
        }

        $response = ApiRetry::post($rpcUrl, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $body,
            'sslverify' => true,
        ], [
            'label'    => 'EVM alchemy_getNFTMetadata',
            'chain_id' => (int) $this->chain->id,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || !isset($json['result']) || !is_array($json['result'])) {
            return null;
        }
        $r = $json['result'];

        $name        = is_string($r['name'] ?? null) ? $r['name'] : null;
        $description = $r['description'] ?? null; // unused but documented for future
        $tokenUri    = is_array($r['tokenUri'] ?? null) ? ($r['tokenUri']['gateway'] ?? null) : null;
        $imgUrl      = null;
        if (isset($r['media']) && is_array($r['media'])) {
            foreach ($r['media'] as $m) {
                if (is_array($m) && isset($m['gateway']) && is_string($m['gateway']) && $m['gateway'] !== '') {
                    $imgUrl = $m['gateway'];
                    break;
                }
            }
        }
        $collName = null;
        if (isset($r['contractMetadata']) && is_array($r['contractMetadata'])) {
            $collName = is_string($r['contractMetadata']['name'] ?? null) ? $r['contractMetadata']['name'] : null;
        }

        return [
            'name'            => $name,
            'image_url'       => $imgUrl,
            'metadata_uri'    => is_string($tokenUri) ? $tokenUri : null,
            'collection_name' => $collName,
        ];
    }

    /**
     * Convert a hex string (with or without 0x prefix) to a decimal
     * string. Uses gmp/bcmath for arbitrary precision; falls back to
     * intval for small values when neither extension is available.
     */
    private static function hexToDecimalString(string $hex): string
    {
        $clean = strtolower(ltrim($hex, '0x'));
        if ($clean === '') {
            return '0';
        }
        if (function_exists('gmp_strval')) {
            return gmp_strval(gmp_init('0x' . $clean, 16), 10);
        }
        if (function_exists('bcadd')) {
            $dec = '0';
            for ($i = 0, $n = strlen($clean); $i < $n; $i++) {
                $dec = bcadd(bcmul($dec, '16'), (string) hexdec($clean[$i]));
            }
            return $dec;
        }
        // Fallback — only safe for small token_ids.
        return (string) hexdec($clean);
    }

    /**
     * Convert Alchemy ISO-8601 ('2024-08-01T12:34:56.000Z') to MySQL
     * DATETIME UTC ('2024-08-01 12:34:56'). Returns the current time
     * if parsing fails — confirmed_at is load-bearing for
     * indexer observability so a missing value is logged-and-defaulted
     * rather than dropped.
     */
    private static function isoToMysqlUtc(string $iso): string
    {
        if ($iso === '') {
            return current_time('mysql', true);
        }
        try {
            $dt = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return current_time('mysql', true);
        }
    }

    /**
     * Thin JSON-RPC wrapper for read-only contract calls. Returns the
     * raw hex result string or null on any error.
     */
    private function ethCall(string $to, string $data): ?string
    {
        $rpcUrl = (string) ($this->chain->rpc_url ?? '');
        // Seeded Alchemy URLs ship as "https://eth-mainnet.g.alchemy.com/v2/"
        // with no key appended. Skip rather than fire a guaranteed 401.
        if (!$rpcUrl || str_ends_with($rpcUrl, '/v2/')) {
            return null;
        }

        $body = wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'eth_call',
            'params'  => [
                ['to' => $to, 'data' => $data],
                'latest',
            ],
        ]);

        $response = ApiRetry::post($rpcUrl, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $body,
            'sslverify' => true,
        ], [
            'label'    => 'EVM eth_call balanceOf',
            'chain_id' => (int) $this->chain->id,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] eth_call error: ' . $response->get_error_message());
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || isset($json['error'])) {
            return null;
        }

        $result = $json['result'] ?? null;
        return is_string($result) && str_starts_with($result, '0x') ? $result : null;
    }

    /**
     * Discover NFT collections created by an address.
     *
     * Strategy: query Etherscan "tokennfttx" for outbound ERC-721/1155
     * transfers where from=0x0 (mints) and contractAddress was deployed
     * by this address. Groups results by contract.
     *
     * @param string $walletAddress  Wallet address to query.
     * @param int    $chainId        Chain ID override (ignored — uses $this->chain->id).
     * @return array<int, array<string, mixed>> Array of normalized collection rows.
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        $chainId = $chainId ?: (int) $this->chain->id;

        $api_key = defined('BCC_ETHERSCAN_API_KEY') ? BCC_ETHERSCAN_API_KEY : '';
        if (!$api_key) {
            return [];
        }

        $explorer = rtrim($this->chain->explorer_url ?? '', '/');
        $api_base = $this->resolveApiBase($explorer);

        // Fetch ERC-721 token transfer events for this address
        $transfers = $this->etherscanGet($api_base, [
            'module'     => 'account',
            'action'     => 'tokennfttx',
            'address'    => $walletAddress,
            'startblock' => 0,
            'endblock'   => 99999999,
            'page'       => 1,
            'offset'     => 500,
            'sort'       => 'asc',
            'apikey'     => $api_key,
        ]);

        if (!is_array($transfers) || empty($transfers)) {
            return [];
        }

        // Group by contract address — keep contracts where this address
        // received mints (from = 0x0). NOTE: this identifies mint recipients,
        // not necessarily contract deployers. Actual creator/deployer role
        // verification is performed by BlockchainQueryService::getEthRole()
        // via on-chain owner() RPC call during the claim flow.
        $contracts = [];
        $zero      = '0x0000000000000000000000000000000000000000';

        foreach ($transfers as $tx) {
            $contractAddress = $tx->contractAddress ?? '';
            $contract        = strtolower($contractAddress);
            if (!$contract) {
                continue;
            }

            if (!isset($contracts[$contract])) {
                $contracts[$contract] = [
                    'contract_address' => $contractAddress,
                    'collection_name'  => $tx->tokenName ?? null,
                    'token_standard'   => 'ERC-721',
                    'mint_count'       => 0,
                ];
            }

            // Count mints (from zero address) only when this wallet is the recipient
            if (strtolower($tx->from ?? '') === $zero
                && strtolower($tx->to ?? '') === strtolower($walletAddress)
            ) {
                $contracts[$contract]['mint_count']++;
            }
        }

        // Only keep collections where this address was involved in minting
        $created = array_filter($contracts, fn($c) => $c['mint_count'] > 0);

        if (empty($created)) {
            return [];
        }

        // Normalize into the schema format
        $native = $this->chain->native_token ?? 'ETH';

        $collections = [];
        foreach ($created as $meta) {
            $collections[] = [
                'contract_address'   => $meta['contract_address'],
                'collection_name'    => $meta['collection_name'],
                'chain_id'           => $chainId,
                'token_standard'     => $meta['token_standard'],
                'total_supply'       => $meta['mint_count'],
                'floor_price'        => null,
                'floor_currency'     => $native,
                'total_volume'       => null,
                'unique_holders'     => null,
                'listed_percentage'  => null,
                'royalty_percentage' => null,
                'metadata_storage'   => null,
            ];
        }

        return $collections;
    }

    // ── Bulk Collection Indexing ───────────────────────────────────────────

    /**
     * Fetch top NFT collections for this EVM chain via Reservoir API.
     *
     * Reservoir provides free-tier access (no API key, 4 req/sec) to the same
     * data shown on etherscan.io/nft-top-contracts: name, floor, volume,
     * holders, supply, image.
     *
     * @param int $limit Number of top collections to fetch (max 100 per call).
     * @return array<int, array<string, mixed>> Array of normalized collection data rows.
     */
    /**
     * Contract-level metadata via Alchemy NFT API v3 `getContractMetadata`.
     * Powers `NftEnrichmentService` collection-row backfill — holds the
     * "what is this contract?" enrichment for the discovery rows that
     * `CollectionRepository::ensureExistsBatch` writes when the holdings
     * indexer first sees a contract.
     *
     * Endpoint: `https://{network}.g.alchemy.com/nft/v3/{key}/getContractMetadata`
     *
     * The chain's `rpc_url` is the JSON-RPC URL (`/v2/{key}`); we derive
     * the NFT v3 base from it via a regex swap rather than hardcoding a
     * per-chain map. Same key, same vendor, same circuit-breaker chain
     * id — works for every Alchemy-backed EVM chain (ethereum, polygon,
     * arbitrum, optimism, base) without further config. Public-RPC
     * chains (avalanche, bsc, etc.) don't match the regex and return
     * null — discovery rows for those chains stay un-enriched until a
     * dedicated fetcher is added.
     *
     * @return array{
     *     collection_name: ?string,
     *     image_url: ?string,
     *     total_supply: ?int,
     *     floor_price: ?float,
     *     floor_currency: ?string,
     *     token_standard: ?string
     * }|null
     */
    public function fetchContractMetadata(string $contract): ?array
    {
        $rpcUrl = (string) ($this->chain->rpc_url ?? '');
        if ($rpcUrl === '') {
            return null;
        }
        $contractLc = strtolower($contract);
        if (!preg_match('/^0x[a-f0-9]{40}$/', $contractLc)) {
            return null;
        }

        // Derive the NFT v3 base from the JSON-RPC URL. Anchored regex
        // so a non-Alchemy rpc_url (custom RPC, public node) returns null
        // rather than producing a malformed request.
        if (!preg_match(
            '#^(https://[a-z0-9-]+\.g\.alchemy\.com)/v2/([A-Za-z0-9_-]+)/?$#',
            $rpcUrl,
            $m
        )) {
            return null;
        }
        $nftV3Base = $m[1] . '/nft/v3/' . $m[2];

        $url = $nftV3Base . '/getContractMetadata?contractAddress=' . rawurlencode($contractLc);

        $response = ApiRetry::get($url, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Accept' => 'application/json'],
            'sslverify' => true,
        ], [
            'label'    => 'EVM alchemy_getContractMetadata',
            'chain_id' => (int) $this->chain->id,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json)) {
            return null;
        }

        // Alchemy NFT v3 returns top-level `name`, `symbol`, `tokenType`,
        // `totalSupply`, `openSeaMetadata.collectionName`, `openSeaMetadata.imageUrl`,
        // `openSeaMetadata.floorPrice`. Older v2 response shapes are
        // documented but not used here — v3 is the supported endpoint.
        $name      = is_string($json['name'] ?? null) && $json['name'] !== ''
            ? $json['name']
            : null;
        $tokenType = is_string($json['tokenType'] ?? null) && $json['tokenType'] !== ''
            ? $json['tokenType']
            : null;
        $supply    = isset($json['totalSupply']) && is_numeric($json['totalSupply'])
            ? (int) $json['totalSupply']
            : null;

        $os = isset($json['openSeaMetadata']) && is_array($json['openSeaMetadata'])
            ? $json['openSeaMetadata']
            : [];
        $imageUrl   = is_string($os['imageUrl'] ?? null) && $os['imageUrl'] !== ''
            ? $os['imageUrl']
            : null;
        $floorPrice = isset($os['floorPrice']) && is_numeric($os['floorPrice'])
            ? (float) $os['floorPrice']
            : null;
        $collName   = is_string($os['collectionName'] ?? null) && $os['collectionName'] !== ''
            ? $os['collectionName']
            : $name;

        // Normalize Alchemy's tokenType (e.g. "ERC721", "ERC1155", "NOT_A_CONTRACT")
        // to the codebase's hyphenated form used in wp_bcc_onchain_collections.
        $standardOut = null;
        if ($tokenType === 'ERC721') {
            $standardOut = 'ERC-721';
        } elseif ($tokenType === 'ERC1155') {
            $standardOut = 'ERC-1155';
        }

        $native = is_string($this->chain->native_token ?? null) && $this->chain->native_token !== ''
            ? (string) $this->chain->native_token
            : 'ETH';

        return [
            'collection_name' => $collName,
            'image_url'       => $imageUrl,
            'total_supply'    => $supply,
            'floor_price'     => $floorPrice,
            'floor_currency'  => $floorPrice !== null ? $native : null,
            'token_standard'  => $standardOut,
        ];
    }

    public function fetch_top_collections(int $limit = 100): array
    {
        // Reservoir's public API (api.reservoir.tools / api-{chain}.reservoir.tools)
        // was sunset in 2024 after the OpenSea acquisition; the DNS no longer
        // resolves and every call returned a curl-6 WP_Error. Rather than swap
        // in another single-vendor "top collections by volume" feed (same risk
        // class — every NFT data API has churned hard in the last 3 years),
        // EVM collection discovery is now driven by user holdings: the
        // NftHoldingsIndexer ingests transfers via Alchemy and bridges new
        // (chain, contract) pairs into wp_bcc_onchain_collections via
        // CollectionRepository::ensureExistsBatch, then NftEnrichmentService
        // backfills metadata using EvmFetcher::fetchContractMetadata against
        // Alchemy NFT v3 (one vendor, already-configured key, no marketing
        // discovery surface to maintain).
        //
        // This method intentionally returns [] so the existing
        // ChainRefreshService::index_collections cron loop keeps running but
        // is a no-op for EVM chains. Cosmos and Solana keep their own working
        // top-collections fetchers via this same interface.
        return [];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Derive the Etherscan-compatible API base URL from an explorer URL.
     * e.g. https://etherscan.io → https://api.etherscan.io/api
     *      https://polygonscan.com → https://api.polygonscan.com/api
     */
    private function resolveApiBase(string $explorerUrl): string
    {
        if (!$explorerUrl) {
            return 'https://api.etherscan.io/api';
        }

        $parsed = parse_url($explorerUrl);
        $host   = strtolower($parsed['host'] ?? '');

        // SSRF-hardening: only derive api-bases for allow-listed explorer hosts.
        // A DB row with a malicious `explorer_url` would otherwise exfiltrate
        // the Etherscan API key via query string to an attacker-controlled host.
        static $allowlist = [
            'etherscan.io'    => 'https://api.etherscan.io/api',
            'polygonscan.com' => 'https://api.polygonscan.com/api',
            'arbiscan.io'     => 'https://api.arbiscan.io/api',
            'basescan.org'    => 'https://api.basescan.org/api',
            'bscscan.com'     => 'https://api.bscscan.com/api',
            'optimistic.etherscan.io' => 'https://api-optimistic.etherscan.io/api',
            'snowtrace.io'    => 'https://api.snowtrace.io/api',
        ];

        // Strict suffix match against allowlist (prevents `etherscan.io.attacker.com`).
        foreach ($allowlist as $allowedHost => $apiBase) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return $apiBase;
            }
        }

        // Unknown host — fall back to Etherscan default rather than constructing
        // an api.<user-controlled>.<tld>/api URL.
        return 'https://api.etherscan.io/api';
    }

    /**
     * @param array<string, mixed> $params
     * @return list<EtherscanNftTransfer>|null
     */
    private function etherscanGet(string $apiBase, array $params): ?array
    {
        $url      = add_query_arg($params, $apiBase);
        $chainId  = (int) $this->chain->id;

        $response = ApiRetry::get($url, [
            'timeout'   => self::HTTP_TIMEOUT,
            'sslverify' => true,
        ], [
            'label'    => 'Etherscan ' . ($params['action'] ?? 'query'),
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] Collection fetch failed: ' . preg_replace('/apikey=[^&]+/', 'apikey=***', $response->get_error_message()));
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response));

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (is_object($json) && isset($json->status) && $json->status === '1' && isset($json->result) && is_array($json->result)) {
            /** @var list<EtherscanNftTransfer> */
            return $json->result;
        }

        return null;
    }
}
