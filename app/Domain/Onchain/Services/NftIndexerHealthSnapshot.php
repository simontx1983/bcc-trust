<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository;
use BCC\Trust\Onchain\Services\EnrichmentScheduler;
use BCC\Trust\Onchain\Workers\NftEthIndexerWorker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reports NFT-indexer subsystem state into the bcc-core
 * `bcc_system_health` filter.
 *
 * Surfaces all the operator signals defined in the V2 Phase 1a plan
 * under §"Observability is non-optional":
 *   - per-chain last_processed_block / head / lag / state / cu_used
 *   - cron staleness (mirrors CronService::admin_notices, 5-min threshold)
 *   - Helius dedupe table size + alarm threshold
 *
 * Hooked from bcc-trust.php at plugin bootstrap.
 *
 * @phpstan-import-type CheckpointRow from ChainCheckpointRepository
 */
final class NftIndexerHealthSnapshot
{
    public const STATUS_GREEN  = 'green';
    public const STATUS_YELLOW = 'yellow';
    public const STATUS_RED    = 'red';

    /** Per-chain stall threshold. Healthy chains should tick every minute;
     *  300s without a tick mirrors the cron-overdue threshold and gives
     *  cron jitter room before we flag the chain. */
    public const CHAIN_STALL_THRESHOLD_SECONDS = 300;

    /** CU-budget pressure threshold — a chain at >=80% of its daily budget
     *  is yellow because once the budget hits 100% the indexer stops
     *  walking that chain until the next UTC midnight rollover. */
    public const CU_BUDGET_PRESSURE_RATIO = 0.8;

    /** Call-count pressure threshold (X1 visibility phase).
     *
     *  `EnrichmentScheduler` maintains a per-chain rolling 10-minute call
     *  counter (cap = `MAX_API_CALLS_PER_CHAIN`). Today this gauge is
     *  consulted by `ApiRetry::request` as a pre-call gate for EVERY
     *  Alchemy caller, not just the scheduler — a side effect of the
     *  pre-check having migrated into transport layer. That means a V2
     *  worker thrashing retries on a degraded chain can silently
     *  pre-block V1 fetches on the same chain.
     *
     *  Surfaced here as a YELLOW signal so the operator can see the
     *  pressure before it produces user-visible silent-failure symptoms.
     *  Phase X2 will move the gauge back inside the scheduler loop where
     *  it belongs; this surface becomes a defense-in-depth signal at
     *  that point.
     */
    public const CALL_COUNT_PRESSURE_RATIO = 0.8;

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    public static function contribute(array $existing): array
    {
        $existing['nft_indexer'] = self::buildSnapshot();
        $existing['helius_dedupe'] = self::buildHeliusDedupe();
        return $existing;
    }

    /**
     * Operator-facing health summary. Derives a single RGB status +
     * actionable issues list from the same per-chain checkpoint data
     * `buildSnapshot()` exposes to the system-health endpoint, so the
     * admin view and any future health probe agree on what "healthy"
     * means.
     *
     * Rules:
     *   RED    — cron not scheduled, cron overdue, 0 active chains, OR
     *            every active chain in degraded/breaker_open (operationally
     *            offline regardless of nominal enable-state).
     *   YELLOW — at least one healthy active chain but something needs
     *            attention: some chains degraded, stalled tick, CU
     *            pressure, scheduler call-count pressure, or overgrown
     *            Helius dedupe table.
     *   GREEN  — at least one active chain, no outstanding issues.
     *
     * `issues` is an ordered list of human-readable lines safe to render
     * verbatim. Each line is plain text — escape on render, not here.
     *
     * @return array{
     *     status: 'green'|'yellow'|'red',
     *     cron_scheduled: bool,
     *     cron_overdue: bool,
     *     cron_overdue_seconds: int,
     *     active_chains_count: int,
     *     total_evm_chains_count: int,
     *     stalled_chains: list<string>,
     *     degraded_chains: list<string>,
     *     cu_pressure_chains: list<string>,
     *     call_count_pressure_chains: list<string>,
     *     call_count_by_chain: array<int, array{slug: string, count: int, cap: int}>,
     *     dedupe_overgrown: bool,
     *     issues: list<string>
     * }
     */
    public static function buildSummary(): array
    {
        $hook    = NftEthIndexerWorker::CRON_HOOK;
        $next    = wp_next_scheduled($hook);
        $cronScheduled = $next !== false;
        $overSec       = $cronScheduled ? max(0, time() - (int) $next) : 0;
        $cronOverdue   = $cronScheduled && $overSec > NftEthIndexerWorker::CRON_OVERDUE_THRESHOLD_SECONDS;

        $dailyBudget = defined('BCC_ETH_DAILY_RPC_BUDGET')
            ? (int) constant('BCC_ETH_DAILY_RPC_BUDGET')
            : NftEthIndexerWorker::DEFAULT_DAILY_BUDGET;

        // Build chain_id → slug map for human-friendly issue strings.
        // EVM-scoped because Phase 1a indexer only runs against evm-type
        // chains; cosmos/solana have their own ingest paths.
        $slugByChainId   = [];
        $totalEvmChains  = 0;
        foreach (ChainRepository::getActive('evm') as $chain) {
            $slugByChainId[(int) $chain->id] = (string) $chain->slug;
            $totalEvmChains++;
        }

        $activeCount             = 0;
        $stalledChains           = [];
        $degradedChains          = [];
        $cuPressureChains        = [];
        $callCountPressureChains = [];
        $callCountByChain        = [];
        $callCountCap            = EnrichmentScheduler::MAX_API_CALLS_PER_CHAIN;
        $now                     = time();

        foreach (ChainCheckpointRepository::getAll() as $cp) {
            $chainId = (int) $cp->chain_id;
            if (!isset($slugByChainId[$chainId])) {
                // Checkpoint exists for a chain that's no longer active or
                // not evm-typed. Skip — not part of this view's scope.
                continue;
            }
            $slug  = $slugByChainId[$chainId];
            $state = (string) $cp->state;

            // Capture call-count for EVERY chain (active or disabled) so the
            // per-chain table can render the gauge for operator inspection.
            // The YELLOW signal below only fires for non-disabled chains
            // because that's where pressure produces user-facing symptoms.
            $callCount = EnrichmentScheduler::getChainApiCount($chainId);
            $callCountByChain[$chainId] = [
                'slug'  => $slug,
                'count' => $callCount,
                'cap'   => $callCountCap,
            ];

            if ($state === ChainCheckpointRepository::STATE_DISABLED) {
                continue;
            }

            $activeCount++;

            if ($state === ChainCheckpointRepository::STATE_BREAKER_OPEN
                || $state === ChainCheckpointRepository::STATE_DEGRADED
            ) {
                $degradedChains[] = $slug . ' (' . $state . ')';
                continue;
            }

            // Stall detection only for chains the operator believes are
            // healthy. Degraded/breaker rows are already surfaced above.
            $lastRunAt = $cp->last_run_at !== null ? strtotime((string) $cp->last_run_at) : false;
            if ($lastRunAt !== false && ($now - $lastRunAt) > self::CHAIN_STALL_THRESHOLD_SECONDS) {
                $stalledChains[] = $slug;
            }

            if ($dailyBudget > 0) {
                $usedRatio = (int) $cp->cu_used_today / $dailyBudget;
                if ($usedRatio >= self::CU_BUDGET_PRESSURE_RATIO) {
                    $cuPressureChains[] = sprintf(
                        '%s (%d/%d CU)',
                        $slug,
                        (int) $cp->cu_used_today,
                        $dailyBudget
                    );
                }
            }

            // Call-count pressure (X1 visibility phase). See class const
            // docblock for the V1↔V2 coupling background. We raise on
            // non-disabled chains only because those are the ones where
            // pressure produces operator-actionable symptoms (silent
            // pre-block of V1 fetches by ApiRetry). `$callCountCap` is
            // a positive class constant; no zero-guard needed.
            $callRatio = $callCount / $callCountCap;
            if ($callRatio >= self::CALL_COUNT_PRESSURE_RATIO) {
                $callCountPressureChains[] = sprintf(
                    '%s (%d/%d)',
                    $slug,
                    $callCount,
                    $callCountCap
                );
            }
        }

        $dedupeRows      = HeliusSeenSignaturesRepository::rowCount();
        $dedupeOvergrown = $dedupeRows > HeliusSeenSignaturesRepository::ALARM_THRESHOLD;

        // Compose the issues list in severity order so the first line is
        // always the most actionable.
        $issues = [];
        if (!$cronScheduled) {
            $issues[] = 'Cron `' . $hook . '` is not scheduled — the worker will never tick. Reactivate the plugin or wait for the plugins_loaded self-heal on the next request.';
        }
        if ($cronOverdue) {
            $issues[] = sprintf(
                'Cron is overdue by %ds (threshold %ds). WP-Cron may be stalled.',
                $overSec,
                NftEthIndexerWorker::CRON_OVERDUE_THRESHOLD_SECONDS
            );
        }
        if ($activeCount === 0 && $totalEvmChains > 0) {
            $issues[] = sprintf(
                '0 of %d EVM chains are enabled. Resume at least one chain (Ethereum recommended) to start block-walking. New chains default to `disabled` to prevent unbounded RPC spend.',
                $totalEvmChains
            );
        }
        if ($totalEvmChains === 0) {
            $issues[] = 'No active EVM chains exist in `wp_bcc_chains`. The indexer has nothing to walk.';
        }
        // Detect the "all active chains degraded" state separately from
        // the generic degraded-chains line. When every nominally-enabled
        // chain is in `degraded` or `breaker_open`, the subsystem is
        // operationally offline regardless of enable state — RED severity.
        // The most common shared cause is project-wide provider quota
        // exhaustion (distinct from per-chain CU budget). See
        // `project_v1_v2_nft_path_separation.md` follow-up.
        $allActiveDegraded = $activeCount > 0 && count($degradedChains) === $activeCount;
        if ($allActiveDegraded) {
            $issues[] = sprintf(
                'All %d active EVM chains are degraded or breaker_open: %s. The subsystem is operationally offline regardless of chain enable-state. Per-chain CU budget is unaffected; check the shared `last_error` in the per-chain table — a uniform error across every chain points at project-wide provider quota exhaustion (Alchemy app-level cap), credentials, or network outage.',
                $activeCount,
                implode(', ', $degradedChains)
            );
        } elseif ($degradedChains !== []) {
            $issues[] = 'Chains in degraded state: ' . implode(', ', $degradedChains) . '. Check `last_error` in the per-chain table below.';
        }
        if ($stalledChains !== []) {
            $issues[] = sprintf(
                'Active chains with no tick in %ds: %s. Worker may be silently failing — check the error log.',
                self::CHAIN_STALL_THRESHOLD_SECONDS,
                implode(', ', $stalledChains)
            );
        }
        if ($cuPressureChains !== []) {
            $issues[] = sprintf(
                'CU budget pressure (>=%d%% of daily): %s. Walking will pause when budget hits 100%% until next UTC midnight.',
                (int) (self::CU_BUDGET_PRESSURE_RATIO * 100),
                implode(', ', $cuPressureChains)
            );
        }
        if ($callCountPressureChains !== []) {
            // Actionable framing — point at the likely cause (V2 retries on
            // a degraded chain) and the diagnostic step (the per-chain
            // table's last_error column). The footgun itself is documented
            // separately on the class constant; here we focus on what the
            // operator does right now.
            $issues[] = sprintf(
                'Call-count pressure (>=%d%% of EnrichmentScheduler per-chain cap): %s. Likely cause: V2 retries on a degraded chain; V1 fetches on the same chain will be pre-blocked at ApiRetry until the 10-min counter rolls over. Check `last_error` in the per-chain table below.',
                (int) (self::CALL_COUNT_PRESSURE_RATIO * 100),
                implode(', ', $callCountPressureChains)
            );
        }
        if ($dedupeOvergrown) {
            $issues[] = sprintf(
                'Helius dedupe table overgrown: %d rows (threshold %d). The sweep cron may have stalled.',
                $dedupeRows,
                HeliusSeenSignaturesRepository::ALARM_THRESHOLD
            );
        }

        // Derive RGB. Red dominates yellow dominates green.
        $isRed = !$cronScheduled
            || $cronOverdue
            || $totalEvmChains === 0
            || $activeCount === 0
            || $allActiveDegraded;
        $isYellow = !$isRed && (
            $stalledChains !== []
            || $degradedChains !== []
            || $cuPressureChains !== []
            || $callCountPressureChains !== []
            || $dedupeOvergrown
        );
        $status = $isRed ? self::STATUS_RED : ($isYellow ? self::STATUS_YELLOW : self::STATUS_GREEN);

        return [
            'status'                     => $status,
            'cron_scheduled'             => $cronScheduled,
            'cron_overdue'               => $cronOverdue,
            'cron_overdue_seconds'       => $overSec,
            'active_chains_count'        => $activeCount,
            'total_evm_chains_count'     => $totalEvmChains,
            'stalled_chains'             => $stalledChains,
            'degraded_chains'            => $degradedChains,
            'cu_pressure_chains'         => $cuPressureChains,
            'call_count_pressure_chains' => $callCountPressureChains,
            'call_count_by_chain'        => $callCountByChain,
            'dedupe_overgrown'           => $dedupeOvergrown,
            'issues'                     => $issues,
        ];
    }

    /**
     * @return array{
     *     cron_overdue: bool,
     *     cron_overdue_seconds: int,
     *     chains: list<array{
     *         chain_id: int,
     *         state: string,
     *         last_processed_block: int,
     *         head_block: int,
     *         lag_blocks: int,
     *         cu_used_today: int,
     *         cu_budget_daily: int,
     *         last_run_at: string|null,
     *         last_error: string|null
     *     }>
     * }
     */
    private static function buildSnapshot(): array
    {
        $hook    = NftEthIndexerWorker::CRON_HOOK;
        $next    = wp_next_scheduled($hook);
        $overSec = $next ? max(0, time() - (int) $next) : 0;
        $overdue = $next && $overSec > NftEthIndexerWorker::CRON_OVERDUE_THRESHOLD_SECONDS;

        $dailyBudget = defined('BCC_ETH_DAILY_RPC_BUDGET')
            ? (int) constant('BCC_ETH_DAILY_RPC_BUDGET')
            : NftEthIndexerWorker::DEFAULT_DAILY_BUDGET;

        $chains = [];
        foreach (ChainCheckpointRepository::getAll() as $cp) {
            $last = (int) $cp->last_processed_block;
            $head = (int) $cp->head_block;
            $chains[] = [
                'chain_id'             => (int) $cp->chain_id,
                'state'                => (string) $cp->state,
                'last_processed_block' => $last,
                'head_block'           => $head,
                'lag_blocks'           => max(0, $head - $last),
                'cu_used_today'        => (int) $cp->cu_used_today,
                'cu_budget_daily'      => $dailyBudget,
                'last_run_at'          => $cp->last_run_at !== null ? (string) $cp->last_run_at : null,
                'last_error'           => $cp->last_error !== null ? (string) $cp->last_error : null,
            ];
        }

        return [
            'cron_overdue'         => $overdue,
            'cron_overdue_seconds' => $overSec,
            'chains'               => $chains,
        ];
    }

    /**
     * @return array{
     *     row_count: int,
     *     alarm_threshold: int,
     *     overgrown: bool
     * }
     */
    private static function buildHeliusDedupe(): array
    {
        $count = HeliusSeenSignaturesRepository::rowCount();
        return [
            'row_count'       => $count,
            'alarm_threshold' => HeliusSeenSignaturesRepository::ALARM_THRESHOLD,
            'overgrown'       => $count > HeliusSeenSignaturesRepository::ALARM_THRESHOLD,
        ];
    }
}
