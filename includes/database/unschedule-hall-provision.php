<?php
/**
 * One-shot retirement of the automatic Hall provisioning schedule.
 *
 * WHY A MIGRATION AND NOT JUST A CODE DELETION
 * --------------------------------------------
 * Removing the `wp_schedule_event()` calls stops FUTURE installs from
 * creating the event. It does nothing to installs that already have one: the
 * event lives in `wp_options.cron`, not in the code, and WordPress keeps
 * firing it on its interval forever. With the handler gone the fire is a
 * no-op, but the schedule entry is still there — an operator inspecting cron
 * sees a recurring job the plugin no longer believes in, and the drift
 * detector cannot tell that state apart from a hook that is supposed to exist
 * and has gone missing.
 *
 * So the schedule is cleared explicitly, once, on every existing install.
 *
 * THE HOOK
 * --------
 *   bcc_hall_provision   daily sweep of EVERY active chain that created an
 *                        open, public PeepSo group ("{Chain} Hall") for any
 *                        chain without one.
 *
 * It enumerated chains on its own, which is precisely the property that made
 * it incompatible with the rule that a Hall is an official,
 * ADMINISTRATOR-CREATED space: registering a chain, rather than any decision
 * to open a space, is what published the group. Hall creation is now
 * operator-initiated, one named chain at a time
 * ({@see \BCC\Trust\Onchain\Admin\ChainsPage::ACTION_HALL_CREATE}), so the
 * hook has no handler any more.
 *
 * NO-ARGUMENT EVENTS, AND WHY THAT MATTERS HERE
 * ---------------------------------------------
 * `wp_clear_scheduled_hook($hook)` clears events whose argument list is
 * EMPTY. An event scheduled with arguments survives it, because WordPress
 * keys events by hook AND serialized arguments.
 *
 * That is safe here: both schedulers that ever created this event called
 * `wp_schedule_event($timestamp, 'daily', 'bcc_hall_provision')` with no
 * fourth argument — `bcc_trust_activate()` and the `plugins_loaded`
 * self-heal. There has never been an argument-bearing variant, so there is
 * nothing for a no-argument clear to miss.
 *
 * FAIL-CLOSED
 * -----------
 * The migration reports COMPLETE only when it has PROVEN the postcondition:
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
 * WHAT IT DOES NOT TOUCH
 * ----------------------
 * No Hall, no group, no membership, no chain row, no post meta, no ownership.
 * Halls that already exist are left exactly as they are — this removes a
 * SCHEDULE, not anything it once created. It reads and writes the WordPress
 * cron option and nothing else.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since PR-1 (administrator-created Halls)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_retired_hall_hooks')) {

    /**
     * The retired hooks, in a single place both the migration and its tests
     * read, so a hook added to one cannot be missed by the other.
     *
     * @return list<string>
     */
    function bcc_trust_retired_hall_hooks(): array
    {
        return [
            'bcc_hall_provision',
        ];
    }
}

if (!function_exists('bcc_trust_unschedule_hall_provision')) {

    /**
     * Clear the retired Hall schedule, then prove it is gone.
     *
     * @return string BCC_TRUST_MIGRATION_COMPLETE once the postcondition
     *                holds, BCC_TRUST_MIGRATION_INCOMPLETE otherwise.
     */
    function bcc_trust_unschedule_hall_provision(): string
    {
        $hooks   = bcc_trust_retired_hall_hooks();
        $cleared = [];

        foreach ($hooks as $hook) {
            $removed = wp_clear_scheduled_hook($hook);
            if (is_int($removed) && $removed > 0) {
                $cleared[$hook] = $removed;
            }
        }

        // THE POSTCONDITION. Asked of WordPress, not inferred from the
        // return values above — wp_clear_scheduled_hook() reports what it
        // removed, which is not the same claim as "nothing is scheduled now".
        $stillScheduled = [];
        foreach ($hooks as $hook) {
            if (wp_next_scheduled($hook) !== false) {
                $stillScheduled[] = $hook;
            }
        }

        if ($stillScheduled !== []) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning(
                    '[bcc-trust] retired Hall schedule still present after clearing — will retry',
                    ['hooks' => $stillScheduled]
                );
            }

            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        if ($cleared !== [] && class_exists('\\BCC\\Core\\Log\\Logger')) {
            // Only worth a line when something was actually removed; a fresh
            // install has nothing to clear and should stay quiet.
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] cleared the retired automatic Hall provisioning schedule',
                ['cleared' => $cleared]
            );
        }

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
