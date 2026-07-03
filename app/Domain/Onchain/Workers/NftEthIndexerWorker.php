<?php

namespace BCC\Trust\Onchain\Workers;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
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

        // Step 5.5: zero-wallet short-circuit. A chain with no linked
        // wallets can never ingest a transfer — every row the firehose
        // returns is filtered out at ingest (WalletRepository lookup in
        // NftHoldingsIndexer). Before this gate, zero-wallet chains
        // burned the full page budget per tick on guaranteed skips and,
        // on transfer-dense chains, hit the dense-block stall path every
        // minute (sustained `dense_block_stall` alert + wasted CU).
        //
        // We follow the head instead of freezing the checkpoint: when a
        // first wallet links later, the V1 snapshot fetch covers history
        // and the V2 walker only needs "from now" — a months-stale
        // checkpoint would trigger a pointless deep catch-up walk.
        //
        // Only the cheap eth_blockNumber head poll (Step 5, un-budgeted)
        // is spent; ALL getAssetTransfers paging is skipped. This gate
        // MUST precede the transfer paging: chains on public RPCs that
        // don't support the Alchemy method (e.g. Avalanche/BSC) would
        // otherwise fail loudly every tick now that fetch errors are
        // explicit (fetch_transfers_since returning null).
        if (!WalletRepository::hasAnyLinksForChain($chainId)) {
            ChainCheckpointRepository::recordSuccess($chainId, $safeHead, $headBlock);
            OnchainCircuitBreaker::recordSuccess($chainId);
            \BCC\Core\Log\Logger::info('[NftEthIndexerWorker] no linked wallets — following head', [
                'chain_id'         => $chainId,
                'head_block'       => $headBlock,
                'checkpoint_block' => $safeHead,
            ]);
            return;
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

        // Highest block number observed across every transfer ingested this
        // tick. Alchemy getAssetTransfers returns rows in ascending
        // (blockNum, logIndex) order, so after reading N pages every block
        // STRICTLY LESS THAN $maxBlockSeen is fully covered — the only block
        // that could still hold un-read transfers in the pages we didn't
        // fetch is $maxBlockSeen itself (the page may have cut mid-block at
        // the boundary). This is the safe-advance watermark used in Step 8
        // when the range only partially drains. 0 = no transfers seen.
        $maxBlockSeen = 0;

        // Track remaining budget locally — addCuUsage below persists the
        // authoritative server-side value, but we don't need to re-read
        // it per page just to gate the loop. The pre-loop value (Step 3)
        // is the starting point.
        $remainingLocal = $cuRemaining;

        // Set when a page fetch fails (transport error, JSON-RPC error,
        // malformed body — fetch_transfers_since returns null). A failed
        // page means we CANNOT prove the range was read, so the normal
        // Step-8 advance is skipped in favor of the error path below.
        $fetchFailed = false;

        for ($pageNum = 0; $pageNum < self::MAX_PAGES_PER_TICK; $pageNum++) {
            if ($remainingLocal < self::CU_PER_CALL) {
                break;
            }

            $page = $fetcher->fetch_transfers_since($rangeFrom, $rangeTo, $pageKey);
            // Charge CU unconditionally — a failed call may still have
            // hit the provider (same posture as before this error path
            // existed; under-counting risks budget overrun).
            ChainCheckpointRepository::addCuUsage($chainId, self::CU_PER_CALL);
            $cuUsed         += self::CU_PER_CALL;
            $remainingLocal -= self::CU_PER_CALL;

            if ($page === null) {
                $fetchFailed = true;
                break;
            }

            $transfers = $page['transfers'];
            if ($transfers !== []) {
                foreach ($transfers as $transfer) {
                    $blockNumber = (int) ($transfer['block_number'] ?? 0);
                    if ($blockNumber > $maxBlockSeen) {
                        $maxBlockSeen = $blockNumber;
                    }
                }
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

        // Step 7.5: fetch-error path. Historically a failed page was
        // indistinguishable from an empty range (the fetcher returned the
        // empty-success shape on wp_error / malformed JSON / JSON-RPC
        // error), so the worker advanced the checkpoint over blocks it
        // NEVER READ — permanent data loss on any transient provider
        // failure. Now the failure is explicit: abort the tick without
        // the normal Step-8 advance. Transfers already ingested from
        // earlier successful pages this tick are safe (ingest is
        // idempotent), and per the safe-partial-advance invariant
        // (Step 8 below) we may still advance to maxBlockSeen - 1 —
        // every block strictly below the watermark was proven read by
        // the SUCCESSFUL pages. Otherwise the checkpoint holds and the
        // next tick re-reads the same range.
        if ($fetchFailed) {
            $errorAdvanceTarget = null;
            if ($maxBlockSeen > 0 && ($maxBlockSeen - 1) >= $rangeFrom) {
                $errorAdvanceTarget = $maxBlockSeen - 1;
                ChainCheckpointRepository::recordSuccess($chainId, $errorAdvanceTarget, $headBlock);
            }
            // recordFailure after the (optional) advance so the row ends
            // degraded with last_error set, but keeps the advanced block.
            OnchainCircuitBreaker::recordFailure($chainId);
            ChainCheckpointRepository::recordFailure(
                $chainId,
                ChainCheckpointRepository::STATE_DEGRADED,
                'alchemy_getAssetTransfers page fetch failed (see EVM Fetcher log for cause)'
            );
            \BCC\Core\Log\Logger::warning('[NftEthIndexerWorker] transfer page fetch failed — tick aborted without full advance', [
                'chain_id'            => $chainId,
                'range'               => "[{$rangeFrom}, {$rangeTo}]",
                'max_block_seen'      => $maxBlockSeen,
                'pages_read'          => intdiv($cuUsed, self::CU_PER_CALL),
                'cu_used'             => $cuUsed,
                'checkpoint_advanced' => $errorAdvanceTarget !== null,
                'checkpoint_block'    => $errorAdvanceTarget,
            ]);
            return;
        }

        // Step 8: advance checkpoint — INVARIANT: the checkpoint only
        // advances over FULLY-READ blocks.
        //
        // Full drain ($pageKey === null): the whole [rangeFrom, rangeTo]
        // range is read; advance to $rangeTo exactly. Happy path, common
        // case, unchanged.
        //
        // Partial drain ($pageKey !== null): the loop broke early on
        // MAX_PAGES_PER_TICK or the CU budget, so unread pages of
        // [rangeFrom, rangeTo] still hold Transfer events. Advancing to
        // $rangeTo would compute $rangeFrom = $rangeTo + 1 next tick and
        // PERMANENTLY SKIP those unread pages — the original data-loss bug.
        // But leaving the checkpoint at $lastProcessed unconditionally is a
        // LIVELOCK: a range whose transfer count exceeds the page budget
        // (MAX_PAGES_PER_TICK × ALCHEMY_MAX_COUNT) never drains, so the
        // checkpoint never moves and Step 6 recomputes the identical range
        // forever, re-reading the same pages and burning CU with zero
        // forward progress.
        //
        // SAFE PARTIAL ADVANCE resolves both: Alchemy returns transfers in
        // ascending (blockNum, logIndex) order, so after reading N pages
        // every block STRICTLY LESS THAN $maxBlockSeen is fully covered —
        // only $maxBlockSeen itself might have more transfers in the unread
        // tail (the page can cut mid-block at the boundary). So the highest
        // block we can SAFELY claim as processed is $maxBlockSeen - 1. We
        // advance the checkpoint there; the next tick resumes at
        // $maxBlockSeen, re-reading only the boundary block onward. That
        // re-read is loss-free because ingest is idempotent: the holdings
        // UPSERT is `INSERT ... ON DUPLICATE KEY UPDATE` keyed on
        // (wallet_link_id, contract_address, token_id) with
        // GREATEST(last_seen_block, VALUES(last_seen_block)) /
        // GREATEST(confirmed_at, ...) (NftHoldingsRepository::upsertMany),
        // and the OUT-leg delete is a no-op on a missing row. Re-reading the
        // boundary block re-upserts identical state — it converges.
        // This guarantees monotonic progress whenever the read pages span
        // >= 2 distinct blocks (the overwhelmingly common case).
        //
        // PATHOLOGICAL edge: $safeBlock < $rangeFrom means even the FIRST
        // block of the range couldn't be fully covered in the page budget
        // ($maxBlockSeen == $rangeFrom, i.e. all 5000+ transfers landed in a
        // single block). We can't safely advance — advancing to
        // $maxBlockSeen would skip the unread tail of that same block. So we
        // hold the checkpoint AND emit a DegradationMetric so an operator
        // sees the dense-block stall instead of a silent CU-burning spin.
        // NOTE: this fetch is NOT contract-scoped — getAssetTransfers reads
        // the chain-wide ERC-721/1155 firehose — so a single block with
        // >5000 transfers is entirely realistic on high-throughput chains
        // (observed sustained on Optimism, 2026-07). The zero-wallet
        // short-circuit at Step 5.5 keeps walletless chains out of this
        // path; a wallet-bearing chain hitting it is a real operator
        // signal (page budget too small for that chain's density).
        $rangeFullyDrained = ($pageKey === null);
        $checkpointTarget  = null;
        if ($rangeFullyDrained) {
            $checkpointTarget = $rangeTo;
        } elseif ($maxBlockSeen > 0) {
            $safeBlock = $maxBlockSeen - 1;
            if ($safeBlock >= $rangeFrom) {
                $checkpointTarget = $safeBlock;
            } else {
                // Dense single-block stall — cannot advance without loss.
                \BCC\Core\Observability\DegradationMetrics::record('nft_indexer', 'dense_block_stall');
                // The metric alone is context-free — give the operator
                // chasing the alert the chain + range without DB
                // archaeology.
                \BCC\Core\Log\Logger::warning('[NftEthIndexerWorker] dense block stall — page budget exhausted inside a single block', [
                    'chain_id'       => $chainId,
                    'range'          => "[{$rangeFrom}, {$rangeTo}]",
                    'max_block_seen' => $maxBlockSeen,
                    'pages_read'     => intdiv($cuUsed, self::CU_PER_CALL),
                    'cu_used'        => $cuUsed,
                ]);
            }
        }
        // ($maxBlockSeen === 0 on a partial drain means the read pages held
        // zero ingestable transfers yet Alchemy still returned a pageKey —
        // we can't prove any block is fully covered, so we hold and let the
        // next tick re-read. No metric: this is a benign re-read, not a
        // stuck dense block.)

        if ($checkpointTarget !== null) {
            ChainCheckpointRepository::recordSuccess($chainId, $checkpointTarget, $headBlock);
        }
        OnchainCircuitBreaker::recordSuccess($chainId);

        \BCC\Core\Log\Logger::info('[NftEthIndexerWorker] tick complete', [
            'chain_id'             => $chainId,
            'range'                => "[{$rangeFrom}, {$rangeTo}]",
            'head_block'           => $headBlock,
            'cu_used'              => $cuUsed,
            'inserts'              => $totalIns,
            'deletes'              => $totalDel,
            'skipped'              => $totalSkip,
            'paginated'            => $pageKey !== null,
            'max_block_seen'       => $maxBlockSeen,
            'checkpoint_advanced'  => $checkpointTarget !== null,
            'checkpoint_block'     => $checkpointTarget,
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
