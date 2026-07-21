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

    // Chart.js CDN enqueue removed 2026-07-21 (admin-audit dead-endpoint
    // cleanup): the admin.js chart sections it fed were deleted with the
    // bcc-trust/v1 stats routes — dashboard tabs render server-side.

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
        $dependencies = ['jquery'];

        // Add jQuery UI tooltip if available
        if (wp_script_is('jquery-ui-tooltip', 'registered')) {
            $dependencies[] = 'jquery-ui-tooltip';
        }

        wp_enqueue_script(
            'bcc-trust-admin',
            $js_dir . 'admin.js',
            $dependencies,
            BCC_TRUST_VERSION,
            true
        );

        // Only the keys admin.js actually reads survive here — the
        // nonce/rest_url/threshold config fed the fraud-dashboard fetch
        // sections deleted 2026-07-21 with their stats routes.
        wp_localize_script(
            'bcc-trust-admin',
            'bccTrustAdmin',
            [
                'strings' => [
                    'confirm_suspend'            => __('Are you sure you want to suspend this user?', 'bcc-trust'),
                    'confirm_unsuspend'          => __('Are you sure you want to unsuspend this user?', 'bcc-trust'),
                    'confirm_clear_votes'        => __('Are you sure you want to clear all votes? This cannot be undone.', 'bcc-trust'),
                    'confirm_clear_fingerprints' => __('Are you sure you want to clear all device fingerprints?', 'bcc-trust'),
                    'confirm_reanalyze'          => __('Reanalyze this user? This may take a moment.', 'bcc-trust'),
                ],
            ]
        );
    }
}
