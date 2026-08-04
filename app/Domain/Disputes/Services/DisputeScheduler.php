<?php

namespace BCC\Trust\Disputes\Services;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Core\ServiceLocator;
use BCC\Trust\Disputes\DisputesPlugin;
use BCC\Trust\Disputes\Services\DisputeResolver;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Disputes\Repositories\UserReportRepository;
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
        // warnIfCronDisabled stays as a direct banner — DISABLE_WP_CRON
        // misconfiguration is a setup failure that should be loud.
        // The other two (warnIfAdjudicationDown + warnIfPermanentOrphans)
        // were moved to the NotificationCenter via *Notification() helpers
        // returning ?NotificationItem; see NotificationCenter::contributeBuiltins.
        add_action('admin_notices', [__CLASS__, 'warnIfCronDisabled']);

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
     * Daily backstop for the poll→dispute seam (Rank Phase 6): for every
     * dispute stuck in 'reviewing' past the grace period, reconcile it
     * against its poll — self-heal a missing poll, skip an open one
     * (the hourly engine sweep owns closing), re-drive the outcome of a
     * closed one whose close hook or async job was lost.
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
        // One-hour grace: long enough that the submit-time poll open and
        // the poll-close hook have clearly had their chance; the sweep
        // only acts on missing/closed polls, so open polls are untouched
        // regardless of dispute age.
        $cutoff     = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $candidates = DisputeRepository::listReviewingCreatedBefore($cutoff, 50);

        if (empty($candidates)) {
            return;
        }

        $voteService = DisputesPlugin::instance()->disputeVoteService();
        foreach ($candidates as $dispute) {
            $voteService->reconcileReviewingDispute((int) $dispute->id);
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

        // Heartbeat (mirrors auto_resolve_expired): last_run = cron fired
        // and we entered the body; last_success = the sweep completed.
        // Without this, a stalled reconcile job is indistinguishable from
        // a healthy one — the hook-name split-brain that hid this job from
        // the monitor until 2026-07-06 also meant no heartbeat existed.
        update_option('bcc_disputes_reconcile_last_run', time(), false);

        try {
            self::doReconcile();
            update_option('bcc_disputes_reconcile_last_success', time(), false);
        } finally {
            DisputeRepository::releaseReconcileLock();
        }
    }

    private static function doReconcile(): void
    {
        // PHASE -1: Release stuck claim-before-send markers.
        //
        // The three notification paths (reporter-result, reported-user,
        // admin-report) all use the same "set notified_at before
        // wp_mail; clear on failure via try/finally" pattern.  The
        // finally does NOT run if the worker dies mid-send (OOM-killer,
        // Action Scheduler SIGKILL at timeout, memory_limit fatal inside
        // a wp_mail hook), leaving a timestamp set that LOOKS like a
        // confirmed delivery — invisible to the Phase 0.5 sweep which
        // only picks up rows where the marker is NULL.
        //
        // This phase clears markers older than $stuckClaimCutoff so the
        // later sweeps can re-enqueue.  Tradeoff: if the original send
        // DID go through but the worker died before clearing state, the
        // recipient will be double-emailed — an acceptable price for not
        // silently dropping resolution notices indefinitely.
        self::releaseStuckClaims();

        // PHASE 0.5: Re-enqueue reporter-result emails that were never sent.
        // Covers silent enqueue failures (AS returned 0,
        // wp_schedule_single_event returned false) AND wp_mail delivery
        // failures, for the "dispute resolved" email to the reporter.
        // resolved_notified_at is the claim-before-send marker.
        self::reconcilePendingReporterResultEmails();

        // PHASE A.5: Alert admins if adjudication has been unavailable for >1 hour.
        self::checkAdjudicationHealth();

        // PHASE B: Retry orphaned adjudications (committed but never completed).
        $orphans = DisputeRepository::getOrphanedDisputes(10);

        if (empty($orphans)) {
            return;
        }

        // Trust-adjudicator presence gate.
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

            // Orphans are accepted/rejected only — both are decisive
            // outcomes (a poll close that met quorum+majority, or an
            // explicit admin decision), so the reporter-penalty gate is
            // always armed here. timeout_no_quorum never reaches this
            // path (its executeAdjudication early-return marks it
            // completed on the spot).
            $quorumMet = true;

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

                // §D5 outcome-match backfill deleted (Rank Phase 6, D-7):
                // participation credit retired with the panel.

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

        $reporterResultReleased = DisputeRepository::resetStuckReporterResultClaims($cutoff);
        $reportedUserReleased   = UserReportRepository::resetStuckReportedUserClaims($cutoff);
        $adminReportReleased    = UserReportRepository::resetStuckAdminReportClaims($cutoff);

        $total = $reporterResultReleased + $reportedUserReleased + $adminReportReleased;

        if ($total === 0) {
            return;
        }

        // Non-zero release count is evidence that workers died mid-send
        // somewhere in the fleet.  Logged at error (not warning) because
        // it means users would have silently missed notifications without
        // this sweep — exactly the invisible failure this phase exists to
        // surface.
        CoreLogger::error('[bcc-disputes] stuck_claims_released', [
            'reporter_result_emails' => $reporterResultReleased,
            'reported_user_emails'   => $reportedUserReleased,
            'admin_report_emails'    => $adminReportReleased,
            'total'                  => $total,
            'cutoff'                 => $cutoff,
        ]);
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
     * NotificationCenter contributor — returns the adjudication-down item
     * when the alert transient is set, null when healthy.
     *
     * Companion to checkAdjudicationHealth() (called from reconciliation cron).
     * Previously emitted as a direct admin_notices banner; routed through
     * NotificationCenter as of the two-engineer audit follow-up so that
     * recurring health signals collect in one surface instead of stacking
     * on every wp-admin page.
     *
     * @return array{id:string,source:string,level:string,message:string}|null
     */
    public static function adjudicationDownNotification(): ?array
    {
        $staleCount = (int) get_transient('bcc_disputes_adjudication_alert');
        if ($staleCount === 0) {
            return null;
        }

        return [
            'id'      => 'disputes.adjudication_down',
            'source'  => 'BCC Disputes',
            'level'   => 'error',
            'message' => sprintf(
                '%d dispute(s) have been waiting over 1 hour for trust adjudication. '
                . 'The trust engine adjudication service may be unavailable. '
                . 'Check the <code>bcc-trust</code> plugin status.',
                $staleCount
            ),
        ];
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
     * NotificationCenter contributor — returns the permanent-orphans item
     * when disputes have exhausted all reconciliation retries, null
     * when healthy. 30-min transient cache to avoid extra queries on
     * every admin page load.
     *
     * Previously emitted as a direct admin_notices banner; routed through
     * NotificationCenter as of the two-engineer audit follow-up.
     *
     * @return array{id:string,source:string,level:string,message:string,link:string}|null
     */
    public static function permanentOrphansNotification(): ?array
    {
        $cacheKey = 'bcc_disputes_permanent_orphan_count';
        $count    = get_transient($cacheKey);
        if ($count === false) {
            $count = DisputeRepository::countPermanentOrphans();
            set_transient($cacheKey, $count, 30 * MINUTE_IN_SECONDS);
        }

        if ((int) $count === 0) {
            return null;
        }

        $link = admin_url('admin.php?page=bcc-trust-dashboard&tab=disputes&filter=orphaned');

        return [
            'id'      => 'disputes.permanent_orphans',
            'source'  => 'BCC Disputes',
            'level'   => 'error',
            'message' => sprintf(
                '%d dispute(s) have failed adjudication 3+ times and are permanently stuck. '
                . 'Trust scores for these disputes are NOT applied.',
                (int) $count
            ),
            'link'    => $link,
        ];
    }

}
