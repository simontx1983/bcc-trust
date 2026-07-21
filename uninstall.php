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

// 3. Clear all scheduled cron events. Single source of truth
//    (includes/cron-hooks.php) — this file runs WITHOUT the plugin
//    autoloader, but a plain require of a pure-literal file works.
//    Previously this list drifted badly from the actually-scheduled set
//    (missing all Onchain + Disputes hooks, divergence sweep, decay,
//    reliability, feed warm, weekly digest, watch-batch).
$cron_lists = require __DIR__ . '/includes/cron-hooks.php';
$cron_hooks = array_merge(
    array_keys($cron_lists['recurring']),
    $cron_lists['cleanup_only']
);

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Dynamic per-chain hooks (bcc_chain_refresh_*) are registered at
// runtime — ChainRefreshService clears them on deactivate, but it can't
// run here (no autoloader), so sweep them by prefix out of the cron array.
$cron_array = get_option('cron');
if (is_array($cron_array)) {
    $dynamic_hooks = [];
    foreach ($cron_array as $event_hooks) {
        if (!is_array($event_hooks)) {
            continue;
        }
        foreach (array_keys($event_hooks) as $hook) {
            if (is_string($hook) && strpos($hook, 'bcc_chain_refresh_') === 0) {
                $dynamic_hooks[$hook] = true;
            }
        }
    }
    foreach (array_keys($dynamic_hooks) as $hook) {
        wp_clear_scheduled_hook($hook);
    }
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