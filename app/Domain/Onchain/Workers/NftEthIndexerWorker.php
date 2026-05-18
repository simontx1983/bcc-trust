<?php

namespace BCC\Trust\Onchain\Workers;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\NftHoldingsIndexer;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;

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
    public const CRON_INTERVAL                   = 'bcc_one_minute';
    public const CONFIRMATIONS                   = 12;
    public const BLOCKS_PER_TICK                 = 2000;
    public const CU_PER_CALL                     = 120;
    public const MAX_PAGES_PER_TICK              = 5; // safety bound on pagination loop
    public const DEFAULT_DAILY_BUDGET            = 50000;
    public const CRON_OVERDUE_THRESHOLD_SECONDS  = 300;
    /**
     * Per-tick wall-clock cap. Hostinger Business shared caps PHP
     * `max_execution_time` at 30s; budgeting our own 20s ceiling
     * leaves headroom for the response trip back to Vercel Cron and
     * any per-chain teardown. Catch-ups split across multiple ticks
     * are already the design (BLOCKS_PER_TICK + pagination cap), so
     * cutting a long tick short never loses progress — the next
     * tick resumes from the persisted checkpoint.
     */
    public const MAX_RUNTIME_SECONDS             = 20;
    private const ADVISORY_LOCK_PREFIX = 'bcc_nft_indexer_chain_';

    /**
     * Self-heal the every-minute cron registration.
     *
     * Originally the schedule call lived only in the plugin's activation
     * hook. That left a silent drift window: any plugin update that
     * ADDED a cron hook (Phase 1a did exactly this) without triggering
     * a reactivation left the hook permanently absent from
     * `wp_options.cron` — the worker never fired and the empty
     * `wp_bcc_chain_checkpoints` table was the only outward sign.
     *
     * Hooked from `plugins_loaded` so any drift self-heals on the next
     * request. `wp_next_scheduled()` reads the autoloaded `cron` option
     * in memory; the per-request cost is one array lookup.
     *
     * This method NEVER unschedules — clearing the hook is owned by
     * the plugin's deactivation cleanup.
     */
    public static function register(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    /**
     * Run a tick for every active EVM chain.
     *
     * Wall-clock budget: MAX_RUNTIME_SECONDS. Once the budget is
     * spent we stop scheduling further chains — the next tick (1 min
     * later for both WP-Cron and Vercel-Cron) will resume from where
     * we left off. Per-chain progress is durable via the checkpoint,
     * so skipping the rest of the loop never loses work.
     */
    public static function runAllChains(): void
    {
        $chains = ChainRepository::getActive();
        if ($chains === []) {
            return;
        }

        $deadline = microtime(true) + (float) self::MAX_RUNTIME_SECONDS;

        foreach ($chains as $chain) {
            if (microtime(true) >= $deadline) {
                \BCC\Core\Log\Logger::info('[NftEthIndexerWorker] tick budget exhausted — deferring remaining chains', [
                    'max_runtime_seconds' => self::MAX_RUNTIME_SECONDS,
                ]);
                break;
            }
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
        if (OnchainCircuitBreaker::isOpen($chainId)) {
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
        $headResult = self::fetchHeadBlock($fetcher);
        $headBlock  = $headResult['block'];
        if ($headBlock <= 0) {
            OnchainCircuitBreaker::recordFailure($chainId);
            ChainCheckpointRepository::recordFailure(
                $chainId,
                ChainCheckpointRepository::STATE_DEGRADED,
                $headResult['error'] ?? 'eth_blockNumber returned 0'
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
        OnchainCircuitBreaker::recordSuccess($chainId);

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
     * Lightweight `eth_blockNumber` poll. CU cost is 16, so we don't
     * bother decrementing the budget for this (one call per tick is
     * negligible).
     *
     * Returns a typed result so the caller can surface the actual
     * upstream cause in the per-chain `last_error` column. Historically
     * this returned `int` with 0 on any failure, which collapsed every
     * cause — RPC unreachable, JSON-RPC error, Alchemy "network not
     * enabled for this app", malformed response — into the same
     * useless "returned 0" string. The dashboard couldn't distinguish
     * "CONFIGURATION FIX REQUIRED" from "TRANSIENT NETWORK BLIP," and
     * the only way to find out was a curl probe.
     *
     * The repo truncates `last_error` to 255 chars on write; this
     * method only normalises whitespace + strips control chars so the
     * stored value renders cleanly in the admin table and log lines.
     *
     * @return array{block: int, error: ?string}
     */
    private static function fetchHeadBlock(EvmFetcher $fetcher): array
    {
        $chain  = $fetcher->get_chain();
        $rpcUrl = (string) ($chain->rpc_url ?? '');
        if ($rpcUrl === '') {
            return ['block' => 0, 'error' => 'eth_blockNumber: rpc_url not configured for this chain'];
        }
        if (str_ends_with($rpcUrl, '/v2/')) {
            return ['block' => 0, 'error' => 'eth_blockNumber: rpc_url missing Alchemy API key suffix (ends with /v2/)'];
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
            return [
                'block' => 0,
                'error' => 'eth_blockNumber transport: ' . self::cleanErrorBody($response->get_error_message()),
            ];
        }

        $rawBody = wp_remote_retrieve_body($response);
        $json    = json_decode($rawBody, true);

        // Non-JSON body — most commonly Alchemy returning a plain-text
        // error like "MATIC_MAINNET is not enabled for this app." when
        // the chain isn't provisioned on the app. Surface verbatim
        // (truncated by the repo) so the next operator doesn't repeat
        // today's curl-probe diagnostic dance.
        if (!is_array($json)) {
            return [
                'block' => 0,
                'error' => 'eth_blockNumber non-JSON response: ' . self::cleanErrorBody($rawBody),
            ];
        }

        // JSON-RPC error object — the well-formed failure path.
        if (isset($json['error']) && is_array($json['error'])) {
            $msg = isset($json['error']['message']) && is_string($json['error']['message'])
                ? $json['error']['message']
                : '(no error.message)';
            return [
                'block' => 0,
                'error' => 'eth_blockNumber RPC error: ' . self::cleanErrorBody($msg),
            ];
        }

        if (!isset($json['result']) || !is_string($json['result'])) {
            return [
                'block' => 0,
                'error' => 'eth_blockNumber missing result field: ' . self::cleanErrorBody($rawBody),
            ];
        }

        $hex = ltrim($json['result'], '0x');
        if ($hex === '') {
            return ['block' => 0, 'error' => 'eth_blockNumber result was empty string'];
        }
        return ['block' => (int) hexdec($hex), 'error' => null];
    }

    /**
     * Single-line, control-char-free cleanup of an upstream error
     * body for embedding in `last_error`. Caps at 200 chars to leave
     * headroom for the caller's prefix label inside the repository's
     * 255-char column truncation.
     */
    private static function cleanErrorBody(string $raw): string
    {
        $cleaned = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw);
        if (!is_string($cleaned)) {
            $cleaned = $raw;
        }
        $cleaned = trim((string) preg_replace('/\s+/', ' ', $cleaned));
        return function_exists('mb_substr') ? mb_substr($cleaned, 0, 200) : substr($cleaned, 0, 200);
    }
}
