<?php
/**
 * Enrichment Scheduler
 *
 * Production-grade, DB-driven scheduler that replaces naive cron enrichment.
 *
 * Design principles:
 *  - DB is source of truth (next_enrichment_at, retry_after, enrichment_attempts)
 *  - MySQL advisory locks prevent cron overlap; wp_cache counters track API budget
 *  - Priority: linked validators > missing data > high stake > everything else
 *  - Exponential backoff on failure (15m → 1h → 6h → 24h cap)
 *  - Staggered scheduling prevents thundering herd
 *  - Hard budget cap stops processing when API limit reached
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;

/**
 * @phpstan-import-type ValidatorRow from ValidatorRepository
 */
final class EnrichmentScheduler
{
    /** @var int API call count at the start of this run (baseline for delta tracking). */
    private static int $apiBaseline = 0;

    // ── Budget limits ───────────────────────────────────────────────────────
    const MAX_VALIDATORS_PER_RUN  = 100;
    const MAX_API_CALLS_PER_RUN   = 200;  // global safety cap
    const MAX_API_CALLS_PER_CHAIN = 50;   // per-chain fairness cap

    // ── Refresh intervals (seconds) ─────────────────────────────────────────
    const REFRESH_LINKED  = 4 * HOUR_IN_SECONDS;     // Wallet-linked: 4h base
    const REFRESH_DEFAULT = 24 * HOUR_IN_SECONDS;     // Bulk-indexed: 24h base
    const JITTER_RATIO    = 0.5;                      // ±50% jitter

    // ── Retry backoff ───────────────────────────────────────────────────────
    const BACKOFF_BASE    = 900;                       // 15 minutes
    const BACKOFF_MAX     = 24 * HOUR_IN_SECONDS;      // 24h cap
    const MAX_ATTEMPTS    = 10;                        // Stop retrying after this

    // ── Lock / counter keys (MySQL advisory locks + wp_cache counters) ─────
    const LOCK_KEY        = 'bcc:enrichment_lock';
    const API_COUNTER_KEY = 'bcc:enrichment_api_calls';
    const CACHE_GROUP     = 'bcc_enrichment';
    const LOCK_TTL        = 600;                       // 10 min lock TTL
    // Wall-clock budget = 80% of LOCK_TTL so we always finish cleanly
    // (stats write, lock release) before the lock would expire. Up to
    // ~9s of sleep per failing HTTP call can accumulate fast under a
    // transient outage; without this cap a full batch can exceed
    // LOCK_TTL and PHP max_execution_time mid-write.
    const BATCH_TIME_BUDGET = 480;                     // 8 min

    /**
     * Run the enrichment scheduler. Called by the hourly cron hook.
     *
     * @return array{processed: int, failed: int, skipped: int, api_calls: int, stopped_reason: string}
     */
    public static function run(): array
    {
        $result = [
            'processed'      => 0,
            'failed'         => 0,
            'skipped'        => 0,
            'api_calls'      => 0,
            'stopped_reason' => 'batch_complete',
        ];

        // ── MySQL advisory lock: prevent concurrent runs ────────────────
        if (!self::acquireLock()) {
            $result['stopped_reason'] = 'locked';
            self::log('Scheduler skipped — another run is locked.');
            return $result;
        }

        try {
            // ── Snapshot API counter baseline for this run ──────────────
            // Do NOT reset — the indexer may be concurrently incrementing.
            // Track budget as delta from this baseline instead.
            self::$apiBaseline = self::getApiCount();
            $runStartedAt      = microtime(true);

            // ── Fetch prioritized batch from DB ─────────────────────────
            $batch = self::fetchBatch();

            if (empty($batch)) {
                $result['stopped_reason'] = 'no_work';
                self::log('Scheduler: no validators due for enrichment.');
                return $result;
            }

            self::log(sprintf('Scheduler: processing batch of %d validators.', count($batch)));

            // ── Process each validator ───────────────────────────────────
            $processed = 0;
            foreach ($batch as $row) {
                // Wall-clock budget: bail before we risk exceeding LOCK_TTL
                // or PHP max_execution_time. Checked first so a slow run
                // cannot bypass it via a fast budget check.
                $elapsed = microtime(true) - $runStartedAt;
                if ($elapsed >= self::BATCH_TIME_BUDGET) {
                    $result['stopped_reason'] = 'time_budget';
                    self::log(sprintf(
                        'Scheduler: time budget reached (%ds). Stopping.',
                        (int) $elapsed
                    ));
                    break;
                }

                // Budget check BEFORE starting work on this validator.
                // Uses delta from baseline to ignore concurrent indexer calls.
                //
                // max(0, …) floor: if the wp_cache counter TTL expired
                // mid-run (LOCK_TTL ~600s but run can approach it under
                // slow APIs) and got reset to 0, the raw subtraction
                // goes negative and the budget check becomes a no-op,
                // allowing the run to process past MAX_API_CALLS_PER_RUN.
                // Clamp so a reset-counter forces us to stop, not skip.
                $apiUsed = max(0, self::getApiCount() - self::$apiBaseline);
                if ($apiUsed >= self::MAX_API_CALLS_PER_RUN) {
                    $result['stopped_reason'] = 'api_budget';
                    self::log("Scheduler: API budget reached ({$apiUsed} calls). Stopping.");
                    break;
                }

                // Per-chain fairness: skip validators whose chain has exhausted
                // its budget or whose circuit breaker is open.
                //
                // ┌─ PHASE X2 — gate now lives here only (load-bearing) ───┐
                // │ Before 2026-05-13 this gate ALSO existed in            │
                // │ `ApiRetry::request` as a transport-layer pre-check.   │
                // │ Removed there because it conflated scheduler fairness │
                // │ with transport policy and caused silent V1 ownership-  │
                // │ discovery failures under V2 retry pressure. The fix:   │
                // │ this scheduler is the ONLY producer + ONLY consumer   │
                // │ of `isChainBudgetExceeded` going forward. Do not     │
                // │ reintroduce a parallel gate at transport — that's    │
                // │ what the load-bearing block in `ApiRetry::request`    │
                // │ explicitly warns against.                              │
                // └────────────────────────────────────────────────────────┘
                $chainId = (int) ($row->chain_id ?? 0);
                if ($chainId > 0 && (self::isChainBudgetExceeded($chainId) || OnchainCircuitBreaker::isOpen($chainId))) {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $enriched = self::enrichRow($row);

                    if (!$enriched) {
                        $result['skipped']++;
                        continue;
                    }

                    // ┌─ PHASE X2 — scheduler-owned counter increment ─────┐
                    // │ Before 2026-05-13 `ApiRetry::request` charged the │
                    // │ counter for every HTTP response. After X2 this    │
                    // │ scheduler is the sole producer — one increment    │
                    // │ per successful `enrichRow`. Semantic shift: the   │
                    // │ counter now measures "rows processed this cycle"  │
                    // │ rather than "HTTP calls made anywhere," which is │
                    // │ the right granularity for per-cycle fairness     │
                    // │ (the original design intent). Each enrichRow may │
                    // │ make >1 HTTP call internally; we don't care for   │
                    // │ fairness purposes — what matters is that one     │
                    // │ chain can't run away with more than                │
                    // │ MAX_API_CALLS_PER_CHAIN rows in a single cycle.   │
                    // │ MAX_API_CALLS_PER_RUN cap still works for the    │
                    // │ same reason at the global scope.                  │
                    // └────────────────────────────────────────────────────┘
                    self::trackApiCall($chainId);

                    self::markSuccess($row, 1);
                    $result['processed']++;
                } catch (\Throwable $e) {
                    self::markFailure($row, $e->getMessage());
                    $result['failed']++;
                }

                $processed++;
            }

            $result['api_calls'] = max(0, self::getApiCount() - self::$apiBaseline);

        } finally {
            self::releaseLock();
        }

        // Log circuit breaker status for chains that have open breakers.
        $chains = ChainRepository::getActive();
        $chainIds = array_map(fn($c) => (int) $c->id, $chains);
        $cbStatus = OnchainCircuitBreaker::getAllStatus($chainIds);
        $openChains = array_filter($cbStatus, fn($s) => $s['status'] !== 'closed');
        if (!empty($openChains)) {
            $openNames = [];
            foreach ($openChains as $cid => $s) {
                foreach ($chains as $c) {
                    if ((int) $c->id === $cid) {
                        $openNames[] = $c->name . '(' . $s['status'] . ',fails=' . $s['failures'] . ')';
                        break;
                    }
                }
            }
            self::log('Circuit breakers: ' . implode(', ', $openNames));
        }

        self::log(sprintf(
            'Scheduler complete: %d processed, %d failed, %d skipped, %d API calls. Stop: %s',
            $result['processed'],
            $result['failed'],
            $result['skipped'],
            $result['api_calls'],
            $result['stopped_reason']
        ));

        return $result;
    }

    // =====================================================================
    // DEAD VALIDATOR CLEANUP
    // =====================================================================

    /**
     * Mark validators as inactive if they haven't been confirmed by the
     * bulk indexer in 30+ days AND have exhausted retry attempts.
     *
     * Called after index_validators() completes. The indexer's bulkUpsert
     * resets enrichment_attempts for every validator it sees (still bonded).
     * So any row that STILL has max attempts after an index run was NOT in
     * the bonded set — it's gone.
     *
     * These rows won't be deleted (historical data), but they'll be marked
     * inactive so the scheduler skips them and the UI shows correct status.
     *
     * @return int Number of validators marked inactive.
     */
    public static function markDeadValidators(): int
    {
        $result = ValidatorRepository::markDeadValidators(self::MAX_ATTEMPTS);

        if ($result > 0) {
            self::log(sprintf('Marked %d dead validators as inactive.', $result));
        }

        return $result;
    }

    // =====================================================================
    // BATCH QUERY (Section 3)
    // =====================================================================

    /**
     * Fetch the next batch of validators due for enrichment.
     *
     * Priority order (SQL CASE):
     *   0 — Wallet-linked + missing critical data (someone's profile, no data yet)
     *   1 — Wallet-linked + due for refresh (someone's profile, stale)
     *   2 — Unlinked + missing critical data (discovery, no data yet)
     *   3 — Unlinked + due for refresh (bulk-indexed, stale)
     *
     * Within each tier: highest stake first, then oldest enrichment.
     *
     * Filters:
     *   - next_enrichment_at <= NOW() (scheduled)
     *   - retry_after IS NULL OR retry_after <= NOW() (not in backoff)
     *   - enrichment_attempts < MAX_ATTEMPTS (not permanently failed)
     */
    /** @return list<ValidatorRow> */
    private static function fetchBatch(): array
    {
        return ValidatorRepository::fetchEnrichmentBatch(self::MAX_ATTEMPTS, self::MAX_VALIDATORS_PER_RUN);
    }

    // =====================================================================
    // ENRICHMENT EXECUTION (Section 4)
    // =====================================================================

    /**
     * Enrich a single validator row.
     *
     * Wraps existing CosmosFetcher::enrich_validator() — does NOT rewrite
     * the enrichment logic. Returns false if the chain/fetcher is unavailable
     * (skip, not failure).
     *
     * @param ValidatorRow $row
     */
    private static function enrichRow(object $row): bool
    {
        $chain = ChainRepository::getById((int) $row->chain_id);
        if (!$chain) {
            self::log("SKIP id={$row->id}: chain_id={$row->chain_id} not found");
            return false;
        }
        if (!FetcherFactory::has_driver($chain->chain_type)) {
            self::log("SKIP id={$row->id}: no driver for chain_type={$chain->chain_type}");
            return false;
        }

        $fetcher = FetcherFactory::make_for_chain($chain);

        if (!$fetcher->supports_feature('validator')) {
            self::log("SKIP id={$row->id}: {$chain->chain_type} doesn't support validator");
            return false;
        }

        // enrich_validator is on FetcherInterface with skip-if-fresh logic
        // built into each implementation. The supports_feature('validator')
        // gate at line 311 above already filtered out non-supporting drivers
        // (their enrich_validator returns []), so the previous
        // method_exists fallback to fetch_validator was dead code.
        $data = $fetcher->enrich_validator($row->operator_address, $row);

        if (empty($data)) {
            throw new \RuntimeException('Fetcher returned empty data for ' . $row->operator_address);
        }

        // enrichByOperator returns false on DB error. Prior behaviour
        // ignored that, so a silent UPDATE failure was followed by
        // markEnrichmentSuccess — pushing the validator's next refresh
        // 24h out while its stored columns remained stale. Throw so
        // the scheduler's catch block routes through markFailure with
        // a retry_after and exponential backoff instead.
        if (!ValidatorRepository::enrichByOperator($data, HOUR_IN_SECONDS)) {
            throw new \RuntimeException(
                'ValidatorRepository::enrichByOperator returned false for ' . $row->operator_address
            );
        }
        return true;
    }

    // =====================================================================
    // SUCCESS / FAILURE HANDLERS (Section 6 — Retry)
    // =====================================================================

    /**
     * Mark a validator as successfully enriched.
     *
     * Calculates next_enrichment_at with deterministic jitter:
     *   - Linked validators: 4h ± 50% (2h–6h)
     *   - Unlinked validators: 24h ± 50% (12h–36h)
     *
     * Jitter is seeded from operator_address (same validator always gets
     * the same offset) to distribute load evenly without random drift.
     *
     * @param ValidatorRow $row
     */
    private static function markSuccess(object $row, int $apiCalls): void
    {
        $isLinked = ($row->wallet_link_id ?? null) !== null;
        $baseTtl  = $isLinked ? self::REFRESH_LINKED : self::REFRESH_DEFAULT;

        // Deterministic jitter: ±JITTER_RATIO of the base TTL.
        $jitter     = (float) (crc32($row->operator_address) & 0x7FFFFFFF) / 0x7FFFFFFF;
        $multiplier = 1.0 + ($jitter - 0.5) * 2 * self::JITTER_RATIO;
        $ttlSeconds = (int) ($baseTtl * $multiplier);

        $nextEnrichmentAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        ValidatorRepository::markEnrichmentSuccess((int) $row->id, $nextEnrichmentAt);

        self::log(sprintf(
            'OK id=%d %s — %d API calls, next in %s',
            $row->id,
            $row->operator_address,
            $apiCalls,
            self::humanSeconds($ttlSeconds)
        ));
    }

    /**
     * Mark a validator as failed with exponential backoff.
     *
     * Backoff formula: min(24h, 15min × 4^(attempts-1))
     *   Attempt 1 → 15 min
     *   Attempt 2 → 1 hour
     *   Attempt 3 → 4 hours
     *   Attempt 4 → 16 hours
     *   Attempt 5+ → 24 hours (capped)
     *
     * Does NOT update next_enrichment_at — the row stays "due" but the
     * retry_after gate prevents processing until the backoff expires.
     *
     * @param ValidatorRow $row
     */
    private static function markFailure(object $row, string $error): void
    {
        $attempts     = ((int) ($row->enrichment_attempts ?? 0)) + 1;
        $backoffSec   = (int) min(self::BACKOFF_MAX, self::BACKOFF_BASE * pow(4, $attempts - 1));
        $retryAfter   = gmdate('Y-m-d H:i:s', time() + $backoffSec);

        ValidatorRepository::markEnrichmentFailure((int) $row->id, $attempts, $retryAfter);

        self::log(sprintf(
            'FAIL id=%d %s — attempt %d, retry in %s. Error: %s',
            $row->id,
            $row->operator_address,
            $attempts,
            self::humanSeconds($backoffSec),
            substr($error, 0, 200)
        ));
    }

    // =====================================================================
    // LOCK + API COUNTER (Section 5)
    // =====================================================================

    /**
     * Acquire MySQL advisory lock. Returns false if another run is in progress.
     * GET_LOCK is session-scoped — auto-releases on process crash or disconnect.
     * Timeout 0 = non-blocking (fail immediately if already held).
     */
    private static function acquireLock(): bool
    {
        return \BCC\Core\DB\AdvisoryLock::acquire(self::LOCK_KEY, 0);
    }

    private static function releaseLock(): void
    {
        \BCC\Core\DB\AdvisoryLock::release(self::LOCK_KEY);
    }

    /**
     * In-process fallback counters for hosts without persistent object cache.
     * wp_cache is volatile per-request without Redis/Memcached, so these
     * static counters ensure budget enforcement within a single cron run.
     */
    private static int $staticGlobalCount = 0;
    /** @var array<int, int> */
    private static array $staticChainCounts = [];

    /**
     * Atomically increment BOTH the global and per-chain API call counters.
     * Uses wp_cache for cross-request persistence (Redis) with an in-process
     * static fallback that always works regardless of cache backend.
     *
     * @param int $chainId Chain ID for per-chain budget tracking.
     */
    public static function trackApiCall(int $chainId = 0): void
    {
        // Always increment static counters (works without Redis).
        self::$staticGlobalCount++;

        // Try wp_cache for cross-request persistence.
        $result = wp_cache_incr(self::API_COUNTER_KEY, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set(self::API_COUNTER_KEY, 1, self::CACHE_GROUP, self::LOCK_TTL);
        }

        // Per-chain counter (if chain specified).
        if ($chainId > 0) {
            self::$staticChainCounts[$chainId] = (self::$staticChainCounts[$chainId] ?? 0) + 1;

            $chainKey = self::API_COUNTER_KEY . ':' . $chainId;
            $result   = wp_cache_incr($chainKey, 1, self::CACHE_GROUP);
            if ($result === false) {
                wp_cache_set($chainKey, 1, self::CACHE_GROUP, self::LOCK_TTL);
            }
        }
    }

    /**
     * Read current global API call count.
     * Returns the higher of wp_cache (cross-request) and static (in-process).
     */
    public static function getApiCount(): int
    {
        $cached = (int) (wp_cache_get(self::API_COUNTER_KEY, self::CACHE_GROUP) ?: 0);
        return max($cached, self::$staticGlobalCount);
    }

    /**
     * Read current per-chain API call count.
     */
    public static function getChainApiCount(int $chainId): int
    {
        $chainKey = self::API_COUNTER_KEY . ':' . $chainId;
        $cached = (int) (wp_cache_get($chainKey, self::CACHE_GROUP) ?: 0);
        return max($cached, self::$staticChainCounts[$chainId] ?? 0);
    }

    /**
     * Check if a chain has exceeded its per-chain budget.
     */
    public static function isChainBudgetExceeded(int $chainId): bool
    {
        return self::getChainApiCount($chainId) >= self::MAX_API_CALLS_PER_CHAIN;
    }

    // =====================================================================
    // LOGGING
    // =====================================================================

    private static function log(string $message): void
    {
        \BCC\Core\Log\Logger::info('[Enrichment] ' . $message);
    }

    private static function humanSeconds(int $seconds): string
    {
        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }
        return round($seconds / 3600, 1) . 'h';
    }
}
