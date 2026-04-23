<?php
/**
 * BCC Trust Engine - Asset Enqueue
 * 
 * @package BCC_Trust_Engine
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
============================================================
FRONTEND ASSETS
============================================================
*/

add_action('wp_enqueue_scripts', 'bcc_trust_enqueue_frontend');

function bcc_trust_enqueue_frontend() {
    $js_dir = BCC_TRUST_URL . 'assets/js/';
    $css_dir = BCC_TRUST_URL . 'assets/css/';
    $js_path = BCC_TRUST_PATH . 'assets/js/';
    $css_path = BCC_TRUST_PATH . 'assets/css/';
    
    $fingerprint_loaded = false;
    
    // ======================================================
    // Device Fingerprinting (only on pages with widgets)
    // ======================================================
    if (bcc_trust_should_load_fingerprint()) {
        if (file_exists($js_path . 'fingerprint.js')) {
            wp_enqueue_script(
                'bcc-trust-fingerprint',
                $js_dir . 'fingerprint.js',
                [], // No dependencies
                BCC_TRUST_VERSION,
                true
            );
            $fingerprint_loaded = true;
        }
    }
    
    // ======================================================
    // Page Data Store (shared fetch for all blocks)
    // ======================================================
    if (file_exists($js_path . 'page-store.js')) {
        wp_enqueue_script(
            'bcc-page-store',
            $js_dir . 'page-store.js',
            [],
            BCC_TRUST_VERSION,
            true
        );

        wp_localize_script(
            'bcc-page-store',
            'bccPageData',
            [
                'endpoint' => esc_url_raw(rest_url('bcc/v1/page/')),
                'nonce'    => wp_create_nonce('wp_rest'),
            ]
        );
    }

    // ======================================================
    // Frontend Main JavaScript
    // ======================================================
    if (file_exists($js_path . 'trust-frontend.js')) {
        // Set up dependencies - only include fingerprint if it was loaded
        $dependencies = ['jquery'];
        if ($fingerprint_loaded) {
            $dependencies[] = 'bcc-trust-fingerprint';
        }
        
        wp_enqueue_script(
            'bcc-trust-frontend',
            $js_dir . 'trust-frontend.js',
            $dependencies,
            BCC_TRUST_VERSION,
            true
        );

        // Localize script with minimal data — avoid exposing user_id, debug
        // mode, or admin URLs to third-party scripts on the page.
        $bcc_trust_data = [
            'rest_url'            => esc_url_raw(rest_url('bcc-trust/v1/')),
            'logged_in'           => is_user_logged_in(),
            'login_url'           => wp_login_url(),
            'fingerprint_enabled' => bcc_trust_is_fingerprint_enabled() && $fingerprint_loaded,
        ];

        // Only expose a real REST nonce to authenticated users.
        // Logged-out users get an empty string so JS header reads
        // 'X-WP-Nonce': '' (WP ignores it, same as no nonce).
        $bcc_trust_data['nonce'] = is_user_logged_in()
            ? wp_create_nonce('wp_rest')
            : '';

        wp_localize_script(
            'bcc-trust-frontend',
            'bccTrust',
            $bcc_trust_data
        );
    }

    // ======================================================
    // Frontend CSS - Main Styles
    // ======================================================
    if (file_exists($css_path . 'trust-frontend.css')) {
        wp_enqueue_style(
            'bcc-trust-frontend',
            $css_dir . 'trust-frontend.css',
            [],
            BCC_TRUST_VERSION
        );

        // Dynamic CSS variables
        wp_add_inline_style(
            'bcc-trust-frontend',
            bcc_trust_get_dynamic_css()
        );
    }

    // ======================================================
    // Frontend CSS - Vote Specific Styles
    // ======================================================
    if (file_exists($css_path . 'trust-vote.css')) {
        wp_enqueue_style(
            'bcc-trust-vote',
            $css_dir . 'trust-vote.css',
            ['bcc-trust-frontend'], // Depends on main styles
            BCC_TRUST_VERSION
        );
    }
}

/**
 * Check if fingerprint should be loaded on this page
 */
function bcc_trust_should_load_fingerprint() {
    // Don't load in admin
    if (is_admin()) {
        return false;
    }
    
    // Check if there's a trust widget on the page
    if (is_singular()) {
        $post = get_post();
        if ($post && has_shortcode($post->post_content, 'bcc_trust')) {
            return true;
        }
    }
    
    // Load on PeepSo page views (not every page site-wide).
    if (class_exists('PeepSo') && is_singular()) {
        $post = get_post();
        if ($post && ($post->post_type === 'peepso-post' || has_block('bcc-trust/', $post) || has_shortcode($post->post_content, 'peepso_pages'))) {
            return true;
        }
    }

    // Check if any widget is active that might need it
    if (is_active_widget(false, false, 'bcc_trust_widget', true)) {
        return true;
    }
    
    // Allow filtering
    return apply_filters('bcc_trust_load_fingerprint', false);
}

/**
 * Check if fingerprinting is enabled
 */
function bcc_trust_is_fingerprint_enabled() {
    return apply_filters('bcc_trust_fingerprint_enabled', true);
}

/*
============================================================
ADMIN ASSETS
============================================================
*/

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
                // Fraud score thresholds for admin UI color coding.
                // Source of truth: includes/config/fraud-detection.php
                'fraudThresholds' => [
                    ['min' => BCC_TRUST_FRAUD_CRITICAL, 'color' => '#9c27b0'],
                    ['min' => BCC_TRUST_FRAUD_HIGH,     'color' => '#dc3545'],
                    ['min' => BCC_TRUST_FRAUD_MEDIUM,   'color' => '#fd7e14'],
                    ['min' => BCC_TRUST_FRAUD_LOW,      'color' => '#ffc107'],
                    ['min' => 0,                        'color' => '#28a745'],
                ],
                'highRiskThreshold' => BCC_TRUST_FRAUD_HIGH,
                'strings'       => [
                    // Confirmation messages
                    'confirm_suspend'           => __('Are you sure you want to suspend this user?', 'bcc-trust'),
                    'confirm_unsuspend'         => __('Are you sure you want to unsuspend this user?', 'bcc-trust'),
                    'confirm_clear_votes'        => __('Are you sure you want to clear all votes? This cannot be undone.', 'bcc-trust'),
                    'confirm_clear_fingerprints' => __('Are you sure you want to clear all device fingerprints?', 'bcc-trust'),
                    'confirm_reanalyze'          => __('Reanalyze this user? This may take a moment.', 'bcc-trust'),
                    'confirm_bulk_suspend'       => __('Are you sure you want to suspend the selected users?', 'bcc-trust'),
                    'confirm_bulk_unsuspend'     => __('Are you sure you want to unsuspend the selected users?', 'bcc-trust'),
                    'confirm_bulk_clear_votes'   => __('Clear votes for selected users? This cannot be undone.', 'bcc-trust'),
                    'confirm_bulk_reanalyze'     => __('Reanalyze selected users? This may take a moment.', 'bcc-trust'),
                    
                    // Status messages
                    'error'                      => __('An error occurred', 'bcc-trust'),
                    'success'                    => __('Success', 'bcc-trust'),
                    'loading'                    => __('Loading...', 'bcc-trust'),
                    'no_data'                    => __('No data available', 'bcc-trust'),
                    'select_items'               => __('Please select at least one item', 'bcc-trust'),
                    
                    // Tooltips
                    'tooltip_fraud_score'         => __('Higher score indicates higher risk', 'bcc-trust'),
                    'tooltip_confidence'          => __('Confidence level based on data volume', 'bcc-trust'),
                    'tooltip_vote_weight'         => __('How much this user\'s vote counts', 'bcc-trust'),
                ]
            ]
        );
    }

}

/*
============================================================
DYNAMIC CSS
============================================================
*/

function bcc_trust_get_dynamic_css() {
    $cached = get_transient( 'bcc_trust_dynamic_css' );
    if ( $cached !== false ) {
        return $cached;
    }

    // Sanitize color options to prevent stored XSS via CSS injection.
    $sanitize_color = function ( $value, $default ) {
        $value = sanitize_hex_color( $value );
        return $value ? $value : $default;
    };

    $primary_color  = $sanitize_color( get_option( 'bcc_trust_primary_color',  '#2196f3' ), '#2196f3' );
    $success_color  = $sanitize_color( get_option( 'bcc_trust_success_color',  '#4caf50' ), '#4caf50' );
    $warning_color  = $sanitize_color( get_option( 'bcc_trust_warning_color',  '#ff9800' ), '#ff9800' );
    $error_color    = $sanitize_color( get_option( 'bcc_trust_error_color',    '#f44336' ), '#f44336' );
    $neutral_color  = $sanitize_color( get_option( 'bcc_trust_neutral_color',  '#9e9e9e' ), '#9e9e9e' );
    $critical_color = $sanitize_color( get_option( 'bcc_trust_critical_color', '#9c27b0' ), '#9c27b0' );
    $info_color     = $sanitize_color( get_option( 'bcc_trust_info_color',     '#00bcd4' ), '#00bcd4' );

    $css = "
        :root {
            --bcc-primary: {$primary_color};
            --bcc-success: {$success_color};
            --bcc-warning: {$warning_color};
            --bcc-error: {$error_color};
            --bcc-neutral: {$neutral_color};
            --bcc-critical: {$critical_color};
            --bcc-info: {$info_color};

            --bcc-tier-elite: #ffd700;
            --bcc-tier-trusted: #4caf50;
            --bcc-tier-neutral: #9e9e9e;
            --bcc-tier-caution: #ff9800;
            --bcc-tier-risky: #f44336;

            --bcc-risk-critical: #9c27b0;
            --bcc-risk-high: #f44336;
            --bcc-risk-medium: #ff9800;
            --bcc-risk-low: #2196f3;
            --bcc-risk-minimal: #4caf50;
        }

        .bcc-tier-elite { color: var(--bcc-tier-elite); }
        .bcc-tier-trusted { color: var(--bcc-tier-trusted); }
        .bcc-tier-neutral { color: var(--bcc-tier-neutral); }
        .bcc-tier-caution { color: var(--bcc-tier-caution); }
        .bcc-tier-risky { color: var(--bcc-tier-risky); }

        .bcc-risk-critical { color: var(--bcc-risk-critical); }
        .bcc-risk-high { color: var(--bcc-risk-high); }
        .bcc-risk-medium { color: var(--bcc-risk-medium); }
        .bcc-risk-low { color: var(--bcc-risk-low); }
        .bcc-risk-minimal { color: var(--bcc-risk-minimal); }
    ";

    set_transient( 'bcc_trust_dynamic_css', $css, 12 * HOUR_IN_SECONDS );

    return $css;
}

// Bust CSS cache whenever a color option is saved.
add_action( 'updated_option', function ( $option_name ) {
    if ( strpos( $option_name, 'bcc_trust_' ) === 0 && strpos( $option_name, '_color' ) !== false ) {
        delete_transient( 'bcc_trust_dynamic_css' );
    }
} );

/*
============================================================
CONDITIONAL LOADING
============================================================
*/

/**
 * Check if trust widget should be loaded on current page
 */
add_filter('bcc_trust_should_load', 'bcc_trust_check_page_for_widget');

function bcc_trust_check_page_for_widget($should_load) {
    if ($should_load) {
        return true;
    }
    
    // Check if current post has the shortcode
    if (is_singular()) {
        $post = get_post();
        if ($post && has_shortcode($post->post_content, 'bcc_trust')) {
            return true;
        }
    }
    
    // Only load on PeepSo page/post types — not site-wide.
    if (class_exists('PeepSo') && is_singular()) {
        $post = get_post();
        if ($post && in_array($post->post_type, ['peepso-page', 'peepso-post'], true)) {
            return true;
        }
    }
    
    return $should_load;
}