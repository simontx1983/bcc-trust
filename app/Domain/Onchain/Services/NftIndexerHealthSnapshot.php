<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
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
