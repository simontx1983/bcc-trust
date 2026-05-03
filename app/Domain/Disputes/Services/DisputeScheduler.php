<?php

namespace BCC\Trust\Disputes\Services;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Core\ServiceLocator;
use BCC\Trust\Disputes\Services\DisputeResolver;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Core\Log\Logger as CoreLogger;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeScheduler
{
    const EVENT_AUTO_RESOLVE  = 'bcc_disputes_auto_resolve';
    const EVENT_RECONCILE     = 'bcc_disputes_reconcile_orphans';

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::EVENT_AUTO_RESOLVE)) {
            wp_schedule_event(time(), 'daily', self::EVENT_AUTO_RESOLVE);
        }
        // Reconciliation runs every 5 minutes to catch split-brain disputes.
        if (!wp_next_scheduled(self::EVENT_RECONCILE)) {
            wp_schedule_event(time(), 'bcc_five_minutes', self::EVENT_RECONCILE);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::EVENT_AUTO_RESOLVE);
        wp_clear_scheduled_hook(self::EVENT_RECONCILE);
    }

    public static function boot(): void
    {
        add_action(self::EVENT_AUTO_RESOLVE, [__CLASS__, 'auto_resolve_expired']);
        add_action(self::EVENT_RECONCILE, [__CLASS__, 'reconcileOrphanedDisputes']);
        add_action('bcc_disputes_async_resolve', [__CLASS__, 'handleAsyncResolve'], 10, 6);

        // Admin health checks.
        add_action('admin_notices', [__CLASS__, 'warnIfCronDisabled']);
        add_action('admin_notices', [__CLASS__, 'warnIfAdjudicationDown']);
        add_action('admin_notices', [__CLASS__, 'warnIfPermanentOrphans']);

        // Self-healing: recreate scheduled events if they were deleted or
        // never registered (e.g. the original activation ran before the
        // cron_schedules filter was available because trust-engine hadn't
        // been loaded yet). schedule() is idempotent via wp_next_scheduled
        // so this is safe to run on every request.
        self::schedule();
    }

    /**
     * Register custom cron intervals used by this scheduler.
     *
     * Hooked into `cron_schedules` at plugin-load time (main plugin file)
     * so the interval is available during the activation hook AND at cron
     * run time — independently of plugin activation order.
     *
     * Idempotent: only adds the interval if another caller (e.g.
     * bcc-trust's Core CronService) hasn't already registered it.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function registerIntervals(array $schedules): array
    {
        if (!isset($schedules['bcc_five_minutes'])) {
            $schedules['bcc_five_minutes'] = [
                'interval' => 300,
                'display'  => 'Every 5 Minutes (BCC Disputes)',
            ];
        }
        return $schedules;
    }

    /**
     * Async handler: resolve a single dispute outside the cron loop.
     *
     * Action Scheduler may re-dispatch stored args from a prior release
     * whose shape we no longer control. Silent `(int)` coercion on
     * null / array / malformed payloads would quietly pass dispute_id=0
     * to DisputeResolver::handle(), which then logs
     * `resolve_race_skipped` forever (WHERE status='reviewing' AND id=0
     * never matches) with no operator-visible alarm. Validate strictly
     * up-front and surface malformed rows as a distinct log event.
     *
     * @param int|string $dispute_id
     * @param int|string $vote_id
     * @param int|string $page_id
     * @param int|string $voter_id
     * @param int|string $reporter_id
     * @param string     $outcome
     */
    public static function handleAsyncResolve($dispute_id, $vote_id, $page_id, $voter_id, $reporter_id, $outcome): void
    {
        $disputeIdInt  = self::validatePositiveIntArg($dispute_id);
        $voteIdInt     = self::validatePositiveIntArg($vote_id);
        $pageIdInt     = self::validatePositiveIntArg($page_id);
        $voterIdInt    = self::validatePositiveIntArg($voter_id);
        $reporterIdInt = self::validatePositiveIntArg($reporter_id);

        // outcome must be one of the three enum strings the resolver
        // understands. Everything else is a malformed job.
        $outcomeStr = is_string($outcome) ? $outcome : '';
        $validOutcomes = ['accepted', 'rejected', 'timeout_no_quorum'];

        if ($disputeIdInt === null
            || $voteIdInt === null
            || $pageIdInt === null
            || $voterIdInt === null
            || $reporterIdInt === null
            || !in_array($outcomeStr, $validOutcomes, true)
        ) {
            CoreLogger::error('[bcc-disputes] async_resolve_malformed_args', [
                'dispute_id'  => $dispute_id,
                'vote_id'     => $vote_id,
                'page_id'     => $page_id,
                'voter_id'    => $voter_id,
                'reporter_id' => $reporter_id,
                'outcome'     => $outcome,
            ]);
            return;
        }

        (new DisputeResolver())->handle(
            $disputeIdInt,
            $voteIdInt,
            $pageIdInt,
            $voterIdInt,
            $reporterIdInt,
            $outcomeStr,
            null
        );
    }

    /**
     * Coerce a scheduler argument to a positive int, or null if invalid.
     *
     * Accepts int and numeric string only. Rejects arrays, objects, null,
     * and non-numeric strings — all of which previously coerced silently
     * to 0 via (int).
     */
    private static function validatePositiveIntArg(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }

        return null;
    }

    /**
     * Auto-resolve disputes that have been open longer than BCC_DISPUTES_TTL_DAYS.
     * Outcome is determined by whichever side has more votes; ties go to 'rejected'
     * (benefit of the doubt to the voter).
     */
    public static function auto_resolve_expired(): void
    {
        // Single atomic lock: MySQL advisory lock serialises across all PHP
        // processes on the same DB.  GET_LOCK(name, 0) is non-blocking — if
        // another process holds it we return immediately.
        //
        // The previous transient-based "outer lock" had a TOCTOU race: two
        // processes could both read the transient as empty, both set it, and
        // both proceed.  Worse, the losing process deleted the transient on
        // advisory-lock failure, opening a window for a third process.
        // The advisory lock alone is sufficient and race-free.
        if (!DisputeRepository::acquireAutoResolveLock()) {
            return;
        }

        // last_run  = heartbeat: cron fired and we entered the body (always written).
        // last_success / last_failure = outcome tracking.
        // Intent-guard and any health dashboard must consult `last_success`, not
        // `last_run` — "ran recently" is not the same as "succeeded recently".
        update_option('bcc_disputes_auto_resolve_last_run', time(), false);

        try {
            self::doAutoResolve();
            update_option('bcc_disputes_auto_resolve_last_success', time(), false);
        } catch (\Throwable $e) {
            update_option('bcc_disputes_auto_resolve_last_failure', [
                'ts'      => time(),
                'message' => $e->getMessage(),
                'class'   => get_class($e),
            ], false);
            CoreLogger::error('[bcc-disputes] auto_resolve failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            throw $e;
        } finally {
            DisputeRepository::releaseAutoResolveLock();
        }
    }

    private static function doAutoResolve(): void
    {
        $cutoff  = gmdate('Y-m-d H:i:s', time() - (BCC_DISPUTES_TTL_DAYS * DAY_IN_SECONDS));
        $expired = DisputeRepository::getExpiredDisputes($cutoff, 50);

        if (empty($expired)) {
            return;
        }

        // Dispatch each resolution as an async action instead of resolving
        // synchronously in a loop. Each resolve() triggers a DB transaction +
        // trust-engine adjudication — blocking the cron with 50 sequential
        // transactions causes timeouts at scale.
        foreach ($expired as $dispute) {
            $verdict = DisputeRepository::computeVerdict(
                (int) $dispute->panel_accepts,
                (int) $dispute->panel_rejects,
                (int) $dispute->panel_size
            );

            $args = [
                (int) $dispute->id,
                (int) $dispute->vote_id,
                (int) $dispute->page_id,
                (int) $dispute->voter_id,
                (int) $dispute->reporter_id,
                $verdict['outcome'],
            ];


            $enqueued = false;
            try {
                $enqueued = DisputeNotificationService::enqueueAsync('bcc_disputes_async_resolve', $args);
            } catch (\Throwable $e) {
                CoreLogger::error('[bcc-disputes] auto_resolve_enqueue_failed', [
                    'dispute_id' => (int) $dispute->id,
                    'error'      => $e->getMessage(),
                ]);
            }
            if (!$enqueued) {
                // Next auto_resolve tick (24h) or reconcile tick will re-pick
                // this dispute since status still 'reviewing'.
                CoreLogger::error('[bcc-disputes] auto_resolve_enqueue_soft_failed', [
                    'dispute_id' => (int) $dispute->id,
                ]);
            }
        }
    }

    /**
     * Reconciliation cron: find disputes that were committed as resolved
     * but whose adjudication never completed (split-brain state).
     *
     * These are disputes where:
     *   - status is 'accepted' or 'rejected' (committed)
     *   - adjudication_status is 'pending' or 'failed' (never completed)
     *   - resolved_at is > 2 minutes ago (grace period for in-flight)
     *   - reopen_count < 3 (circuit breaker)
     *
     * For each orphan, we retry the adjudication call. If it fails again,
     * we increment reopen_count. After 3 failures, we leave it for manual
     * admin review.
     */
    public static function reconcileOrphanedDisputes(): void
    {
        if (!DisputeRepository::acquireReconcileLock()) {
            return; // Another process is running — skip this tick.
        }

        try {
            self::doReconcile();
        } finally {
            DisputeRepository::releaseReconcileLock();
        }
    }

    private static function doReconcile(): void
    {
        // PHASE -1: Release stuck claim-before-send markers.
        //
        // The four notification paths (panelist, reporter-result,
        // reported-user, admin-report) all use the same "set notified_at
        // before wp_mail; clear on failure via try/finally" pattern.  The
        // finally does NOT run if the worker dies mid-send (OOM-killer,
        // Action Scheduler SIGKILL at timeout, memory_limit fatal inside
        // a wp_mail hook), leaving a timestamp set that LOOKS like a
        // confirmed delivery — invisible to the Phase 0 / Phase 0.5
        // sweeps which only pick up rows where the marker is NULL.
        //
        // This phase clears markers older than $stuckClaimCutoff so the
        // later sweeps can re-enqueue.  Tradeoff: if the original send
        // DID go through but the worker died before clearing state, the
        // recipient will be double-emailed — an acceptable price for not
        // silently dropping panelist assignments indefinitely.
        self::releaseStuckClaims();

        // PHASE 0: Re-enqueue panelist notifications that were never sent.
        // Covers silent enqueue failures (AS returned 0, wp_schedule_single_event
        // returned false) AND wp_mail delivery failures. notified_at is the
        // claim-before-send marker — notifyPanelist flips it from NULL → now
        // before calling wp_mail and clears it back to NULL on send failure,
        // so this sweep re-selects the row if (and only if) no worker is
        // currently inside the send window with the claim held.
        self::reconcilePendingPanelistNotifications();

        // PHASE 0.5: Re-enqueue reporter-result emails that were never sent.
        // Covers the same failure modes as PHASE 0, for the "dispute resolved"
        // email to the reporter. resolved_notified_at is the claim-before-send
        // marker — see PHASE 0 comment.
        self::reconcilePendingReporterResultEmails();

        // PHASE A: Retry stuck "reviewing" disputes where all votes are in
        // but resolution failed (trust engine was unavailable at resolution time).
        // These are invisible to the orphan query (which only looks for
        // accepted/rejected status), so they'd wait 7 days for auto-resolve.
        self::retryStuckReviewingDisputes();

        // PHASE A.5: Alert admins if adjudication has been unavailable for >1 hour.
        self::checkAdjudicationHealth();

        // PHASE B: Retry orphaned adjudications (committed but never completed).
        $orphans = DisputeRepository::getOrphanedDisputes(10);

        if (empty($orphans)) {
            return;
        }

        // Trust-adjudicator presence gate — mirrors emergencyResolveIfStale.
        // Without this, calls to executeAdjudication() below fall through to
        // the NullObject whose accept/reject methods return false, which
        // trips markAdjudicationFailedAndBumpReopen() and chews through the
        // circuit-breaker's 3-strike budget for a TEMPORARY trust-engine
        // outage. Skipping here leaves the orphan row untouched so the next
        // reconcile tick retries once the service is back.
        if (!ServiceLocator::hasRealService(DisputeAdjudicationInterface::class)) {
            CoreLogger::error('[bcc-disputes] reconcile_adjudicator_unavailable', [
                'orphan_count' => count($orphans),
            ]);
            return;
        }

        $resolver = new DisputeResolver();

        foreach ($orphans as $dispute) {
            $disputeId = (int) $dispute->id;

            CoreLogger::info('[bcc-disputes] reconcile_retry', [
                'dispute_id'   => $disputeId,
                'status'       => $dispute->status,
                'reopen_count' => $dispute->reopen_count,
            ]);

            // Compute quorum ONCE; stable for the rest of this iteration because
            // status is already out of 'reviewing' (reconciliation only picks up
            // accepted/rejected orphans). For 'accepted' quorum is met by
            // definition; for 'rejected' we check the panel tally.
            $quorumMet = ($dispute->status === 'accepted')
                ? true
                : DisputeRepository::wasQuorumMetForDispute($disputeId);

            try {
                $success = $resolver->executeAdjudication(
                    $disputeId,
                    (int) $dispute->vote_id,
                    (int) $dispute->page_id,
                    (int) $dispute->voter_id,
                    (int) $dispute->reporter_id,
                    $dispute->status,
                    0, // system actor
                    $quorumMet
                );
            } catch (\Throwable $e) {
                CoreLogger::error('[bcc-disputes] reconcile_exception', [
                    'dispute_id' => $disputeId,
                    'error'      => $e->getMessage(),
                ]);
                $success = false;
            }

            if ($success) {
                // Write status BEFORE firing side-effects. If this fails,
                // the dispute stays 'failed' and the next reconcile tick
                // retries — but the penalty hook will NOT have fired yet,
                // preventing double-penalty on retry.
                DisputeRepository::setAdjudicationStatus($disputeId, 'completed');

                // Verify the status write actually took effect before
                // firing irreversible side-effects (penalty hook, emails).
                // Uses the dedicated uncached repository method — NOT
                // getDisputeById() which is cached and omits this column.
                $adjStatus = DisputeRepository::getAdjudicationStatus($disputeId);
                if ($adjStatus !== 'completed') {
                    CoreLogger::error('[bcc-disputes] reconcile_status_write_failed', [
                        'dispute_id' => $disputeId,
                        'actual_status' => $adjStatus,
                    ]);
                    // Do NOT fire penalty or notification — next tick will retry.
                    continue;
                }

                // Reporter penalty is now applied inside the trust-engine
                // adjudicator's own transaction (see executeAdjudication →
                // rejectVoteDispute). The deprecated
                // `bcc.trust.dispute_rejected_penalty` action has been removed
                // — firing it here would double-apply.

                $emailOk = false;
                try {
                    $emailOk = DisputeNotificationService::enqueueAsync(
                        'bcc_disputes_email_reporter_result',
                        [$disputeId, (int) $dispute->reporter_id, $dispute->status]
                    );
                } catch (\Throwable $e) {
                    CoreLogger::error('[bcc-disputes] reconcile_email_enqueue_failed', [
                        'dispute_id'  => $disputeId,
                        'reporter_id' => (int) $dispute->reporter_id,
                        'error'       => $e->getMessage(),
                    ]);
                }
                if (!$emailOk) {
                    CoreLogger::error('[bcc-disputes] reconcile_email_enqueue_soft_failed', [
                        'dispute_id'  => $disputeId,
                        'reporter_id' => (int) $dispute->reporter_id,
                    ]);
                }

                CoreLogger::info('[bcc-disputes] reconcile_success', [
                    'dispute_id' => $disputeId,
                ]);
            } else {
                // Atomic: set status='failed' AND reopen_count+=1 in one
                // UPDATE. The two-write split (setAdjudicationStatus then
                // incrementReopenCount) allowed the circuit-breaker counter
                // to fall out of sync with the status when the second query
                // failed, leaving the dispute eligible for retry forever.
                $updated = DisputeRepository::markAdjudicationFailedAndBumpReopen($disputeId);

                if (!$updated) {
                    // DB error: leave the dispute in its prior state so the
                    // next reconcile tick retries. Failing loudly is the only
                    // signal ops will see; do NOT increment any metric here
                    // that assumes the write succeeded.
                    CoreLogger::error('[bcc-disputes] reconcile_failed_status_write_error', [
                        'dispute_id' => $disputeId,
                    ]);
                    continue;
                }

                CoreLogger::error('[bcc-disputes] reconcile_failed', [
                    'dispute_id'   => $disputeId,
                    'reopen_count' => (int) $dispute->reopen_count + 1,
                ]);
            }
        }
    }

    /**
     * Release claim-before-send markers that look stuck.
     *
     * See doReconcile() "PHASE -1" comment for the failure mode this
     * guards against.  The cutoff (10 minutes) is long enough to be
     * confident the original worker is dead (Action Scheduler retry
     * windows, PHP max_execution_time, SMTP timeouts all resolve in
     * well under 10 minutes) and short enough that a stuck row does not
     * silently rot for the full dispute TTL.
     *
     * Each reset method is bounded — it only touches rows whose downstream
     * state still allows the email to be meaningfully retried — and
     * returns the number of claims released so operators can graph it.
     */
    private static function releaseStuckClaims(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 600); // 10 minutes

        $panelistReleased       = DisputeRepository::resetStuckPanelistClaims($cutoff);
        $reporterResultReleased = DisputeRepository::resetStuckReporterResultClaims($cutoff);
        $reportedUserReleased   = DisputeRepository::resetStuckReportedUserClaims($cutoff);
        $adminReportReleased    = DisputeRepository::resetStuckAdminReportClaims($cutoff);

        $total = $panelistReleased + $reporterResultReleased
               + $reportedUserReleased + $adminReportReleased;

        if ($total === 0) {
            return;
        }

        // Non-zero release count is evidence that workers died mid-send
        // somewhere in the fleet.  Logged at error (not warning) because
        // it means users would have silently missed notifications without
        // this sweep — exactly the invisible failure this phase exists to
        // surface.
        CoreLogger::error('[bcc-disputes] stuck_claims_released', [
            'panelist_notifications'    => $panelistReleased,
            'reporter_result_emails'    => $reporterResultReleased,
            'reported_user_emails'      => $reportedUserReleased,
            'admin_report_emails'       => $adminReportReleased,
            'total'                     => $total,
            'cutoff'                    => $cutoff,
        ]);
    }

    /**
     * Reconcile panel rows whose initial notification never landed.
     *
     * Grace period (120s) gives the original async enqueue time to fire
     * before we treat it as failed. Batch size is bounded to keep each
     * reconcile tick under the 5-minute cron interval even under
     * large-scale email outages. Each re-enqueue targets the same
     * `bcc_disputes_notify_panelist` hook; notifyPanelist only sets
     * notified_at after a confirmed wp_mail send, so repeated sweeps
     * cannot double-deliver once SMTP recovers.
     */
    private static function reconcilePendingPanelistNotifications(): void
    {
        $cutoff  = gmdate('Y-m-d H:i:s', time() - 120);
        $pending = DisputeRepository::getPendingPanelistNotifications($cutoff, 20);

        if (empty($pending)) {
            return;
        }

        CoreLogger::info('[bcc-disputes] reconcile_pending_panelist_notifications', [
            'count' => count($pending),
        ]);

        foreach ($pending as $row) {
            $enqueued = false;
            try {
                $enqueued = DisputeNotificationService::enqueueAsync(
                    'bcc_disputes_notify_panelist',
                    [$row['panelist_user_id'], $row['dispute_id'], $row['page_id']]
                );
            } catch (\Throwable $e) {
                CoreLogger::error('[bcc-disputes] reconcile_notify_enqueue_exception', [
                    'dispute_id'  => $row['dispute_id'],
                    'panelist_id' => $row['panelist_user_id'],
                    'error'       => $e->getMessage(),
                ]);
            }
            if (!$enqueued) {
                CoreLogger::error('[bcc-disputes] reconcile_notify_enqueue_soft_failed', [
                    'dispute_id'  => $row['dispute_id'],
                    'panelist_id' => $row['panelist_user_id'],
                ]);
            }
        }
    }

    /**
     * Reconcile reporter-result emails that were never delivered.
     *
     * Grace period (180s) gives the original async enqueue from
     * DisputeResolver::handle time to fire. Batch size is bounded
     * to keep each reconcile tick under the 5-minute cron interval.
     * emailReporterResult claims resolved_notified_at atomically BEFORE
     * calling wp_mail (and clears it on failure), so repeated sweeps
     * cannot double-deliver even when concurrent AS workers race.
     */
    private static function reconcilePendingReporterResultEmails(): void
    {
        $cutoff  = gmdate('Y-m-d H:i:s', time() - 180);
        $pending = DisputeRepository::getPendingReporterResultEmails($cutoff, 20);

        if (empty($pending)) {
            return;
        }

        CoreLogger::info('[bcc-disputes] reconcile_pending_reporter_emails', [
            'count' => count($pending),
        ]);

        foreach ($pending as $row) {
            $enqueued = false;
            try {
                $enqueued = DisputeNotificationService::enqueueAsync(
                    'bcc_disputes_email_reporter_result',
                    [$row['dispute_id'], $row['reporter_id'], $row['outcome']]
                );
            } catch (\Throwable $e) {
                CoreLogger::error('[bcc-disputes] reconcile_reporter_email_enqueue_exception', [
                    'dispute_id'  => $row['dispute_id'],
                    'reporter_id' => $row['reporter_id'],
                    'error'       => $e->getMessage(),
                ]);
            }
            if (!$enqueued) {
                CoreLogger::error('[bcc-disputes] reconcile_reporter_email_enqueue_soft_failed', [
                    'dispute_id'  => $row['dispute_id'],
                    'reporter_id' => $row['reporter_id'],
                ]);
            }
        }
    }

    /**
     * Find disputes stuck in "reviewing" where total votes >= panel_size
     * (all votes are in but resolution was never executed — typically
     * because the trust engine was unavailable at the moment of the
     * deciding vote). Re-trigger resolution for these disputes.
     */
    private static function retryStuckReviewingDisputes(): void
    {
        // Grace period: only retry disputes where the last vote was > 2 minutes ago.
        $cutoff = gmdate('Y-m-d H:i:s', time() - 120);

        $stuck = DisputeRepository::getStuckReviewingDisputes($cutoff, 10);

        if (empty($stuck)) {
            return;
        }

        foreach ($stuck as $dispute) {
            $verdict = DisputeRepository::computeVerdict(
                (int) $dispute->panel_accepts,
                (int) $dispute->panel_rejects,
                (int) $dispute->panel_size
            );

            CoreLogger::info('[bcc-disputes] retry_stuck_reviewing', [
                'dispute_id' => (int) $dispute->id,
                'accepts'    => (int) $dispute->panel_accepts,
                'rejects'    => (int) $dispute->panel_rejects,
                'outcome'    => $verdict['outcome'],
            ]);

            $enqueued = false;
            try {
                $enqueued = DisputeNotificationService::enqueueAsync('bcc_disputes_async_resolve', [
                    (int) $dispute->id,
                    (int) $dispute->vote_id,
                    (int) $dispute->page_id,
                    (int) $dispute->voter_id,
                    (int) $dispute->reporter_id,
                    $verdict['outcome'],
                ]);
            } catch (\Throwable $e) {
                CoreLogger::error('[bcc-disputes] stuck_reviewing_enqueue_failed', [
                    'dispute_id' => (int) $dispute->id,
                    'error'      => $e->getMessage(),
                ]);
            }
            if (!$enqueued) {
                // Next reconcile tick will re-query stuck reviewing disputes
                // (dispute still 'reviewing' with last vote > 2min ago).
                CoreLogger::error('[bcc-disputes] stuck_reviewing_enqueue_soft_failed', [
                    'dispute_id' => (int) $dispute->id,
                ]);
            }
        }
    }

    /**
     * Alert admins when the trust adjudication service has been unavailable
     * for over 1 hour.  Detected by checking for disputes resolved >1 hour
     * ago whose adjudication_status is still 'pending' or 'failed'.
     *
     * Sets a transient that triggers an admin notice on the next dashboard
     * load.  The transient auto-expires after 2 hours to self-clear once
     * the service recovers and reconciliation catches up.
     */
    private static function checkAdjudicationHealth(): void
    {
        $staleCount = DisputeRepository::countStaleAdjudications();

        if ($staleCount === 0) {
            delete_transient('bcc_disputes_adjudication_alert');
            return;
        }

        // Only log + set transient if not already alerting (avoid log spam).
        if (!get_transient('bcc_disputes_adjudication_alert')) {
            CoreLogger::error('[bcc-disputes] adjudication_unavailable_prolonged', [
                'stale_count' => $staleCount,
                'threshold'   => '1 hour',
            ]);
            set_transient('bcc_disputes_adjudication_alert', $staleCount, 2 * HOUR_IN_SECONDS);
        }
    }

    /**
     * Show admin notice when adjudication has been unavailable for >1 hour.
     * Companion to checkAdjudicationHealth() (called from reconciliation cron).
     */
    public static function warnIfAdjudicationDown(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $staleCount = get_transient('bcc_disputes_adjudication_alert');
        if (!$staleCount) {
            return;
        }

        echo wp_kses_post(
            '<div class="notice notice-error"><p>'
            . '<strong>BCC Disputes:</strong> '
            . sprintf(
                '%d dispute(s) have been waiting over 1 hour for trust adjudication. '
                . 'The trust engine adjudication service may be unavailable. '
                . 'Check the <code>bcc-trust</code> plugin status.',
                (int) $staleCount
            )
            . '</p></div>'
        );
    }

    /**
     * Emergency fallback: resolve severely overdue disputes on-demand.
     *
     * Called from DisputeController when a panelist or reporter loads
     * their queue. If cron has stopped (misconfigured, disabled, hosting
     * issue), disputes can sit in 'reviewing' indefinitely. This catches
     * disputes that are 2x the TTL (14 days) overdue and resolves up to
     * 5 per request to avoid blocking the HTTP response.
     *
     * This is a SAFETY NET, not a replacement for cron. It only fires
     * when cron has clearly failed.
     */
    public static function emergencyResolveIfStale(): void
    {
        // Only trigger if auto-resolve hasn't run in 48+ hours.
        $lastRun = (int) get_option('bcc_disputes_auto_resolve_last_run', 0);

        // Fresh install: last_run=0 means auto_resolve has never fired (or this
        // is a new deploy). Treat as healthy and skip the emergency path —
        // otherwise every panelist-queue load on a fresh site would trigger
        // the expensive stale-resolve DB probe (bounded only by a 10-min
        // transient). The daily auto_resolve cron will populate this option
        // on its first tick; after that, real staleness is detected normally.
        if ($lastRun === 0) {
            return;
        }

        if ((time() - $lastRun) < 2 * DAY_IN_SECONDS) {
            return; // Cron is working — no emergency needed.
        }

        // Rate-limit the emergency check globally. The transient is our
        // "don't hammer this path" marker; the advisory lock below handles
        // the thundering-herd race where N concurrent panel_queue requests
        // all race past get_transient() before the first set_transient()
        // lands. GET_LOCK is non-blocking (timeout=0) so we return immediately
        // without tying up PHP workers waiting for the lock.
        $emergencyKey = 'bcc_disputes_emergency_check';
        if (get_transient($emergencyKey)) {
            return;
        }

        if (!DisputeRepository::acquireEmergencyResolveLock()) {
            // Another worker is already inside this path — let them finish
            // and the transient they set will suppress subsequent requests.
            return;
        }

        try {
            // Re-check the transient INSIDE the lock. Request A acquires the
            // lock, runs the emergency path, sets the transient, releases the
            // lock; request B then acquires the lock but the transient is
            // already set — this re-check prevents a second emergency batch.
            // @phpstan-ignore if.alwaysFalse (transient mutates from another request between outer and inner checks)
            if (get_transient($emergencyKey)) {
                return;
            }

            // If the trust adjudicator is down, every enqueued async_resolve will
            // roll back in DisputeResolver::handle(). Skip WITHOUT setting
            // the 10-minute transient so the emergency path can fire as soon as
            // the service recovers.
            if (!ServiceLocator::hasRealService(DisputeAdjudicationInterface::class)) {
                return;
            }

            set_transient($emergencyKey, 1, 600);

            self::doEmergencyResolve($lastRun);
        } finally {
            DisputeRepository::releaseEmergencyResolveLock();
        }
    }

    /**
     * Body of the emergency resolve path. Extracted so emergencyResolveIfStale()
     * can wrap it in acquire/release while keeping the try/finally tidy.
     */
    private static function doEmergencyResolve(int $lastRun): void
    {

        $hardStopCutoff = gmdate('Y-m-d H:i:s', time() - (BCC_DISPUTES_TTL_DAYS * 2 * DAY_IN_SECONDS));
        $stale = DisputeRepository::getExpiredDisputes($hardStopCutoff, 5);

        if (empty($stale)) {
            return;
        }

        CoreLogger::warning('[bcc-disputes] emergency_resolve_triggered', [
            'count'       => count($stale),
            'last_cron'   => $lastRun > 0 ? gmdate('Y-m-d H:i:s', $lastRun) : 'never',
            'hard_cutoff' => $hardStopCutoff,
        ]);

        foreach ($stale as $dispute) {
            $disputeId = (int) $dispute->id;

            // Intentionally no per-dispute dedup: both as_next_scheduled_action
            // and wp_next_scheduled require an exact args match, and we enqueue
            // with a 6-tuple. DisputeResolver::handle is idempotent
            // (WHERE status='reviewing' in beginResolveTransaction), so a
            // duplicate async_resolve is a harmless no-op.

            $verdict = DisputeRepository::computeVerdict(
                (int) $dispute->panel_accepts,
                (int) $dispute->panel_rejects,
                (int) $dispute->panel_size
            );

            $enqueued = false;
            try {
                $enqueued = DisputeNotificationService::enqueueAsync('bcc_disputes_async_resolve', [
                    $disputeId,
                    (int) $dispute->vote_id,
                    (int) $dispute->page_id,
                    (int) $dispute->voter_id,
                    (int) $dispute->reporter_id,
                    $verdict['outcome'],
                ]);
            } catch (\Throwable $e) {
                CoreLogger::error('[bcc-disputes] emergency_resolve_enqueue_failed', [
                    'dispute_id' => $disputeId,
                    'error'      => $e->getMessage(),
                ]);
            }
            if (!$enqueued) {
                CoreLogger::error('[bcc-disputes] emergency_resolve_enqueue_soft_failed', [
                    'dispute_id' => $disputeId,
                ]);
            }
        }
    }

    /**
     * Show an admin notice if WP-Cron is disabled and the auto-resolve
     * cron hasn't fired recently. Without a system cron replacement,
     * disputes will never auto-resolve and reconciliation won't run.
     */
    public static function warnIfCronDisabled(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
            return;
        }

        // Check if auto-resolve has fired within the last 48 hours.
        $lastRun = (int) get_option('bcc_disputes_auto_resolve_last_run', 0);
        if ($lastRun > 0 && (time() - $lastRun) < 2 * DAY_IN_SECONDS) {
            return; // System cron is working — no warning needed.
        }

        echo wp_kses_post(
            '<div class="notice notice-warning"><p>'
            . '<strong>BCC Disputes:</strong> '
            . 'DISABLE_WP_CRON is enabled but the dispute auto-resolve cron has not fired in over 48 hours. '
            . 'Please configure a system cron (<code>wp-cron.php</code>) to ensure disputes are auto-resolved and reconciliation runs.'
            . '</p></div>'
        );
    }

    /**
     * Show admin notice when disputes have exhausted all reconciliation
     * retries and require manual admin intervention.
     */
    public static function warnIfPermanentOrphans(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Cache the count check for 30 minutes to avoid extra queries on every admin page.
        $cacheKey = 'bcc_disputes_permanent_orphan_count';
        $count = get_transient($cacheKey);
        if ($count === false) {
            $count = DisputeRepository::countPermanentOrphans();
            set_transient($cacheKey, $count, 30 * MINUTE_IN_SECONDS);
        }

        if ((int) $count === 0) {
            return;
        }

        echo wp_kses_post(
            '<div class="notice notice-error"><p>'
            . '<strong>BCC Disputes:</strong> '
            . sprintf(
                '%d dispute(s) have failed adjudication 3+ times and are permanently stuck. '
                . 'Trust scores for these disputes are NOT applied. '
                . '<a href="%s">Review and re-adjudicate &rarr;</a>',
                (int) $count,
                admin_url('admin.php?page=bcc-trust-dashboard&tab=disputes&filter=orphaned')
            )
            . '</p></div>'
        );
    }

}
