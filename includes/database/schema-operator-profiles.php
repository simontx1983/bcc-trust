<?php
/**
 * Shared validator-operator profile and page-specific overrides.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_operator_profiles_table(): string
{
    return \BCC\Core\DB\DB::table('operator_profiles');
}

function bcc_onchain_operator_profile_overrides_table(): string
{
    return \BCC\Core\DB\DB::table('operator_profile_overrides');
}

function bcc_onchain_create_operator_profiles_table(): void
{
    global $wpdb;
    $charsetCollate = $wpdb->get_charset_collate();
    $profiles       = bcc_onchain_operator_profiles_table();
    $overrides      = bcc_onchain_operator_profile_overrides_table();

    $profileSql = "CREATE TABLE {$profiles} (
        user_id BIGINT UNSIGNED NOT NULL,
        brand_name VARCHAR(100) NOT NULL DEFAULT '',
        bio TEXT NOT NULL,
        website_url VARCHAR(255) NOT NULL DEFAULT '',
        x_url VARCHAR(255) NOT NULL DEFAULT '',
        discord_url VARCHAR(255) NOT NULL DEFAULT '',
        telegram_url VARCHAR(255) NOT NULL DEFAULT '',
        logo_attachment_id BIGINT UNSIGNED NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (user_id),
        KEY idx_updated_at (updated_at)
    ) {$charsetCollate};";

    $overrideSql = "CREATE TABLE {$overrides} (
        page_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        brand_name VARCHAR(100) NULL DEFAULT NULL,
        bio TEXT NULL DEFAULT NULL,
        website_url VARCHAR(255) NULL DEFAULT NULL,
        x_url VARCHAR(255) NULL DEFAULT NULL,
        discord_url VARCHAR(255) NULL DEFAULT NULL,
        telegram_url VARCHAR(255) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (page_id),
        KEY idx_user_page (user_id, page_id)
    ) {$charsetCollate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($profileSql);
    dbDelta($overrideSql);
}
