<?php
/**
 * Main Database Loader
 *
 * @package BCC_Trust_Engine
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


/**
 * Create all tables during plugin activation
 */
function bcc_trust_create_tables() {

    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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
        'bcc_trust_endorsements',
        'bcc_trust_user_verifications',
        'bcc_trust_activity',
        'bcc_trust_activity_archive',
        'bcc_trust_flags',
        'bcc_trust_reputation',
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
