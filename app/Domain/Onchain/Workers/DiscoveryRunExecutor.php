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
use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use BCC\Trust\Onchain\Services\DiscoveryScanSession;
use BCC\Trust\Onchain\Support\CosmwasmPassReport;
use BCC\Trust\Onchain\Support\CosmwasmPassStopReason;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Support\DiscoveryReadiness;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunStatus;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type DiscoveryRunRow from DiscoveryRunRepository
 */
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
     *
     * ── ⚠ `$allowContinuation` — WHO MAY HOST A SESSION (PR 7.3) ────────
     * TRUE for the Action Scheduler worker, which is exactly the kind of
     * caller a multi-chunk session needs: short-lived, restartable, and
     * able to be scheduled again.
     *
     * FALSE for the supervised CLI, and not as a convenience. That command's
     * whole contract is ONE PASS PER INVOCATION, executed inline while a
     * human watches the terminal — it says so in its own preflight banner.
     * A session there would queue chunks the operator never asked for, on a
     * process that is about to exit, and their status would come back after
     * the summary had already printed.
     *
     * It is a parameter rather than an inferred fact on purpose: the caller
     * declares what it can host, and `grep` finds both answers.
     */
    public static function execute(int $runId, bool $allowContinuation = true): array
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

        // ── PR 7.1: RE-ASK READINESS, IMMEDIATELY BEFORE PROVIDER WORK ──
        //
        // The request gate ran when the operator pressed the button. Between
        // then and now a deploy may have changed wp-config, an administrator
        // may have withdrawn product support or the opt-in, or the canary
        // allowlist may have narrowed. Configuration is not frozen onto the
        // run, so it must be re-read at the last possible moment.
        //
        // ⚠ WHY THIS IS NOT REDUNDANT WITH THE WORKER'S OWN GATE.
        // `runBackfillForChain()` does check `backfillEnabled()` — and
        // returns PASS_SKIPPED, which arrives here as the generic
        // CHAIN_NOT_READY / `chain_refused_to_prepare` pair. That pair is
        // also what a pause, an open breaker and a missing driver produce,
        // so the operator learns only that something refused, never which
        // switch. Asking here lets the ledger record the ACTUAL blocker.
        //
        // The mode is taken from the ROW, not recomputed: a checkpoint that
        // completed while this run sat queued must not silently re-judge it
        // against a different switch than the one it was approved under.
        $readiness = DiscoveryReadiness::forExecution($chainId, $scanMode);

        if (!$readiness['eligible']) {
            Logger::warning('[bcc-trust] discovery run refused at execution time; configuration changed after queueing', [
                'run_id'     => $runId,
                'chain_id'   => $chainId,
                'scan_mode'  => $scanMode,
                'error_code' => $readiness['reason'],
            ]);

            // ⚠ ZERO PROVIDER CALLS. This returns before the budget, the
            // fetcher and the worker exist, so there is nothing to abort
            // mid-flight and no request to un-send.
            //
            // This is a confirmed terminal FAILURE carrying the specific
            // code — never a success, and never "0 collections found".
            // Nothing was looked at, so nothing was found.
            self::terminalFailure($runId, $token, $readiness['reason'], $chainId, (int) $run->attempt_count);

            return [
                'status' => 'failed',
                'reason' => $readiness['reason'],
                'run_id' => $runId,
            ];
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
            // ── PR 7.3: may this authorized session take another chunk? ──
            //
            // ⚠ THIS IS CONTINUATION, NOT CREATION. Everything below acts on
            // the run row an administrator already made: same id, same uuid,
            // same operator, same chain, same frozen scan mode. Nothing here
            // can pick a chain and nothing here can insert a run — the only
            // two writes are "release this row for its next chunk" and
            // "schedule the same run id again".
            $session = $allowContinuation
                ? self::continueSession($run, $runId, $chainId, $token, $counts, $report)
                : ['continued' => false, 'reason' => ''];

            if ($session['continued']) {
                // The lease is already released and the next chunk is queued.
                // Deliberately NOT terminal: `active_marker` is still set, so
                // `uq_active` keeps refusing a second session for this chain.
                return [
                    'status'  => 'chunk_complete',
                    'reason'  => $stopReason,
                    'run_id'  => $runId,
                    'report'  => $report,
                    'budget'  => $budget,
                ];
            }

            // The session is over. Its reason replaces the chunk's own stop
            // reason, because the run row describes the SESSION — an operator
            // reading `request_budget_exhausted` on a run that actually hit
            // its chunk ceiling would be told the wrong thing.
            if ($session['reason'] !== '') {
                $stopReason = $session['reason'];
            }

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
     * Decide whether this authorized session takes another chunk, and if so
     * release the lease and queue it (PR 7.3).
     *
     * ── WHAT THIS CAN AND CANNOT DO ─────────────────────────────────────
     * It can do exactly two things: release THIS run row for its next chunk,
     * and schedule THIS run id again. It cannot select a chain, cannot
     * insert a run, and cannot change the operator or the frozen scan mode.
     * That is the whole difference between bounded continuation of an
     * authorized run and automatic scan creation, which stays forbidden.
     *
     * ── ORDER IS THE SAFETY PROPERTY ────────────────────────────────────
     * 1. Re-read the run row. Between chunks the row is `queued`, so an
     *    administrator can withdraw it — a cancelled row must never be
     *    continued, and the row is the only place that fact lives.
     * 2. Re-ask readiness with the frozen mode. Support, opt-in, allowlist
     *    and pause can all change under a session.
     * 3. Re-read authoritative progress. `eligible_now`, never `remaining`.
     * 4. Only then consult the ceilings.
     * 5. Release the lease BEFORE scheduling, so the next chunk can claim.
     *
     * ⚠ THE RELEASE MUST BE CONFIRMED BEFORE ANYTHING IS SCHEDULED. If
     * `releaseForNextChunk()` returns false we did not hold the row, so we
     * schedule nothing and fall through to the ordinary terminal path — and
     * if THAT also fails to confirm, the lease expires and the reaper
     * returns the run. There is no path where an action is queued for a run
     * whose state we did not successfully write.
     *
     * @param DiscoveryRunRow $run    the run row as read at claim time
     * @param array<string, int> $counts this chunk's telemetry
     * @return array{continued: bool, reason: string}
     */
    private static function continueSession(
        object $run,
        int $runId,
        int $chainId,
        string $token,
        array $counts,
        CosmwasmPassReport $report
    ): array {
        $stop = static fn(string $reason): array => ['continued' => false, 'reason' => $reason];

        // ── 1. the row, re-read ─────────────────────────────────────────
        //
        // ⚠ NOT the copy taken at claim time. An administrator may have
        // withdrawn the session while this chunk was running.
        $fresh = DiscoveryRunRepository::findById($runId);
        if ($fresh === null) {
            return $stop(DiscoveryScanSession::STOP_NOT_READY);
        }

        $cancelled = (string) $fresh->status === DiscoveryRunStatus::CANCELLED;

        // ── 2. readiness, with the mode frozen on the row ───────────────
        $readiness = DiscoveryReadiness::forExecution($chainId, (string) $run->scan_mode);

        // ── 3. authoritative progress ───────────────────────────────────
        $progress    = DiscoveryScanProgress::forChain($chainId);
        $eligibleNow = 0;
        $delayed     = 0;

        if (($progress['ok'] ?? false) === true) {
            $eligibleNow = (int) ($progress['eligible_now'] ?? 0);
            $delayed     = (int) ($progress['delayed_families'] ?? 0);
        } else {
            // ⚠ A FAILED PROGRESS READ IS NOT AN EMPTY QUEUE. Zero eligible
            // work ends the session honestly; it never reports completion,
            // because `scan_complete` is derived elsewhere from the same
            // failed read and comes back UNKNOWN.
            Logger::warning('[bcc-trust] discovery progress unavailable between chunks; ending the session', [
                'run_id'   => $runId,
                'chain_id' => $chainId,
            ]);
        }

        // ── 4. the ceilings ─────────────────────────────────────────────
        // ⚠ `?? 0` IS NOT DEFENSIVE PADDING. There is a real window — a
        // files-only deploy before the migration has run — where the row
        // predates `chunks_used`. Reading it as zero starts the session's
        // count from the beginning, which is the safe direction: the
        // ceiling still bites, just one session later than it might have.
        // ⚠ `+ 1` COUNTS THE CHUNK THAT JUST RAN. The row is only
        // incremented by the release below, so at this moment
        // `chunks_used` is the count BEFORE this chunk. Passing it raw
        // authorised MAX_CHUNKS + 1 chunks: the 25th would decide with 24,
        // continue, and a 26th would run before the ceiling bit. The
        // ceiling means "chunks this session may spend", so the chunk in
        // hand has to be one of them.
        $decision = DiscoveryScanSession::decide([
            'chunks_used'   => max(0, (int) ($fresh->chunks_used ?? 0)) + 1,
            'requests_used' => max(0, (int) ($fresh->requests_used ?? 0)) + (int) ($counts['requests_used'] ?? 0),
            'age_seconds'   => self::ageSeconds($fresh->requested_at ?? null),
            'error_chunks'  => $report->errors !== [] ? 1 : 0,
            'ready'         => ($readiness['eligible'] ?? false) === true,
            'cancelled'     => $cancelled,
            'eligible_now'  => $eligibleNow,
            'delayed'       => $delayed,
        ]);

        if (!$decision['continue']) {
            return $stop($decision['reason']);
        }

        // ── 5. release, THEN schedule ───────────────────────────────────
        $delay    = DiscoveryScanSession::nextChunkDelay();
        $released = DiscoveryRunRepository::releaseForNextChunk($runId, $token, $counts, $delay);

        if (!$released) {
            Logger::error('[bcc-trust] discovery chunk release was NOT confirmed; not scheduling a continuation', [
                'run_id'   => $runId,
                'chain_id' => $chainId,
            ]);

            return $stop('');
        }

        \BCC\Core\Cron\AsyncDispatcher::scheduleSingle(
            time() + $delay,
            self::HOOK,
            [$runId],
            'bcc-discovery'
        );

        // ⚠ NOT audited as a terminal event. A chunk boundary is not the end
        // of the administrator's action; the ONE checked request record and
        // the ONE terminal record still bracket the whole session. Auditing
        // every chunk would bury the two rows that matter under 25 that do
        // not.
        Logger::info('[bcc-trust] discovery session continuing to the next chunk', [
            'run_id'      => $runId,
            'chain_id'    => $chainId,
            'chunks_used' => max(0, (int) ($fresh->chunks_used ?? 0)) + 1,
            'eligible'    => $eligibleNow,
        ]);

        return ['continued' => true, 'reason' => ''];
    }

    /**
     * Whole seconds since the session was requested. PURE apart from the clock.
     *
     * ⚠ `requested_at` is stored UTC and read back naive, so it must be
     * parsed as UTC. Reading it in the site timezone would make a session
     * look hours old the moment it started, and the age ceiling would end
     * every session on its first chunk.
     *
     * @param mixed $requestedAt
     */
    private static function ageSeconds($requestedAt): int
    {
        if (!is_string($requestedAt) || trim($requestedAt) === '') {
            return 0;
        }

        $ts = strtotime($requestedAt . ' UTC');
        if ($ts === false) {
            return 0;
        }

        return max(0, time() - $ts);
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
