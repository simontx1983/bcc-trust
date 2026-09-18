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
 * ability to REACH them — the admin POST routes, the run-status AJAX action,
 * the panels and their continuation controls, and the one-shot CLI command.
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
 * verification, capability and manual-intake controls, targeted contract
 * validation, the per-wallet NFT indexer tick, Cosmos/EVM/Solana ownership
 * reads, join, stance, listing, profile and reconciliation, endpoint policy,
 * breaker behaviour, and the discovery-maintenance schedule that prunes rows
 * that already exist.
 *
 * Guarded by ScannerEntryPointsAreFrozenTest.
 */
final class ScannerFreeze
{
    /**
     * The entry points this freeze covers, for the guard test to enumerate.
     *
     * Names only — this is documentation the test can read, not a registry
     * anything dispatches from.
     */
    public const FROZEN_ENTRY_POINTS = [
        'admin_post_bcc_discovery_scan_request',
        'admin_post_bcc_discovery_scan_retry',
        'admin_post_bcc_discovery_scan_cancel',
        'wp_ajax_bcc_discovery_run_status',
        'admin_post_bcc_chain_cw_pause',
        'admin_post_bcc_chain_cw_resume',
        'admin_post_bcc_chain_cw_backfill',
        'admin_post_bcc_chain_cw_retry',
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
