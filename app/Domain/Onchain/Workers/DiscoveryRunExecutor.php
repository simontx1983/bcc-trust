<?php

declare(strict_types=1);

/**
 * The registered refusal for the retired discovery-run async hook.
 *
 * ── WHY THIS FILE STILL EXISTS ──────────────────────────────────────────
 * It is a tombstone with a job. `bcc_discovery_run_execute` is a ONE-SHOT
 * hook, so freezing and then deleting what CREATED work does not retract
 * work already queued: an action enqueued before this deployment still
 * fires on its own schedule. An unregistered callback on a scheduled event
 * is drift a health check has to explain; a registered refusal is not.
 *
 * ── WHAT S8 REMOVED ─────────────────────────────────────────────────────
 * `execute()`, `continueSession()`, the terminal-failure and audit paths,
 * and with them the last references to the fourteen scanner classes this
 * file used to hold alive — the ledger repository, the session and progress
 * services, the pass report and stop reason, the gate, the readiness check,
 * the five run value objects and the worker itself. All are deleted.
 *
 * The refusal is now UNCONDITIONAL rather than a `ScannerFreeze::frozen()`
 * consultation. That is deliberately stronger: a flag is a thing that can
 * be flipped, and there is no longer any implementation behind it to flip
 * it back on. `ScannerFreeze` retires with the code it was guarding.
 *
 * ── WHEN THIS FILE GOES ─────────────────────────────────────────────────
 * Only once an operator has confirmed the Action Scheduler queue for this
 * hook is drained — `as_get_scheduled_actions()`, or a read-only count over
 * `wp_actionscheduler_actions WHERE hook = 'bcc_discovery_run_execute'`.
 * ⚠ A no-argument `wp_clear_scheduled_hook()` will MISS the wp-cron
 * fallback events, because those carry `[$runId]`. Unbinding is a later,
 * separate change.
 *
 * @package BCC\Trust\Onchain\Workers
 */

namespace BCC\Trust\Onchain\Workers;

if (!defined('ABSPATH')) {
    exit;
}

final class DiscoveryRunExecutor
{
    /** The one-shot hook AsyncDispatcher used to fire. Not recurring. */
    public const HOOK = 'bcc_discovery_run_execute';

    /**
     * THE REGISTERED CALLBACK — what a surviving queued action fires into.
     *
     * Refuses before claiming, advancing, failing or otherwise touching the
     * run, and makes zero provider requests. There is nothing left to
     * delegate to, which is the point: the refusal cannot be bypassed by
     * configuration because there is no longer an implementation behind it.
     *
     * Logged at WARNING rather than silently swallowed. The queue is
     * believed empty on every environment, so an actual firing is new
     * information an operator should be able to find in the application log
     * instead of inferring from its absence.
     *
     * @return array{status: string, run_id: int}
     */
    public static function handleQueuedAction(int $runId): array
    {
        if (class_exists('\BCC\Core\Log\Logger')) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] a queued discovery run fired after the scanner was removed; refused without touching the run',
                ['run_id' => $runId, 'hook' => self::HOOK]
            );
        }

        return ['status' => 'retired', 'run_id' => $runId];
    }
}
