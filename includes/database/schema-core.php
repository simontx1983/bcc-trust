<?php
/**
 * Core Database Schema
 *
 * @package BCC\Trust\Core
 * @subpackage Database
 * @version 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create all core database tables
 */
function bcc_trust_create_core_tables() {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    /*
    ======================================================
    VOTES TABLE
    ======================================================
    */

    $votes_table = \BCC\Trust\Core\Database\TableRegistry::votes();

    $sql = "CREATE TABLE $votes_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        voter_user_id BIGINT UNSIGNED NOT NULL,
        page_id BIGINT UNSIGNED NOT NULL,
        category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        vote_type TINYINT NOT NULL,
        weight DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
        vested_weight DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
        fraud_score_at_vote TINYINT UNSIGNED NULL,
        vesting_stage TINYINT UNSIGNED NOT NULL DEFAULT 0,
        vesting_started_at DATETIME NULL,
        fully_vested_at DATETIME NULL,
        weight_corrected_at DATETIME NULL,
        reason VARCHAR(100) NULL,
        explanation TEXT NULL,
        status TINYINT NOT NULL DEFAULT 1,
        ip_address VARBINARY(16) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_voter_page_cat (voter_user_id, page_id, category_id),
        KEY idx_page_votes (page_id, vote_type, status),
        KEY idx_page_recent (page_id, status, created_at),
        KEY idx_page_voter (page_id, voter_user_id, status),
        KEY idx_voter_history (voter_user_id, created_at),
        KEY idx_voter_status_date (voter_user_id, status, created_at),
        KEY idx_created (created_at),
        KEY idx_ip_lookup (ip_address, created_at),
        KEY idx_page_score (page_id, status, weight),
        KEY idx_vesting (vesting_stage, fully_vested_at),
        KEY idx_correction (weight_corrected_at, fraud_score_at_vote),
        KEY idx_votes_page_cat_status (page_id, category_id, status),
        KEY idx_vote_lookup (voter_user_id, page_id, category_id, status)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);

    /*
    ======================================================
    PAGE TRUST SCORES
    ======================================================
    */

    $scores_table = \BCC\Trust\Core\Database\TableRegistry::scores();

    $sql = "CREATE TABLE $scores_table (
        page_id BIGINT UNSIGNED NOT NULL,
        category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        page_owner_id BIGINT UNSIGNED NOT NULL,
        total_score DECIMAL(5,2) NOT NULL DEFAULT 50.00,
        positive_score DECIMAL(5,2) NOT NULL DEFAULT 0,
        negative_score DECIMAL(5,2) NOT NULL DEFAULT 0,
        onchain_bonus DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        contribution_bonus DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        penalty_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        attestation_bonus DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        vote_count INT UNSIGNED NOT NULL DEFAULT 0,
        unique_voters INT UNSIGNED NOT NULL DEFAULT 0,
        confidence_score DECIMAL(3,2) NOT NULL DEFAULT 0,
        reputation_tier VARCHAR(20) NOT NULL DEFAULT 'neutral',
        endorsement_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_vote_at DATETIME NULL,
        last_calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fraud_metadata TEXT NULL,
        recalculate_required TINYINT(1) NOT NULL DEFAULT 0,
        recalc_failures INT UNSIGNED NOT NULL DEFAULT 0,
        elite_eligible TINYINT(1) NOT NULL DEFAULT 0,
        elite_eligible_at DATETIME NULL,
        PRIMARY KEY (page_id, category_id),
        KEY idx_owner_scores (page_owner_id, total_score),
        KEY idx_tier_lookup (reputation_tier, total_score),
        KEY idx_confidence (confidence_score),
        KEY idx_recalculate (recalculate_required, last_calculated_at),
        KEY idx_cat_score (category_id, positive_score, total_score),
        KEY idx_recalc_failures (recalc_failures, recalculate_required),
        KEY idx_elite_sweep (elite_eligible_at, total_score)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);

    /*
    ======================================================
    SCORE VELOCITY TRACKING
    ======================================================
    Tracks daily score-change accumulation per page to enforce
    BCC_TRUST_MAX_SCORE_CHANGE_PER_DAY velocity cap.
    */

    $velocity_table = $scores_table . '_velocity';

    $sql = "CREATE TABLE $velocity_table (
        page_id BIGINT UNSIGNED NOT NULL,
        track_date DATE NOT NULL,
        score_delta DECIMAL(8,4) NOT NULL DEFAULT 0,
        PRIMARY KEY (page_id, track_date),
        KEY idx_date (track_date)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);

    /*
    ======================================================
    READ MODEL DIRTY QUEUE
    ======================================================
    Cross-process queue for pages needing read-model sync.

    Race-fix: INSERT ... ON DUPLICATE KEY UPDATE bumps created_at on every
    re-mark, and the cron deletes only rows whose created_at <= the
    snapshot it captured before fetching. A mutation that arrives while
    the cron is mid-sync therefore *bumps* the row (rather than being
    silently dropped by INSERT IGNORE) and survives the post-sync DELETE.

    DATETIME(6) precision is required because two concurrent enqueues
    within the same whole second would otherwise collapse to identical
    timestamps and the post-sync DELETE could not tell them apart.
    */

    $dirty_queue_table = \BCC\Trust\Core\Database\TableRegistry::dirtyQueue();

    $sql = "CREATE TABLE $dirty_queue_table (
        page_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (page_id)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);

    /*
    ======================================================
    ENDORSEMENTS (retired)
    ======================================================
    The bcc_trust_endorsements table was frozen after the Slice E
    endorse→vouch cutover (legacy rows materialized into
    bcc_trust_attestations as kind=vouch) and is dropped by
    includes/database/drop-endorsements-table.php. Its CREATE TABLE
    + unique-key + base_weight migrations were removed here so fresh
    installs never create it.
    */

    /*
    ======================================================
    EMAIL VERIFICATIONS (retired)
    ======================================================
    Email verification is delegated to PeepSo. The old
    bcc_trust_verifications token table is dropped here so it
    doesn't linger on reactivation after the switch.
    */

    $legacy_verifications_table = $wpdb->prefix . 'bcc_trust_verifications';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS {$legacy_verifications_table}");

    /*
    ======================================================
    ACTIVITY LOG
    ======================================================
    */

    $activity_table = \BCC\Trust\Core\Database\TableRegistry::activity();

    $sql = "CREATE TABLE $activity_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(50) NOT NULL,
        target_type VARCHAR(50) NOT NULL,
        target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ip_address VARBINARY(16) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_action (action),
        KEY idx_target (target_type, target_id),
        KEY idx_created (created_at),
        KEY idx_ip_lookup (ip_address, created_at),
        KEY idx_user_action_date (user_id, action, created_at),
        KEY idx_action_created (action, created_at)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);

    /*
    ======================================================
    ACTIVITY ARCHIVE TABLE
    ======================================================
    */

    $activity_archive_table = $activity_table . '_archive';
    
    // Create archive table with same structure as main table
    $wpdb->query("CREATE TABLE IF NOT EXISTS $activity_archive_table LIKE $activity_table");
    
    // Add archive-specific index (check first for MySQL < 8.0 compatibility)
    $index_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE()
               AND table_name = %s
               AND index_name = 'idx_archive_created'",
            $activity_archive_table
        )
    );
    if (!$index_exists) {
        $wpdb->query("ALTER TABLE $activity_archive_table ADD INDEX idx_archive_created (created_at)");
    }

    /*
    ======================================================
    FLAGS — RETIRED (disputes reconciliation, 2026-07-08)
    ======================================================
    bcc_trust_flags was the legacy vote-flag primitive that predated the
    panel-adjudication dispute system (bcc_disputes). It was write-dead
    (its only writer was the deleted report_vote route); every reader was
    repointed to the live bcc_disputes table (reporter-keyed) and the table
    is dropped by includes/database/drop-trust-flags-table.php. Do NOT
    re-add a CREATE here — it would resurrect the retired table on the next
    dbDelta.
    */

    /*
    ======================================================
    REPUTATION — RETIRED (Architecture A, Slice 1c)
    ======================================================
    A member's trust now lives on their self-page row in
    bcc_trust_page_scores (page_id = ID_BASE + user_id). The
    bcc_trust_reputation table is no longer created here and is dropped by
    includes/database/drop-legacy-reputation.php. Do NOT re-add a CREATE
    here — it would resurrect the retired table on the next dbDelta.
    */

    /*
    ======================================================
    DEVICE FINGERPRINTS
    ======================================================
    */

    $fingerprints_table = \BCC\Trust\Core\Database\TableRegistry::fingerprints();

    $sql = "CREATE TABLE $fingerprints_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        fingerprint VARCHAR(64) NOT NULL,
        automation_score TINYINT UNSIGNED DEFAULT 0,
        automation_signals TEXT NULL,
        screen_resolution VARCHAR(20) DEFAULT NULL,
        first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address VARBINARY(16) NULL,
        user_agent TEXT NULL,
        risk_level VARCHAR(20) DEFAULT 'low',
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_fingerprint (fingerprint),
        KEY idx_automation (automation_score),
        KEY idx_user_fingerprint (user_id, fingerprint),
        KEY idx_user_fingerprint_lastseen (user_id, fingerprint, last_seen),
        KEY idx_automation_risk (automation_score, risk_level),
        KEY idx_ip_fingerprint (ip_address, fingerprint)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);

    /*
    ======================================================
    BEHAVIOR PATTERNS
    ======================================================
    */

    $patterns_table = \BCC\Trust\Core\Database\TableRegistry::patterns();

    $sql = "CREATE TABLE $patterns_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        pattern_type VARCHAR(50) NOT NULL,
        pattern_data TEXT NOT NULL,
        confidence DECIMAL(3,2) DEFAULT 0,
        detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_type (pattern_type),
        KEY idx_expires (expires_at),
        KEY idx_user_type (user_id, pattern_type),
        KEY idx_detected (detected_at)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);

    /*
    ======================================================
    FRAUD ANALYSIS
    ======================================================
    */

    $fraud_analysis_table = \BCC\Trust\Core\Database\TableRegistry::fraudAnalysis();

    $sql = "CREATE TABLE $fraud_analysis_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        fraud_score TINYINT UNSIGNED NOT NULL,
        risk_level VARCHAR(20) NOT NULL,
        confidence DECIMAL(3,2) NOT NULL,
        triggers TEXT NOT NULL,
        details TEXT NULL,
        analyzed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_score (fraud_score),
        KEY idx_risk (risk_level),
        KEY idx_expires (expires_at),
        KEY idx_user_recent (user_id, analyzed_at)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);

    /*
    ======================================================
    SUSPENSIONS
    ======================================================
    */

    $suspensions_table = \BCC\Trust\Core\Database\TableRegistry::suspensions();

    $sql = "CREATE TABLE $suspensions_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        suspended_by BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(100) NOT NULL,
        fraud_score_at_time TINYINT UNSIGNED NULL,
        notes TEXT NULL,
        suspended_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        unsuspended_at DATETIME NULL,
        unsuspended_by BIGINT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_status (suspended_at, unsuspended_at),
        KEY idx_expires (expires_at),
        KEY idx_active (unsuspended_at, expires_at)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);

    /*
    ======================================================
    MATERIALIZED TRUST EDGES
    Pre-aggregated graph edges for PageRank. One row per
    (source_user, target_user, edge_type) storing the SUM
    of all vote / endorsement weights between those two users.
    Updated incrementally on every vote / endorsement write.
    ======================================================
    */

    $edges_table = \BCC\Trust\Core\Database\TableRegistry::edges();

    $sql = "CREATE TABLE $edges_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source_user_id BIGINT UNSIGNED NOT NULL,
        target_user_id BIGINT UNSIGNED NOT NULL,
        weight DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        vote_count INT UNSIGNED NOT NULL DEFAULT 0,
        edge_type VARCHAR(20) NOT NULL DEFAULT 'vote',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_updated DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_edge (source_user_id, target_user_id, edge_type),
        KEY idx_source (source_user_id),
        KEY idx_target (target_user_id),
        KEY idx_target_source (target_user_id, source_user_id),
        KEY idx_type_weight (edge_type, weight),
        KEY idx_pagerank (edge_type, source_user_id, target_user_id, weight)
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql);
}
