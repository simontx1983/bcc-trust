<?php
/**
 * Canonical bcc-trust cron hook list — single source of truth.
 *
 * Consumed by THREE places that previously each kept their own drifting
 * copy:
 *   - bcc-trust.php  → the `bcc_expected_cron_hooks` drift-detector
 *     contributor (uses `recurring`, adds `source`).
 *   - bcc_trust_deactivate()  → clears recurring + cleanup_only, then
 *     calls the two service unschedulers for the dynamic/prefixed hooks.
 *   - uninstall.php  → runs WITHOUT the autoloader, so this file MUST
 *     stay pure literals: no class references, no WP calls, no constants.
 *
 * The `recurring` map is the drift-tracked set (interval + description).
 * `cleanup_only` are fire-once / retired / legacy hooks that must still
 * be cleared on deactivate/uninstall but are NOT drift-tracked (a
 * "missing" fire-once hook means "no recent queue activity," not drift).
 *
 * Dynamic per-chain hooks (`bcc_chain_refresh_*`) are NOT listed here —
 * they are registered at runtime and cleared by ChainRefreshService on
 * deactivate, or by prefix sweep in uninstall.php.
 *
 * @return array{recurring: array<string, array{interval: string, description: string}>, cleanup_only: list<string>}
 */

return [
    'recurring' => [
        // Core domain
        'bcc_trust_daily_cleanup'               => ['interval' => 'daily',                        'description' => 'audit-log retention + daily housekeeping'],
        'bcc_trust_hourly_recalc'               => ['interval' => 'hourly',                       'description' => 'page-score recalculation sweep'],
        'bcc_trust_daily_ml_update'             => ['interval' => 'daily',                        'description' => 'fraud-detection ML refresh'],
        'bcc_trust_daily_graph_update'          => ['interval' => 'daily',                        'description' => 'trust-graph rank + vote/endorsement ring detection'],
        'bcc_trust_process_recalculations'      => ['interval' => 'bcc_five_minutes',             'description' => 'recalc queue worker'],
        'bcc_trust_daily_maintenance'           => ['interval' => 'daily',                        'description' => 'read-model sync safety net'],
        'bcc_trust_daily_contribution_recovery' => ['interval' => 'daily',                        'description' => 'trust recovery through contribution (caution/risky cohort)'],
        'bcc_trust_weekly_digest'               => ['interval' => 'bcc_weekly',                   'description' => 'weekly digest mailer'],
        // Recurring, not a legacy drain: CronService::scheduleAll() has always
        // scheduled this weekly. It was filed under cleanup_only, so the
        // drift detector never expected it — and when it silently went
        // missing, nothing noticed. Kept in step with CronService::ownedJobs()
        // by CronScheduleSelfHealTest.
        'bcc_trust_weekly_slow_ring_scan'       => ['interval' => 'bcc_weekly',                   'description' => 'slow endorsement-ring detection (scale hardening)'],
        'bcc_trust_deferred_rm_sync'            => ['interval' => 'bcc_thirty_seconds',           'description' => 'read-model deferred-rebuild for staleness recovery'],
        'bcc_trust_divergence_state_sweep'      => ['interval' => 'daily',                        'description' => 'divergence-state classification + §J.7 notifications'],
        'bcc_trust_daily_attestation_decay'     => ['interval' => 'daily',                        'description' => 'attestation_bonus decay recompute sweep (Slice E)'],
        'bcc_attestor_reliability_sweep'        => ['interval' => 'daily',                        'description' => 'operator-reliability cache recompute (Slice 3)'],
        'bcc_trust_elite_eligibility_sweep'     => ['interval' => 'daily',                        'description' => '§J.12 elite-eligibility gate recompute (tenure crossings + dispute-window expiry)'],
        'bcc_trust_feed_hot_warm'               => ['interval' => 'bcc_one_minute',               'description' => 'anon /feed/hot first-page payload warm'],
        // Onchain domain
        'bcc_onchain_daily_refresh'             => ['interval' => 'daily',                        'description' => 'onchain holdings refresh sweep'],
        'bcc_onchain_retry_bonus'               => ['interval' => 'hourly',                       'description' => 'onchain bonus-application retry'],
        'bcc_gated_group_provision'             => ['interval' => 'daily',                        'description' => 'holder-group + delegator-community provisioning (PeepSo write surface)'],
        'bcc_hall_provision'                    => ['interval' => 'daily',                        'description' => 'one-open-Hall-per-chain provisioning (PeepSo write surface)'],
        'bcc_gated_group_reconcile_sweep'       => ['interval' => 'twicedaily',                   'description' => 'holder-group reconcile sweep'],
        'bcc_gated_group_revoke_sweep'          => ['interval' => 'twicedaily',                   'description' => 'holder-group + delegator-community revoke re-verification sweeps'],
        'bcc_nft_eth_indexer_tick'              => ['interval' => 'bcc_one_minute',               'description' => 'NFT EVM indexer per-chain tick'],
        'bcc_helius_dedupe_sweep'               => ['interval' => 'bcc_five_minutes',             'description' => 'Helius signature replay LRU eviction'],
        'bcc_helius_subscription_reconcile'     => ['interval' => 'twicedaily',                   'description' => 'Helius subscription address-list reconcile (covers dropped subscribe/unsubscribe)'],
        'bcc_nft_enrichment_tick'               => ['interval' => 'bcc_five_minutes',             'description' => 'NFT metadata backfill (name + image_url)'],
        'bcc_watch_batch_sweep'                 => ['interval' => 'bcc_minute',                   'description' => 'WatchBatchAggregator sweep (WatchBatchAggregator::SWEEP_HOOK / ::SWEEP_INTERVAL)'],
        // Disputes domain
        'bcc_disputes_auto_resolve'             => ['interval' => 'daily',                        'description' => 'dispute auto-resolve sweep'],
        'bcc_disputes_reconcile_orphans'        => ['interval' => 'bcc_five_minutes',             'description' => 'dispute reconcile (covers cron + AS enqueue failures)'],
        // Rank domain (redesign Phase 1 data planes)
        'bcc_rank_daily_tier_snapshot'          => ['interval' => 'daily',                        'description' => 'rank tier-day snapshot (per-user daily trust-tier qualification rows)'],
        // Rank domain (redesign Phase 5 cutover)
        'bcc_rank_confirmation_sweep'           => ['interval' => 'bcc_five_minutes',             'description' => '24h Apprentice confirmation resolver (R1 predicate)'],
        'bcc_rank_daily_evaluate'               => ['interval' => 'daily',                        'description' => 'rank promotion/demotion evaluate over ranked members'],
        // Rank domain (redesign Phase 6 meaningful voting)
        'bcc_rank_poll_close_sweep'             => ['interval' => 'hourly',                       'description' => 'meaningful-vote poll close sweep (quorum/majority evaluation + day-90 inconclusive)'],
        // Rank domain (helping emitters) — weekly community-upkeep credit
        'bcc_rank_stewardship_sweep'            => ['interval' => 'bcc_weekly',                   'description' => 'weekly stewardship sweep — credits owners of active User-kind communities (helping + contribution)'],
    ],

    // Fire-once / retired / legacy hooks — cleared on deactivate/uninstall
    // but deliberately excluded from drift detection.
    'cleanup_only' => [
        // Fire-once bootstrap jobs.
        'bcc_trust_initial_user_sync',
        'bcc_trust_initial_read_model_sync',
        // Retired automatic NFT collection discovery. These five ran
        // unattended chain-wide sweeps: `bcc_index_collections` walked
        // every active EVM/Solana/Cosmos chain for "top collections", and
        // the four `bcc_cosmwasm_*` hooks walked every opted-in Cosmos
        // chain for CW-721 code families. Chain-wide discovery is now
        // operator-initiated, one named chain at a time, so none of them
        // has a handler any more.
        //
        // They are listed here — not merely deleted from `recurring` —
        // because installs that ran an earlier build still carry these
        // events in `wp_options.cron`. Removing the registration does not
        // remove the event; a scheduled hook with no handler simply fires
        // into nothing on every cron run, forever. Deactivation clears
        // them from here, and
        // includes/database/unschedule-automatic-nft-discovery.php clears
        // them on installs that are never deactivated.
        'bcc_index_collections',
        'bcc_cosmwasm_backfill_tick',
        'bcc_cosmwasm_daily_discovery',
        'bcc_cosmwasm_weekly_retry',
        'bcc_cosmwasm_metadata_refresh',
        // Scale-hardening / legacy drains still worth clearing on long-lived installs.
        'bcc_pull_batch_sweep',
        // Retired hooks (kept for uninstall hygiene on installs that scheduled them pre-retirement).
        // bcc_trust_daily_vesting retired 2026-07-31 (Rank Phase 2, audit #10):
        // the graduation recompute erased velocity-capped vested_weight values.
        'bcc_trust_daily_vesting',
        'bcc_trust_hourly_graph_update',
        'bcc_trust_hourly_ring_detection',
        'bcc_trust_hourly_risk_refresh',
        'bcc_trust_backfill_edges',
        'bcc_trust_archive_activity_event',
    ],
];
