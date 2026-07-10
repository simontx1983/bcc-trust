<?php
/**
 * User Verifications Table Schema
 *
 * Stores all external identity verifications per user.
 * Each user can have one active verification per type.
 *
 * Supported types (current and planned):
 *   - 'github'  : GitHub OAuth verification
 *   - 'domain'  : Domain ownership verification
 *   - 'wallet'  : Blockchain wallet verification
 *
 * Generic columns hold data common to all types.
 * Type-specific data (e.g. followers, repos, chain) is stored
 * as a JSON-encoded string in the `meta` column.
 *
 * @package BCC\Trust\Core
 * @subpackage Database
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_user_verifications_table() {

    global $wpdb;

    $table   = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
    $charset = $wpdb->get_charset_collate();

    // Columns: type = 'github'|'domain'|'wallet_ethereum'|etc.
    // meta = JSON blob with provider-specific data (followers, chain, etc.)
    // status = 'active'|'revoked'|'expired'
    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(50) NOT NULL,
        provider_id VARCHAR(255) DEFAULT NULL,
        provider_username VARCHAR(255) DEFAULT NULL,
        provider_avatar VARCHAR(255) DEFAULT NULL,
        meta TEXT DEFAULT NULL,
        access_token TEXT DEFAULT NULL,
        refresh_token TEXT DEFAULT NULL,
        token_expires_at DATETIME DEFAULT NULL,
        trust_boost FLOAT DEFAULT 0,
        fraud_reduction INT DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        verified_at DATETIME DEFAULT NULL,
        last_synced DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_user_type (user_id, type),
        UNIQUE KEY unique_provider (type, provider_id),
        KEY idx_user (user_id),
        KEY idx_type (type),
        KEY idx_status (status),
        KEY idx_verified (verified_at),
        KEY idx_trust_boost (trust_boost),
        KEY idx_user_status (user_id, status)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'BCC Trust: User verifications table installed', []);
}
