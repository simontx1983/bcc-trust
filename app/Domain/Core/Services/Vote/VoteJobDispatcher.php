<?php
/**
 * Vote Job Dispatcher
 *
 * Single dispatch point for all async work that must run after a vote
 * transaction commits. Called once per successful vote — never from inside
 * a transaction closure.
 *
 * Design constraints:
 *  - All jobs accept only $voteId (int) so no user-supplied data is serialised
 *    into the wp_options cron table.
 *  - Uses Action Scheduler when available (avoids wp_options contention);
 *    falls back to wp_schedule_single_event().
 *  - wp_schedule_single_event() deduplicates by (hook, serialised args).
 *    Two calls for the same $voteId register exactly one cron entry per job.
 *  - Jobs are independent and may run in any order.
 *  - Action Scheduler path uses a MySQL advisory lock (GET_LOCK / RELEASE_LOCK)
 *    to close the TOCTOU window between as_has_scheduled_action() and
 *    as_enqueue_async_action(). The lock is session-scoped: MySQL releases it
 *    automatically if the connection drops, so a PHP crash cannot permanently
 *    block future dispatches. The wp-cron fallback deduplicates natively and
 *    does not require a lock.
 *
 * @package BCC\Trust\Core\Services\Vote
 * @version 2.1.0
 */

namespace BCC\Trust\Core\Services\Vote;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VoteJobDispatcher {

    /**
     * Delayed retry of the post-vote FRAUD stage only (F-107).
     *
     * Trust-graph and stats sub-tasks recover through their recurring
     * sweeps; fraud analysis has none, so a failure inside it (a deadlock,
     * a DB blip) used to be logged and then lost for good — the voter's
     * fraud_score never reflected that vote. It is now retried with
     * exponential backoff: 5, 10, 20, 40, 80 minutes.
     *
     * Re-running is safe for the SCORE: VoteFraudAnalyzer::run() re-checks
     * that the vote is still active, recomputes from live signals, and the
     * stored score is written monotonically. It is NOT side-effect free: a
     * retry after a failure part-way through can write a second
     * fraud_analysis row, a second fraud_analysis_vote audit row and repeat
     * the downvote-velocity / fan-in alerts. Those are observational
     * records, and a duplicate is preferred to a vote never analysed.
     */
    public const FRAUD_RETRY_HOOK         = 'bcc_trust_async_fraud_analysis_retry';
    public const FRAUD_RETRY_MAX_ATTEMPTS = 5;
    private const FRAUD_RETRY_BASE_DELAY  = 300;

    /**
     * Delayed retry of the WHOLE post-vote job when its dispatch was missed
     * before any sub-task ran (F-107 follow-up): the enqueue failed, or the
     * dispatch lock timed out and the job is not actually scheduled. One
     * attempt after FRAUD_RETRY_BASE_DELAY; the retry runs handlePostVote()
     * INLINE (it is already in a worker, so there is no second enqueue to
     * miss), and the fraud stage inside it keeps its own retries.
     */
    public const POST_VOTE_RETRY_HOOK = 'bcc_trust_async_post_vote_retry';

    /**
     * Schedule all post-commit async jobs for a committed vote.
     *
     * Enqueues a SINGLE composite job that internally dispatches all
     * post-vote tasks. This reduces advisory lock overhead from 4 locks
     * per vote to 1 lock per vote (4x reduction at scale).
     *
     * @param int $voteId  The ID returned by VoteRepository::upsertRaw().
     */
    public static function dispatch( int $voteId ): void {
        if ( ! self::enqueue( 'bcc_trust_async_post_vote', [ $voteId ] ) ) {
            self::scheduleRetry( self::POST_VOTE_RETRY_HOOK, [ $voteId ], self::FRAUD_RETRY_BASE_DELAY );
        }
    }

    /**
     * Retry handler for a missed post-vote dispatch (F-107 follow-up).
     * Registered in Plugin::registerAsyncJobs.
     */
    public static function handlePostVoteRetry( int $voteId ): void {
        self::handlePostVote( $voteId );
    }

    /**
     * Composite handler: runs all post-vote async tasks in sequence.
     *
     * Registered in Plugin::boot() or bootstrap.php via:
     *   add_action('bcc_trust_async_post_vote', [VoteJobDispatcher::class, 'handlePostVote']);
     *
     * Each sub-task is wrapped in try/catch so one failure doesn't block the rest.
     */
    public static function handlePostVote( int $voteId ): void {
        $tasks = [
            VoteFraudAnalyzer::HOOK,
            'bcc_trust_async_trust_graph_update',
            // bcc_trust_async_reputation_recalculate retired (Architecture A):
            // votes on an entity page no longer recompute the owner's personal
            // tier, so the owner-reputation recalc listener was removed.
            'bcc_trust_async_stats_refresh',
        ];

        foreach ( $tasks as $hook ) {
            try {
                do_action( $hook, $voteId );
            } catch ( \Throwable $e ) {
                // The composite job fired but a sub-task threw during execution
                // (not a dispatch miss — that's cron_dispatch). Trust-graph +
                // stats recover via their recurring sweeps; fraud analysis has
                // none. Surface it so /system/health sees swallowed post-vote
                // work, then keep going so one failure doesn't block the rest.
                if ( class_exists( '\\BCC\\Core\\Observability\\DegradationMetrics' ) ) {
                    \BCC\Core\Observability\DegradationMetrics::record( 'post_commit_task', 'vote_subtask_failed' );
                }
                if ( class_exists( '\\BCC\\Core\\Log\\Logger' ) ) {
                    \BCC\Core\Log\Logger::error( '[BCC Trust] Post-vote sub-task failed', [
                        'hook'    => $hook,
                        'vote_id' => $voteId,
                        'error'   => $e->getMessage(),
                    ] );
                }
                // The one sub-task with no recurring sweep gets a retry.
                if ( $hook === VoteFraudAnalyzer::HOOK ) {
                    self::scheduleFraudRetry( $voteId, 1 );
                }
            }
        }
    }

    /**
     * Retry handler for a failed post-vote fraud analysis (F-107).
     *
     * Registered in Plugin::registerAsyncJobs with 2 accepted args.
     */
    public static function handleFraudRetry( int $voteId, int $attempt ): void {
        try {
            do_action( VoteFraudAnalyzer::HOOK, $voteId );
            return;
        } catch ( \Throwable $e ) {
            // Same metric as the first failure (no new DegradationMetric
            // event, so the bcc-core subsystem map and its docs stay as is).
            if ( class_exists( '\\BCC\\Core\\Observability\\DegradationMetrics' ) ) {
                \BCC\Core\Observability\DegradationMetrics::record( 'post_commit_task', 'vote_subtask_failed' );
            }
            $exhausted = $attempt >= self::FRAUD_RETRY_MAX_ATTEMPTS;
            if ( class_exists( '\\BCC\\Core\\Log\\Logger' ) ) {
                \BCC\Core\Log\Logger::error(
                    $exhausted
                        ? '[BCC Trust] Post-vote fraud analysis failed; retries EXHAUSTED, this vote will not be analysed'
                        : '[BCC Trust] Post-vote fraud analysis retry failed; rescheduling',
                    [
                        'vote_id' => $voteId,
                        'attempt' => $attempt,
                        'error'   => $e->getMessage(),
                    ]
                );
            }
            if ( ! $exhausted ) {
                self::scheduleFraudRetry( $voteId, $attempt + 1 );
            }
        }
    }

    /** Schedule fraud retry number $attempt (1-based) after its backoff delay. */
    private static function scheduleFraudRetry( int $voteId, int $attempt ): void {
        self::scheduleRetry(
            self::FRAUD_RETRY_HOOK,
            [ $voteId, $attempt ],
            self::FRAUD_RETRY_BASE_DELAY * ( 2 ** ( $attempt - 1 ) )
        );
    }

    /**
     * Schedule a delayed retry event. Action Scheduler when present, wp-cron
     * otherwise. A failed schedule is recorded exactly like a failed first
     * dispatch (no new DegradationMetric event).
     *
     * @param list<int> $args
     */
    private static function scheduleRetry( string $hook, array $args, int $delaySeconds ): void {
        $runAt = time() + $delaySeconds;

        $scheduled = function_exists( 'as_schedule_single_action' )
            ? (bool) as_schedule_single_action( $runAt, $hook, $args )
            : wp_schedule_single_event( $runAt, $hook, $args ) !== false;

        if ( ! $scheduled ) {
            if ( class_exists( '\\BCC\\Core\\Observability\\DegradationMetrics' ) ) {
                \BCC\Core\Observability\DegradationMetrics::record( 'cron_dispatch', 'vote_job_dispatcher' );
            }
            if ( class_exists( '\\BCC\\Core\\Log\\Logger' ) ) {
                \BCC\Core\Log\Logger::error( '[bcc-trust] vote job retry could not be scheduled', [
                    'hook' => $hook,
                    'args' => $args,
                ] );
            }
        }
    }

    /**
     * Schedule the async edge-recalculation job after a vote removal.
     *
     * @param int $voterId  Voter whose edges need updating.
     * @param int $pageId   Page the vote was removed from.
     */
    public static function dispatchRemoval( int $voterId, int $pageId ): void {
        self::enqueue( 'bcc_trust_async_edge_recalculate', [ $voterId, $pageId ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Enqueue a single async action.
     *
     * Delegates to enqueueWithLock() for Action Scheduler (which has no native
     * deduplication) and falls back to wp_schedule_single_event() (which does).
     *
     * @param string $hook
     * @param list<mixed> $args
     * @return bool true when the job is scheduled (now or already); false
     *              when the dispatch was missed
     */
    private static function enqueue( string $hook, array $args ): bool {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            return self::enqueueWithLock( $hook, $args );
        }

        $positional = array_values( $args );

        // Fallback: wp-cron's spill_over-minute dedup is best-effort and
        // two callers within the same second can still both pass its check.
        // Explicit wp_next_scheduled gate + md5-keyed transient closes the
        // TOCTOU window at native-cron resolution.
        if ( wp_next_scheduled( $hook, $positional ) ) {
            return true;
        }

        $fingerprint = 'bcc_enq_' . md5( $hook . '|' . wp_json_encode( $positional ) );
        if ( get_transient( $fingerprint ) ) {
            return true;
        }
        // Short TTL: only needs to span the scheduler's visibility lag.
        set_transient( $fingerprint, 1, 30 );

        // Soft failure on sick wp_options means none of the post-vote tasks
        // (fraud analysis, trust graph, recalc, stats) ever run for this
        // vote. Surface via the cron_dispatch DegradationMetric subsystem
        // so /system/health and the operator journal both see the loss.
        $scheduled = wp_schedule_single_event( time(), $hook, $positional );
        if ( $scheduled === false ) {
            \BCC\Core\Observability\DegradationMetrics::record( 'cron_dispatch', 'vote_job_dispatcher' );
            \BCC\Core\Log\Logger::error( '[bcc-trust] vote job dispatcher enqueue failed', [
                'hook' => $hook,
                'args' => $positional,
            ] );
            return false;
        }
        return true;
    }

    /**
     * Enqueue an Action Scheduler job under a MySQL advisory lock.
     *
     * Closes the TOCTOU window between as_has_scheduled_action() and
     * as_enqueue_async_action(). Without the lock, two concurrent dispatches
     * (e.g. a network retry) can both pass the has_scheduled_action check and
     * both enqueue the job, causing duplicate fraud analysis and score updates.
     *
     * Lock mechanics:
     *  - GET_LOCK() is session-scoped: MySQL releases it automatically when
     *    the connection closes, so a PHP crash cannot permanently hold the lock.
     *  - The lock name is derived from md5($hook . serialize($args)) — all hex
     *    characters, safe to interpolate, always 41 chars (MySQL limit: 64).
     *  - Timeout 10 s: if a lock is not acquired within that window we assume
     *    the lock holder has already enqueued the job and bail safely.
     *  - try/finally guarantees the lock is released even if as_enqueue throws.
     *
     * @param string  $hook
     * @param mixed[] $args
     * @return bool true when the job is scheduled (now or already)
     */
    private static function enqueueWithLock( string $hook, array $args ): bool {
        // Lock name: 'vote_job_' (9 chars) + md5 (32 hex chars) = 41 chars.
        $lockName    = 'vote_job_' . md5( $hook . wp_json_encode( $args ) );
        $lockTimeout = 10; // seconds to wait before giving up

        $acquired = \BCC\Core\DB\AdvisoryLock::acquire( $lockName, $lockTimeout );

        if ( ! $acquired ) {
            \BCC\Core\Log\Logger::error( sprintf(
                '[BCC Trust] Job dispatch lock timeout — hook "%s", args: %s. Another process is dispatching.',
                $hook,
                wp_json_encode( $args )
            ) );
            // F-107 follow-up: the holder is ASSUMED to have enqueued it.
            // Only a confirmed schedule counts; otherwise report a miss so
            // the caller retries. (If the holder is still mid-enqueue the
            // job can run twice; every post-vote sub-task is re-runnable.)
            return as_has_scheduled_action( $hook, $args );
        }

        try {
            if ( as_has_scheduled_action( $hook, $args ) ) {
                return true;
            }

            // as_enqueue_async_action returns the new action id, or 0 when
            // the Action Scheduler store rejects the insert. This is the
            // PRIMARY dispatch path in production (AS present), yet it had
            // NO failure handling — a soft enqueue failure silently
            // stranded all post-vote sub-tasks (fraud, trust graph, stats)
            // with neither a log nor a metric, unlike the wp-cron fallback
            // in enqueue(). The cron_dispatch/vote_job_dispatcher subsystem
            // already documents "AS returning 0" as its trigger; record it
            // (+ log) so /system/health and the operator journal see the
            // loss, at parity with the fallback. The caller now also
            // retries a missed post-vote dispatch (F-107 follow-up).
            $actionId = as_enqueue_async_action( $hook, $args );
            if ( ! $actionId ) {
                \BCC\Core\Observability\DegradationMetrics::record( 'cron_dispatch', 'vote_job_dispatcher' );
                \BCC\Core\Log\Logger::error( '[bcc-trust] vote job dispatcher AS enqueue failed', [
                    'hook' => $hook,
                    'args' => $args,
                ] );
                return false;
            }
            return true;
        } finally {
            \BCC\Core\DB\AdvisoryLock::release( $lockName );
        }
    }
}
