<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * NFT enrichment scheduler (V2 Phase 1c).
 *
 * Backfills name / image_url / metadata_uri / collection_name on
 * persistent holdings rows after the indexer has written them.
 *
 * Why a separate phase from indexing:
 *   - Indexer write path is hot (every ETH block, every Helius webhook
 *     delivery). Adding metadata fetches inline would multiply the CU
 *     budget by ~10× and stretch worker tick latency.
 *   - Metadata calls fail more often than transfer queries (IPFS
 *     gateway unreachable, mint metadata not yet uploaded, etc.).
 *     Decoupling means a flaky metadata source doesn't degrade the
 *     ownership-truth path.
 *   - Enrichment is the gate the read-path swap depends on. A row
 *     without `enriched_at` is invisible to `findVisibleEnrichedForWallet`,
 *     so HoldingsService falls through to the V1 transient — no
 *     thumbnail-less gallery regression.
 *
 * Per-chain dispatch via FetcherFactory; `EvmFetcher::fetchMetadataForToken`
 * and `SolanaFetcher::fetchMetadataForMint` are the per-chain seams.
 *
 * Cron `bcc_nft_enrichment_tick` runs every 5 minutes via the
 * existing `bcc_five_minutes` interval. Each tick processes one
 * batch of `BATCH_SIZE` rows per active chain. Long catch-ups split
 * across multiple ticks; per-row failures are tolerated (the
 * fetcher returns null and the row stays un-enriched until next tick).
 */
final class NftEnrichmentService
{
    public const CRON_HOOK  = 'bcc_nft_enrichment_tick';
    public const BATCH_SIZE = 50;

    /**
     * Run a tick across every active chain. Catches per-chain
     * exceptions so one chain's flakiness can't starve the others.
     */
    public static function runAllChains(): void
    {
        foreach (ChainRepository::getActive() as $chain) {
            $chainId = (int) $chain->id;
            if ($chainId <= 0) {
                continue;
            }
            try {
                self::runForChain($chainId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::warning('[NftEnrichmentService] tick failed', [
                    'chain_id' => $chainId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process up to BATCH_SIZE pending-enrichment rows on a chain.
     * Public so admin "Run enrichment now" buttons can invoke it.
     *
     * @return array{attempted: int, enriched: int, failed: int}
     */
    public static function runForChain(int $chainId): array
    {
        $result = ['attempted' => 0, 'enriched' => 0, 'failed' => 0];
        if ($chainId <= 0) {
            return $result;
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return $result;
        }
        if (!FetcherFactory::has_driver((string) $chain->chain_type)) {
            return $result;
        }
        $fetcher = FetcherFactory::make_for_chain($chain);

        $pending = NftHoldingsRepository::findPendingEnrichment($chainId, self::BATCH_SIZE);
        if ($pending === []) {
            return $result;
        }

        foreach ($pending as $row) {
            $result['attempted']++;

            $holdingId = (int) $row->id;
            $contract  = (string) $row->contract_address;
            $tokenId   = (string) $row->token_id;

            // Per-row try/catch makes the batch-isolation guarantee
            // STRUCTURAL rather than a property of every fetcher's
            // error handling. Today both fetchers convert all errors
            // to null returns, but a future fetcher edit that lets
            // one Throwable escape shouldn't poison the whole batch
            // for that chain on this tick.
            try {
                $metadata = self::fetchMetadata($fetcher, $contract, $tokenId);

                if ($metadata === null) {
                    // Soft fail — leave un-enriched and let the next tick
                    // try again. A persistent-failure counter could be added
                    // in a follow-up phase; not implemented today.
                    $result['failed']++;
                    continue;
                }

                $ok = NftHoldingsRepository::applyEnrichment($holdingId, $metadata);
                if ($ok) {
                    $result['enriched']++;
                } else {
                    $result['failed']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                \BCC\Core\Log\Logger::warning('[NftEnrichmentService] row failed', [
                    'chain_id'    => $chainId,
                    'holding_id'  => $holdingId,
                    'error'       => $e->getMessage(),
                ]);
                continue;
            }
        }

        // Per-tick completion fires every 5 minutes per chain — info-level
        // would flood the log with no-ops. Only log when something
        // actually happened OR a row failed (worth seeing).
        if ($result['enriched'] > 0 || $result['failed'] > 0) {
            \BCC\Core\Log\Logger::info('[NftEnrichmentService] tick complete', [
                'chain_id' => $chainId,
                'stats'    => $result,
            ]);
        }

        // Collection-row enrichment runs in the same tick. Discovery rows
        // come in via NftHoldingsIndexer's bridge; this is the matching
        // backfill path that fills name/image/supply/floor from Alchemy
        // NFT v3 `getContractMetadata`. EVM-only for now — Cosmos and
        // Solana already have collection rows populated by their own
        // top_collections fetchers, so they don't depend on this seam.
        try {
            self::enrichCollectionsForChain($chainId, $fetcher);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[NftEnrichmentService] collection enrichment failed', [
                'chain_id' => $chainId,
                'error'    => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Backfill name / image / supply / floor on collection rows that came
     * from the indexer's discovery bridge. Bounded by `BATCH_SIZE` per
     * chain per tick. Per-row failures are isolated — a single Alchemy
     * miss shouldn't poison the rest of the batch.
     */
    private static function enrichCollectionsForChain(int $chainId, object $fetcher): void
    {
        if (!($fetcher instanceof EvmFetcher)) {
            return;
        }

        $pending = CollectionRepository::findPendingEnrichment($chainId, self::BATCH_SIZE);
        if ($pending === []) {
            return;
        }

        $enriched = 0;
        $failed   = 0;
        foreach ($pending as $row) {
            $collectionId = (int) $row->id;
            $contract     = (string) $row->contract_address;

            try {
                $metadata = $fetcher->fetchContractMetadata($contract);
                if ($metadata === null) {
                    $failed++;
                    continue;
                }
                if (CollectionRepository::applyEnrichment($collectionId, $metadata)) {
                    $enriched++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                \BCC\Core\Log\Logger::warning('[NftEnrichmentService] collection row failed', [
                    'chain_id'      => $chainId,
                    'collection_id' => $collectionId,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        if ($enriched > 0 || $failed > 0) {
            \BCC\Core\Log\Logger::info('[NftEnrichmentService] collections enriched', [
                'chain_id' => $chainId,
                'enriched' => $enriched,
                'failed'   => $failed,
                'pending'  => count($pending),
            ]);
        }
    }

    /**
     * Per-chain dispatch. Returns null when the fetcher doesn't
     * support enrichment for this chain type (e.g., a chain-type
     * with no metadata API yet).
     *
     * @return array{name: ?string, image_url: ?string, metadata_uri: ?string, collection_name: ?string}|null
     */
    private static function fetchMetadata(object $fetcher, string $contract, string $tokenId): ?array
    {
        if ($fetcher instanceof EvmFetcher) {
            return $fetcher->fetchMetadataForToken($contract, $tokenId);
        }
        if ($fetcher instanceof SolanaFetcher) {
            // Solana persists mint as both contract + token_id; either
            // works as the lookup key.
            return $fetcher->fetchMetadataForMint($contract);
        }
        return null;
    }
}
