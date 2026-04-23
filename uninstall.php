<?php
/**
 * BCC Trust — Uninstall
 *
 * Fired when the plugin is deleted (not just deactivated). Drops all
 * custom database tables, removes options/transients/user-meta, and
 * clears cron events for the unified Trust plugin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1. Drop all custom tables (bcc_trust_* prefix + known non-prefixed tables).
$prefix = $wpdb->prefix . 'bcc_trust_';

$tables = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
        DB_NAME,
        $wpdb->esc_like($prefix) . '%'
    )
);

$read_model = $wpdb->prefix . 'bcc_page_read_model';
$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $read_model));
if ($exists === $read_model) {
    $tables[] = $read_model;
}

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

// 2. Delete all plugin options.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('bcc_trust_') . '%'
    )
);

// Delete transients (rate-limit, trust caches, page-data caches).
$transient_patterns = [
    '_transient_bcc_rl_',
    '_transient_timeout_bcc_rl_',
    '_transient_bcc_trust_',
    '_transient_timeout_bcc_trust_',
    '_transient_bcc_page_data_',
    '_transient_timeout_bcc_page_data_',
];
foreach ($transient_patterns as $pattern) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($pattern) . '%'
        )
    );
}

// 3. Clear all scheduled cron events.
$cron_hooks = [
    'bcc_trust_daily_cleanup',
    'bcc_trust_hourly_recalc',
    'bcc_trust_daily_ml_update',
    'bcc_trust_daily_graph_update',
    'bcc_trust_hourly_graph_update',
    'bcc_trust_hourly_ring_detection',
    'bcc_trust_hourly_risk_refresh',
    'bcc_trust_initial_user_sync',
    'bcc_trust_daily_vesting',
    'bcc_trust_process_recalculations',
    'bcc_trust_backfill_edges',
    'bcc_trust_archive_activity_event',
    'bcc_trust_daily_maintenance',
    'bcc_trust_initial_read_model_sync',
    'bcc_trust_deferred_rm_sync',
];

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// 4. Clean up user meta — both _bcc_* (private keys like _bcc_github_state)
//    and bcc_trust_* (public keys like bcc_trust_ml_prediction, bcc_trust_last_login).
$meta_patterns = [
    '_bcc_',
    'bcc_trust_',
];
foreach ($meta_patterns as $pattern) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like($pattern) . '%'
        )
    );
}