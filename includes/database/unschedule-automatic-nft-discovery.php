<?php
/**
 * One-shot retirement of the automatic NFT collection discovery schedules.
 *
 * WHY A MIGRATION AND NOT JUST A CODE DELETION
 * --------------------------------------------
 * Removing a `wp_schedule_event()` call stops FUTURE installs from creating
 * the event. It does nothing to installs that already have one: the event
 * lives in `wp_options.cron`, not in the code, and WordPress keeps firing it
 * on its interval forever. With the handler gone the fire is a no-op, but the
 * schedule entry is still there — an operator inspecting cron sees five
 * recurring discovery jobs that the plugin no longer believes in, and the
 * drift detector cannot tell that state apart from a hook that is supposed to
 * exist and has gone missing.
 *
 * So the schedules are cleared explicitly, once, on every existing install.
 *
 * THE FIVE HOOKS
 * --------------
 *   bcc_index_collections           4-hourly sweep of EVERY active
 *                                   EVM/Solana/Cosmos chain for "top
 *                                   collections".
 *   bcc_cosmwasm_backfill_tick      5-minutely historical CW-721 backfill
 *                                   slice, chain picked round-robin.
 *   bcc_cosmwasm_daily_discovery    daily incremental CW-721 pass over every
 *                                   opted-in chain.
 *   bcc_cosmwasm_weekly_retry       weekly retry pass over the same set.
 *   bcc_cosmwasm_metadata_refresh   daily hook + 30-day guard, migration
 *                                   check over every opted-in chain.
 *
 * Every one of them iterated chains on its own. Chain-wide discovery is now
 * operator-initiated, one named chain at a time, so none of them has a
 * handler any more.
 *
 * NO-ARGUMENT EVENTS, AND WHY THAT MATTERS HERE
 * ---------------------------------------------
 * `wp_clear_scheduled_hook($hook)` clears events whose argument list is
 * EMPTY. An event scheduled with arguments survives it, because WordPress
 * keys events by hook AND serialized arguments.
 *
 * That is safe for these five: every scheduler that created them called
 * `wp_schedule_event($timestamp, $interval, $hook)` with no fourth argument
 * — `ChainRefreshService::schedule_crons()`, the retired
 * `CosmwasmDiscoveryWorker::register()`, and `bcc_trust_activate()`. There
 * has never been an argument-bearing variant of any of them, so there is
 * nothing for a no-argument clear to miss.
 *
 * FAIL-CLOSED
 * -----------
 * The migration reports COMPLETE only when it has PROVEN the postcondition:
 * `wp_next_scheduled()` returns false for all five. If any hook is still
 * scheduled after the clear — a concurrent request re-adding it, a filter
 * refusing the unschedule, an object-cache read that has not settled — it
 * returns INCOMPLETE and runs again on the next request. It never marks
 * itself done on the strength of having *called* the clear.
 *
 * This is deliberately the opposite of "assume it worked": a failed cleanup
 * that marked itself complete would leave the schedules in place with no
 * remaining attempt to remove them, and nothing would ever say so.
 *
 * IDEMPOTENT
 * ----------
 * Re-running is harmless. Clearing a hook that is not scheduled is a no-op
 * that returns 0, and the postcondition check passes immediately. The runner
 * short-circuits on the completion option anyway.
 *
 * WHAT IT DOES NOT TOUCH
 * ----------------------
 * No collection row, no verification flag, no community, no wallet, no
 * holdings, no provider. It reads and writes the WordPress cron option and
 * nothing else.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since PR-1 (stop automatic NFT discovery)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_retired_discovery_hooks')) {

    /**
     * The retired hooks, in a single place both the migration and its tests
     * read, so a hook added to one cannot be missed by the other.
     *
     * @return list<string>
     */
    function bcc_trust_retired_discovery_hooks(): array
    {
        return [
            'bcc_index_collections',
            'bcc_cosmwasm_backfill_tick',
            'bcc_cosmwasm_daily_discovery',
            'bcc_cosmwasm_weekly_retry',
            'bcc_cosmwasm_metadata_refresh',
        ];
    }
}

if (!function_exists('bcc_trust_unschedule_automatic_nft_discovery')) {

    /**
     * Clear every retired discovery schedule, then prove they are gone.
     *
     * @return string BCC_TRUST_MIGRATION_COMPLETE once the postcondition
     *                holds, BCC_TRUST_MIGRATION_INCOMPLETE otherwise.
     */
    function bcc_trust_unschedule_automatic_nft_discovery(): string
    {
        $hooks   = bcc_trust_retired_discovery_hooks();
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
                    '[bcc-trust] retired discovery schedules still present after clearing — will retry',
                    ['hooks' => $stillScheduled]
                );
            }

            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        if ($cleared !== [] && class_exists('\\BCC\\Core\\Log\\Logger')) {
            // Only worth a line when something was actually removed; a fresh
            // install has nothing to clear and should stay quiet.
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] cleared retired automatic NFT discovery schedules',
                ['cleared' => $cleared]
            );
        }

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
