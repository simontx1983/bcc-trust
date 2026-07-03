<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use BCC\Trust\Onchain\Support\StargazeMarketplaceApi;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "How many linked wallets hold this collection?" — the demand signal
 * behind the Verify Collections queue.
 *
 * Verification used to be admin-guesswork: unverified rows carried no
 * hint of whether real platform users hold the collection, so a
 * community with genuine members could sit unverified indefinitely
 * (and its would-be members churn). This service turns linked-wallet
 * ownership into a per-collection count so the queue can rank by
 * demonstrated demand.
 *
 * Two sources, one map:
 *  - **Indexed chains (EVM/SOL):** COUNT(DISTINCT wallet_link_id) over
 *    the visible persistent holdings index — real per-wallet data,
 *    one bounded GROUP BY.
 *  - **Cosmos Hub:** no holdings persistence exists by design (V2
 *    Phase 2 read-time posture), so demand is computed from the same
 *    Stargaze marketplace per-wallet rollups discovery uses — one
 *    (6h-cached) API call per linked Hub wallet, wallet fan-out
 *    bounded. This deliberately does NOT create a Cosmos holdings
 *    store: the map is a render-time aggregate, recomputed from
 *    sources, so wallet unlinks self-heal with no cleanup hooks.
 *
 * Admin-page freshness (15 min) over per-request cost: the map is
 * cached as one blob; the Verify page is the only consumer.
 */
final class CollectionDemandService
{
    private const CACHE_KEY   = 'collection_demand_map_v1';
    private const CACHE_GROUP = 'bcc_onchain';
    private const CACHE_TTL   = 15 * MINUTE_IN_SECONDS;

    /** Max linked Cosmos wallets consulted per build (fan-out bound). */
    private const COSMOS_WALLET_CAP = 200;

    /**
     * Demand map keyed `"<chain_id>|<lowercased contract>"` → distinct
     * linked wallets holding it. Contracts nobody holds are absent.
     *
     * @return array<string, int>
     */
    public static function linkedHolderCounts(bool $force = false): array
    {
        if (!$force) {
            $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
            if (is_array($cached)) {
                /** @var array<string, int> $cached */
                return $cached;
            }
        }

        $map = self::fromHoldingsIndex();
        foreach (self::fromCosmosRollups() as $key => $count) {
            // Sources are disjoint by chain (holdings index has no
            // Cosmos rows), but max() keeps a hypothetical overlap from
            // double-counting.
            $map[$key] = max($map[$key] ?? 0, $count);
        }

        wp_cache_set(self::CACHE_KEY, $map, self::CACHE_GROUP, self::CACHE_TTL);
        return $map;
    }

    /** Composite key shared with the Verify Collections page. */
    public static function key(int $chainId, string $contract): string
    {
        return $chainId . '|' . strtolower($contract);
    }

    /** @return array<string, int> */
    private static function fromHoldingsIndex(): array
    {
        $map = [];
        foreach (NftHoldingsRepository::countDistinctWalletsPerContract() as $row) {
            $map[self::key((int) $row->chain_id, (string) $row->contract_address)] = (int) $row->wallets;
        }
        return $map;
    }

    /**
     * Cosmos Hub demand from per-wallet marketplace rollups. Best-effort:
     * a wallet whose rollup can't be read contributes nothing (the count
     * is a floor, never wrong-side inflated).
     *
     * @return array<string, int>
     */
    private static function fromCosmosRollups(): array
    {
        $chain = ChainRepository::getBySlug('cosmos');
        if ($chain === null) {
            return [];
        }
        $chainId = (int) $chain->id;

        $addresses = WalletRepository::listAddressesForChain($chainId, self::COSMOS_WALLET_CAP);
        if ($addresses === []) {
            return [];
        }
        if (count($addresses) === self::COSMOS_WALLET_CAP) {
            \BCC\Core\Log\Logger::warning('[CollectionDemandService] cosmos wallet fan-out hit its cap — demand counts are a floor', [
                'cap' => self::COSMOS_WALLET_CAP,
            ]);
        }

        $map = [];
        foreach ($addresses as $address) {
            $rollup = StargazeMarketplaceApi::profileCollections((string) $address);
            if ($rollup === null) {
                continue; // Unreadable wallet — skip, don't fail the map.
            }
            $seen = [];
            foreach ($rollup as $c) {
                $key = self::key($chainId, $c['contract_address']);
                if (isset($seen[$key])) {
                    continue; // One wallet counts once per collection.
                }
                $seen[$key] = true;
                $map[$key]  = ($map[$key] ?? 0) + 1;
            }
        }

        return $map;
    }
}
