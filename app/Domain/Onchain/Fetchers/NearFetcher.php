<?php

namespace BCC\Trust\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\ApiRetry;

/**
 * NEAR Protocol Validator Fetcher
 *
 * Uses the NEAR JSON-RPC `validators` method to fetch all active validators.
 * Endpoint: https://rpc.mainnet.near.org
 *
 * Stake amounts are in yoctoNEAR (1 NEAR = 1e24 yoctoNEAR).
 * Validator names are readable pool IDs (e.g. "astro-stakers.poolv1.near").
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
class NearFetcher implements FetcherInterface
{
    /** @var ChainRow */
    private object $chain;
    private string $rpc_url;
    private int    $timeout = 20;

    /** @param ChainRow $chain */
    public function __construct(object $chain)
    {
        $this->chain   = $chain;
        $this->rpc_url = $chain->rpc_url ?? 'https://rpc.mainnet.near.org';
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
        return $feature === 'validator';
    }

    /**
     * Fetch a single validator by account ID.
     *
     * @return array<string, mixed>
     */
    public function fetch_validator(string $address): array
    {
        $validators = $this->getCurrentValidators();

        foreach ($validators as $v) {
            if (($v['account_id'] ?? '') === $address) {
                return $this->mapValidator($v, 0);
            }
        }

        return [];
    }

    /** @return array<int, array{validator_address: string, shares?: string|null, amount?: float|null}> */
    public function fetch_delegations(string $delegatorAddress): array
    {
        return []; // NEAR delegation discovery is per-pool and not implemented yet.
    }

    public function count_holdings(string $wallet, string $contract): int
    {
        return 0;
    }

    /**
     * @return array{items: list<array{contract_address: string, token_id: string, chain_id: int, collection_name: ?string, name: ?string, image_url: ?string, metadata_uri: ?string, token_standard: ?string}>, truncated: bool, cursor: ?string}
     */
    public function list_holdings(string $wallet, ?string $cursor = null): array
    {
        return ['items' => [], 'truncated' => false, 'cursor' => null];
    }

    /**
     * Fetch all active validators sorted by stake descending.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch_all_validators(): array
    {
        $validators = $this->getCurrentValidators();

        if (empty($validators)) {
            return [];
        }

        // Sort by stake descending for rank.
        usort($validators, function ($a, $b) {
            return bccomp($b['stake'] ?? '0', $a['stake'] ?? '0');
        });

        $results = [];
        foreach ($validators as $rank => $v) {
            $results[] = $this->mapValidator($v, $rank);
        }

        return $results;
    }

    /**
     * Enrich a validator. NEAR returns all data in one RPC call.
     *
     * @return array<string, mixed>
     */
    public function enrich_validator(string $address, ?object $existingRow = null): array
    {
        return $this->fetch_validator($address);
    }

    /**
     * Not supported.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    public function fetch_top_collections(int $limit = 100): array
    {
        return [];
    }

    // ── Internal ────────────────────────────────────────────────────

    /**
     * Fetch current_validators from the NEAR RPC.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCurrentValidators(): array
    {
        $result = $this->rpcCall('validators', [null]);

        if (!is_array($result) || !isset($result['current_validators'])) {
            return [];
        }

        return $result['current_validators'];
    }

    /**
     * Map a NEAR validator to the standard schema.
     *
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    private function mapValidator(array $v, int $rank): array
    {
        $accountId = $v['account_id'] ?? '';
        $stake     = $this->toDisplay($v['stake'] ?? '0');

        // Uptime: blocks produced / blocks expected.
        $produced = (int) ($v['num_produced_blocks'] ?? 0);
        $expected = (int) ($v['num_expected_blocks'] ?? 0);
        $uptime   = $expected > 0 ? round(($produced / $expected) * 100, 2) : null;

        // Moniker: NEAR pool names are already readable (e.g. "astro-stakers.poolv1.near").
        // Strip the ".poolv1.near" suffix for cleaner display.
        $moniker = $accountId;
        $moniker = preg_replace('/\.pool(v1)?\.near$/', '', $moniker);

        return [
            'operator_address'  => $accountId,
            'chain_id'          => (int) $this->chain->id,
            'moniker'           => $moniker,
            'status'            => 'active',
            'commission_rate'   => null, // NEAR pools set fees individually, not in RPC response
            'total_stake'       => $stake,
            'self_stake'        => null, // Not available from validators RPC
            'delegator_count'   => null, // Would need separate contract query
            'uptime_30d'        => $uptime,
            'jailed_count'      => 0,
            'voting_power_rank' => $rank + 1,
        ];
    }

    /**
     * Convert yoctoNEAR to NEAR.
     * 1 NEAR = 1e24 yoctoNEAR. Using string math to avoid float precision loss.
     */
    private function toDisplay(string $yocto): float
    {
        if (strlen($yocto) <= 24) {
            return round((float) $yocto / 1e24, 6);
        }

        // For large numbers, split to avoid float overflow.
        $whole    = substr($yocto, 0, -24) ?: '0';
        $fraction = str_pad(substr($yocto, -24), 24, '0', STR_PAD_LEFT);
        $fraction = substr($fraction, 0, 6); // 6 decimal places

        return (float) "{$whole}.{$fraction}";
    }

    /**
     * Make a JSON-RPC call to the NEAR RPC endpoint.
     *
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    private function rpcCall(string $method, array $params): ?array
    {
        $chainId = (int) $this->chain->id;
        $body    = wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => 'bcc',
            'method'  => $method,
            'params'  => $params,
        ]);

        $response = ApiRetry::post($this->rpc_url, [
            'timeout' => $this->timeout,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ], [
            'label'    => 'NEAR RPC ' . $method,
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[NEAR Fetcher] RPC error for ' . $method . ': ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[NEAR Fetcher] HTTP ' . $code . ' for ' . $method);
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($json) || isset($json['error'])) {
            \BCC\Core\Log\Logger::error('[NEAR Fetcher] RPC error: ' . ($json['error']['message'] ?? 'unknown'));
            return null;
        }

        return $json['result'] ?? null;
    }
}
