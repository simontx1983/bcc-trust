<?php

declare(strict_types=1);

/**
 * The bounded maintenance sweep. NOT a discovery scheduler.
 *
 * ── THE FOUR THINGS IT MAY DO ───────────────────────────────────────────
 *   1. Re-dispatch an EXISTING administrator-requested queued run.
 *   2. Recover an expired lease (running -> queued, with backoff).
 *   3. Terminalize a run that has consumed every attempt.
 *   4. Prune eligible terminal history in bounded batches.
 *
 * ── AND WHY IT CANNOT DO MORE ───────────────────────────────────────────
 * It has NO chain-selection logic anywhere. It never reads the chains
 * table, never consults a capability flag, and never constructs a request.
 * Every row it touches was created by a named administrator. That is
 * structural, not a policy comment: there is no code path here that could
 * produce a run, so this sweep cannot become recurring automatic discovery
 * however it is later edited — a change that made it possible would have to
 * add the request machinery, which review would see.
 *
 * ── AGE IS NOT A FAILURE ────────────────────────────────────────────────
 * A queued run is NEVER failed for being old. On an installation whose cron
 * is disabled or externally driven, a run may legitimately wait; it is
 * simply re-dispatched. Age only derives the read-time `pickup_overdue`
 * flag, which this sweep does not write. Failing a run for waiting would
 * destroy a good request that was about to execute.
 *
 * @package BCC\Trust\Onchain\Workers
 */

namespace BCC\Trust\Onchain\Workers;

use BCC\Core\Cron\AsyncDispatcher;
use BCC\Core\Log\Logger;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class DiscoveryRunMaintenance
{
    /**
     * Recurring hook.
     *
     * ⚠ `includes/cron-hooks.php` DECLARES this hook; it does not schedule it.
     * That file is read by `bcc_trust_deactivate()` (the clear-list) and by the
     * `bcc_expected_cron_hooks` drift detector — neither calls
     * `wp_schedule_event`. PR 7A shipped with the declaration and a bare
     * `add_action` and nothing else, so the handler was wired to an event that
     * never existed: the drift detector reported it MISSING forever and the
     * sweep never ran. `register()` below is what actually schedules it.
     */
    public const HOOK = 'bcc_discovery_run_maintenance';

    /** Existing shared interval — see CronService::addCronIntervals. */
    public const INTERVAL = 'bcc_five_minutes';

    /** Runs re-dispatched per tick. */
    private const DISPATCH_LIMIT = 20;

    /** Expired leases triaged per tick. */
    private const REAP_LIMIT = 20;

    /** Terminal rows pruned per tick. */
    private const PRUNE_BATCH = 200;

    /**
     * Wire the handler AND schedule the recurring event.
     *
     * Called from the `plugins_loaded` self-heal block in bcc-trust.php, the
     * same shape as ValidatorMsgQueueWorker::register() — so a hook added by
     * an update schedules itself on the next request without a reactivation.
     *
     * Idempotent on both halves:
     *   • `add_action` with this array callback yields a stable WordPress
     *     callback id, so repeating it replaces rather than appends.
     *   • `AsyncDispatcher::registerRecurring()` returns false and schedules
     *     nothing when `wp_next_scheduled()` already reports an event.
     *
     * This schedules MAINTENANCE, not discovery. `tick()` has no
     * chain-selection logic and cannot create a run, so scheduling it can
     * never amount to unattended scanning — the property the CW-721 comment
     * in bcc-trust.php protects.
     */
    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'handleSweep'], 10, 0);
        AsyncDispatcher::registerRecurring(self::HOOK, self::INTERVAL);
    }

    /**
     * The cron entry point — same `handleX` shape as ValidatorMsgQueueWorker.
     *
     * Exists because `tick()` returns its counts for callers and tests, while
     * a WordPress action callback must return nothing. Binding `tick` directly
     * is what PHPStan flags as `return.void`, and swallowing the value behind
     * an anonymous closure instead would cost the stable callback id that
     * makes repeated registration idempotent.
     */
    public static function handleSweep(): void
    {
        self::tick();
    }

    /**
     * One maintenance tick.
     *
     * @return array{redispatched: int, requeued: int, exhausted: int, pruned: int}
     */
    public static function tick(): array
    {
        $result = ['redispatched' => 0, 'requeued' => 0, 'exhausted' => 0, 'pruned' => 0];

        // ── 1 + 2. Triage expired leases FIRST ──────────────────────────
        // Before re-dispatching, so a run whose worker died is already back
        // in `queued` and can be picked up in the same tick rather than
        // waiting a further five minutes.
        foreach (DiscoveryRunRepository::findExpiredLeases(self::REAP_LIMIT) as $run) {
            $runId   = (int) $run->id;
            $attempts = (int) $run->attempt_count;

            if ($attempts >= DiscoveryRunRepository::MAX_ATTEMPTS) {
                // Nothing will ever claim it again. Leaving it `running`
                // forever would be a lie told by omission.
                if (DiscoveryRunRepository::terminalizeExhausted($runId)) {
                    $result['exhausted']++;
                    Logger::error('[bcc-trust] discovery run exhausted its attempts after a lease expiry', [
                        'run_id'        => $runId,
                        'attempt_count' => $attempts,
                    ]);
                }
                continue;
            }

            // ⚠ The requeue does NOT bump `attempt_count`. The claim already
            // counted it, and counting a dead worker twice would burn every
            // attempt on a healthy run inside fifteen minutes.
            if (DiscoveryRunRepository::requeueExpiredLease($runId)) {
                $result['requeued']++;
            }
        }

        // ── 1. Re-dispatch work an administrator already asked for ──────
        foreach (DiscoveryRunRepository::findDispatchable(self::DISPATCH_LIMIT) as $run) {
            $runId = (int) $run->id;

            $accepted = \BCC\Core\Cron\AsyncDispatcher::enqueueAsync(
                DiscoveryRunExecutor::HOOK,
                [$runId],
                'bcc-discovery'
            );

            if ($accepted) {
                $result['redispatched']++;
                continue;
            }

            // Nothing is lost: the run stays queued and the next tick tries
            // again. Recorded because a dispatcher that never accepts is a
            // real operational condition, not a transient.
            Logger::error('[bcc-trust] discovery run re-dispatch was not accepted', ['run_id' => $runId]);
        }

        // ── 4. Retention ────────────────────────────────────────────────
        $result['pruned'] = DiscoveryRunRepository::pruneTerminal(self::PRUNE_BATCH);

        return $result;
    }
}
