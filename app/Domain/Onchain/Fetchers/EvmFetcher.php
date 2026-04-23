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
        return in_array($feature, ['collection', 'top_collections'], true);
    }

    /** @return array<string, mixed> */
    public function fetch_validator(string $address): array
    {
        return []; // EVM chains don't expose validator data via Etherscan API
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
    public function fetch_top_collections(int $limit = 100): array
    {
        $chainId = (int) $this->chain->id;

        // Map our chain slugs to Reservoir chain IDs.
        $reservoirChains = [
            'ethereum'  => 1,
            'polygon'   => 137,
            'arbitrum'  => 42161,
            'optimism'  => 10,
            'base'      => 8453,
            'avalanche' => 43114,
            'bsc'       => 56,
        ];

        $slug = $this->chain->slug ?? '';
        $reservoirChainId = $reservoirChains[$slug] ?? null;

        if (!$reservoirChainId) {
            return [];
        }

        $baseUrl = ($reservoirChainId === 1)
            ? 'https://api.reservoir.tools'
            : "https://api-{$slug}.reservoir.tools";

        $url = add_query_arg([
            'sortBy'         => 'allTimeVolume',
            'limit'          => min($limit, 100),
            'includeTopBid'  => 'false',
        ], $baseUrl . '/collections/v7');

        $headers = ['Accept' => 'application/json'];

        // Use API key if configured (higher rate limits).
        if (defined('BCC_RESERVOIR_API_KEY') && BCC_RESERVOIR_API_KEY) {
            $headers['x-api-key'] = BCC_RESERVOIR_API_KEY;
        }

        $response = ApiRetry::get($url, [
            'timeout' => 20,
            'headers' => $headers,
        ], [
            'label'    => 'Reservoir collections ' . $slug,
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] Reservoir fetch failed: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[EVM Fetcher] Reservoir returned ' . $code . ' for ' . $slug);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $items = $body['collections'] ?? [];

        if (empty($items)) {
            return [];
        }

        $native = $this->chain->native_token ?? 'ETH';
        $collections = [];

        foreach ($items as $item) {
            $contract = $item['primaryContract'] ?? ($item['id'] ?? '');
            if (!$contract) {
                continue;
            }

            $floorAsk = $item['floorAsk']['price']['amount']['native'] ?? null;
            $volume   = $item['volume']['allTime'] ?? null;

            $collections[] = [
                'contract_address'   => $contract,
                'chain_id'           => $chainId,
                'collection_name'    => $item['name'] ?? null,
                'token_standard'     => $item['contractKind'] ?? 'ERC-721',
                'total_supply'       => isset($item['tokenCount']) ? (int) $item['tokenCount'] : null,
                'floor_price'        => $floorAsk !== null ? (float) $floorAsk : null,
                'floor_currency'     => $native,
                'unique_holders'     => isset($item['ownerCount']) ? (int) $item['ownerCount'] : null,
                'total_volume'       => $volume !== null ? (float) $volume : null,
                'listed_percentage'  => isset($item['onSaleCount'], $item['tokenCount']) && $item['tokenCount'] > 0
                    ? round((int) $item['onSaleCount'] / (int) $item['tokenCount'] * 100, 2)
                    : null,
                'royalty_percentage' => isset($item['royalties']['bps']) ? (float) $item['royalties']['bps'] / 100 : null,
                'metadata_storage'   => null,
                'image_url'          => $item['image'] ?? null,
            ];
        }

        return $collections;
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
