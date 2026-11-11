<?php
/**
 * Trust Engine Admin Dashboard - Menu Registration
 *
 * @package BCC\Trust\Core
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register admin menu
 */
add_action('admin_menu', function () {
    // Was the "Trust Engine" top-level menu; now the Trust Analytics item
    // under BCC Moderation. Slug unchanged.
    add_submenu_page(
        \BCC\Trust\Core\Admin\AdminMenus::MODERATION,
        'Trust Analytics',
        'Trust Analytics',
        'manage_options',
        'bcc-trust-dashboard',
        'bcc_trust_render_dashboard',
        \BCC\Trust\Core\Admin\AdminMenus::ORDER_ANALYTICS
    );
});

/**
 * Main dashboard render function - delegates to dashboard-view.php
 */
function bcc_trust_render_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sorry, you are not allowed to access this page.'));
    }

    echo '<div class="wrap bcc-admin-wrap">';
    echo '<h1>Trust Analytics</h1>';

    // Load the view which contains all the tab rendering
    $view_file = BCC_TRUST_PATH . 'includes/dashboard-view.php';
    if (file_exists($view_file)) {
        include $view_file;
    } else {
        wp_die('Dashboard view file not found. Please reinstall the BCC Trust Engine plugin.');
    }

    echo '</div>'; // close .wrap
}