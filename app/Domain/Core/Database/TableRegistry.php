<?php

namespace BCC\Trust\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for all trust engine table names.
 *
 * Every table name accessor is a static method. All tables are defined
 * here — nowhere else. All callers reference this class directly.
 *
 * This class is intentionally static: table names are derived from
 * $wpdb->prefix which is a global singleton, and the methods are pure
 * getters with no side effects.
 *
 * exists() is the one non-getter: the shared table-existence check
 * behind the per-repository tableExists() methods, so the SHOW TABLES
 * probes that used to run per-request collapse into a warm object
 * cache read.
 */
final class TableRegistry
{
    private const EXISTS_CACHE_GROUP = 'bcc_tables';
    private const EXISTS_CACHE_TTL   = HOUR_IN_SECONDS;

    /** @var array<string, bool> Request-scoped memo (positive AND negative). */
    private static array $existsMemo = [];

    /** @var array<string, bool> Request-scoped memo, keyed "table.column". */
    private static array $columnMemo = [];

    /**
     * Table-existence check: static memo → wp_cache (1h) → SHOW TABLES.
     *
     * Only POSITIVE results are cached cross-request. A `false` stays
     * request-scoped, so a table created moments ago (activation,
     * schema migration) is visible to the very next request — no
     * negative poisoning. Tables don't un-exist outside installers,
     * and installers call flushExistenceCache().
     *
     * Diagnostic/repair surfaces (AdminDashboardRepository repair data,
     * RepairService, bcc_trust_verify_all_tables) intentionally do NOT
     * route through here — they must see ground truth.
     */
    public static function exists(string $table): bool
    {
        if (isset(self::$existsMemo[$table])) {
            return self::$existsMemo[$table];
        }

        $cached = wp_cache_get('exists_' . $table, self::EXISTS_CACHE_GROUP);
        if ($cached === '1') {
            self::$existsMemo[$table] = true;
            return true;
        }

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        self::$existsMemo[$table] = $exists;
        if ($exists) {
            wp_cache_set('exists_' . $table, '1', self::EXISTS_CACHE_GROUP, self::EXISTS_CACHE_TTL);
        }

        return $exists;
    }

    /**
     * Column-existence check — same caching contract as {@see exists()}.
     *
     * Exists because plugin CODE can run against an OLD SCHEMA. The
     * schema-version gate gets a `GET_LOCK`; when a concurrent request
     * already holds it the gate returns early and that request
     * "proceeds un-migrated" (bcc-trust.php, schema-migration hook). During a
     * deploy under traffic that is every request until the winner finishes.
     * Ordering cannot close it — REST/cron already run after the gate — so
     * writers that reference a newly-added column must ask first.
     *
     * Cost: one SHOW COLUMNS per (table, column) per request at worst, and
     * zero once the positive answer is in the object cache. NOT once per
     * write — callers hold the boolean for the duration of their statement.
     *
     * Only POSITIVE results are cached cross-request, exactly as exists()
     * does: a `false` stays request-scoped, so a column added moments ago is
     * visible to the very next request and a negative can never poison the
     * cache. Installers additionally call flushExistenceCache(), which drops
     * the request-scoped memo too — so a migration completing mid-request is
     * picked up by the same request.
     *
     * Fails SAFE: any DB error returns false, which makes callers degrade to
     * the pre-migration behaviour rather than emit SQL against a column that
     * may not be there.
     */
    public static function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (isset(self::$columnMemo[$key])) {
            return self::$columnMemo[$key];
        }

        $cached = wp_cache_get('col_' . $key, self::EXISTS_CACHE_GROUP);
        if ($cached === '1') {
            self::$columnMemo[$key] = true;
            return true;
        }

        global $wpdb;

        // Suppress errors around the probe: a missing TABLE (fresh install,
        // mid-activation) must read as "column absent", not surface a DB
        // error to the caller mid-score-write.
        $suppressed = $wpdb->suppress_errors(true);
        $found      = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            $column
        ));
        $wpdb->suppress_errors($suppressed);

        $exists = is_string($found) && $found === $column;

        self::$columnMemo[$key] = $exists;
        if ($exists) {
            wp_cache_set('col_' . $key, '1', self::EXISTS_CACHE_GROUP, self::EXISTS_CACHE_TTL);
        }

        return $exists;
    }

    /**
     * Drop all cached existence answers. Called by the installers
     * (bcc_trust_create_tables, bcc_onchain_ensure_schema) after they
     * run, so anything cached mid-install is re-probed.
     */
    public static function flushExistenceCache(): void
    {
        $tables = array_unique(array_merge(
            array_keys(self::$existsMemo),
            array_values(self::all())
        ));
        foreach ($tables as $table) {
            wp_cache_delete('exists_' . $table, self::EXISTS_CACHE_GROUP);
        }
        self::$existsMemo = [];

        // Column answers too — a dbDelta that just ADDED a column must not
        // leave a stale `false` memoized for the rest of this request.
        foreach (array_keys(self::$columnMemo) as $key) {
            wp_cache_delete('col_' . $key, self::EXISTS_CACHE_GROUP);
        }
        self::$columnMemo = [];
    }

    public static function votes(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_votes';
    }

    public static function scores(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_page_scores';
    }

    // NOTE: bcc_trust_endorsements retired (endorse-retirement final slice,
    // 2026-07-02) — reads repointed to bcc_trust_attestations (kind=vouch);
    // the table is dropped by includes/database/drop-endorsements-table.php.

    public static function activity(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_activity';
    }

    public static function activityArchive(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_activity_archive';
    }

    // NOTE: bcc_trust_flags removed — the legacy vote-flag/dispute table was
    // write-dead and retired (disputes reconciliation, 2026-07-08). Disputes
    // live in bcc_disputes (Domain/Disputes); the table is dropped by
    // includes/database/drop-trust-flags-table.php.

    public static function fingerprints(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_device_fingerprints';
    }

    public static function patterns(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_patterns';
    }

    public static function userInfo(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_user_info';
    }

    public static function fraudAnalysis(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_fraud_analysis';
    }

    public static function suspensions(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_suspensions';
    }

    public static function userVerifications(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_user_verifications';
    }

    public static function edges(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_edges';
    }

    public static function pageReadModel(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_page_read_model';
    }

    public static function pageFlags(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_page_flags';
    }

    public static function scoreEvents(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_score_events';
    }

    public static function scoreVelocity(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_page_scores_velocity';
    }

    public static function loginDays(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_login_days';
    }

    public static function tierDays(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_tier_days';
    }

    public static function rankEvents(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_rank_events';
    }

    public static function clusterFindings(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_cluster_findings';
    }

    public static function rankState(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_rank_state';
    }

    public static function rankPending(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_rank_pending';
    }

    public static function polls(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_polls';
    }

    public static function ballots(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_ballots';
    }

    public static function questLog(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_quest_log';
    }

    public static function dirtyQueue(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_rm_dirty_queue';
    }

    // V1 frontend support tables (per docs/api-contract-v1.md §6.5)

    public static function watchMeta(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_watch_meta';
    }

    public static function pageFollows(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_page_follows';
    }

    public static function watchBatches(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_watch_batches';
    }

    public static function contentReports(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_content_reports';
    }

    public static function hiddenActivities(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_hidden_activities';
    }

    public static function disputeParticipations(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_dispute_participations';
    }

    // V2 Phase 1: §I1 push notifications

    public static function pushSubscriptions(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_push_subscriptions';
    }

    // V1.5 a11y: photo alt-text sidecar (per api-contract-v1.md §3.3.9).
    // 1:1 with peepso_photos.pho_id; PeepSo updates can't clobber it
    // because the table is BCC-owned.

    public static function photoAlts(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_photo_alts';
    }

    // V2 Trust Attestation Layer: §J wire contract.
    // Stores Layer-1 attestations (Vouch + Back). Disputes
    // live separately (stake + panel mechanics don't fit this shape).
    // Generalized successor to the retired bcc_trust_endorsements table
    // per the §J.11 "endorse collapses into vouch" migration.

    public static function trustAttestations(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_attestations';
    }

    /**
     * PR-8b sidecar — prior-state memory + cooldown bookkeeping for the
     * §J.8 divergence-state notifier. Keyed on (target_kind, target_id);
     * not a cache (the classifier remains the source of truth).
     */
    public static function targetDivergenceState(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_target_divergence_state';
    }

    /**
     * Stoke — one row per (act_id, user_id), the row's existence IS the
     * stoke (one per person). Backs the feed-ranking `heat_stage`
     * signal and the public `stoke_count`; never feeds `bcc_trust_scores`.
     */
    public static function stokes(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_trust_stokes';
    }

    /**
     * Slice 3 — nightly memoization of AttestationOutcomeClassifier output.
     * One row per attestor (PRIMARY KEY user_id). The cron owns writes;
     * read paths (/me/reliability, standing) read-cache-first and fall
     * back to a live compute on cache miss. NOT a source of truth — the
     * classifier remains canonical; this is a recompute cache.
     */
    public static function attestorReliabilityCache(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_attestor_reliability_cache';
    }

    /**
     * Post-shortcode sidecar — one row per top-level activity, mapping
     * act_id → the permanent 8-letter permalink code
     * (`/u/{handle}/post/{code}`). Rows mint lazily on first feed
     * emission; a code is never rewritten.
     */
    public static function postShortcodes(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_post_shortcodes';
    }

    // NOTE: bcc_user_locals removed — Halls membership ledger is PeepSo's
    // peepso_group_members; primary-Hall pointer is wp_usermeta.bcc_primary_hall_group_id.
    // NOTE: bcc_page_claims removed — page claims merged into bcc_onchain_claims
    // (entity_type='page'); recovery_pending lives there.

    /**
     * All table names as an associative array.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'votes'              => self::votes(),
            'scores'             => self::scores(),
            'user_verifications' => self::userVerifications(),
            'activity'           => self::activity(),
            'activity_archive'   => self::activityArchive(),
            'fingerprints'       => self::fingerprints(),
            'patterns'           => self::patterns(),
            'user_info'          => self::userInfo(),
            'fraud_analysis'     => self::fraudAnalysis(),
            'suspensions'        => self::suspensions(),
            'edges'              => self::edges(),
            'page_read_model'    => self::pageReadModel(),
            'page_flags'         => self::pageFlags(),
            'score_events'       => self::scoreEvents(),
            'score_velocity'     => self::scoreVelocity(),
            'dirty_queue'        => self::dirtyQueue(),
            // V1 frontend support tables
            'watch_meta'         => self::watchMeta(),
            'watch_batches'      => self::watchBatches(),
            'content_reports'    => self::contentReports(),
            'hidden_activities'  => self::hiddenActivities(),
            'dispute_participations' => self::disputeParticipations(),
            // V2 Phase 1 push notifications
            'push_subscriptions' => self::pushSubscriptions(),
            // V1.5 a11y photo alt sidecar
            'photo_alts'         => self::photoAlts(),
            // V2 Trust Attestation Layer (§J)
            'trust_attestations' => self::trustAttestations(),
            // V2 Trust Attestation Layer PR-8b — divergence-state sidecar
            'target_divergence_state' => self::targetDivergenceState(),
            // Stoke accumulator
            'stokes'             => self::stokes(),
            // Slice 3 — nightly operator-reliability recompute cache
            'attestor_reliability_cache' => self::attestorReliabilityCache(),
            // Post-shortcode permalink sidecar
            'post_shortcodes'    => self::postShortcodes(),
        ];
    }
}
