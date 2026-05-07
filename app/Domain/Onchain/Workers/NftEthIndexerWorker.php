<?php

namespace BCC\Trust\Onchain\Workers;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\NftHoldingsIndexer;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\CircuitBreaker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * NFT ETH Indexer Worker (V2 Phase 1a).
 *
 * Cron-driven polling worker that walks confirmed Transfer events from
 * Alchemy and hands them to NftHoldingsIndexer. One tick per chain per
 * minute via wp-cron (`bcc_nft_eth_indexer_tick`).
 *
 * Confirmation gating (per locked decision 2026-05-06):
 *   safe_head = head_block - CONFIRMATIONS  (CONFIRMATIONS = 12 for ETH)
 *
 * Reorgs deeper than 12 blocks are vanishingly rare on mainnet ETH;
 * accepting that ~2-minute write lag in exchange for never-wrong
 * ownership state is the locked tradeoff.
 *
 * Per-tick budget gates:
 *   1. CircuitBreaker — if OPEN, skip (degraded state)
 *   2. ChainCheckpointRepository::cuRemainingForToday — if 0, skip
 *      (CU budget exhausted; resets at next UTC midnight)
 *   3. Block-range cap — process at most BLOCKS_PER_TICK blocks per
 *      run; large catch-ups split across multiple ticks
 *
 * On any failure: degrade chain state, persist last_error, do NOT
 * advance checkpoint. Next tick re-attempts the same range.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 * @phpstan-import-type CheckpointRow from ChainCheckpointRepository
 */
final class NftEthIndexerWorker
{
    public const CRON_HOOK                       = 'bcc_nft_eth_indexer_tick';
    public const CONFIRMATIONS                   = 12;
    public const BLOCKS_PER_TICK                 = 2000;
    public const CU_PER_CALL                     = 120;
    public const MAX_PAGES_PER_TICK              = 5; // safety bound on pagination loop
    public const DEFAULT_DAILY_BUDGET            = 50000;
    public const CRON_OVERDUE_THRESHOLD_SECONDS  = 300;
    private const ADVISORY_LOCK_PREFIX = 'bcc_nft_indexer_chain_';

    /**
     * Run a tick for every active EVM chain.
     */
    public static function runAllChains(): void
    {
        $chains = ChainRepository::getActive();
        if ($chains === []) {
            return;
        }

        foreach ($chains as $chain) {
            $chainType = (string) $chain->chain_type;
            if ($chainType !== 'evm') {
                continue;
            }
            $chainId = (int) $chain->id;
            if ($chainId <= 0) {
                continue;
            }

            try {
                self::runForChain($chainId);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[NftEthIndexerWorker] tick failed', [
                    'chain_id' => $chainId,
                    'error'    => $e->getMessage(),
                ]);
                ChainCheckpointRepository::recordFailure(
                    $chainId,
                    ChainCheckpointRepository::STATE_DEGRADED,
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * Run a tick for one chain. Public so admin "Run now" buttons can
     * invoke it directly from `IndexerStatusPage`.
     *
     * Wrapped in a per-chain advisory lock (Phase 1c carry-over from
     * 1a's review): with wp-cron + Helius webhook + admin "Run now"
     * all able to invoke this method, two concurrent ticks for the
     * same chain could double-spend CU and produce overlapping
     * checkpoint advances. The lock is non-blocking — if another
     * worker holds it we silently skip this tick.
     */
    public static function runForChain(int $chainId): void
    {
        if ($chainId <= 0) {
            return;
        }

        $lockKey = self::ADVISORY_LOCK_PREFIX . $chainId;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lockKey, 0)) {
            \BCC\Core\Log\Logger::info('[NftEthIndexerWorker] tick skipped — concurrent run holds the lock', [
                'chain_id' => $chainId,
            ]);
            return;
        }
        try {
            self::runForChainInsideLock($chainId);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lockKey);
        }
    }

    /**
     * Locked body of {@see runForChain()}. Extracted so the lock
     * acquire/release stays tight around the single call site that
     * touches the chain's checkpoint and CU budget.
     */
    private static function runForChainInsideLock(int $chainId): void
    {
        // Step 1: ensure checkpoint row exists (idempotent).
        ChainCheckpointRepository::ensureExists($chainId);

        $checkpoint = ChainCheckpointRepository::get($chainId);
        if ($checkpoint === null) {
            return;
        }
        if ((string) $checkpoint->state === ChainCheckpointRepository::STATE_DISABLED) {
            return; // Operator-paused.
        }

        // Step 2: circuit-breaker gate.
        if (CircuitBreaker::isOpen($chainId)) {
            ChainCheckpointRepository::recordFailure(
                $chainId,
                ChainCheckpointRepository::STATE_BREAKER_OPEN,
                'Circuit breaker open'
            );
            return;
        }

        // Step 3: CU-budget gate.
        $dailyBudget = defined('BCC_ETH_DAILY_RPC_BUDGET')
            ? (int) constant('BCC_ETH_DAILY_RPC_BUDGET')
            : self::DEFAULT_DAILY_BUDGET;
        $cuRemaining = ChainCheckpointRepository::cuRemainingForToday($chainId, $dailyBudget);
        if ($cuRemaining < self::CU_PER_CALL) {
            ChainCheckpointRepository::recordFailure(
                $chainId,
                ChainCheckpointRepository::STATE_DEGRADED,
                'Daily CU budget exhausted'
            );
            return;
        }

        // Step 4: resolve fetcher + chain.
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return;
        }
        if (!FetcherFactory::has_driver((string) $chain->chain_type)) {
            return;
        }
        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!($fetcher instanceof EvmFetcher)) {
            return; // Phase 1a only runs against EvmFetcher.
        }

        // Step 5: discover head + safe_head.
        $headBlock = self::fetchHeadBlock($fetcher);
        if ($headBlock <= 0) {
            CircuitBreaker::recordFailure($chainId);
            ChainCheckpointRepository::recordFailure(
                $chainId,
                ChainCheckpointRepository::STATE_DEGRADED,
                'eth_blockNumber returned 0'
            );
            return;
        }
        // First-run priming: when last_processed_block = 0 we don't want to
        // back-walk the entire chain. Anchor at safe_head so the indexer
        // picks up forward events from "now."
        $lastProcessed = (int) $checkpoint->last_processed_block;
        if ($lastProcessed === 0) {
            $lastProcessed = max(0, $headBlock - self::CONFIRMATIONS);
        }

        $safeHead = $headBlock - self::CONFIRMATIONS;
        if ($safeHead <= $lastProcessed) {
            ChainCheckpointRepository::recordSuccess($chainId, $lastProcessed, $headBlock);
            return; // Nothing to do — already caught up to safe head.
        }

        // Step 6: clamp the per-tick range so a long catch-up splits
        // across multiple ticks instead of blowing CU budget in one shot.
        $rangeFrom = $lastProcessed + 1;
        $rangeTo   = min($safeHead, $rangeFrom + self::BLOCKS_PER_TICK - 1);

        // Step 7: paginate through the range, ingesting batches.
        $pageKey   = null;
        $cuUsed    = 0;
        $totalIns  = 0;
        $totalDel  = 0;
        $totalSkip = 0;

        // Track remaining budget locally — addCuUsage below persists the
        // authoritative server-side value, but we don't need to re-read
        // it per page just to gate the loop. The pre-loop value (Step 3)
        // is the starting point.
        $remainingLocal = $cuRemaining;

        for ($pageNum = 0; $pageNum < self::MAX_PAGES_PER_TICK; $pageNum++) {
            if ($remainingLocal < self::CU_PER_CALL) {
                break;
            }

            $page = $fetcher->fetch_transfers_since($rangeFrom, $rangeTo, $pageKey);
            ChainCheckpointRepository::addCuUsage($chainId, self::CU_PER_CALL);
            $cuUsed         += self::CU_PER_CALL;
            $remainingLocal -= self::CU_PER_CALL;

            $transfers = $page['transfers'];
            if ($transfers !== []) {
                $batchResult = NftHoldingsIndexer::ingest($chainId, $transfers);
                $totalIns  += $batchResult['inserts'];
                $totalDel  += $batchResult['deletes'];
                $totalSkip += $batchResult['skipped'];
            }

            $pageKey = $page['page_key'];
            if ($pageKey === null) {
                break; // Range fully drained.
            }
        }

        // Step 8: advance checkpoint. Only mark up to $rangeTo because
        // a multi-page range that exhausted MAX_PAGES_PER_TICK still
        // covered everything in [rangeFrom, rangeTo] — pagination is
        // within the range, not across it.
        ChainCheckpointRepository::recordSuccess($chainId, $rangeTo, $headBlock);
        CircuitBreaker::recordSuccess($chainId);

        \BCC\Core\Log\Logger::info('[NftEthIndexerWorker] tick complete', [
            'chain_id'      => $chainId,
            'range'         => "[{$rangeFrom}, {$rangeTo}]",
            'head_block'    => $headBlock,
            'cu_used'       => $cuUsed,
            'inserts'       => $totalIns,
            'deletes'       => $totalDel,
            'skipped'       => $totalSkip,
            'paginated'     => $pageKey !== null,
        ]);
    }

    /**
     * Lightweight `eth_blockNumber` poll. Returns the int block height
     * or 0 on any error. CU cost is 16, so we don't bother decrementing
     * the budget for this (one call per tick is negligible).
     */
    private static function fetchHeadBlock(EvmFetcher $fetcher): int
    {
        $chain  = $fetcher->get_chain();
        $rpcUrl = (string) ($chain->rpc_url ?? '');
        if (!$rpcUrl || str_ends_with($rpcUrl, '/v2/')) {
            return 0;
        }

        $body = wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'eth_blockNumber',
            'params'  => [],
        ]);

        $response = ApiRetry::post($rpcUrl, [
            'timeout'   => 10,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $body,
            'sslverify' => true,
        ], [
            'label'    => 'EVM eth_blockNumber',
            'chain_id' => (int) $chain->id,
        ]);

        if (is_wp_error($response)) {
            return 0;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || !isset($json['result']) || !is_string($json['result'])) {
            return 0;
        }

        $hex = ltrim($json['result'], '0x');
        if ($hex === '') {
            return 0;
        }
        return (int) hexdec($hex);
    }
}
