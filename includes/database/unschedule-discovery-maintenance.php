<?php
/**
 * One-shot retirement of the discovery-run ledger maintenance schedule.
 *
 * WHY A MIGRATION AND NOT JUST A CODE DELETION
 * --------------------------------------------
 * S6 removed both halves of `DiscoveryRunMaintenance::register()` — the
 * `add_action` and the `AsyncDispatcher::registerRecurring()` call — so no
 * future install creates the event. That does nothing for installs that
 * already have one: the event lives in `wp_options.cron`, not in the code,
 * and WordPress keeps firing it every five minutes forever. With the handler
 * gone the fire is a no-op, but the schedule entry is still there — an
 * operator inspecting cron sees a recurring job the plugin no longer believes
 * in, and the drift detector cannot tell that state apart from a hook that is
 * supposed to exist and has gone missing.
 *
 * So the schedule is cleared explicitly, once, on every existing install.
 *
 * THE HOOK
 * --------
 *   bcc_discovery_run_maintenance   five-minute sweep over the discovery-run
 *                                   ledger: re-dispatch runs an administrator
 *                                   had already requested, requeue expired
 *                                   leases, terminalize exhausted ones, prune
 *                                   terminal history.
 *
 * MAINTENANCE, never discovery — it had no chain-selection logic and could
 * not create a run. It is retired because the surface that created runs is
 * gone, and because it had already been a complete no-op since the freeze:
 * `tick()` returns its zero counts before touching anything.
 *
 * ⚠ RETENTION GOES WITH IT. `DiscoveryRunRepository::pruneTerminal()` has
 * exactly one production caller, that sweep, so terminal run history is no
 * longer pruned by anything. Deliberate: the rows are retained for the
 * table-drop stage rather than deleted while the surface is being withdrawn.
 * This migration prunes NOTHING and deletes no row from any table.
 *
 * ⚠ NOT THE EXECUTOR. `bcc_discovery_run_execute` is a one-shot async hook
 * and is NOT cleared here. Its binding is deliberately retained while a
 * queued action can still exist, because an unregistered callback on a
 * scheduled event is drift a health check has to explain while a registered
 * no-op that refuses before claiming anything is not. Clearing it would also
 * need more than this: Action Scheduler's wp-cron fallback events for it
 * carry `[$runId]`, and a no-argument clear cannot see an
 * argument-bearing event.
 *
 * NO-ARGUMENT EVENTS, AND WHY THAT IS SAFE HERE
 * ---------------------------------------------
 * `wp_clear_scheduled_hook($hook)` clears events whose argument list is
 * EMPTY. An event scheduled with arguments survives it, because WordPress
 * keys events by hook AND serialized arguments.
 *
 * That is safe for this hook. The only thing that ever scheduled it was
 * `AsyncDispatcher::registerRecurring($hook, $interval)`, which calls
 * `wp_schedule_event($timestamp, $interval, $hook)` with no fourth argument.
 * There has never been an argument-bearing variant, so there is nothing for a
 * no-argument clear to miss.
 *
 * FAIL-CLOSED
 * -----------
 * Reports COMPLETE only when it has PROVEN the postcondition:
 * `wp_next_scheduled()` returns false. If the hook is still scheduled after
 * the clear — a concurrent request re-adding it, a filter refusing the
 * unschedule, an object-cache read that has not settled — it returns
 * INCOMPLETE and runs again on the next request. It never marks itself done
 * on the strength of having *called* the clear.
 *
 * IDEMPOTENT
 * ----------
 * Re-running is harmless. Clearing a hook that is not scheduled is a no-op
 * that returns 0, and the postcondition check passes immediately. The runner
 * short-circuits on the completion option anyway.
 *
 * ITS OWN done_option, AND WHY THAT IS NOT DUPLICATION
 * ----------------------------------------------------
 * `unschedule-automatic-nft-discovery.php` and `unschedule-hall-provision.php`
 * already exist and do the same KIND of work. Adding this hook to either would
 * have been a silent no-op: both have long since completed on every install,
 * so the runner short-circuits on their `done_option` and the new hook would
 * never be reached. A fresh entry with a fresh option is the only shape that
 * runs — the same reason `unschedule_automatic_nft_discovery_v2` exists
 * alongside `v1`.
 *
 * WHAT IT DOES NOT TOUCH
 * ----------------------
 * No discovery run, no ledger row, no collection, no chain row, no checkpoint,
 * no option other than the WordPress cron option and its own completion flag.
 * This removes a SCHEDULE, not anything that schedule once did.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since S6 (scanner retirement — cron)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_retired_discovery_maintenance_hooks')) {

    /**
     * The retired hooks, in a single place both the migration and its tests
     * read, so a hook added to one cannot be missed by the other.
     *
     * ⚠ `bcc_discovery_run_execute` is deliberately NOT in this list — see
     * the file docblock.
     *
     * @return list<string>
     */
    function bcc_trust_retired_discovery_maintenance_hooks(): array
    {
        return [
            'bcc_discovery_run_maintenance',
        ];
    }
}

if (!function_exists('bcc_trust_unschedule_discovery_maintenance')) {

    /**
     * Clear the retired maintenance schedule, then prove it is gone.
     *
     * @return string BCC_TRUST_MIGRATION_COMPLETE once the postcondition
     *                holds, BCC_TRUST_MIGRATION_INCOMPLETE otherwise.
     */
    function bcc_trust_unschedule_discovery_maintenance(): string
    {
        $hooks   = bcc_trust_retired_discovery_maintenance_hooks();
        $cleared = [];

        foreach ($hooks as $hook) {
            $removed = wp_clear_scheduled_hook($hook);
            if (is_int($removed) && $removed > 0) {
                $cleared[$hook] = $removed;
            }
        }

        // THE POSTCONDITION. Asked of WordPress, not inferred from the return
        // values above — wp_clear_scheduled_hook() reports what it removed,
        // which is not the same claim as "nothing is scheduled now".
        $stillScheduled = [];
        foreach ($hooks as $hook) {
            if (wp_next_scheduled($hook) !== false) {
                $stillScheduled[] = $hook;
            }
        }

        if ($stillScheduled !== []) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning(
                    '[bcc-trust] retired discovery maintenance schedule still present after clearing — will retry',
                    ['hooks' => $stillScheduled]
                );
            }

            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        if ($cleared !== [] && class_exists('\\BCC\\Core\\Log\\Logger')) {
            // ⚠ Only worth a line when something was actually removed. A
            // fresh install has nothing to clear and must stay quiet —
            // logging unconditionally here would announce a retirement that
            // did not happen on every install, forever, which is exactly the
            // noise drop-onchain-role-boost-columns.php produces hourly.
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] cleared the retired discovery maintenance schedule',
                ['cleared' => $cleared]
            );
        }

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
