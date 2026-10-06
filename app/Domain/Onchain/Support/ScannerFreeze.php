<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The full-chain discovery scanner is FROZEN: nothing may start, continue,
 * retry, cancel or inspect a run.
 *
 * ── WHAT THIS IS ────────────────────────────────────────────────────────
 * One switch, consulted by every scanner ENTRY POINT. It does not delete the
 * scanner: the services, workers, repositories, tables, schema files, run
 * rows and history are all untouched and still tested. What it removes is the
 * ability to REACH them.
 *
 * ── AND "EVERY ENTRY POINT" INCLUDES THE ONES WITH NO OPERATOR ──────────
 * Freezing the operator-facing routes is not enough, and the first draft of
 * this class proved it. Two paths reach a run with nobody clicking anything:
 *
 *   1. `DiscoveryRunMaintenance` runs every five minutes. It requeues expired
 *      leases, terminalizes exhausted runs, finds dispatchable runs and
 *      enqueues the executor — so a frozen UI plus a live sweep still starts,
 *      continues and retries scans.
 *   2. An executor action ALREADY QUEUED in Action Scheduler fires on its own
 *      schedule after deployment. Freezing what creates work does not stop
 *      work that already exists.
 *
 * Both are frozen here: the sweep is a complete no-op (which also retains run
 * history, since pruning is skipped), and the executor refuses before the
 * claim, so a pending action makes zero provider requests and mutates nothing.
 *
 * ── WHY FREEZE RATHER THAN DELETE ───────────────────────────────────────
 * Retirement is several changes: detaching what ownership shares with the
 * scanner, then removing the surface, then the implementation and its data.
 * Deleting the implementation in the same change as the entry points would
 * mean the operator surface and the code it drives disappear together, with
 * no intermediate state anyone can run, review or roll back. Freezing first
 * makes the operator-visible half reversible by a one-line change, and keeps
 * the scanner's own tests green while it is unreachable.
 *
 * ── WHAT IS DELIBERATELY NOT FROZEN ─────────────────────────────────────
 * Everything that is not a full-chain run: manual Add Collection, collection
 * verification, the capability and manual-intake controls
 * (`bcc_supports_nft_collections`, `manual_collection_discovery_enabled` —
 * different columns, different routes), targeted contract validation, the
 * per-wallet NFT indexer tick, Cosmos/EVM/Solana ownership reads, join,
 * stance, listing, profile and reconciliation, endpoint policy and breaker
 * behaviour.
 *
 * The maintenance HOOK also stays registered and its schedule is left in
 * place — only its tick is a no-op. An unregistered callback on a scheduled
 * event is drift a health check has to explain; a registered no-op is not.
 *
 * ── S4: THE SCAFFOLDING IS GONE, AND SO IS THE OPERATOR SURFACE ─────────
 * This docblock used to describe four private `*Markup()` twins —
 * `CosmwasmScannerPanel::renderMarkup()` / `::renderCandidateDetailMarkup()`,
 * `DiscoveryScanPanel::renderMarkup()` and
 * `NftDiscoveryPage::render_cw_operation_control_markup()` — which existed so
 * that freezing a renderer did not delete its DOM coverage. It called them
 * scaffolding and said the deletion PR must remove them with the panels and
 * the tests that drive them. S4 is that step for the SURFACE: all four twins,
 * both panel classes, the two admin-route classes and the eleven entry points
 * they served are deleted, along with the tests whose only subject was the
 * markup.
 *
 * ⚠ WHAT THIS CLASS STILL DOES. Two background entry points remain frozen —
 * the five-minute maintenance sweep and the async executor — and `frozen()`
 * is what keeps the RETAINED scanner implementation inert: the worker, the
 * sweep, the executor and the one-shot CLI class all still consult it. The
 * implementation is untouched by S4 and is deleted in the leaf-removal stage,
 * which is when this class goes with it.
 *
 * The maintenance HOOK also stays registered and its schedule is left in
 * place, for the reason stated above.
 *
 * Guarded by ScannerEntryPointsAreFrozenTest and
 * ScannerBackgroundEntryPointsAreFrozenTest.
 */
final class ScannerFreeze
{
    /**
     * Every entry point this freeze covers, for the guard test to enumerate.
     *
     * Names only — this is documentation the test can read, not a registry
     * anything dispatches from. It must list the BACKGROUND entry points too:
     * an inventory of operator-facing routes alone would have described a
     * freeze that a five-minute cron and a queued async action walked straight
     * through.
     *
     * ── S4: ELEVEN ENTRY POINTS LEFT THIS LIST BY BEING DELETED ─────────
     * This inventory used to carry eleven more names — eight admin-post
     * routes, one `wp_ajax_` action and one `cli:` command:
     *
     *   admin_post_bcc_discovery_scan_request / _retry / _cancel
     *   wp_ajax_bcc_discovery_run_status
     *   admin_post_bcc_chain_cw_pause / _resume / _backfill / _retry
     *   admin_post_bcc_chain_cw_discovery_enable / _disable
     *   cli:bcc-trust cosmwasm
     *
     * None of them is frozen any more, because none of them exists. A freeze
     * list names what is reachable in principle and refused in practice; a
     * deleted route is neither. Keeping them would describe a guard standing
     * over nothing, and the inventory test would assert against routes no
     * file registers.
     *
     * ⚠ THE TWO THAT REMAIN ARE STILL LOAD-BEARING. The five-minute sweep and
     * the async executor are deliberately left registered — an unregistered
     * callback on a scheduled event is drift a health check has to explain,
     * and a queued action that lands on nothing is worse than one refused.
     * They retire in their own stage, once the queue is confirmed empty.
     *
     * ⚠⚠ `frozen()` IS UNCHANGED AND MUST STAY THAT WAY. The retained scanner
     * readers — the worker, the maintenance sweep, the executor and the
     * one-shot CLI class — still consult it, and it is what keeps them inert.
     * S4 withdrew the surface, not the freeze.
     */
    public const FROZEN_ENTRY_POINTS = [
        // ── Background: the paths that need no operator at all ──────────────
        // The five-minute sweep requeues expired leases, terminalizes exhausted
        // runs, finds dispatchable ones and enqueues the executor. Its callback
        // stays registered and its schedule is left alone; the tick is a no-op.
        'cron:bcc_discovery_run_maintenance',
        // The async executor hook. Freezing what CREATES work does not stop work
        // that already exists, so an action queued before this deployment fires
        // and is refused before the claim.
        'async:bcc_discovery_run_execute',
    ];

    /**
     * TRUE while the scanner surface is frozen.
     *
     * Deliberately a constant expression and NOT filterable: a filter would
     * be one more way to reach a run, which is the thing being removed. The
     * next PR in the retirement deletes the implementation this guards.
     */
    public static function frozen(): bool
    {
        return true;
    }
}
