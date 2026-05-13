<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository;
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
     *   RED    — cron not scheduled, cron overdue, OR 0 active chains
     *            (the silent-failure modes operators most often miss).
     *   YELLOW — at least one active chain but something needs attention:
     *            stalled tick, degraded/breaker_open state, CU pressure,
     *            or overgrown Helius dedupe table.
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

        $activeCount       = 0;
        $stalledChains     = [];
        $degradedChains    = [];
        $cuPressureChains  = [];
        $now               = time();

        foreach (ChainCheckpointRepository::getAll() as $cp) {
            $chainId = (int) $cp->chain_id;
            if (!isset($slugByChainId[$chainId])) {
                // Checkpoint exists for a chain that's no longer active or
                // not evm-typed. Skip — not part of this view's scope.
                continue;
            }
            $slug  = $slugByChainId[$chainId];
            $state = (string) $cp->state;

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
        if ($degradedChains !== []) {
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
            || $activeCount === 0;
        $isYellow = !$isRed && (
            $stalledChains !== []
            || $degradedChains !== []
            || $cuPressureChains !== []
            || $dedupeOvergrown
        );
        $status = $isRed ? self::STATUS_RED : ($isYellow ? self::STATUS_YELLOW : self::STATUS_GREEN);

        return [
            'status'                 => $status,
            'cron_scheduled'         => $cronScheduled,
            'cron_overdue'           => $cronOverdue,
            'cron_overdue_seconds'   => $overSec,
            'active_chains_count'    => $activeCount,
            'total_evm_chains_count' => $totalEvmChains,
            'stalled_chains'         => $stalledChains,
            'degraded_chains'        => $degradedChains,
            'cu_pressure_chains'     => $cuPressureChains,
            'dedupe_overgrown'       => $dedupeOvergrown,
            'issues'                 => $issues,
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
