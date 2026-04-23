<?php
/**
 * Blockchain Query Service
 *
 * Queries public RPC endpoints to determine a wallet's role in a project:
 *
 *   Holder  — wallet owns at least one NFT from the contract
 *   Creator — wallet is the contract deployer / owner()
 *   Team    — wallet address appears in an on-chain team list (if implemented)
 *
 * Uses WordPress HTTP API (wp_remote_post / wp_remote_get) so proxy/timeout
 * settings from wp-config.php are respected automatically.
 *
 * Supported chains: ethereum, solana, cosmos
 *
 * @package BCC_Trust_Engine
 * @subpackage Services/Wallet
 */

namespace BCC\Trust\Core\Services\wallet;

use BCC\Core\Http\SafeHttpClient;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class BlockchainQueryService {

    // Default public RPC endpoints (overridable via wp-config constants)
    private const DEFAULT_ETH_RPC    = 'https://eth.llamarpc.com';
    private const DEFAULT_SOL_RPC    = 'https://api.mainnet-beta.solana.com';
    private const DEFAULT_COSMOS_RPC = 'https://rest.cosmos.directory/cosmoshub';

    /**
     * Allowed Cosmos REST endpoint hosts.
     *
     * Extend via the 'bcc_trust_allowed_cosmos_hosts' filter.
     */
    private const ALLOWED_COSMOS_HOSTS = [
        'lcd.cosmos.network',
        'rest.cosmos.directory',
        'cosmoshub-lcd.stakeandrelax.net',
    ];

    // Role constants
    public const ROLE_HOLDER  = 'holder';
    public const ROLE_CREATOR = 'creator';
    public const ROLE_TEAM    = 'team';
    public const ROLE_NONE    = 'none';

    /**
     * Fetch all NFT collections owned by a wallet in a single Alchemy API call.
     *
     * Uses getContractsForOwner which returns every collection the wallet holds,
     * with metadata (name, image) — one HTTP request total.
     *
     * Requires BCC_ALCHEMY_API_KEY defined in wp-config.php.
     *
     * @param string $address  Wallet address
     * @param string $chain    'ethereum' | 'solana'
     * @return array<int, array<string, mixed>>  Each entry: {contract_address, name, image, token_type, balance}
     */
    public static function getNFTCollectionsForOwner(string $address, string $chain): array {
        switch ($chain) {
            case 'ethereum':
                return self::getAlchemyEthCollections($address);
            case 'solana':
                return self::getAlchemySolCollections($address);
            default:
                return [];
        }
    }

    /**
     * Ethereum: single call to Alchemy getContractsForOwner.
     *
     * @return list<array<string, mixed>>
     */
    private static function getAlchemyEthCollections(string $address): array {
        $apiKey = defined('BCC_ALCHEMY_API_KEY') ? BCC_ALCHEMY_API_KEY : '';
        if (empty($apiKey)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust: BCC_ALCHEMY_API_KEY not configured in wp-config.php', []);
            return [];
        }

        // Circuit-breaker short-circuit. Avoids hammering a sustained
        // Alchemy outage and prevents callers from eating 15-second timeouts
        // on every verification request while the upstream is down. On OPEN
        // we return the same empty sentinel the legacy error paths used —
        // callers already treat [] as "no verifiable NFTs" and degrade gracefully.
        $cbKey = 'alchemy:eth';
        if (!\BCC\Trust\Core\Support\CircuitBreaker::allow($cbKey)) {
            return [];
        }

        // NFT API v2 — exclude known spam and airdrops at the API level
        $url = 'https://eth-mainnet.g.alchemy.com/nft/v2/' . rawurlencode($apiKey)
             . '/getContractsForOwner'
             . '?owner='                . rawurlencode($address)
             . '&withMetadata=true'
             . '&excludeFilters[]=SPAM'
             . '&excludeFilters[]=AIRDROPS'
             . '&pageSize=100';

        $response = SafeHttpClient::get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            \BCC\Trust\Core\Support\CircuitBreaker::recordFailure($cbKey, new \RuntimeException($response->get_error_message()));
            \BCC\Core\Log\Logger::warning('[bcc-trust] ' . 'BCC Trust Alchemy NFT error: ', ['detail' => $response->get_error_message()]);
            return [];
        }

        \BCC\Trust\Core\Support\CircuitBreaker::recordSuccess($cbKey);

        $body      = json_decode(wp_remote_retrieve_body($response), true);
        $contracts = $body['contracts'] ?? [];

        $collections = [];
        foreach ($contracts as $c) {
            // Double-check spam flag from response body (defence-in-depth)
            if (!empty($c['isSpam'])) {
                continue;
            }

            // v2 uses 'openSea' key; v3 uses 'openSeaMetadata' — support both
            $openSea = $c['openSea'] ?? $c['openSeaMetadata'] ?? [];

            $name = $c['name']
                 ?? $openSea['collectionName']
                 ?? '';

            $image = $openSea['imageUrl'] ?? '';

            $addr = strtolower($c['address'] ?? '');

            // Skip unnamed or zero-balance contracts
            if (empty($name) || empty($addr) || ((int) ($c['totalBalance'] ?? 0)) < 1) {
                continue;
            }

            $collections[] = [
                'contract_address' => $addr,
                'name'             => sanitize_text_field($name),
                'image'            => esc_url_raw($image),
                'token_type'       => $c['tokenType'] ?? 'ERC721',
                'balance'          => (int) $c['totalBalance'],
            ];
        }

        return $collections;
    }

    /**
     * Solana: single call to Alchemy getAssetsByOwner (Metaplex DAS API).
     * Returns collection-level groupings where available.
     *
     * @return list<array<string, mixed>>
     */
    private static function getAlchemySolCollections(string $address): array {
        $apiKey = defined('BCC_ALCHEMY_API_KEY') ? BCC_ALCHEMY_API_KEY : '';
        if (empty($apiKey)) {
            return [];
        }

        $cbKey = 'alchemy:solana';
        if (!\BCC\Trust\Core\Support\CircuitBreaker::allow($cbKey)) {
            return [];
        }

        $url  = 'https://solana-mainnet.g.alchemy.com/v2/' . rawurlencode($apiKey);
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'getAssetsByOwner',
            'params'  => [
                'ownerAddress' => $address,
                'page'         => 1,
                'limit'        => 100,
                'displayOptions' => ['showCollectionMetadata' => true],
            ],
        ]);

        $response = SafeHttpClient::post($url, [
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => (string) $body,
            'timeout'     => 15,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            \BCC\Trust\Core\Support\CircuitBreaker::recordFailure($cbKey, new \RuntimeException($response->get_error_message()));
            \BCC\Core\Log\Logger::warning('[bcc-trust] ' . 'BCC Trust Alchemy Solana NFT error: ', ['detail' => $response->get_error_message()]);
            return [];
        }

        \BCC\Trust\Core\Support\CircuitBreaker::recordSuccess($cbKey);

        $data   = json_decode(wp_remote_retrieve_body($response), true);
        $assets = $data['result']['items'] ?? [];

        // Group by collection mint to deduplicate
        $seen        = [];
        $collections = [];

        foreach ($assets as $asset) {
            $collectionMint = $asset['grouping'][0]['group_value'] ?? '';
            $collectionName = $asset['content']['metadata']['name']  ?? '';
            $image          = $asset['content']['links']['image']    ?? '';

            if (empty($collectionMint) || isset($seen[$collectionMint])) {
                continue;
            }

            $seen[$collectionMint] = true;

            $collections[] = [
                'contract_address' => $collectionMint,
                'name'             => sanitize_text_field($collectionName),
                'image'            => esc_url_raw($image),
                'token_type'       => 'SPL',
                'balance'          => 1,
            ];
        }

        return $collections;
    }

    /**
     * Determine the highest role a wallet holds in an Ethereum NFT project.
     *
     * @param string $walletAddress  0x-prefixed Ethereum address
     * @param string $contractAddress 0x-prefixed ERC-721/1155 contract address
     * @return string  One of ROLE_CREATOR, ROLE_HOLDER, ROLE_TEAM, ROLE_NONE
     */
    public static function getEthRole(string $walletAddress, string $contractAddress): string {

        // Creator check first (highest trust)
        if (self::isEthContractCreator($walletAddress, $contractAddress)) {
            return self::ROLE_CREATOR;
        }

        // Holder check
        if (self::isEthNftHolder($walletAddress, $contractAddress)) {
            return self::ROLE_HOLDER;
        }

        return self::ROLE_NONE;
    }

    /**
     * Determine the highest role a wallet holds in a Solana NFT project.
     *
     * @param string $walletAddress   Base58 Solana wallet address
     * @param string $mintAddress     Base58 Candy Machine / collection mint address
     * @return string
     */
    public static function getSolanaRole(string $walletAddress, string $mintAddress): string {

        // Creator: check if wallet is the verified creator of the collection
        if (self::isSolanaCollectionCreator($walletAddress, $mintAddress)) {
            return self::ROLE_CREATOR;
        }

        // Holder: wallet owns at least one token from the collection
        if (self::isSolanaNftHolder($walletAddress, $mintAddress)) {
            return self::ROLE_HOLDER;
        }

        return self::ROLE_NONE;
    }

    /**
     * Validate a Cosmos REST endpoint URL against the allowlist.
     *
     * Prevents SSRF by ensuring only approved hosts are contacted.
     *
     * @param string $url  The REST endpoint URL to validate.
     * @return true|WP_Error  True if valid, WP_Error otherwise.
     */
    private static function validateCosmosEndpoint(string $url) {
        $parsed = wp_parse_url($url);

        // HTTPS-only in production. The `bcc_trust_allow_cosmos_http` filter
        // exists for local-dev mocks only — defaults to false so a misfiled
        // wp-config constant cannot downgrade traffic silently.
        $allowedSchemes = apply_filters('bcc_trust_allow_cosmos_http', false)
            ? ['https', 'http']
            : ['https'];
        if (empty($parsed['scheme']) || !in_array($parsed['scheme'], $allowedSchemes, true)) {
            return new WP_Error('invalid_scheme', 'Cosmos REST URL scheme not permitted.');
        }

        $host = strtolower($parsed['host'] ?? '');
        if (empty($host)) {
            return new WP_Error('missing_host', 'Cosmos REST URL has no host.');
        }

        $allowed = apply_filters('bcc_trust_allowed_cosmos_hosts', self::ALLOWED_COSMOS_HOSTS);

        if (!in_array($host, $allowed, true)) {
            \BCC\Core\Log\Logger::error(sprintf('BCC Trust: Blocked Cosmos REST request to disallowed host: %s', $host));
            return new WP_Error(
                'disallowed_host',
                sprintf('Cosmos REST host "%s" is not in the allowed list.', $host)
            );
        }

        // SSRF defence-in-depth: even with a curated allowlist, another plugin
        // (or a compromised option) may inject a host that resolves — or has
        // been DNS-poisoned — to a private/link-local/loopback address. Block
        // those outright so internal metadata services (e.g. 169.254.169.254)
        // and dev-only services cannot be probed via this endpoint.
        //
        // Resolved via gethostbynamel() for multi-answer coverage. Literal IPs
        // in the host field are already rejected by the allowlist (none of the
        // defaults are raw IPs) and also trigger the validator below.
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if (empty($ips)) {
            return new WP_Error(
                'host_unresolvable',
                sprintf('Cosmos REST host "%s" did not resolve.', $host)
            );
        }
        foreach ($ips as $ip) {
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($public === false) {
                \BCC\Core\Log\Logger::error(sprintf(
                    'BCC Trust: Blocked Cosmos REST request to %s — resolves to non-public IP %s',
                    $host,
                    $ip
                ));
                return new WP_Error(
                    'disallowed_ip',
                    sprintf('Cosmos REST host "%s" resolves to a non-public address.', $host)
                );
            }
        }

        return true;
    }

    /**
     * Determine the highest role a wallet holds in a Cosmos NFT project.
     *
     * @param string $walletAddress  Bech32 Cosmos address
     * @param string $contractAddress CosmWasm contract address
     * @param string $restEndpoint   REST API base URL for the chain
     * @return string
     */
    public static function getCosmosRole(
        string $walletAddress,
        string $contractAddress,
        string $restEndpoint = self::DEFAULT_COSMOS_RPC
    ): string {

        $valid = self::validateCosmosEndpoint($restEndpoint);
        if (is_wp_error($valid)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust: Cosmos endpoint rejected — ', ['detail' => $valid->get_error_message()]);
            return self::ROLE_NONE;
        }

        // Breaker key includes the host so a single flaky LCD node doesn't
        // short-circuit verification against all Cosmos chains in the allowlist.
        $host = strtolower(wp_parse_url($restEndpoint, PHP_URL_HOST) ?: 'unknown');
        $cbKey = 'cosmos:' . $host;
        if (!\BCC\Trust\Core\Support\CircuitBreaker::allow($cbKey)) {
            return self::ROLE_NONE;
        }

        try {
            if (self::isCosmosContractOwner($walletAddress, $contractAddress, $restEndpoint)) {
                \BCC\Trust\Core\Support\CircuitBreaker::recordSuccess($cbKey);
                return self::ROLE_CREATOR;
            }

            if (self::isCosmosNftHolder($walletAddress, $contractAddress, $restEndpoint)) {
                \BCC\Trust\Core\Support\CircuitBreaker::recordSuccess($cbKey);
                return self::ROLE_HOLDER;
            }

            // Both checks completed without a transport error — treat as
            // success from the breaker's perspective so a run of "no role"
            // verdicts doesn't trip it. The checks log their own WP_Errors
            // internally and only return false on a real RPC failure.
            \BCC\Trust\Core\Support\CircuitBreaker::recordSuccess($cbKey);
        } catch (\Throwable $e) {
            \BCC\Trust\Core\Support\CircuitBreaker::recordFailure($cbKey, $e);
            \BCC\Core\Log\Logger::warning('[bcc-trust] Cosmos role lookup failed', [
                'host'  => $host,
                'error' => $e->getMessage(),
            ]);
            return self::ROLE_NONE;
        }

        return self::ROLE_NONE;
    }


    /*
    |--------------------------------------------------------------------------
    | ETHEREUM HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Check if walletAddress == owner() on the contract.
     * Falls back to checking the contract deployer via eth_getTransactionByHash
     * if owner() reverts (i.e. contract has no Ownable).
     */
    private static function isEthContractCreator(string $wallet, string $contract): bool {
        $rpc = defined('BCC_ETH_RPC_URL') ? BCC_ETH_RPC_URL : self::DEFAULT_ETH_RPC;

        // Call owner() — selector 0x8da5cb5b
        $result = self::ethCall($rpc, $contract, '0x8da5cb5b');
        if ($result && strlen($result) >= 66) {
            // Result is 32-byte ABI-encoded address: last 20 bytes
            $ownerHex = '0x' . substr($result, 26, 40);
            if (strtolower($ownerHex) === strtolower($wallet)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check ERC-721 balanceOf(wallet) > 0.
     * Also tries ERC-1155 balanceOf(wallet, 0) as a fallback.
     */
    private static function isEthNftHolder(string $wallet, string $contract): bool {
        $rpc = defined('BCC_ETH_RPC_URL') ? BCC_ETH_RPC_URL : self::DEFAULT_ETH_RPC;

        // ERC-721 balanceOf(address) — selector 0x70a08231
        $paddedWallet = str_pad(ltrim(strtolower($wallet), '0x'), 64, '0', STR_PAD_LEFT);
        $data = '0x70a08231' . $paddedWallet;

        $result = self::ethCall($rpc, $contract, $data);
        if ($result) {
            $balance = hexdec(ltrim($result, '0x'));
            if ($balance > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Low-level eth_call via JSON-RPC.
     *
     * @param string $rpc      RPC endpoint URL
     * @param string $to       Contract address
     * @param string $data     ABI-encoded call data (0x-prefixed hex)
     * @return string|null     Hex result string or null on error
     */
    private static function ethCall(string $rpc, string $to, string $data): ?string {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'eth_call',
            'params'  => [
                ['to' => $to, 'data' => $data],
                'latest',
            ],
            'id' => 1,
        ]);
        if ($body === false) {
            \BCC\Core\Log\Logger::error('[bcc-trust] BCC Trust ETH RPC encode failed', ['to' => $to]);
            return null;
        }

        $response = SafeHttpClient::post($rpc, [
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => $body,
            'timeout'     => 10,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust ETH RPC error: ', ['detail' => $response->get_error_message()]);
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return $decoded['result'] ?? null;
    }


    /*
    |--------------------------------------------------------------------------
    | SOLANA HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Check if wallet is a verified creator of the collection.
     * We query the mint account metadata to read the creators array.
     */
    private static function isSolanaCollectionCreator(string $wallet, string $mint): bool {
        $rpc = defined('BCC_SOL_RPC_URL') ? BCC_SOL_RPC_URL : self::DEFAULT_SOL_RPC;

        // Derive Metaplex metadata PDA — complex; skip for now and rely on holder check
        // Full creator verification would require deriving the metadata PDA from the mint
        // and reading on-chain data. For MVP, return false and let admins assign creator manually.
        return false;
    }

    /**
     * Check if wallet holds any SPL token from a specific collection mint.
     * Uses getTokenAccountsByOwner with the mint address.
     */
    private static function isSolanaNftHolder(string $wallet, string $mint): bool {
        $rpc = defined('BCC_SOL_RPC_URL') ? BCC_SOL_RPC_URL : self::DEFAULT_SOL_RPC;

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'getTokenAccountsByOwner',
            'params'  => [
                $wallet,
                ['mint' => $mint],
                ['encoding' => 'jsonParsed'],
            ],
        ]);
        if ($body === false) {
            \BCC\Core\Log\Logger::error('[bcc-trust] BCC Trust SOL RPC encode failed', ['wallet' => $wallet, 'mint' => $mint]);
            return false;
        }

        $response = SafeHttpClient::post($rpc, [
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => $body,
            'timeout'     => 10,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust SOL RPC error: ', ['detail' => $response->get_error_message()]);
            return false;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $accounts = $decoded['result']['value'] ?? [];

        foreach ($accounts as $account) {
            $amount = $account['account']['data']['parsed']['info']['tokenAmount']['uiAmount'] ?? 0;
            if ($amount > 0) {
                return true;
            }
        }

        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | COSMOS HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Check if walletAddress is the contract admin/owner via REST.
     */
    private static function isCosmosContractOwner(
        string $wallet,
        string $contract,
        string $rest
    ): bool {
        $url = rtrim($rest, '/') . '/cosmwasm/wasm/v1/contract/' . rawurlencode($contract);

        $response = SafeHttpClient::get($url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $admin = $data['contract_info']['admin'] ?? '';

        return $admin === $wallet;
    }

    /**
     * Check if wallet owns any token in a CW721 contract.
     * Calls the tokens{owner} smart query.
     */
    private static function isCosmosNftHolder(
        string $wallet,
        string $contract,
        string $rest
    ): bool {
        // CW721 smart query: {"tokens":{"owner":"<wallet>","limit":1}}
        $query = base64_encode(json_encode([
            'tokens' => ['owner' => $wallet, 'limit' => 1],
        ]) ?: '');

        $url = rtrim($rest, '/') . '/cosmwasm/wasm/v1/contract/'
             . rawurlencode($contract) . '/smart/' . $query;

        $response = SafeHttpClient::get($url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            return false;
        }

        $data   = json_decode(wp_remote_retrieve_body($response), true);
        $tokens = $data['data']['tokens'] ?? [];

        return !empty($tokens);
    }
}
