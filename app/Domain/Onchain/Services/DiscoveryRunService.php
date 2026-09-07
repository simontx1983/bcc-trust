<?php

declare(strict_types=1);

/**
 * The only writer of discovery INTENT.
 *
 * ── AUTHORIZATION IS THE POINT ──────────────────────────────────────────
 * A run is a durable statement that a NAMED administrator asked for a scan.
 * Every guard here exists to keep that statement true: the operator must be
 * an explicit, existing WordPress user holding `manage_options`, checked on
 * that named user rather than via `current_user_can()` — because this
 * service is reachable from WP-CLI, where there is no current user at all,
 * and an implicit actor must never satisfy an authorization check.
 *
 * User id 0 is not an administrator identity. It is the absence of one.
 *
 * ── NOTHING IS CONTACTED FROM HERE ──────────────────────────────────────
 * Requesting a run makes no provider call. It validates, inserts, audits
 * and dispatches. A refusal creates no row at all.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Support\DiscoveryReadiness;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunStatus;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use BCC\Trust\Onchain\ValueObjects\DiscoverySessionTotals;
use BCC\Trust\Onchain\Workers\DiscoveryRunExecutor;

if (!defined('ABSPATH')) {
    exit;
}

final class DiscoveryRunService
{
    /** Audit actions. All ≤ 50 chars — the AuditLogger length guard. */
    public const AUDIT_REQUESTED = 'admin_discovery_run_requested';
    public const AUDIT_CANCELLED = 'admin_discovery_run_cancelled';
    public const AUDIT_RETRIED   = 'admin_discovery_run_retried';

    /**
     * Attempts at the insert/read cycle when losing the active-run race.
     * Three is enough to survive a run terminalizing underneath us; more
     * would just be a spin loop wearing a bound.
     */
    private const RACE_ATTEMPTS = 3;

    /** Microseconds between race attempts. */
    private const RACE_SLEEP_US = 50000;

    /**
     * Request one bounded discovery run for one chain.
     *
     * @return array{ok: bool, status: string, reason?: string,
     *               run_id?: int, run_uuid?: string, scan_mode?: string,
     *               active_run_id?: int}
     *
     * @param string|null $forceScanMode NORMALLY NULL — the server chooses.
     *
     * ⚠ The one caller that pins a mode is the supervised WP-CLI command,
     * and it pins INCREMENTAL. That command documents at length that the
     * historical backfill "is NOT on that path and is not named anywhere in
     * this file", and a test enforces it. Letting the server pick would make
     * a backfill reachable from a surface whose entire contract is that it
     * cannot be — so the pin is preserving an existing invariant, not
     * carving out an exception to a new one.
     *
     * The administrator Scan button passes null and never sees a mode.
     */
    public function request(
        int $chainId,
        int $operatorId,
        string $jobKind = DiscoveryJobKind::COSMWASM_DISCOVERY,
        ?string $forceScanMode = null
    ): array {
        if (!DiscoveryJobKind::isRequestable($jobKind)) {
            return $this->refuse(DiscoveryRunError::UNSUPPORTED_REQUEST);
        }

        if ($forceScanMode !== null && !DiscoveryScanMode::isValid($forceScanMode)) {
            return $this->refuse(DiscoveryRunError::UNSUPPORTED_REQUEST);
        }

        $operator = $this->resolveOperator($operatorId);
        if ($operator === null) {
            return $this->refuse(DiscoveryRunError::OPERATOR_UNRESOLVED);
        }

        // ── Gate: product support, environment, driver, opt-in, active ──
        // Refused here means NO ROW IS CREATED and NO PROVIDER IS TOUCHED.
        // Queueing a run that the executor is guaranteed to refuse would
        // manufacture a failed run on a schedule and tell the operator
        // their request was accepted when it never could be.
        //
        // ⚠ PR 7.1: the readiness decision also returns the mode it judged
        // against. Recomputing the mode after the gate would reopen the gap
        // this closes — a checkpoint completing between the two reads would
        // queue an INCREMENTAL run that was approved as HISTORICAL, against
        // a backfill switch that was never consulted for it.
        $readiness = $this->chainReadiness($chainId, $forceScanMode);
        if (!$readiness['eligible']) {
            return $this->refuse($readiness['reason']);
        }

        $scanMode = $readiness['scan_mode'];

        return $this->createRun($chainId, $operator, $jobKind, $scanMode, null, self::AUDIT_REQUESTED);
    }

    /**
     * Retry a terminal run by creating a NEW row that points back at it.
     *
     * History is never rewritten: the original keeps its outcome, and
     * `retry_of_run_id` is what makes the chain readable afterwards.
     *
     * @return array{ok: bool, status: string, reason?: string,
     *               run_id?: int, run_uuid?: string, scan_mode?: string,
     *               active_run_id?: int}
     */
    public function retry(int $runId, int $operatorId): array
    {
        $operator = $this->resolveOperator($operatorId);
        if ($operator === null) {
            return $this->refuse(DiscoveryRunError::OPERATOR_UNRESOLVED);
        }

        $original = DiscoveryRunRepository::findById($runId);
        if ($original === null) {
            return $this->refuse(DiscoveryRunError::UNSUPPORTED_REQUEST);
        }

        if (!DiscoveryRunStatus::isTerminal((string) $original->status)) {
            // Still active. The unique index would refuse the insert anyway;
            // saying so plainly beats letting it look like a race.
            return [
                'ok'            => false,
                'status'        => 'refused',
                'reason'        => DiscoveryRunError::ALREADY_ACTIVE,
                'active_run_id' => (int) $original->id,
            ];
        }

        $chainId = (int) $original->chain_id;
        $jobKind = (string) $original->job_kind;

        if (!DiscoveryJobKind::isRequestable($jobKind)) {
            return $this->refuse(DiscoveryRunError::UNSUPPORTED_REQUEST);
        }

        // Re-gated, not trusted from the original: product support, the
        // environment switches and the per-chain opt-in may all have
        // changed since, and a retry is a fresh authorization.
        $readiness = $this->chainReadiness($chainId);
        if (!$readiness['eligible']) {
            return $this->refuse($readiness['reason']);
        }

        $scanMode = $readiness['scan_mode'];

        return $this->createRun($chainId, $operator, $jobKind, $scanMode, (int) $original->id, self::AUDIT_RETRIED);
    }

    /**
     * Withdraw a run that has not started.
     *
     * @return array{ok: bool, status: string, reason?: string, run_id?: int}
     */
    public function cancel(int $runId, int $operatorId): array
    {
        $operator = $this->resolveOperator($operatorId);
        if ($operator === null) {
            return $this->refuse(DiscoveryRunError::OPERATOR_UNRESOLVED);
        }

        $run = DiscoveryRunRepository::findById($runId);
        if ($run === null) {
            return $this->refuse(DiscoveryRunError::UNSUPPORTED_REQUEST);
        }

        if ((string) $run->status !== DiscoveryRunStatus::QUEUED) {
            return $this->refuse(DiscoveryRunError::UNSUPPORTED_REQUEST);
        }

        try {
            TransactionManager::run(function () use ($runId, $operator, $run) {
                if (!DiscoveryRunRepository::markCancelled($runId)) {
                    throw new \RuntimeException('cancel lost its compare-and-swap');
                }

                // ── ⚠ PR 7.4: A WITHDRAWN SESSION STILL DID ITS WORK ────
                //
                // Cancel is only offered on a QUEUED run, which for a session
                // means BETWEEN chunks — so the row already holds however many
                // chunks and provider requests the operator paid for. Auditing
                // the withdrawal without them made a 12-chunk session look
                // identical to one cancelled before it started.
                //
                // ⚠ RE-READ AFTER the state change, never before: `$run` was
                // fetched while the row still said `queued`, and an audit that
                // reported the pre-cancel status would be describing a row
                // that no longer exists. Same authority as the executor's
                // terminal paths.
                //
                // ⚠ AND NOTHING IS INVENTED. If the re-read cannot be
                // confirmed, the cancellation is audited with its own bounded
                // facts alone. A withdrawal must never carry a chunk that was
                // not persisted.
                $totals = DiscoverySessionTotals::fromPersistedRow(
                    DiscoveryRunRepository::findById($runId)
                );

                $meta = [
                    'run_uuid'         => (string) $run->run_uuid,
                    'job_kind'         => (string) $run->job_kind,
                    'chain_id'         => (int) $run->chain_id,
                    'previous_status'  => DiscoveryRunStatus::QUEUED,
                    'operator_user_id' => $operator,
                ];

                $auditId = AuditLogger::logChecked(
                    self::AUDIT_CANCELLED,
                    $runId,
                    $totals === null ? $meta : $meta + $totals->toAuditMeta(),
                    'discovery_run',
                    $operator
                );

                if ($auditId === null) {
                    // A cancellation nobody can prove was authorized is not
                    // a cancellation. Roll the state change back with it.
                    throw new \RuntimeException('checked audit write failed; rolling back the cancellation');
                }

                return ['ok' => true, 'audit_id' => $auditId];
            });
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] discovery run cancellation rolled back', [
                'run_id'     => $runId,
                'error_code' => DiscoveryRunError::AUDIT_UNCOMMITTED,
                'error'      => $e->getMessage(),
            ]);

            return $this->refuse(DiscoveryRunError::AUDIT_UNCOMMITTED);
        }

        return ['ok' => true, 'status' => 'cancelled', 'run_id' => $runId];
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * Insert + checked audit + dispatch, with the bounded active-run race.
     *
     * @return array{ok: bool, status: string, reason?: string,
     *               run_id?: int, run_uuid?: string, scan_mode?: string,
     *               active_run_id?: int}
     */
    private function createRun(
        int $chainId,
        int $operator,
        string $jobKind,
        string $scanMode,
        ?int $retryOfRunId,
        string $auditAction
    ): array {
        for ($attempt = 0; $attempt < self::RACE_ATTEMPTS; $attempt++) {
            $created = DiscoveryRunRepository::insertQueued(
                $jobKind,
                $scanMode,
                $chainId,
                $operator,
                $retryOfRunId
            );

            if ($created !== null) {
                return $this->finishCreation($created, $chainId, $operator, $jobKind, $scanMode, $retryOfRunId, $auditAction);
            }

            // The insert lost. Either the unique index refused it because a
            // run is genuinely active, or the write failed outright.
            $active = DiscoveryRunRepository::findActive($jobKind, $chainId);

            if ($active !== null) {
                return [
                    'ok'            => false,
                    'status'        => 'refused',
                    'reason'        => DiscoveryRunError::ALREADY_ACTIVE,
                    'active_run_id' => (int) $active->id,
                ];
            }

            // ⚠ NO ACTIVE RUN, YET THE INSERT FAILED.
            // The winner terminalized between our INSERT and our SELECT, so
            // the slot is free again. Retrying is correct; returning a
            // fabricated id or a row we read before the conflict is not.
            usleep(self::RACE_SLEEP_US);
        }

        Logger::error('[bcc-trust] discovery run request lost the active-run race repeatedly', [
            'chain_id'   => $chainId,
            'job_kind'   => $jobKind,
            'error_code' => DiscoveryRunError::CONTENTION,
        ]);

        return $this->refuse(DiscoveryRunError::CONTENTION);
    }

    /**
     * @param array{id: int, run_uuid: string} $created
     * @return array{ok: bool, status: string, reason?: string,
     *               run_id?: int, run_uuid?: string, scan_mode?: string}
     */
    private function finishCreation(
        array $created,
        int $chainId,
        int $operator,
        string $jobKind,
        string $scanMode,
        ?int $retryOfRunId,
        string $auditAction
    ): array {
        $runId = $created['id'];

        // ── The authorization record commits with the request ───────────
        // If it cannot be written, the run must not stand: a queued run
        // with no proof of who authorized it is exactly what this design
        // exists to prevent.
        try {
            TransactionManager::run(function () use ($runId, $created, $chainId, $operator, $jobKind, $scanMode, $retryOfRunId, $auditAction) {
                $meta = [
                    'run_uuid'         => $created['run_uuid'],
                    'job_kind'         => $jobKind,
                    'scan_mode'        => $scanMode,
                    'chain_id'         => $chainId,
                    'operator_user_id' => $operator,
                ];

                if ($retryOfRunId !== null) {
                    $meta['retry_of_run_id'] = $retryOfRunId;
                }

                $auditId = AuditLogger::logChecked($auditAction, $runId, $meta, 'discovery_run', $operator);

                if ($auditId === null) {
                    throw new \RuntimeException('checked audit write failed; rolling back the run request');
                }

                return ['ok' => true, 'audit_id' => $auditId];
            });
        } catch (\Throwable $e) {
            // Remove the row we just created. It is queued and unattributed;
            // leaving it would let an unauthorized scan run.
            DiscoveryRunRepository::markCancelled($runId);

            Logger::error('[bcc-trust] discovery run request rolled back; the authorization is NOT durably recorded', [
                'run_id'     => $runId,
                'chain_id'   => $chainId,
                'error_code' => DiscoveryRunError::AUDIT_UNCOMMITTED,
                'error'      => $e->getMessage(),
            ]);

            return $this->refuse(DiscoveryRunError::AUDIT_UNCOMMITTED);
        }

        // ── Dispatch, AFTER the durable record ──────────────────────────
        // Order matters: a durable request that failed to dispatch is
        // recoverable by the maintenance sweep; a dispatch with no row
        // behind it is lost work.
        $dispatched = \BCC\Core\Cron\AsyncDispatcher::enqueueAsync(
            DiscoveryRunExecutor::HOOK,
            [$runId],
            'bcc-discovery'
        );

        if (!$dispatched) {
            // Not a user-facing failure and NOT an attempt: no claim
            // occurred. The run is durable and the sweep will re-dispatch.
            Logger::error('[bcc-trust] discovery run dispatch was not accepted; the maintenance sweep will re-dispatch', [
                'run_id'   => $runId,
                'chain_id' => $chainId,
            ]);
        }

        return [
            'ok'        => true,
            'status'    => DiscoveryRunStatus::QUEUED,
            'run_id'    => $runId,
            'run_uuid'  => $created['run_uuid'],
            'scan_mode' => $scanMode,
        ];
    }

    /**
     * May this chain be scanned right now, and if not, exactly why?
     *
     * ── PR 7.1: ONE AUTHORITY, ASKED HERE TOO ───────────────────────────
     * This method used to answer with its own subset of the rules: chain
     * shape, then {@see CosmwasmScanEligibility::verdict()}. It knew
     * nothing about NFT product support and nothing about the environment
     * master switches — so it accepted requests the executor was then
     * guaranteed to refuse, which is precisely the "manufacture a failed
     * run and tell the operator it was accepted" outcome the comment at
     * the call site warns about.
     *
     * ⚠ It also collapsed every per-chain refusal into DISCOVERY_DISABLED.
     * A paused chain, a chain outside the canary allowlist and a chain
     * nobody opted in all produced one word, so the operator-facing reason
     * could not name the blocker.
     *
     * {@see DiscoveryReadiness} now owns the order and the combination;
     * this method just asks it and passes the mode it selected back to the
     * caller so the request and the run row cannot disagree about which
     * walk was authorised.
     *
     * @param string|null $forcedScanMode pinned by a supervised CLI caller only
     *
     * @return array{reason: string, eligible: bool, scan_mode: string}
     */
    private function chainReadiness(int $chainId, ?string $forcedScanMode = null): array
    {
        return DiscoveryReadiness::forRequest($chainId, $forcedScanMode);
    }

    /**
     * Resolve and authorize a NAMED administrator.
     *
     * Never `current_user_can()`: this service runs from WP-CLI too, where
     * there is no current user, and an implicit actor must not satisfy an
     * authorization check.
     */
    private function resolveOperator(int $operatorId): ?int
    {
        if ($operatorId <= 0) {
            return null;
        }

        if (get_userdata($operatorId) === false) {
            return null;
        }

        if (!user_can($operatorId, 'manage_options')) {
            return null;
        }

        return $operatorId;
    }

    /** @return array{ok: false, status: string, reason: string} */
    private function refuse(string $reason): array
    {
        return ['ok' => false, 'status' => 'refused', 'reason' => $reason];
    }
}
