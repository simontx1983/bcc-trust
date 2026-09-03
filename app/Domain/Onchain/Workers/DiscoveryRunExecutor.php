<?php

declare(strict_types=1);

/**
 * Claim one requested run, execute it, record the outcome.
 *
 * ── IT ADDS NO DISCOVERY LOGIC ──────────────────────────────────────────
 * Everything about how a pass works — locking, budgets, the circuit
 * breaker, checkpoint advance — already lives in
 * {@see CosmwasmDiscoveryWorker} and is UNTOUCHED by PR 7A. This class is a
 * ledger adapter: claim, delegate, write the terminal result. If it ever
 * starts deciding what to scan, it has become a scheduler.
 *
 * ── IT NEVER RECLAIMS AN EXPIRED LEASE ──────────────────────────────────
 * The claim admits `status = 'queued'` only. Recovering a dead worker's run
 * belongs to {@see DiscoveryRunMaintenance}, and keeping the two apart is
 * what makes `attempt_count` mean one thing.
 *
 * @package BCC\Trust\Onchain\Workers
 */

namespace BCC\Trust\Onchain\Workers;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Support\CosmwasmPassReport;
use BCC\Trust\Onchain\Support\CosmwasmPassStopReason;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;

if (!defined('ABSPATH')) {
    exit;
}

final class DiscoveryRunExecutor
{
    /** The one-shot hook AsyncDispatcher fires. Not recurring. */
    public const HOOK = 'bcc_discovery_run_execute';

    /** Audit actions for terminal outcomes. No human actor. */
    public const AUDIT_COMPLETED = 'discovery_run_completed';
    public const AUDIT_FAILED    = 'discovery_run_failed';

    // ⚠ NO EXECUTOR-SPECIFIC BUDGET.
    //
    // An earlier draft hardcoded 20 requests / 8 seconds here, copied from
    // the admin backfill. That was wrong twice over. The 20/8 pair is a
    // WEB-REQUEST constraint — it exists because that path runs inside an
    // admin POST — and a cron-executed or CLI-executed run is not in a web
    // request. Worse, it would have created a SECOND budget system: the
    // supervised CLI documents at length that it runs the canonical
    // ceilings, "which is the entire point of running it", and an executor
    // with its own numbers would have silently made that untrue.
    //
    // `new CosmwasmTickBudget()` takes the canonical
    // CosmwasmDiscoveryGate ceilings, so every ledger-backed pass is
    // bounded by the same two numbers an operator can read and override.

    /**
     * Execute one run by id.
     *
     * @return array{status: string, reason?: string, run_id: int,
     *               report?: CosmwasmPassReport, budget?: CosmwasmTickBudget}
     *
     * ⚠ `report` and `budget` are returned for an IN-PROCESS caller only
     * (the supervised CLI, which prints a summary in the same request).
     * The ledger stores bounded COUNTS and never the pass's error strings,
     * because free text is what PR 5b removed from durable storage — so a
     * caller that reconstructed the summary from the row alone would print
     * an empty `errors` list for a pass that failed loudly.
     */
    public static function execute(int $runId): array
    {
        if ($runId <= 0) {
            return ['status' => 'skipped', 'run_id' => 0];
        }

        $token = DiscoveryRunRepository::claim($runId);
        if ($token === null) {
            // Someone else claimed it, it is not due yet, or it is already
            // terminal. All three are ordinary — not an error.
            return ['status' => 'not_claimed', 'run_id' => $runId];
        }

        $run = DiscoveryRunRepository::findById($runId);
        if ($run === null) {
            // We hold a lease on a row we cannot read. Do NOT guess: fail it
            // with a bounded code rather than run a pass we cannot attribute.
            self::terminalFailure($runId, $token, DiscoveryRunError::READ_UNAVAILABLE, 0, 0);
            return ['status' => 'failed', 'reason' => DiscoveryRunError::READ_UNAVAILABLE, 'run_id' => $runId];
        }

        $chainId  = (int) $run->chain_id;
        $jobKind  = (string) $run->job_kind;
        $scanMode = (string) $run->scan_mode;

        if ($jobKind !== DiscoveryJobKind::COSMWASM_DISCOVERY) {
            self::terminalFailure($runId, $token, DiscoveryRunError::CHAIN_NOT_READY, $chainId, 0);
            return ['status' => 'failed', 'reason' => DiscoveryRunError::CHAIN_NOT_READY, 'run_id' => $runId];
        }

        if (!class_exists('\\BCC\\Trust\\Onchain\\Workers\\CosmwasmDiscoveryWorker')) {
            self::terminalFailure($runId, $token, DiscoveryRunError::CHAIN_NOT_READY, $chainId, 0);
            return ['status' => 'failed', 'reason' => DiscoveryRunError::CHAIN_NOT_READY, 'run_id' => $runId];
        }

        $budget = new CosmwasmTickBudget();
        $report = new CosmwasmPassReport();

        try {
            // The mode was resolved once, at request time, and frozen onto
            // the row — so a checkpoint that completes mid-flight cannot
            // silently change what this run is doing.
            $outcome = $scanMode === DiscoveryScanMode::HISTORICAL
                ? CosmwasmDiscoveryWorker::runBackfillForChain($chainId, $budget, $report)
                : CosmwasmDiscoveryWorker::runSupervisedSingleChainPass($chainId, $budget, $report);
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] discovery run threw', [
                'run_id'     => $runId,
                'chain_id'   => $chainId,
                'error_code' => DiscoveryRunError::EXECUTION_FAILED,
                'error'      => $e->getMessage(),
            ]);

            self::terminalFailure($runId, $token, DiscoveryRunError::EXECUTION_FAILED, $chainId, (int) $run->attempt_count);

            return [
                'status' => 'failed',
                'reason' => DiscoveryRunError::EXECUTION_FAILED,
                'run_id' => $runId,
                'report' => $report,
                'budget' => $budget,
            ];
        }

        $stopReason = CosmwasmPassStopReason::forOutcome($outcome, $budget);

        // ⚠ A BUDGET STOP IS A SUCCESS.
        // The pass did real work and stopped at a ceiling it was told to
        // respect. `partial` carries that, and it is also set when the pass
        // recorded an error on the way — a provider refusal can abort a walk
        // with the budget barely touched, and the stop reason alone would
        // then read `pass_completed`.
        $partial = CosmwasmPassStopReason::isPartial($stopReason) || $report->errors !== [];

        $counts = [
            'requests_used'       => $budget->spent(),
            'pages_fetched'       => $report->pagesFetched(),
            'families_seen'       => $report->familiesClassified,
            'contracts_seen'      => $report->contractsClassified,
            'collections_emitted' => $report->collectionsEmitted,
            'collections_denied'  => $report->collectionsDenied,
        ];

        // ⚠ ONLY A PASS THAT RAN IS A SUCCESS.
        //
        // An earlier draft called markSucceeded() for every outcome, so a
        // pass that never started — because a peer held the chain's advisory
        // lock, or because the chain refused to prepare — was recorded as a
        // completed scan. The stop reason said `lock_contended` while the
        // status said `succeeded`, and the status is what an operator reads.
        //
        // The stop reason is preserved on BOTH paths, so the nine
        // distinctions survive: the run's STATUS says whether it completed,
        // the stop reason says why it ended.
        if ($outcome === CosmwasmDiscoveryWorker::PASS_RAN) {
            $confirmed = DiscoveryRunRepository::markSucceeded($runId, $token, $stopReason, $partial, $counts);
        } else {
            $confirmed = DiscoveryRunRepository::markFailed(
                $runId,
                $token,
                $outcome === CosmwasmDiscoveryWorker::PASS_FAILED
                    ? DiscoveryRunError::EXECUTION_FAILED
                    : DiscoveryRunError::CHAIN_NOT_READY,
                $stopReason
            );
        }

        if (!$confirmed) {
            // ⚠ THE ONE OUTCOME THAT MUST NEVER BE REPORTED AS SUCCESS.
            // The terminal write did not land — we lost the lease, or the
            // update matched nothing. We do not know whether the result is
            // recorded, so we claim nothing. The lease expires, the reaper
            // returns the run, and the next attempt settles it.
            Logger::error('[bcc-trust] discovery run terminal write was NOT confirmed; leaving the lease to expire', [
                'run_id'     => $runId,
                'chain_id'   => $chainId,
                'error_code' => DiscoveryRunError::TERMINAL_WRITE_UNCONFIRMED,
            ]);

            return [
                'status'  => 'unconfirmed',
                'reason'  => DiscoveryRunError::TERMINAL_WRITE_UNCONFIRMED,
                'run_id'  => $runId,
            ];
        }

        $ran = $outcome === CosmwasmDiscoveryWorker::PASS_RAN;

        self::auditTerminal($runId, $ran ? self::AUDIT_COMPLETED : self::AUDIT_FAILED, [
            'run_uuid'    => (string) $run->run_uuid,
            'chain_id'    => $chainId,
            'scan_mode'   => $scanMode,
            'stop_reason' => $stopReason,
            'partial'     => $partial ? 1 : 0,
        ] + $counts);

        // The caller is told which branch ran. Reporting 'succeeded' for a
        // pass that never started is exactly the lie the status split above
        // exists to prevent.
        return [
            'status'  => $ran ? 'succeeded' : 'failed',
            'reason'  => $stopReason,
            'run_id'  => $runId,
            'report'  => $report,
            'budget'  => $budget,
        ];
    }

    /**
     * Write a terminal failure and audit it, honouring the same
     * confirmation contract as the success path.
     */
    private static function terminalFailure(
        int $runId,
        string $token,
        string $errorCode,
        int $chainId,
        int $attempt
    ): void {
        if (!DiscoveryRunRepository::markFailed($runId, $token, $errorCode)) {
            Logger::error('[bcc-trust] discovery run failure write was NOT confirmed; leaving the lease to expire', [
                'run_id'     => $runId,
                'error_code' => DiscoveryRunError::TERMINAL_WRITE_UNCONFIRMED,
            ]);

            return;
        }

        self::auditTerminal($runId, self::AUDIT_FAILED, [
            'chain_id'      => $chainId,
            'error_code'    => $errorCode,
            'attempt_count' => $attempt,
        ]);
    }

    /**
     * Audit a terminal outcome — BEST EFFORT, by design.
     *
     * ── WHY THIS DOES NOT ROLL BACK ─────────────────────────────────────
     * A terminal row has NO human actor and must never be attributed to the
     * administrator who requested the scan. The ledger already records the
     * outcome durably, so a failed secondary audit degrades observability,
     * not truth.
     *
     * Rolling the result back would strand the run `running` until its lease
     * expired and then re-execute every provider call — turning a logging
     * failure into duplicated external traffic. `audit_degraded` keeps the
     * gap visible instead.
     *
     * @param array<string, scalar> $meta
     */
    private static function auditTerminal(int $runId, string $action, array $meta): void
    {
        $auditId = AuditLogger::logChecked($action, $runId, $meta, 'discovery_run', null);

        if ($auditId !== null) {
            return;
        }

        DiscoveryRunRepository::markAuditDegraded($runId);

        Logger::error('[bcc-trust] discovery run terminal audit could not be written; result preserved, audit_degraded set', [
            'run_id' => $runId,
            'action' => $action,
        ]);
    }
}
