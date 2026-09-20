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
 * ── TEMPORARY SCAFFOLDING, TO BE DELETED WITH THE SCANNER ───────────────
 * Freezing a renderer would have deleted its DOM coverage, so each frozen
 * renderer keeps its markup in a PRIVATE twin that the existing tests drive
 * directly: `CosmwasmScannerPanel::renderMarkup()` /
 * `::renderCandidateDetailMarkup()`, `DiscoveryScanPanel::renderMarkup()` and
 * `NftDiscoveryPage::render_cw_operation_control_markup()`.
 *
 * ⚠ These twins are SCAFFOLDING, not architecture. They exist only to keep
 * the retained markup under test while it is unreachable, and the scanner-
 * deletion PR MUST delete them along with the panels, the tests that drive
 * them and this class. Do not add more of them, and do not build on them.
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
     */
    public const FROZEN_ENTRY_POINTS = [
        // ── Operator-facing: admin-post routes and the run-status AJAX call ──
        'admin_post_bcc_discovery_scan_request',
        'admin_post_bcc_discovery_scan_retry',
        'admin_post_bcc_discovery_scan_cancel',
        'wp_ajax_bcc_discovery_run_status',
        'admin_post_bcc_chain_cw_pause',
        'admin_post_bcc_chain_cw_resume',
        'admin_post_bcc_chain_cw_backfill',
        'admin_post_bcc_chain_cw_retry',
        // Per-chain scanner opt-in. `cosmwasm_nft_discovery_enabled` is read only by the
        // scanner (gate, eligibility, one-shot CLI, health snapshot); no ownership,
        // manual-intake or capability path consumes it. The separate manual controls
        // (`bcc_supports_nft_collections`, `manual_collection_discovery_enabled`) are
        // different columns with their own routes and are NOT frozen.
        'admin_post_bcc_chain_cw_discovery_enable',
        'admin_post_bcc_chain_cw_discovery_disable',

        // ── Background: the paths that need no operator at all ──────────────
        // The five-minute sweep requeues expired leases, terminalizes exhausted
        // runs, finds dispatchable ones and enqueues the executor. Its callback
        // stays registered and its schedule is left alone; the tick is a no-op.
        'cron:bcc_discovery_run_maintenance',
        // The async executor hook. Freezing what CREATES work does not stop work
        // that already exists, so an action queued before this deployment fires
        // and is refused before the claim.
        'async:bcc_discovery_run_execute',

        // ── Command line ────────────────────────────────────────────────────
        'cli:bcc-trust cosmwasm',
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
