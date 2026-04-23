<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\ServiceLocator;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\CircuitBreaker;

/**
 * Fetches raw on-chain signals from Etherscan (Ethereum) and Solana public RPC.
 *
 * Required constants (define in wp-config.php):
 *   BCC_ETHERSCAN_API_KEY  — https://etherscan.io/myapikey (free)
 *
 * Solana uses the public mainnet RPC — no key required for basic queries.
 */
class SignalFetcher
{
    const ETHERSCAN_BASE = 'https://api.etherscan.io/api';
    const HTTP_TIMEOUT   = 10;

    /**
     * Resolve Solana RPC URL. Uses BCC_SOLANA_RPC_URL constant if defined,
     * otherwise falls back to the public mainnet endpoint (rate-limited).
     */
    private static function getSolanaRpcUrl(): string
    {
        return defined('BCC_SOLANA_RPC_URL') ? BCC_SOLANA_RPC_URL : 'https://api.mainnet-beta.solana.com';
    }

    /**
     * Validate wallet address format before making external API calls.
     */
    public static function validate_address(string $address, string $chain): bool
    {
        return match ($chain) {
            'ethereum' => (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address),
            'solana'   => (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address),
            'cosmos'   => (bool) preg_match('/^[a-z]{1,20}1[a-z0-9]{38,58}$/', $address),
            default    => false,
        };
    }

    /**
     * Fetch signals for one wallet on one chain.
     *
     * Circuit breaking, retries, and per-chain budgeting live in the
     * shared `CircuitBreaker` / `ApiRetry` layer (exercised by
     * `etherscanRequest()` and `solanaRpc()`). This method only handles
     * address validation, caching, and scoring. The cache lookup sits
     * BEFORE any circuit check because cached data never hits upstream
     * and should stay available during outages.
     *
     * @param bool $force  Delete transient cache and force a fresh API call.
     * @return array<string, mixed>|null  Associative array of signal data, or null on API error.
     */
    public static function fetch(string $address, string $chain, bool $force = false): ?array
    {
        if (!self::validate_address($address, $chain)) {
            \BCC\Core\Log\Logger::error('[Onchain] Invalid ' . $chain . ' address format: ' . $address);
            return null;
        }

        $cache_key = 'bcc_onchain_' . md5( $address . $chain );

        if ( $force ) {
            delete_transient( $cache_key );
        }

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $result = match ($chain) {
            'ethereum' => self::fetchEthereum($address),
            'solana'   => self::fetchSolana($address),
            default    => null,
        };

        if ( $result !== null ) {
            // Align this transient's TTL with the canonical DB cache
            // (BCC_ONCHAIN_CACHE_HOURS, typically 24h) so the two layers
            // cannot disagree. Previously 6h here vs 24h in the DB
            // meant SignalRepository::get_cached returned 24h-old rows
            // AFTER this transient expired, while an admin "force
            // refresh" that deleted only this transient still saw the
            // stale DB row short-circuit the fetch path.
            $ttl = defined('BCC_ONCHAIN_CACHE_HOURS')
                ? (int) BCC_ONCHAIN_CACHE_HOURS * HOUR_IN_SECONDS
                : 6 * HOUR_IN_SECONDS;
            set_transient( $cache_key, $result, $ttl );
        }

        return $result;
    }

    /**
     * Get signal health status for the chains SignalFetcher handles.
     *
     * Sources from the unified `CircuitBreaker` state + the per-chain
     * "last success" option the breaker maintains. Kept under the old
     * shape so the admin SettingsPage renderer does not need to change.
     *
     * @return array<string, array{last_success?: int, last_failure?: int, consecutive_failures?: int, status?: string}>
     */
    public static function getChainHealthStatus(): array
    {
        $chains   = ['ethereum', 'solana', 'cosmos'];
        $statuses = [];

        foreach ($chains as $chain) {
            $chainId = ChainRepository::resolveId($chain);
            if ($chainId === null) {
                $statuses[$chain] = [];
                continue;
            }

            $cbStatus = CircuitBreaker::getAllStatus([$chainId])[$chainId] ?? null;
            if ($cbStatus === null) {
                $statuses[$chain] = [];
                continue;
            }

            $failures = (int) $cbStatus['failures'];
            $openedAt = (int) $cbStatus['opened_at'];

            // Freshness: persists across wp_cache flushes via option.
            $lastSuccessOpt = get_option('bcc_onchain_last_success_' . $chainId, null);
            $lastSuccess    = $lastSuccessOpt !== null ? (int) $lastSuccessOpt : null;

            // Map breaker state → legacy "status" bucket so the dashboard
            // still renders healthy/intermittent/degraded.
            if ($failures === 0) {
                $status = $lastSuccess !== null ? 'healthy' : 'unknown';
            } elseif ($failures >= 3) {
                $status = 'degraded';
            } else {
                $status = 'intermittent';
            }

            $entry = [
                'consecutive_failures' => $failures,
                'status'               => $status,
            ];
            if ($lastSuccess !== null) {
                $entry['last_success'] = $lastSuccess;
            }
            // Breaker only stamps `opened_at` on transitions to OPEN, so
            // we only have a meaningful "last failure time" once the
            // breaker has tripped. Individual pre-threshold failures are
            // not timestamped.
            if ($openedAt > 0) {
                $entry['last_failure'] = $openedAt;
            }

            $statuses[$chain] = $entry;
        }

        return $statuses;
    }

    /**
     * Return all wallets connected to a user, keyed by chain.
     *
     * @return array<string, string[]>  ['ethereum' => ['0xABC…'], 'solana' => ['abc…']]
     */
    public static function get_connected_wallets(int $user_id): array
    {
        return ServiceLocator::resolveWalletLinkRead()->getLinksForUser($user_id);
    }

    // ── Ethereum ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private static function fetchEthereum(string $address): ?array
    {
        $api_key = defined('BCC_ETHERSCAN_API_KEY') ? BCC_ETHERSCAN_API_KEY : '';

        if (!$api_key) {
            \BCC\Core\Log\Logger::error('[Onchain] BCC_ETHERSCAN_API_KEY not defined in wp-config.php');
            return null;
        }

        $all_txs = self::etherscanRequest([
            'module'     => 'account',
            'action'     => 'txlist',
            'address'    => $address,
            'startblock' => 0,
            'endblock'   => 99999999,
            'page'       => 1,
            'offset'     => 1000,
            'sort'       => 'asc',
            'apikey'     => $api_key,
        ]);

        if ($all_txs === null) {
            return null;
        }

        $first_tx_at     = null;
        $wallet_age_days = 0;
        $tx_count        = 0;
        $contract_count  = 0;

        $tx_count = count($all_txs);

        if ($tx_count > 0 && !empty($all_txs[0]->timeStamp)) {
            $ts              = (int) $all_txs[0]->timeStamp;
            $first_tx_at     = gmdate('Y-m-d H:i:s', $ts);
            $wallet_age_days = (int) floor((time() - $ts) / DAY_IN_SECONDS);
        }

        foreach ($all_txs as $tx) {
            if (isset($tx->contractAddress) && $tx->contractAddress !== '' && strtolower($tx->from ?? '') === strtolower($address)) {
                $contract_count++;
            }
        }

        return [
            'wallet_age_days' => $wallet_age_days,
            'first_tx_at'     => $first_tx_at,
            'tx_count'        => $tx_count,
            'contract_count'  => $contract_count,
            'raw_data'        => [
                'source'          => 'etherscan',
                'wallet_age_days' => $wallet_age_days,
                'tx_count'        => $tx_count,
                'contract_count'  => $contract_count,
            ],
        ];
    }

    // ── Solana ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private static function fetchSolana(string $address): ?array
    {
        $signatures = self::solanaRpc('getSignaturesForAddress', [
            $address,
            ['limit' => 1000],
        ]);

        if ($signatures === null) {
            return null;
        }

        $tx_count        = count($signatures);
        $first_tx_at     = null;
        $wallet_age_days = 0;

        if ($tx_count > 0) {
            $oldest = end($signatures);
            if (isset($oldest->blockTime) && $oldest->blockTime) {
                $ts              = (int) $oldest->blockTime;
                $first_tx_at     = gmdate('Y-m-d H:i:s', $ts);
                $wallet_age_days = (int) floor((time() - $ts) / DAY_IN_SECONDS);
            }
        }

        // Solana RPC does not expose a reliable program-deployment count.
        // Store 0 for the DB column (NOT NULL constraint), but flag as
        // unsupported in raw_data so the scorer can exclude this metric.
        $contract_count  = 0;

        return [
            'wallet_age_days' => $wallet_age_days,
            'first_tx_at'     => $first_tx_at,
            'tx_count'        => $tx_count,
            'contract_count'  => $contract_count,
            'raw_data'        => [
                'source'                    => 'solana_rpc',
                'wallet_age_days'           => $wallet_age_days,
                'tx_count'                  => $tx_count,
                'tx_count_capped'           => ($tx_count === 1000),
                'contract_count_unsupported' => true,
            ],
        ];
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     * @return list<\stdClass>|null
     */
    private static function etherscanRequest(array $params): ?array
    {
        $raw = self::etherscanRequestRaw($params);
        if ($raw !== null && isset($raw->status) && $raw->status === '1' && isset($raw->result) && is_array($raw->result)) {
            /** @var list<\stdClass> */
            return $raw->result;
        }
        return null;
    }

    /** @param array<string, mixed> $params */
    private static function etherscanRequestRaw(array $params): ?object
    {
        $url      = add_query_arg($params, self::ETHERSCAN_BASE);

        $chainId = \BCC\Trust\Onchain\Repositories\ChainRepository::resolveId('ethereum');

        $response = ApiRetry::get($url, [
            'timeout'   => self::HTTP_TIMEOUT,
            'sslverify' => true,
        ], [
            'label'    => 'Etherscan signal ' . ($params['action'] ?? 'query'),
            'chain_id' => $chainId ?? 0,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[Onchain] Etherscan request failed: ' . preg_replace('/apikey=[^&]+/', 'apikey=***', $response->get_error_message()));
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \BCC\Core\Log\Logger::error('[Onchain] Etherscan JSON decode error');
            return null;
        }

        return $json;
    }

    /**
     * @param array<int, mixed> $params
     * @return object[]|null
     */
    private static function solanaRpc(string $method, array $params): ?array
    {
        $body     = wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

        $chainId = \BCC\Trust\Onchain\Repositories\ChainRepository::resolveId('solana');

        $response = ApiRetry::post(self::getSolanaRpcUrl(), [
            'timeout'     => self::HTTP_TIMEOUT,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => $body,
            'sslverify'   => true,
        ], [
            'label'    => 'Solana signal ' . $method,
            'chain_id' => $chainId ?? 0,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[Onchain] Solana RPC request failed: ' . $response->get_error_message());
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response));

        if (json_last_error() !== JSON_ERROR_NONE || !isset($json->result)) {
            \BCC\Core\Log\Logger::error('[Onchain] Solana RPC invalid response');
            return null;
        }

        return is_array($json->result) ? $json->result : null;
    }
}
