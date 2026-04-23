<?php

namespace BCC\Trust\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\ApiRetry;

/**
 * THORChain Node Fetcher
 *
 * THORChain uses a custom node API (thornode), not standard Cosmos SDK LCD.
 * Endpoints:
 *   GET /thorchain/nodes        — all nodes
 *   GET /thorchain/node/{addr}  — single node
 *
 * Bond amounts are in 1e8 base units (1 RUNE = 100,000,000).
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
class ThorchainFetcher implements FetcherInterface
{
    /** @var ChainRow */
    private object $chain;
    private string $base_url;
    private int    $timeout = 20;

    /** @param ChainRow $chain */
    public function __construct(object $chain)
    {
        $this->chain    = $chain;
        $this->base_url = rtrim($chain->rest_url ?? 'https://thornode.ninerealms.com', '/');
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
     * Fetch a single node by address.
     *
     * @return array<string, mixed>
     */
    public function fetch_validator(string $address): array
    {
        $node = $this->apiGet("/thorchain/node/{$address}");
        if (!$node || !isset($node['node_address'])) {
            return [];
        }

        return $this->mapNode($node, 0);
    }

    /**
     * Fetch all active and standby nodes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch_all_validators(): array
    {
        $nodes = $this->apiGet('/thorchain/nodes');
        if (!is_array($nodes)) {
            return [];
        }

        // Sort by total_bond descending for rank assignment.
        usort($nodes, function ($a, $b) {
            return bccomp($b['total_bond'] ?? '0', $a['total_bond'] ?? '0');
        });

        $results = [];
        foreach ($nodes as $rank => $node) {
            // Skip disabled nodes — they've left the network.
            $status = $node['status'] ?? '';
            if ($status === 'Disabled') {
                continue;
            }

            $results[] = $this->mapNode($node, $rank);
        }

        return $results;
    }

    /**
     * Enrich a single node. THORChain returns all data in one call,
     * so this just re-fetches the node — no separate enrichment needed.
     *
     * @return array<string, mixed>
     */
    public function enrich_validator(string $address, ?object $existingRow = null): array
    {
        return $this->fetch_validator($address);
    }

    /**
     * Not supported — THORChain doesn't have NFT collections.
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
     * Map a THORChain node response to the standard validator schema.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function mapNode(array $node, int $rank): array
    {
        $chainId    = (int) $this->chain->id;
        $nodeAddr   = $node['node_address'] ?? '';
        $totalBond  = $this->toDisplay($node['total_bond'] ?? '0');
        $status     = $this->mapStatus($node['status'] ?? '');
        $slashPts   = (int) ($node['slash_points'] ?? 0);
        $version    = $node['version'] ?? '';
        $operatorAddr = $node['node_operator_address'] ?? '';

        // Self-bond: find the bond provider matching the node operator.
        $selfBond = null;
        $providers = $node['bond_providers']['providers'] ?? [];
        foreach ($providers as $provider) {
            if (($provider['bond_address'] ?? '') === $operatorAddr) {
                $selfBond = $this->toDisplay($provider['bond'] ?? '0');
                break;
            }
        }

        // Delegator count: number of bond providers (including operator).
        $delegatorCount = count($providers);

        // Moniker: THORChain nodes don't have monikers, use truncated address.
        $moniker = $nodeAddr;
        if (strlen($moniker) > 16) {
            $moniker = substr($moniker, 0, 8) . '...' . substr($moniker, -4);
        }

        return [
            'operator_address'  => $nodeAddr,
            'chain_id'          => $chainId,
            'moniker'           => $moniker,
            'status'            => $status,
            'commission_rate'   => null, // THORChain has no commission concept
            'total_stake'       => $totalBond,
            'self_stake'        => $selfBond,
            'delegator_count'   => $delegatorCount,
            'uptime_30d'        => null, // THORChain uses slash_points instead
            'jailed_count'      => $slashPts,
            'voting_power_rank' => $rank + 1,
        ];
    }

    /**
     * Convert base units (1e8) to display units.
     */
    private function toDisplay(string $amount): float
    {
        return round((float) $amount / 1e8, 6);
    }

    /**
     * Map THORChain status to our standard status.
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'Active'  => 'active',
            'Ready'   => 'active',
            default   => 'inactive',
        };
    }

    /**
     * Make an HTTP GET request to the THORNode API.
     *
     * @return array<string, mixed>|null
     */
    private function apiGet(string $path): ?array
    {
        $url     = $this->base_url . $path;
        $chainId = (int) $this->chain->id;

        $response = ApiRetry::get($url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Accept'      => 'application/json',
                'x-client-id' => 'bcc-onchain',
            ],
        ], [
            'label'    => 'THORNode ' . $path,
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[THORChain Fetcher] error for ' . $path . ': ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[THORChain Fetcher] HTTP ' . $code . ' for ' . $path);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
