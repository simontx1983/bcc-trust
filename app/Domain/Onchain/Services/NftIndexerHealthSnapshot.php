<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\REST\HeliusWebhookEndpoint;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository;
use BCC\Trust\Onchain\Services\EnrichmentScheduler;
use BCC\Trust\Onchain\Services\HeliusSubscriptionManager;
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
     * Helius webhook freshness thresholds (X5).
     *
     * Solana ingestion is externally event-driven via Helius webhook
     * deliveries — there is no local "tick" to monitor. The freshness
     * timestamp written by `HeliusWebhookEndpoint::handle` on every
     * authenticated delivery (including empty-payload pings) is the
     * single source of truth for "is Solana ingestion alive?"
     *
     *   YELLOW — no delivery in 60+ minutes. Could be a quiet window
     *            OR webhook stalled — operator should check.
     *   RED    — no delivery in 4+ hours. Provider outage, webhook
     *            delivery suspended, or local handler is failing
     *            silently.
     *
     * Sized for "expected-traffic period." Naturally quiet windows
     * during low-Solana-NFT-activity hours may still cross the YELLOW
     * threshold; that's accepted in exchange for catching real
     * silence early. Adjusting these to be wider would mean an
     * outage could hide for hours.
     */
    public const HELIUS_FRESHNESS_YELLOW_SECONDS = 3600;   // 60 min
    public const HELIUS_FRESHNESS_RED_SECONDS    = 14400;  // 4 h

    /**
     * Minimum history entries before progression-detection rules fire.
     *
     * `fake_healthy` (stagnant block + advancing head) and "regression"
     * need at least 3 entries to distinguish a real stall from
     * insufficient sample. Lag-drift needs 5 (the full window) to assert
     * monotonic increase across 4 deltas. With <3 entries we report
     * `insufficient_data` and stay GREEN.
     */
    public const PROGRESSION_MIN_SAMPLES = 3;

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
     *   RED    — cron not scheduled, cron overdue, 0 active chains,
     *            every active chain in degraded/breaker_open (operationally
     *            offline regardless of nominal enable-state), OR ANY chain
     *            shows backward progression (last_processed_block went
     *            DOWN — checkpoint regression, a correctness anomaly).
     *   YELLOW — at least one healthy active chain but something needs
     *            attention: some chains degraded, stalled tick, CU
     *            pressure, scheduler call-count pressure, overgrown
     *            Helius dedupe table, "fake healthy" progression
     *            (block stagnant while head advances), or monotonic
     *            lag drift (worker can't keep up with chain throughput).
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
     *     fake_healthy_chains: list<string>,
     *     lag_drift_chains: list<string>,
     *     regression_chains: list<string>,
     *     progression_by_chain: array<int, array{slug: string, deltas: list<int>, last_block: int, sample_count: int}>,
     *     dedupe_overgrown: bool,
     *     helius_freshness: array{state: 'green'|'yellow'|'red'|'never_delivered'|'not_provisioned', last_delivery_at: int|null, age_seconds: int|null},
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
        $fakeHealthyChains       = [];
        $lagDriftChains          = [];
        $regressionChains        = [];
        $progressionByChain      = [];
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

            // Progression detection (X3). First-class operational state,
            // not UI decoration. The history column is the truth source;
            // the per-chain sparkline column is just one presentation of
            // it. Detection rules:
            //
            //   regression       — ANY backward block delta. Correctness
            //                      anomaly (checkpoint should never go
            //                      down beyond N=CONFIRMATIONS reorg
            //                      depth). Surfaced as RED regardless of
            //                      chain state.
            //   fake_healthy     — state=healthy, block stagnant across
            //                      last MIN_SAMPLES entries, head_block
            //                      advanced. The dangerous silent-failure
            //                      class: worker is ticking but checkpoint
            //                      isn't moving while the chain produces
            //                      blocks. YELLOW.
            //   lag_drift        — state=healthy, monotonic increase in
            //                      (head - block) across the full 5-entry
            //                      window. Independent of absolute lag —
            //                      the trend is the signal, not the size.
            //                      YELLOW.
            //
            // O(5) per chain. No DB scan — all derivation runs over the
            // bounded JSON column already loaded by `getAll()`.
            $history = ChainCheckpointRepository::decodeProgressionHistory(
                $cp->block_progression_history ?? null
            );
            $progressionSignal = self::deriveProgressionSignal($history, $state);
            $progressionByChain[$chainId] = [
                'slug'         => $slug,
                'deltas'       => $progressionSignal['deltas'],
                'last_block'   => $progressionSignal['last_block'],
                'sample_count' => count($history),
            ];
            if ($progressionSignal['regression']) {
                $regressionChains[] = sprintf(
                    '%s (last_processed_block went DOWN — see history)',
                    $slug
                );
            }
            if ($progressionSignal['fake_healthy']) {
                $fakeHealthyChains[] = $slug;
            }
            if ($progressionSignal['lag_drifting']) {
                $fromLag = (int) $progressionSignal['lag_first'];
                $toLag   = (int) $progressionSignal['lag_last'];
                $lagDriftChains[] = sprintf(
                    '%s (lag drifted %d→%d across %d ticks)',
                    $slug,
                    $fromLag,
                    $toLag,
                    count($history)
                );
            }
        }

        $dedupeRows      = HeliusSeenSignaturesRepository::rowCount();
        $dedupeOvergrown = $dedupeRows > HeliusSeenSignaturesRepository::ALARM_THRESHOLD;

        // Helius webhook freshness (X5). Solana ingestion alive-or-dead.
        // See helper for the state machine.
        $heliusFreshness = self::deriveHeliusFreshness();

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
        // Progression signals (X3). Listed before less-severe issues so
        // the regression case (RED) sorts ahead.
        if ($regressionChains !== []) {
            $issues[] = sprintf(
                'BACKWARD progression detected: %s. This should never occur outside the N=%d-block reorg window and signals a checkpoint regression bug, corrupted progression history, or manual overwrite. Inspect `block_progression_history` and `last_processed_block` immediately.',
                implode(', ', $regressionChains),
                NftEthIndexerWorker::CONFIRMATIONS
            );
        }
        if ($fakeHealthyChains !== []) {
            $issues[] = sprintf(
                'Worker alive but NOT progressing on %s: chain state reports healthy and `last_run_at` is fresh, but `last_processed_block` has not advanced across the last %d ticks while `head_block` has. This is the dangerous silent-failure class — heartbeat is lying. Inspect worker logs for swallowed exceptions in the post-fetch loop.',
                implode(', ', $fakeHealthyChains),
                self::PROGRESSION_MIN_SAMPLES
            );
        }
        if ($lagDriftChains !== []) {
            $issues[] = sprintf(
                'Lag drifting upward on %s: the worker is ticking but `BLOCKS_PER_TICK` is below the chain natural block-production rate. Lag will continue to grow until either traffic eases or BLOCKS_PER_TICK is raised. Independent of absolute lag size — the trend is the signal.',
                implode(', ', $lagDriftChains)
            );
        }
        if ($dedupeOvergrown) {
            $issues[] = sprintf(
                'Helius dedupe table overgrown: %d rows (threshold %d). The sweep cron may have stalled.',
                $dedupeRows,
                HeliusSeenSignaturesRepository::ALARM_THRESHOLD
            );
        }
        // Helius freshness issue lines (X5). Actionable wording: name
        // the likely causes and the exact next operator step. Each cause
        // class points at a different remediation surface.
        if ($heliusFreshness['state'] === 'red') {
            $issues[] = sprintf(
                'Solana ingestion silent for %s (last Helius delivery: %s). At RED severity (>4h). Likely causes: provider outage at Helius, webhook delivery suspended (check Helius dashboard for the app status), or local handler is rejecting deliveries silently before the freshness mark fires (signature mismatch — check `bcc_helius_signature_sigfail_total`). NFT holdings on Solana have stopped updating.',
                self::formatDuration((int) $heliusFreshness['age_seconds']),
                self::formatTimestamp((int) $heliusFreshness['last_delivery_at'])
            );
        } elseif ($heliusFreshness['state'] === 'yellow') {
            $issues[] = sprintf(
                'Solana ingestion has not received a Helius delivery in %s (last: %s). At YELLOW severity (>1h). Could be a naturally quiet window during low-Solana-NFT-activity hours, or an early warning that the webhook has stalled. Check the Helius panel below for delivery counters and the Helius dashboard for app status.',
                self::formatDuration((int) $heliusFreshness['age_seconds']),
                self::formatTimestamp((int) $heliusFreshness['last_delivery_at'])
            );
        } elseif ($heliusFreshness['state'] === 'never_delivered') {
            $issues[] = 'Helius webhook is provisioned but no deliveries have been received yet. Verify (1) the Helius app dashboard shows our callback URL as the configured endpoint, (2) `BCC_HELIUS_WEBHOOK_SECRET` in wp-config.php matches the Helius app secret, and (3) at least one tracked Solana wallet exists.';
        }

        // Derive RGB. Red dominates yellow dominates green.
        // Regression is RED — checkpoint going backwards is a correctness
        // anomaly that should never happen outside the reorg window.
        // Helius RED (>4h silence) is RED — Solana ingestion is offline.
        $isRed = !$cronScheduled
            || $cronOverdue
            || $totalEvmChains === 0
            || $activeCount === 0
            || $allActiveDegraded
            || $regressionChains !== []
            || $heliusFreshness['state'] === 'red';
        $isYellow = !$isRed && (
            $stalledChains !== []
            || $degradedChains !== []
            || $cuPressureChains !== []
            || $callCountPressureChains !== []
            || $fakeHealthyChains !== []
            || $lagDriftChains !== []
            || $dedupeOvergrown
            || $heliusFreshness['state'] === 'yellow'
            || $heliusFreshness['state'] === 'never_delivered'
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
            'fake_healthy_chains'        => $fakeHealthyChains,
            'lag_drift_chains'           => $lagDriftChains,
            'regression_chains'          => $regressionChains,
            'progression_by_chain'       => $progressionByChain,
            'dedupe_overgrown'           => $dedupeOvergrown,
            'helius_freshness'           => $heliusFreshness,
            'issues'                     => $issues,
        ];
    }

    /**
     * Derive the Helius freshness state (X5).
     *
     * State machine:
     *   not_provisioned  — webhook id absent. Freshness is not applicable.
     *   never_delivered  — provisioned but no delivery timestamp recorded.
     *                      Could be fresh provisioning OR misconfiguration.
     *                      YELLOW.
     *   green / yellow / red — derived from age against the two thresholds.
     *
     * @return array{state: 'green'|'yellow'|'red'|'never_delivered'|'not_provisioned', last_delivery_at: int|null, age_seconds: int|null}
     */
    private static function deriveHeliusFreshness(): array
    {
        $webhookId = (string) get_option(HeliusSubscriptionManager::OPTION_WEBHOOK_ID, '');
        if ($webhookId === '') {
            return [
                'state'            => 'not_provisioned',
                'last_delivery_at' => null,
                'age_seconds'      => null,
            ];
        }

        $rawTs = (int) get_option(HeliusWebhookEndpoint::OPTION_LAST_DELIVERY_AT, 0);
        if ($rawTs <= 0) {
            return [
                'state'            => 'never_delivered',
                'last_delivery_at' => null,
                'age_seconds'      => null,
            ];
        }

        // Clock-skew tolerance: a system clock briefly ahead of the
        // recorded timestamp would produce a negative age. Clamp at 0
        // and treat as fresh.
        $age = max(0, time() - $rawTs);

        if ($age > self::HELIUS_FRESHNESS_RED_SECONDS) {
            $state = 'red';
        } elseif ($age > self::HELIUS_FRESHNESS_YELLOW_SECONDS) {
            $state = 'yellow';
        } else {
            $state = 'green';
        }

        return [
            'state'            => $state,
            'last_delivery_at' => $rawTs,
            'age_seconds'      => $age,
        ];
    }

    /**
     * Human-friendly duration ("2h 14m", "47m", "12s"). Compact enough
     * to fit inline in issue lines without bloating the 200-byte
     * truncation budget downstream callers may impose.
     *
     * Public so admin views can render the same string the issue lines
     * use without recomputing the format.
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return ((int) floor($seconds / 60)) . 'm';
        }
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds - $h * 3600) / 60);
        return $h . 'h ' . $m . 'm';
    }

    /**
     * ISO-8601 UTC timestamp for log/issue-line use.
     */
    private static function formatTimestamp(int $unixSeconds): string
    {
        if ($unixSeconds <= 0) {
            return 'never';
        }
        return gmdate('c', $unixSeconds);
    }

    /**
     * Derive per-chain progression signals from the bounded history.
     *
     * Returns a packed struct so the caller can both raise top-card
     * signals AND render the per-chain sparkline from one pass — no
     * second loop over the same history.
     *
     * Detection rules (all O(n) over the bounded n <= 5 history):
     *   regression   — ANY backward delta in block (correctness anomaly)
     *   fake_healthy — state=healthy AND block stagnant across last
     *                  PROGRESSION_MIN_SAMPLES entries AND head_block
     *                  advanced across the same window
     *   lag_drifting — state=healthy AND lag (head-block) monotonically
     *                  increasing across the FULL window (needs 5 entries)
     *
     * @param  list<array{block: int, head: int, at: string}> $history
     * @return array{
     *     deltas: list<int>,
     *     last_block: int,
     *     regression: bool,
     *     fake_healthy: bool,
     *     lag_drifting: bool,
     *     lag_first: int,
     *     lag_last: int
     * }
     */
    private static function deriveProgressionSignal(array $history, string $state): array
    {
        $count      = count($history);
        $lastBlock  = $count > 0 ? $history[$count - 1]['block'] : 0;
        $deltas     = [];

        // Per-step block deltas + per-step lag values, computed once.
        $lags = [];
        for ($i = 0; $i < $count; $i++) {
            $entry  = $history[$i];
            $lags[] = $entry['head'] - $entry['block'];
            if ($i > 0) {
                $deltas[] = $entry['block'] - $history[$i - 1]['block'];
            }
        }

        $regression  = false;
        foreach ($deltas as $delta) {
            if ($delta < 0) {
                $regression = true;
                break;
            }
        }

        $fakeHealthy = false;
        $lagDrifting = false;

        if ($state === ChainCheckpointRepository::STATE_HEALTHY && $count >= self::PROGRESSION_MIN_SAMPLES) {
            // Fake-healthy: last MIN_SAMPLES entries all share the same
            // `block` (stagnant) AND head_block over the same window
            // advanced (chain produced new blocks the worker did not
            // process).
            $tail            = array_slice($history, -self::PROGRESSION_MIN_SAMPLES);
            $tailBlocks      = array_column($tail, 'block');
            $tailHeads       = array_column($tail, 'head');
            $blocksStagnant  = count(array_unique($tailBlocks)) === 1;
            $headsAdvanced   = end($tailHeads) > $tailHeads[0];
            $fakeHealthy     = $blocksStagnant && $headsAdvanced;
        }

        if ($state === ChainCheckpointRepository::STATE_HEALTHY && $count === ChainCheckpointRepository::MAX_PROGRESSION_ENTRIES) {
            // Lag-drift: strict monotonic increase across the full
            // 4-delta sequence (5 history entries). Independent of
            // absolute lag size — the trend is the signal.
            $monotonic = true;
            for ($i = 1; $i < count($lags); $i++) {
                if ($lags[$i] <= $lags[$i - 1]) {
                    $monotonic = false;
                    break;
                }
            }
            $lagDrifting = $monotonic;
        }

        return [
            'deltas'       => $deltas,
            'last_block'   => $lastBlock,
            'regression'   => $regression,
            'fake_healthy' => $fakeHealthy,
            'lag_drifting' => $lagDrifting,
            'lag_first'    => $lags === [] ? 0 : $lags[0],
            'lag_last'     => $lags === [] ? 0 : $lags[count($lags) - 1],
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
