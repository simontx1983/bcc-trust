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
        // CosmWasm CW-721 discovery. All four handlers re-check the
        // fail-CLOSED CosmwasmDiscoveryGate, so a scheduled hook on an
        // environment that has not opted in is a no-op — being listed here
        // is drift tracking, not permission. The "monthly" pass rides a
        // DAILY interval plus a durable >=30-day elapsed guard, because
        // wp-cron has no monthly interval and we do not invent one.
        'bcc_cosmwasm_backfill_tick'            => ['interval' => 'bcc_five_minutes',             'description' => 'CosmWasm CW-721 historical backfill (one chain slice per tick; needs BCC_COSMWASM_BACKFILL_ENABLED)'],
        'bcc_cosmwasm_daily_discovery'          => ['interval' => 'daily',                        'description' => 'CosmWasm CW-721 incremental discovery — new code ids + new contracts under known families'],
        'bcc_cosmwasm_weekly_retry'             => ['interval' => 'bcc_weekly',                   'description' => 'CosmWasm CW-721 retry sweep — temporarily_unreachable + inconclusive, capped and backed off'],
        'bcc_cosmwasm_metadata_refresh'         => ['interval' => 'daily',                        'description' => 'CosmWasm CW-721 migration check + mutable metadata refresh (monthly via a durable elapsed guard)'],
        'bcc_watch_batch_sweep'                 => ['interval' => 'bcc_pull_batch_sweep_minute',  'description' => 'WatchBatchAggregator sweep'],
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
