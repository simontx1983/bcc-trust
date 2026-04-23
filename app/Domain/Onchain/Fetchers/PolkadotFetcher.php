<?php

namespace BCC\Trust\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\ApiRetry;

/**
 * Polkadot Validator Fetcher
 *
 * Uses the Subscan REST API (free tier) to fetch validator data.
 * Endpoint: https://polkadot.api.subscan.io
 *
 * Subscan returns stake in Plancks (1 DOT = 1e10 Plancks).
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
class PolkadotFetcher implements FetcherInterface
{
    /** @var ChainRow */
    private object $chain;
    private string $base_url;
    private int    $timeout = 20;

    /** @param ChainRow $chain */
    public function __construct(object $chain)
    {
        $this->chain    = $chain;
        $this->base_url = rtrim($chain->rest_url ?? 'https://polkadot.api.subscan.io', '/');
    }

    /** @return ChainRow */
    public function get_chain(): object
    {
        return $this->chain;
    }

    public function supports_feature(string $feature): bool
    {
        return $feature === 'validator';
    }

    /**
     * Fetch a single validator by stash address.
     *
     * @return array<string, mixed>
     */
    public function fetch_validator(string $address): array
    {
        $data = $this->apiPost('/api/scan/staking/validator', [
            'stash' => $address,
        ]);

        if (!$data || !isset($data['info'])) {
            return [];
        }

        return $this->mapValidator($data['info'], 0);
    }

    /**
     * Fetch all active validators sorted by stake.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch_all_validators(): array
    {
        $validators = [];
        $page       = 0;
        $perPage    = 100;

        // Paginate through all validators (Subscan returns max 100 per page).
        while (true) {
            $data = $this->apiPost('/api/scan/staking/validators', [
                'order'      => 'desc',
                'order_field' => 'bonded_total',
                'row'        => $perPage,
                'page'       => $page,
            ]);

            $list = $data['list'] ?? [];
            if (empty($list)) {
                break;
            }

            foreach ($list as $v) {
                $validators[] = $v;
            }

            // Stop if we got fewer than requested (last page).
            if (count($list) < $perPage) {
                break;
            }

            $page++;

            // Safety cap — don't fetch more than 10 pages (1000 validators).
            if ($page >= 10) {
                break;
            }
        }

        if (empty($validators)) {
            return [];
        }

        // Sort by bonded_total descending for rank assignment.
        usort($validators, function ($a, $b) {
            return bccomp($b['bonded_total'] ?? '0', $a['bonded_total'] ?? '0');
        });

        $results = [];
        foreach ($validators as $rank => $v) {
            $mapped = $this->mapValidator($v, $rank);
            if ($mapped['status'] === 'active') {
                $results[] = $mapped;
            }
        }

        return $results;
    }

    /**
     * Enrich a validator. Subscan returns full data in one call.
     *
     * @return array<string, mixed>
     */
    public function enrich_validator(string $address, ?object $existingRow = null): array
    {
        return $this->fetch_validator($address);
    }

    /**
     * Not supported — Polkadot doesn't have NFT collections in this fetcher.
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
     * Map a Subscan validator response to the standard schema.
     *
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    private function mapValidator(array $v, int $rank): array
    {
        $chainId    = (int) $this->chain->id;
        $decimals   = (int) ($this->chain->decimals ?? 10);
        $divisor    = pow(10, $decimals);

        // Address: stash account
        $address = $v['stash_account_display']['address']
                ?? $v['stash_account']
                ?? '';

        // Moniker: on-chain identity display name, fallback to truncated address.
        $moniker = $v['stash_account_display']['display'] ?? null;
        if (!$moniker || $moniker === $address) {
            $moniker = $address;
            if (strlen($moniker) > 16) {
                $moniker = substr($moniker, 0, 6) . '...' . substr($moniker, -4);
            }
        }

        // Stake values: Plancks → DOT
        $bondedTotal = round((float) ($v['bonded_total'] ?? '0') / $divisor, 6);
        $bondedOwner = isset($v['bonded_owner'])
            ? round((float) $v['bonded_owner'] / $divisor, 6)
            : null;

        // Commission: Subscan returns as perbill (1e9 = 100%).
        // Some responses return it as a percentage string already.
        $commissionRaw = $v['validator_prefs_value'] ?? null;
        $commission = null;
        if ($commissionRaw !== null) {
            $commVal = (float) $commissionRaw;
            // If > 100, it's perbill format (1e9 = 100%).
            $commission = $commVal > 100 ? round($commVal / 1e7, 2) : round($commVal, 2);
        }

        // Nominator count (delegators).
        $nominators = isset($v['count_nominators']) ? (int) $v['count_nominators'] : null;

        // Status: Subscan uses 'validator' for active, 'waiting' for inactive.
        $isActive = ($v['validator_status'] ?? '') !== 'waiting'
                 && ($v['is_active'] ?? true);

        return [
            'operator_address'  => $address,
            'chain_id'          => $chainId,
            'moniker'           => $moniker,
            'status'            => $isActive ? 'active' : 'inactive',
            'commission_rate'   => $commission,
            'total_stake'       => $bondedTotal,
            'self_stake'        => $bondedOwner,
            'delegator_count'   => $nominators,
            'uptime_30d'        => null, // Would need era points tracking
            'jailed_count'      => 0,
            'voting_power_rank' => $rank + 1,
        ];
    }

    /**
     * Make a POST request to the Subscan API.
     * Requires BCC_SUBSCAN_API_KEY defined in wp-config.php.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function apiPost(string $path, array $body): ?array
    {
        $apiKey = defined('BCC_SUBSCAN_API_KEY') ? BCC_SUBSCAN_API_KEY : '';

        if (!$apiKey) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] BCC_SUBSCAN_API_KEY not defined in wp-config.php. Get a free key at https://support.subscan.io/');
            return null;
        }

        $url     = $this->base_url . $path;
        $chainId = (int) $this->chain->id;

        $response = ApiRetry::post($url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'X-API-Key'    => $apiKey,
            ],
            'body' => wp_json_encode($body),
        ], [
            'label'    => 'Subscan ' . $path,
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] error for ' . $path . ': ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] HTTP ' . $code . ' for ' . $path);
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($json) || ($json['code'] ?? -1) !== 0) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] API error for ' . $path . ': ' . ($json['message'] ?? 'unknown'));
            return null;
        }

        return $json['data'] ?? null;
    }
}
