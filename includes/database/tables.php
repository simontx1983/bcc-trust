<?php
/**
 * Main Database Loader
 *
 * @package BCC\Trust\Core
 * @subpackage Database
 * @version 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Database Components
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/schema-core.php';
require_once __DIR__ . '/schema-user-info.php';
require_once __DIR__ . '/schema-user-verifications.php';
require_once __DIR__ . '/schema-project.php';
require_once __DIR__ . '/schema-quest-log.php';
require_once __DIR__ . '/schema-page-flags.php';
require_once __DIR__ . '/schema-score-events.php';

// V1 frontend support tables (per docs/api-contract-v1.md §6.5)
require_once __DIR__ . '/schema-watch-meta.php';
require_once __DIR__ . '/schema-page-follows.php';
require_once __DIR__ . '/schema-watch-batches.php';
require_once __DIR__ . '/schema-content-reports.php';
require_once __DIR__ . '/schema-hidden-activities.php';

// V2 Phase 1: §I1 push notifications — VAPID subscriptions per user/device
require_once __DIR__ . '/schema-push-subscriptions.php';
// V1.5 a11y: photo alt-text sidecar (per api-contract-v1.md §3.3.9)
require_once __DIR__ . '/schema-photo-alts.php';
// V2 Trust Attestation Layer: §J wire contract — generalizes Endorse → Vouch
require_once __DIR__ . '/schema-trust-attestations.php';
// V2 Trust Attestation Layer PR-8b: divergence-state sidecar — prior-state
// memory for the PolarizationTransitionNotifier cron worker.
require_once __DIR__ . '/schema-target-divergence-state.php';
// Stoke — the forge-fire feed reaction (cosmetic for trust, real input
// to feed ranking). One row per (act_id, user_id); never touches
// bcc_trust_scores.
require_once __DIR__ . '/schema-stokes.php';
// V2 Trust Attestation Layer Slice 3 — nightly operator-reliability
// recompute cache (memoizes AttestationOutcomeClassifier per attestor).
require_once __DIR__ . '/schema-attestor-reliability-cache.php';
// Post-shortcode permalink sidecar — act_id → 8-letter code behind
// /u/{handle}/post/{code}. Includes a one-time option-guarded dev backfill.
require_once __DIR__ . '/schema-post-shortcodes.php';
// Rank redesign Phase 1 data planes — explicit-login day buckets +
// daily trust-tier qualification history (approved plan 2026-07-31).
require_once __DIR__ . '/schema-login-days.php';
require_once __DIR__ . '/schema-tier-days.php';
// Rank redesign Phase 4 — append-only evidence ledger + formal
// cluster determinations (shadow; nothing gates on them yet).
require_once __DIR__ . '/schema-rank-events.php';
require_once __DIR__ . '/schema-cluster-findings.php';
// Rank redesign Phase 5 — canonical rank state + the 24h Apprentice
// confirmation clock (the atomic cutover).
require_once __DIR__ . '/schema-rank-state.php';
require_once __DIR__ . '/schema-rank-pending.php';
// Rank redesign Phase 6 — meaningful-voting poll engine (generic polls
// + per-voter ballots with immutable §16.6 weight snapshots).
require_once __DIR__ . '/schema-polls.php';
require_once __DIR__ . '/schema-ballots.php';
// Rank redesign Phase 7 — append-only community-custody ledger (§21.2
// caps + 30-day global cooldown; create/receive/transfer_out events).
require_once __DIR__ . '/schema-community-ownership-log.php';
// Rank redesign Phase 8 — misconduct findings (§15) + append-only
// decision history (§15.5). Distinct from cluster findings (Phase 4).
require_once __DIR__ . '/schema-findings.php';
require_once __DIR__ . '/schema-finding-decisions.php';
// NOTE: bcc_user_locals removed — Halls membership lives in PeepSo's
// peepso_group_members (single graph rule); primary-Hall pointer in
// wp_usermeta.bcc_primary_hall_group_id.
// NOTE: bcc_page_claims merged into bcc_onchain_claims — page claims
// use entity_type='page'; recovery_pending column added to onchain_claims.


/**
 * Create all tables during plugin activation
 */
function bcc_trust_create_tables() {

    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Data-preserving pull→watch physical rename — MUST run before the
    // dbDelta sweeps below so they see the already-renamed shape instead of
    // creating empty bcc_watch_* tables alongside the populated legacy ones.
    // Idempotent (guarded on the old names) — a clean no-op on fresh installs.
    if (function_exists('bcc_trust_rename_pull_to_watch')) {
        bcc_trust_rename_pull_to_watch();
    }

    \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'BCC Trust: Starting database installation', []);

    /*
    |--------------------------------------------------------------------------
    | Check if PeepSo exists (optional)
    |--------------------------------------------------------------------------
    */

    $peepso_pages_table = $wpdb->prefix . 'peepso_page_categories';
    $peepso_pages_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $peepso_pages_table)) === $peepso_pages_table;

    if (!$peepso_pages_exists) {
        \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'BCC Trust: PeepSo Pages table not found. Trust tables will still be created.', []);
    }

    /*
    |--------------------------------------------------------------------------
    | Create Tables
    |--------------------------------------------------------------------------
    */

    // User info table (source of truth)
    if (function_exists('bcc_trust_create_user_info_table')) {
        bcc_trust_create_user_info_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'BCC Trust: User info table created', []);
    } else {
        \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust ERROR: bcc_trust_create_user_info_table function missing', []);
    }

    // User verifications table (GitHub, domain, wallet, etc.)
    if (function_exists('bcc_trust_create_user_verifications_table')) {
        bcc_trust_create_user_verifications_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'BCC Trust: User verifications table created', []);
    } else {
        \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust ERROR: bcc_trust_create_user_verifications_table function missing', []);
    }

    // Core trust engine tables
    if (function_exists('bcc_trust_create_core_tables')) {
        bcc_trust_create_core_tables();
        \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'BCC Trust: Core tables created', []);
    } else {
        \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust ERROR: bcc_trust_create_core_tables function missing', []);
    }

    // Quest log table (onboarding quest completions)
    if (function_exists('bcc_trust_create_quest_log_table')) {
        bcc_trust_create_quest_log_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Quest log table created', []);
    }

    // Page flags table (public flagging, signal only — no score impact)
    if (function_exists('bcc_trust_create_page_flags_table')) {
        bcc_trust_create_page_flags_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Page flags table created', []);
    }

    // Score events table (audit trail for trust score changes)
    if (function_exists('bcc_trust_create_score_events_table')) {
        bcc_trust_create_score_events_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Score events table created', []);
    }

    // V1 frontend support tables (watch meta)
    if (function_exists('bcc_trust_create_watch_meta_table')) {
        bcc_trust_create_watch_meta_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Watch meta table created', []);
    }
    if (function_exists('bcc_trust_create_page_follows_table')) {
        bcc_trust_create_page_follows_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Page follows table created', []);
    }
    if (function_exists('bcc_trust_create_watch_batches_table')) {
        bcc_trust_create_watch_batches_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Watch batches table created', []);
    }
    if (function_exists('bcc_trust_create_content_reports_table')) {
        bcc_trust_create_content_reports_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Content reports table created', []);
    }
    if (function_exists('bcc_trust_create_hidden_activities_table')) {
        bcc_trust_create_hidden_activities_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Hidden activities table created', []);
    }
    if (function_exists('bcc_trust_create_push_subscriptions_table')) {
        bcc_trust_create_push_subscriptions_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Push subscriptions table created', []);
    }
    if (function_exists('bcc_trust_create_photo_alts_table')) {
        bcc_trust_create_photo_alts_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Photo alts table created', []);
    }
    if (function_exists('bcc_trust_create_trust_attestations_table')) {
        bcc_trust_create_trust_attestations_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Trust attestations table created', []);
    }
    if (function_exists('bcc_trust_create_target_divergence_state_table')) {
        bcc_trust_create_target_divergence_state_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Target divergence-state sidecar table created', []);
    }
    if (function_exists('bcc_trust_create_stokes_table')) {
        bcc_trust_create_stokes_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Stokes table created', []);
    }
    if (function_exists('bcc_trust_create_attestor_reliability_cache_table')) {
        bcc_trust_create_attestor_reliability_cache_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Attestor reliability cache table created', []);
    }
    if (function_exists('bcc_trust_create_post_shortcodes_table')) {
        bcc_trust_create_post_shortcodes_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Post shortcodes table created', []);
    }
    if (function_exists('bcc_trust_create_login_days_table')) {
        bcc_trust_create_login_days_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Login days table created', []);
    }
    if (function_exists('bcc_trust_create_tier_days_table')) {
        bcc_trust_create_tier_days_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Tier days table created', []);
    }
    if (function_exists('bcc_trust_create_rank_events_table')) {
        bcc_trust_create_rank_events_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Rank events table created', []);
    }
    if (function_exists('bcc_trust_create_cluster_findings_table')) {
        bcc_trust_create_cluster_findings_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Cluster findings table created', []);
    }
    if (function_exists('bcc_trust_create_rank_state_table')) {
        bcc_trust_create_rank_state_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Rank state table created', []);
    }
    if (function_exists('bcc_trust_create_rank_pending_table')) {
        bcc_trust_create_rank_pending_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Rank pending table created', []);
    }
    if (function_exists('bcc_trust_create_polls_table')) {
        bcc_trust_create_polls_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Polls table created', []);
    }
    if (function_exists('bcc_trust_create_ballots_table')) {
        bcc_trust_create_ballots_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Ballots table created', []);
    }
    if (function_exists('bcc_trust_create_community_ownership_log_table')) {
        bcc_trust_create_community_ownership_log_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Community ownership log table created', []);
    }
    if (function_exists('bcc_trust_create_findings_table')) {
        bcc_trust_create_findings_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Findings table created', []);
    }
    if (function_exists('bcc_trust_create_finding_decisions_table')) {
        bcc_trust_create_finding_decisions_table();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Finding decisions table created', []);
    }

    // Data backfills (canonical handles + wallet placeholder-emails) run
    // through the shared migration runner, NOT by calling each backfill
    // directly. This makes the schema-install path and the runtime
    // plugins_loaded path use one idempotent implementation and one
    // per-migration lock, so they cannot race or double-mark completion.
    // The runner is normally what fires these (independent of
    // BCC_TRUST_SCHEMA_VERSION); invoking it here means a schema bump also
    // drains any pending migration in the same pass. See
    // includes/database/migration-runner.php.
    if (function_exists('bcc_trust_run_pending_migrations')) {
        bcc_trust_run_pending_migrations();
    }

    // §D5 reaction seeding — idempotent insert of the three custom
    // reactions (Solid / Vouch / Back) as peepso_reaction_user
    // CPT posts. Re-running is safe; missing kinds get inserted,
    // present kinds are left alone.
    if (class_exists('\\BCC\\Trust\\Core\\Services\\Reactions\\ReactionSeeder')) {
        (new \BCC\Trust\Core\Services\Reactions\ReactionSeeder())->seed();
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Reaction seeder invoked', []);
    }

    // Page-level tables (verifications, metrics, identities, endorsement types, read model)
    if (function_exists('bcc_trust_create_page_tables')) {
        bcc_trust_create_page_tables();
        \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'BCC Trust: Page-level tables created', []);
    } else {
        \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust ERROR: bcc_trust_create_page_tables function missing', []);
    }

    /*
    |--------------------------------------------------------------------------
    | Verify All Tables Were Created
    |--------------------------------------------------------------------------
    */

    $missing_tables = bcc_trust_verify_all_tables();

    if (empty($missing_tables)) {
        \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: All database tables verified successfully', []);
    } else {
        \BCC\Core\Log\Logger::error('[bcc-trust] BCC Trust ERROR: Missing tables: ', ['detail' => implode(', ', $missing_tables)]);
    }

    \BCC\Core\Log\Logger::info('[bcc-trust] BCC Trust: Database installation completed', []);

    // Tables changed — drop any cached existence answers so the next
    // tableExists() call re-probes.
    \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();

    return true;
}


/**
 * Verify all required tables exist
 *
 * @return array
 */
function bcc_trust_verify_all_tables() {

    global $wpdb;

    $required_tables = [
        'bcc_trust_votes',
        'bcc_trust_page_scores',
        // bcc_trust_endorsements retired (endorse-retirement final slice) —
        // vouch attestations live in bcc_trust_attestations.
        'bcc_trust_attestations',
        'bcc_trust_user_verifications',
        'bcc_trust_activity',
        'bcc_trust_activity_archive',
        'bcc_trust_flags',
        // bcc_trust_reputation retired (Architecture A, Slice 1c) — trust moved
        // to the self-page row in bcc_trust_page_scores.
        'bcc_trust_device_fingerprints',
        'bcc_trust_patterns',
        'bcc_trust_user_info',
        'bcc_trust_fraud_analysis',
        'bcc_trust_suspensions',
        'bcc_trust_edges',
        'bcc_page_read_model',
        'bcc_trust_quest_log',
        'bcc_trust_page_flags',
        'bcc_trust_score_events',
        // V1 frontend support tables
        'bcc_watch_meta',
        'bcc_watch_batches',
        'bcc_content_reports',
        'bcc_hidden_activities',
        // V2 Phase 1 push notifications
        'bcc_push_subscriptions',
        // V1.5 a11y photo alt sidecar
        'bcc_photo_alts',
        // V2 Trust Attestation Layer
        'bcc_trust_attestations',
        // Stoke accumulator
        'bcc_trust_stokes',
        // Slice 3 operator-reliability recompute cache
        'bcc_attestor_reliability_cache',
        // Post-shortcode permalink sidecar
        'bcc_post_shortcodes',
        // Rank redesign Phase 1 data planes
        'bcc_trust_login_days',
        'bcc_trust_tier_days',
        // Rank redesign Phase 4 evidence ledger (shadow)
        'bcc_trust_rank_events',
        'bcc_trust_cluster_findings',
        // Rank redesign Phase 5 canonical state + confirmation clock
        'bcc_trust_rank_state',
        'bcc_trust_rank_pending',
        // Rank redesign Phase 6 meaningful-voting poll engine
        'bcc_trust_polls',
        'bcc_trust_ballots',
        // Rank redesign Phase 7 community-custody ledger
        'bcc_trust_community_ownership_log',
        // Rank redesign Phase 8 misconduct findings + decisions
        'bcc_trust_findings',
        'bcc_trust_finding_decisions',
    ];

    $missing = [];

    foreach ($required_tables as $table) {

        $full_name = $wpdb->prefix . $table;

        $exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $full_name)
        );

        if ($exists !== $full_name) {
            $missing[] = $table;
        }
    }

    return $missing;
}

/**
 * Acquire the schema-migration advisory lock (non-blocking).
 *
 * Prevents N concurrent requests from stampeding dbDelta when a deploy
 * bumps BCC_TRUST_SCHEMA_VERSION. Lives here (not in the bootstrap)
 * because direct $wpdb access is confined to the database layer.
 *
 * @return bool True if this request holds the lock.
 */
function bcc_trust_acquire_schema_lock(): bool {
    global $wpdb;

    return (int) $wpdb->get_var("SELECT GET_LOCK('bcc_trust_schema_migration', 0)") === 1;
}

/**
 * Release the schema-migration advisory lock.
 */
function bcc_trust_release_schema_lock(): void {
    global $wpdb;

    $wpdb->query("SELECT RELEASE_LOCK('bcc_trust_schema_migration')");
}
