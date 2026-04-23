<?php
/**
 * Trust Engine Admin Dashboard — Navigation & Tab Dispatcher
 *
 * Each tab lives in its own file under includes/admin/tabs/.
 * This file only renders the nav bar and delegates to the right tab.
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Load tab files ────────────────────────────────────────────
$tabs_dir = BCC_TRUST_PATH . 'includes/admin/tabs/';
require_once $tabs_dir . 'tab-overview.php';
require_once $tabs_dir . 'tab-pages.php';
require_once $tabs_dir . 'tab-users.php';
require_once $tabs_dir . 'tab-activity.php';
require_once $tabs_dir . 'tab-fraud.php';
require_once $tabs_dir . 'tab-devices.php';
require_once $tabs_dir . 'tab-rings.php';
require_once $tabs_dir . 'tab-ml.php';
require_once $tabs_dir . 'tab-repair.php';
require_once BCC_TRUST_PATH . 'includes/admin/dashboard-verified.php';

$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';

// ── Tab definitions ───────────────────────────────────────────
$tabs = [
    'overview'  => 'Overview',
    'pages'     => 'Top Pages',
    'all-pages' => 'All Pages',
    'users'     => 'User Trust',
    'verified'  => '✓ Verified',
    'activity'  => 'Activity Log',
    'fraud'     => 'Fraud Detection',
    'devices'   => 'Devices',
    'rings'     => 'Vote Rings',
    'ml'        => 'ML Insights',
    'repair'    => '🔧 Repair',
];

// Highlight tabs that need attention
$attention_tabs = [ 'repair' => '#f0f8ff', 'verified' => '#e6f3e6' ];
?>

<nav class="nav-tab-wrapper">
    <?php foreach ( $tabs as $slug => $label ):
        $active = $current_tab === $slug ? 'nav-tab-active' : '';
        $style  = isset( $attention_tabs[ $slug ] ) ? ' style="background:' . $attention_tabs[ $slug ] . ';"' : '';
        $url    = esc_url( admin_url( 'admin.php?page=bcc-trust-dashboard&tab=' . $slug ) );
    ?>
        <a href="<?php echo $url; ?>" class="nav-tab <?php echo $active; ?>"<?php echo $style; ?>>
            <?php echo esc_html( $label ); ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="tab-content" style="padding-top:10px;">
    <?php
    switch ( $current_tab ) {
        case 'pages':     bcc_trust_render_pages_tab();     break;
        case 'all-pages': bcc_trust_render_all_pages_tab(); break;
        case 'users':     bcc_trust_render_users_tab();     break;
        case 'verified':  bcc_trust_render_verified_tab();  break;
        case 'activity':  bcc_trust_render_activity_tab();  break;
        case 'fraud':     bcc_trust_render_fraud_tab();     break;
        case 'devices':   bcc_trust_render_devices_tab();   break;
        case 'rings':     bcc_trust_render_rings_tab();     break;
        case 'ml':        bcc_trust_render_ml_tab();        break;
        case 'repair':    bcc_trust_render_repair_tab();    break;
        default:          bcc_trust_render_overview_tab();  break;
    }
    ?>
</div>