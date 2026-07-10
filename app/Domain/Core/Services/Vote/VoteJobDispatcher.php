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
     * Schedule all post-commit async jobs for a committed vote.
     *
     * Enqueues a SINGLE composite job that internally dispatches all
     * post-vote tasks. This reduces advisory lock overhead from 4 locks
     * per vote to 1 lock per vote (4x reduction at scale).
     *
     * @param int $voteId  The ID returned by VoteRepository::upsertRaw().
     */
    public static function dispatch( int $voteId ): void {
        self::enqueue( 'bcc_trust_async_post_vote', [ $voteId ] );
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
     */
    private static function enqueue( string $hook, array $args ): void {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            self::enqueueWithLock( $hook, $args );
            return;
        }

        $positional = array_values( $args );

        // Fallback: wp-cron's spill_over-minute dedup is best-effort and
        // two callers within the same second can still both pass its check.
        // Explicit wp_next_scheduled gate + md5-keyed transient closes the
        // TOCTOU window at native-cron resolution.
        if ( wp_next_scheduled( $hook, $positional ) ) {
            return;
        }

        $fingerprint = 'bcc_enq_' . md5( $hook . '|' . wp_json_encode( $positional ) );
        if ( get_transient( $fingerprint ) ) {
            return;
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
        }
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
     */
    private static function enqueueWithLock( string $hook, array $args ): void {
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
            return;
        }

        try {
            if ( ! as_has_scheduled_action( $hook, $args ) ) {
                // as_enqueue_async_action returns the new action id, or 0 when
                // the Action Scheduler store rejects the insert. This is the
                // PRIMARY dispatch path in production (AS present), yet it had
                // NO failure handling — a soft enqueue failure silently
                // stranded all post-vote sub-tasks (fraud, trust graph, stats)
                // with neither a log nor a metric, unlike the wp-cron fallback
                // in enqueue(). The cron_dispatch/vote_job_dispatcher subsystem
                // already documents "AS returning 0" as its trigger; record it
                // (+ log) so /system/health and the operator journal see the
                // loss, at parity with the fallback.
                $actionId = as_enqueue_async_action( $hook, $args );
                if ( ! $actionId ) {
                    \BCC\Core\Observability\DegradationMetrics::record( 'cron_dispatch', 'vote_job_dispatcher' );
                    \BCC\Core\Log\Logger::error( '[bcc-trust] vote job dispatcher AS enqueue failed', [
                        'hook' => $hook,
                        'args' => $args,
                    ] );
                }
            }
        } finally {
            \BCC\Core\DB\AdvisoryLock::release( $lockName );
        }
    }
}
