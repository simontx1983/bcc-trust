<?php
/**
 * User Info Table Schema - CLEAN VERSION
 *
 * @package BCC\Trust\Core
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_user_info_table() {

    global $wpdb;

    $table = $wpdb->prefix . 'bcc_trust_user_info';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        user_login VARCHAR(60) NOT NULL,
        user_email VARCHAR(100) NOT NULL,
        display_name VARCHAR(250) NOT NULL,
        registered DATETIME DEFAULT NULL,
        usr_last_activity DATETIME DEFAULT NULL,
        usr_views INT DEFAULT 0,
        usr_likes INT DEFAULT 0,
        usr_role VARCHAR(50) DEFAULT NULL,
        fraud_score INT DEFAULT 0,
        peak_fraud_score INT DEFAULT 0,
        trust_rank FLOAT DEFAULT 0,
        risk_level VARCHAR(20) DEFAULT 'unknown',
        is_suspended TINYINT(1) DEFAULT 0,
        is_verified TINYINT(1) DEFAULT 0,
        votes_cast INT DEFAULT 0,
        endorsements_given INT DEFAULT 0,
        automation_score INT DEFAULT 0,
        behavior_score INT DEFAULT 0,
        pages_owned INT DEFAULT 0,
        groups_owned INT DEFAULT 0,
        posts_created INT DEFAULT 0,
        comments_made INT DEFAULT 0,
        last_login DATETIME DEFAULT NULL,
        last_ip_address VARCHAR(45) DEFAULT NULL,
        device_fingerprint VARCHAR(255) DEFAULT NULL,
        device_fraud_probability FLOAT DEFAULT 0,
        signals_updated_at DATETIME DEFAULT NULL,
        risk_label VARCHAR(20) NOT NULL DEFAULT 'Low',
        risk_color VARCHAR(10) NOT NULL DEFAULT '#28a745',
        fraud_triggers TEXT DEFAULT NULL,
        page_ids_owned TEXT DEFAULT NULL,
        reputation_tier VARCHAR(20) NOT NULL DEFAULT 'neutral',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_id (user_id),
        KEY risk_level (risk_level),
        KEY usr_last_activity (usr_last_activity),
        KEY idx_fraud_risk (fraud_score, risk_level),
        KEY idx_verification (is_verified, fraud_score),
        KEY idx_suspended (is_suspended),
        KEY idx_trust_rank (trust_rank),
        KEY idx_behavior_score (behavior_score),
        KEY idx_updated_at (updated_at),
        KEY idx_reputation_tier (reputation_tier)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Backfill peak_fraud_score for existing users so the oscillation
    // protection floor works for pre-existing accounts, not just new ones.
    // Safe to run repeatedly: only updates rows where peak is NULL or 0
    // but fraud_score is already > 0.
    $wpdb->query(
        "UPDATE {$table}
         SET peak_fraud_score = fraud_score
         WHERE fraud_score > 0
           AND (peak_fraud_score IS NULL OR peak_fraud_score = 0)"
    );

    // One-time backfill of reputation_tier from the authoritative reputation
    // table so queries that read ui.reputation_tier do not return 'neutral'
    // for every existing user after the column was added. Safe to re-run.
    if (!get_option('bcc_trust_user_info_reputation_tier_backfilled')) {
        $reputation_table = $wpdb->prefix . 'bcc_trust_reputation';
        $wpdb->query(
            "UPDATE {$table} ui
             INNER JOIN {$reputation_table} r ON ui.user_id = r.user_id
             SET ui.reputation_tier = r.reputation_tier
             WHERE ui.reputation_tier = 'neutral'
               AND r.reputation_tier <> 'neutral'"
        );
        update_option('bcc_trust_user_info_reputation_tier_backfilled', time(), false);
    }

    \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'BCC Trust: User info table installed', []);
}