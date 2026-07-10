<?php

namespace BCC\Trust\Disputes\Services;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Core\ServiceLocator;
use BCC\Trust\Disputes\Repositories\DisputeParticipationRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Disputes\Services\DisputeNotificationService;
use BCC\Core\Log\Logger as CoreLogger;

if (!defined('ABSPATH')) {
    exit;
}

final class DisputeResolver
{
    public function handle(int $disputeId, int $voteId, int $pageId, int $voterId, int $reporterId, string $outcome, ?int $actorId = null): bool
    {
        // Atomic status transition via repository: WHERE status = 'reviewing'
        // prevents double-resolution under concurrent requests.
        // Transaction is left OPEN on success — we must commit or rollback.
        $txn = DisputeRepository::beginResolveTransaction($disputeId, $outcome);

        if ($txn['db_error']) {
            CoreLogger::error('[bcc-disputes] ' .'resolve_rollback', [
                'dispute_id' => $disputeId,
                'outcome'    => $outcome,
                'step'       => 'status_update',
                'db_error'   => $txn['db_error'],
            ]);
            return false;
        }

        if ($txn['race']) {
            // Already resolved by a concurrent request — expected under
            // concurrent panelist voting; not an error condition.
            CoreLogger::info('[bcc-disputes] resolve_race_skipped', [
                'dispute_id' => $disputeId,
                'outcome'    => $outcome,
            ]);
            return false;
        }

        // ── Pre-commit gate: verify trust engine is available ────────────
        //
        // Compute quorum INSIDE the still-open transaction. Under MySQL's
        // default REPEATABLE READ, the panel-row SELECT here reads a
        // snapshot consistent with the locked dispute row — closing the
        // race window where `cleanupForDeletedUser` could delete a
        // panelist row between commit and a post-commit quorum read,
        // falsely flipping $quorumMet to false and silently suppressing
        // the reporter penalty on a genuinely rejected dispute.
        try {
            $hasRealAdjudicator = ServiceLocator::hasRealService(DisputeAdjudicationInterface::class);

            if (!$hasRealAdjudicator) {
                DisputeRepository::rollbackTransaction();
                CoreLogger::error('[bcc-disputes] ' .'trust_adjudication_service_unavailable', [
                    'dispute_id' => $disputeId,
                    'outcome'    => $outcome,
                ]);
                return false;
            }

            // Quorum evaluated inside the transaction so panel rows cannot
            // be concurrently mutated between here and adjudication.
            if ($outcome === 'accepted') {
                $quorumMet = true;
            } elseif ($outcome === 'timeout_no_quorum') {
                $quorumMet = false;
            } else {
                // outcome === 'rejected' — verify via the authoritative panel
                // rows rather than trusting the caller, so a stale/denormalised
                // cache tally cannot smuggle a false-positive quorum signal.
                $quorumMet = DisputeRepository::wasQuorumMetForDispute($disputeId);
            }

            // adjudication_status='pending' is already set atomically
            // by beginResolveTransaction() in the same UPDATE statement.

            // commitTransaction() now returns bool.  A false return means
            // MySQL rolled back our status UPDATE at commit time (commit-
            // time deadlock, serialization failure, connection drop).
            // If we proceeded past that, the dispute would still be
            // 'reviewing' on disk but we'd call the adjudicator and fire
            // the reporter-result email as if it had been resolved.  Abort
            // cleanly and let the caller retry / reconcile pick it up.
            if (!DisputeRepository::commitTransaction()) {
                CoreLogger::error('[bcc-disputes] resolve_commit_failed', [
                    'dispute_id' => $disputeId,
                    'outcome'    => $outcome,
                ]);
                return false;
            }
        } catch (\Throwable $e) {
            DisputeRepository::rollbackTransaction();
            CoreLogger::error('dispute_resolution_failed', [
                'dispute_id' => $disputeId,
                'outcome'    => $outcome,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
            ]);
            return false;
        }

        // Status changed — invalidate all caches tied to this dispute.
        DisputeRepository::invalidateDispute($disputeId);

        // ── Post-commit: execute adjudication ────────────────────────────
        $actorId     = $actorId ?? get_current_user_id();
        try {
            $adjudicationSucceeded = $this->executeAdjudication(
                $disputeId, $voteId, $pageId, $voterId, $reporterId, $outcome, $actorId, $quorumMet
            );
        } catch (\Throwable $e) {
            // Dispute status is already committed; do not leak adjudicator
            // exceptions to the REST layer. Mark failed and let the
            // reconciliation cron retry.
            CoreLogger::error('[bcc-disputes] adjudication_exception', [
                'dispute_id' => $disputeId,
                'outcome'    => $outcome,
                'error'      => $e->getMessage(),
            ]);
            $adjudicationSucceeded = false;
        }

        if (!$adjudicationSucceeded) {
            // Mark as failed for reconciliation cron to pick up.
            DisputeRepository::setAdjudicationStatus($disputeId, 'failed');

            CoreLogger::error('[bcc-disputes] ' .'trust_adjudication_failed', [
                'dispute_id' => $disputeId,
                'vote_id'    => $voteId,
                'outcome'    => $outcome,
                'action'     => 'marked_for_reconciliation',
            ]);
            return false;
        }

        // ── Adjudication succeeded — mark completed, verify, then fire side-effects ──
        // setAdjudicationStatus now returns bool; false means the UPDATE failed
        // at the driver level (deadlock, connection reset). Bail without firing
        // side-effects — reconciliation will retry.
        if (!DisputeRepository::setAdjudicationStatus($disputeId, 'completed')) {
            CoreLogger::error('[bcc-disputes] resolve_status_write_returned_false', [
                'dispute_id' => $disputeId,
            ]);
            return false;
        }

        // Read-back guard: even when the driver reports success, replica lag
        // or cache-layer anomalies can leave the row logically at 'pending'.
        // Reconciliation will retry the whole adjudication — firing penalty +
        // email here would cause double-penalty on that retry. Mirrors the
        // guard in DisputeScheduler::doReconcile().
        $adjStatus = DisputeRepository::getAdjudicationStatus($disputeId);
        if ($adjStatus !== 'completed') {
            CoreLogger::error('[bcc-disputes] resolve_status_write_failed', [
                'dispute_id'    => $disputeId,
                'actual_status' => $adjStatus,
            ]);
            return false;
        }

        // §D5 — backfill outcome_match on every credited participation
        // row attached to this dispute. We map the dispute-status enum
        // ('accepted'/'rejected') to the panel-vote enum ('accept'/
        // 'reject') the participation rows store. timeout_no_quorum
        // disputes intentionally leave outcome_match NULL — there's no
        // "correct" answer when quorum failed.
        if ($outcome === 'accepted' || $outcome === 'rejected') {
            $finalDecision = $outcome === 'accepted' ? 'accept' : 'reject';
            try {
                $participationRepo = new DisputeParticipationRepository();
                $updated = $participationRepo->backfillOutcomeMatch($disputeId, $finalDecision);
                CoreLogger::audit('dispute_participation_backfilled', [
                    'dispute_id' => $disputeId,
                    'outcome'    => $outcome,
                    'rows'       => $updated,
                ]);
            } catch (\Throwable $e) {
                // Backfill failure is non-fatal: the dispute is already
                // resolved and the panelist's credited rows still exist —
                // they just don't get an accuracy mark. There is no
                // reconciliation sweep, so surface it on /system/health
                // (sustained activation = accuracy marks accruing NULL)
                // alongside the log.
                \BCC\Core\Observability\DegradationMetrics::record('post_commit_task', 'dispute_backfill_failed');
                CoreLogger::error('[bcc-disputes] participation_backfill_failed', [
                    'dispute_id' => $disputeId,
                    'outcome'    => $outcome,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Reporter penalty is now owned by the trust-engine inside its own
        // transaction via DisputeAdjudicationInterface::rejectVoteDispute.
        // The prior `bcc.trust.dispute_rejected_penalty` action has been
        // removed: executeAdjudication() passed $quorumMet through, and
        // the adjudicator applied or skipped the penalty atomically before
        // returning. Firing the deprecated hook here would double-apply.

        // Defence-in-depth: a throw from the async-action layer (malformed args,
        // Action Scheduler DB error) must not 500 the REST response when the
        // dispute is already resolved on-disk. The reporter-result email will
        // be retried by reconciliation's resolve path if never delivered.
        $emailOk = false;
        try {
            $emailOk = DisputeNotificationService::enqueueAsync(
                'bcc_disputes_email_reporter_result',
                [$disputeId, $reporterId, $outcome]
            );
        } catch (\Throwable $e) {
            CoreLogger::error('[bcc-disputes] reporter_email_enqueue_failed', [
                'dispute_id'  => $disputeId,
                'reporter_id' => $reporterId,
                'error'       => $e->getMessage(),
            ]);
        }
        if (!$emailOk) {
            // resolved_notified_at stays NULL; reconciliation's resolve path
            // or a later admin replay will re-enqueue.
            CoreLogger::error('[bcc-disputes] reporter_email_enqueue_soft_failed', [
                'dispute_id'  => $disputeId,
                'reporter_id' => $reporterId,
            ]);
        }

        return true;
    }

    /**
     * Execute the trust-engine adjudication call.
     *
     * Extracted so the reconciliation cron can reuse it. $reporterId and
     * $quorumMet are required for the reject path: the adjudicator applies
     * the reporter fraud + reputation penalty inside its own transaction
     * when $quorumMet is true, and skips it otherwise (preventing reviewer
     * silence from being treated as proof of a bad-faith report).
     */
    public function executeAdjudication(
        int $disputeId,
        int $voteId,
        int $pageId,
        int $voterId,
        int $reporterId,
        string $outcome,
        int $actorId,
        bool $quorumMet
    ): bool {
        // timeout_no_quorum: no trust mutation, no audit event. The status
        // transition in beginResolveTransaction is the only on-disk effect.
        // Returning true here marks the dispute as adjudicated-complete so
        // the reconcile cron does not retry it — there is no work to retry.
        if ($outcome === 'timeout_no_quorum') {
            return true;
        }

        $adjudicator = ServiceLocator::resolveDisputeAdjudication();

        if ($outcome === 'accepted') {
            return $adjudicator->acceptVoteDispute(
                $disputeId,
                $voteId,
                $pageId,
                $voterId,
                $actorId
            );
        }

        return $adjudicator->rejectVoteDispute(
            $disputeId,
            $voteId,
            $pageId,
            $reporterId,
            $actorId,
            $quorumMet
        );
    }
}
