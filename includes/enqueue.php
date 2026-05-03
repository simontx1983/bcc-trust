<?php
/**
 * BCC Trust Engine - Asset Enqueue (admin only)
 *
 * Per the headless architecture, all user-facing UI lives in bcc-frontend
 * (Next.js). The only assets enqueued from PHP are wp-admin pages.
 *
 * @package BCC\Trust\Core
 * @version 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_enqueue_scripts', 'bcc_trust_enqueue_admin');

function bcc_trust_enqueue_admin($hook) {
    // Only load on our admin pages
    $trust_pages = ['bcc-trust-dashboard', 'bcc-trust-moderation', 'bcc-trust-settings', 'bcc-trust-logs'];
    $current_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    if (!in_array($current_page, $trust_pages)) {
        return;
    }

    $js_dir = BCC_TRUST_URL . 'assets/js/';
    $css_dir = BCC_TRUST_URL . 'assets/css/';
    $js_path = BCC_TRUST_PATH . 'assets/js/';
    $css_path = BCC_TRUST_PATH . 'assets/css/';

    // ======================================================
    // Chart.js for admin charts
    // ======================================================
    if ($current_page === 'bcc-trust-dashboard') {
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            [],
            '3.9.1',
            true
        );

        // Add SRI integrity hash for CDN security.
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle === 'chart-js') {
                $tag = str_replace(
                    ' src=',
                    ' integrity="sha384-9MhbyIRcBVQiiC7FSd7T38oJNj2Zh+EfxS7/vjhBi4OOT78NlHSnzM31EZRWR1LZ" crossorigin="anonymous" src=',
                    $tag
                );
            }
            return $tag;
        }, 10, 2);
    }

    // ======================================================
    // Admin Dashboard CSS — Forge design system
    // ======================================================
    if (file_exists($css_path . 'admin-dashboard.css')) {
        wp_enqueue_style(
            'bcc-trust-admin-dashboard',
            $css_dir . 'admin-dashboard.css',
            [],
            BCC_TRUST_VERSION
        );
    }

    // ======================================================
    // Admin JavaScript
    // ======================================================
    if (file_exists($js_path . 'admin.js')) {
        $dependencies = ['jquery', 'wp-api'];

        // Add jQuery UI tooltip if available
        if (wp_script_is('jquery-ui-tooltip', 'registered')) {
            $dependencies[] = 'jquery-ui-tooltip';
        }

        // Add Chart.js as dependency if on dashboard and it was loaded
        if ($current_page === 'bcc-trust-dashboard' && wp_script_is('chart-js', 'enqueued')) {
            $dependencies[] = 'chart-js';
        }

        wp_enqueue_script(
            'bcc-trust-admin',
            $js_dir . 'admin.js',
            $dependencies,
            BCC_TRUST_VERSION,
            true
        );

        wp_localize_script(
            'bcc-trust-admin',
            'bccTrustAdmin',
            [
                'nonce'         => wp_create_nonce('wp_rest'),
                'rest_url'      => esc_url_raw(rest_url('bcc-trust/v1/')),
                'current_page'  => $current_page,
                'fraudThresholds' => [
                    ['min' => BCC_TRUST_FRAUD_CRITICAL, 'color' => '#9c27b0'],
                    ['min' => BCC_TRUST_FRAUD_HIGH,     'color' => '#dc3545'],
                    ['min' => BCC_TRUST_FRAUD_MEDIUM,   'color' => '#fd7e14'],
                    ['min' => BCC_TRUST_FRAUD_LOW,      'color' => '#ffc107'],
                    ['min' => 0,                        'color' => '#28a745'],
                ],
                'highRiskThreshold' => BCC_TRUST_FRAUD_HIGH,
                'strings'       => [
                    'confirm_suspend'           => __('Are you sure you want to suspend this user?', 'bcc-trust'),
                    'confirm_unsuspend'         => __('Are you sure you want to unsuspend this user?', 'bcc-trust'),
                    'confirm_clear_votes'        => __('Are you sure you want to clear all votes? This cannot be undone.', 'bcc-trust'),
                    'confirm_clear_fingerprints' => __('Are you sure you want to clear all device fingerprints?', 'bcc-trust'),
                    'confirm_reanalyze'          => __('Reanalyze this user? This may take a moment.', 'bcc-trust'),
                    'confirm_bulk_suspend'       => __('Are you sure you want to suspend the selected users?', 'bcc-trust'),
                    'confirm_bulk_unsuspend'     => __('Are you sure you want to unsuspend the selected users?', 'bcc-trust'),
                    'confirm_bulk_clear_votes'   => __('Clear votes for selected users? This cannot be undone.', 'bcc-trust'),
                    'confirm_bulk_reanalyze'     => __('Reanalyze selected users? This may take a moment.', 'bcc-trust'),
                    'error'                      => __('An error occurred', 'bcc-trust'),
                    'success'                    => __('Success', 'bcc-trust'),
                    'loading'                    => __('Loading...', 'bcc-trust'),
                    'no_data'                    => __('No data available', 'bcc-trust'),
                    'select_items'               => __('Please select at least one item', 'bcc-trust'),
                    'tooltip_fraud_score'         => __('Higher score indicates higher risk', 'bcc-trust'),
                    'tooltip_confidence'          => __('Confidence level based on data volume', 'bcc-trust'),
                    'tooltip_vote_weight'         => __('How much this user\'s vote counts', 'bcc-trust'),
                ]
            ]
        );
    }
}
