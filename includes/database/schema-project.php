<?php
/**
 * Page-Level Database Schema
 *
 * Creates the denormalized page read model for fast discovery/search reads.
 *
 * @package BCC\Trust\Core
 * @subpackage Database
 * @version 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create page-level database tables.
 */
function bcc_trust_create_page_tables() {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    /*
    ======================================================
    PAGE READ MODEL
    Precomputed, denormalized page data for fast reads by
    the discovery endpoint, page header, and search results.
    Updated via hooks on vote / endorsement / score changes.
    ======================================================
    */

    $read_model_table = \BCC\Trust\Core\Database\TableRegistry::pageReadModel();

    $sql = "CREATE TABLE $read_model_table (
        page_id BIGINT UNSIGNED NOT NULL,
        owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        trust_score DECIMAL(5,2) NOT NULL DEFAULT 50.00,
        reputation_tier VARCHAR(20) NOT NULL DEFAULT 'neutral',
        confidence_score DECIMAL(3,2) NOT NULL DEFAULT 0.00,
        positive_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        negative_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        onchain_bonus DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        endorsement_bonus DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        vote_count INT UNSIGNED NOT NULL DEFAULT 0,
        unique_voters INT UNSIGNED NOT NULL DEFAULT 0,
        endorsement_count INT UNSIGNED NOT NULL DEFAULT 0,
        follower_count INT UNSIGNED NOT NULL DEFAULT 0,
        page_type VARCHAR(50) NOT NULL DEFAULT 'builder',
        is_verified TINYINT(1) NOT NULL DEFAULT 0,
        github_username VARCHAR(100) DEFAULT NULL,
        github_followers INT UNSIGNED NOT NULL DEFAULT 0,
        x_username VARCHAR(100) DEFAULT NULL,
        x_followers INT UNSIGNED NOT NULL DEFAULT 0,
        has_wallet TINYINT(1) NOT NULL DEFAULT 0,
        last_vote_at DATETIME NULL,
        last_endorsement_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (page_id),
        KEY idx_trust_sort (trust_score DESC),
        KEY idx_endorsement_sort (endorsement_count DESC),
        KEY idx_follower_sort (follower_count DESC),
        KEY idx_tier_trust (reputation_tier, trust_score),
        KEY idx_type_trust (page_type, trust_score),
        KEY idx_owner (owner_id),
        KEY idx_verified_trust (is_verified, trust_score),
        KEY idx_confidence (confidence_score)
    ) $charset_collate;";

    dbDelta($sql);
}
