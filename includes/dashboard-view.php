<?php
/**
 * Trust Engine Admin Dashboard — Navigation & Tab Dispatcher
 *
 * Each tab lives in its own file under includes/admin/tabs/.
 * This file only renders the nav bar and delegates to the right tab.
 *
 * Layout (two-engineer audit MEDIUM item #5 — 2026-05-27):
 *   The original 13-tab strip was past the comfortable visual-nav limit.
 *   Tabs are now grouped into 6 logical clusters; clusters with multiple
 *   sub-views render a second-level sub-nav. Legacy tab slugs continue
 *   to resolve via $legacy_alias so external bookmarks don't break.
 *
 *   Overview                   single page
 *   Ecosystem                  single page (load-bearing trust-review)
 *   Pages    → Top / All       merged top-pages + all-pages
 *   Users    → Trust / Verified  merged user-trust + verified
 *   Signals  → Fraud / Devices / Rings / ML
 *   Activity → Log / Push
 *
 * @package BCC\Trust\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Load tab files ────────────────────────────────────────────
$tabs_dir = BCC_TRUST_PATH . 'includes/admin/tabs/';
require_once $tabs_dir . 'tab-overview.php';
require_once $tabs_dir . 'tab-ecosystem.php';
require_once $tabs_dir . 'tab-pages.php';
require_once $tabs_dir . 'tab-users.php';
require_once $tabs_dir . 'tab-activity.php';
require_once $tabs_dir . 'tab-fraud.php';
require_once $tabs_dir . 'tab-devices.php';
require_once $tabs_dir . 'tab-rings.php';
require_once $tabs_dir . 'tab-ml.php';
require_once $tabs_dir . 'tab-push.php';
require_once BCC_TRUST_PATH . 'includes/admin/dashboard-verified.php';
// Note: tab-repair.php is no longer required here — Repair was moved
// out of the Dashboard tab nav to "BCC System → Repair" and is
// loaded from bcc-trust.php at module boot.

// ── Cluster definitions ───────────────────────────────────────
//
// `subs` => null means the cluster is a single page (no second-level
// nav). `default_sub` is the sub-slug used when `?tab=<cluster>` has
// no `&sub=` param.
$clusters = [
    'overview' => [
        'label'  => 'Overview',
        'subs'   => null,
        'render' => 'bcc_trust_render_overview_tab',
    ],
    'ecosystem' => [
        'label'  => 'Ecosystem',
        'subs'   => null,
        'render' => 'bcc_trust_render_ecosystem_tab',
    ],
    'pages' => [
        'label'       => 'Pages',
        'default_sub' => 'top',
        'subs'        => [
            'top' => [ 'label' => 'Top',  'render' => 'bcc_trust_render_pages_tab' ],
            'all' => [ 'label' => 'All',  'render' => 'bcc_trust_render_all_pages_tab' ],
        ],
    ],
    'users' => [
        'label'       => 'Users',
        'default_sub' => 'trust',
        'subs'        => [
            'trust'    => [ 'label' => 'User Trust',  'render' => 'bcc_trust_render_users_tab' ],
            'verified' => [ 'label' => '✓ Verified', 'render' => 'bcc_trust_render_verified_tab', 'highlight' => '#e6f3e6' ],
        ],
    ],
    'signals' => [
        'label'       => 'Signals',
        'default_sub' => 'fraud',
        'subs'        => [
            'fraud'   => [ 'label' => 'Fraud Detection', 'render' => 'bcc_trust_render_fraud_tab' ],
            'devices' => [ 'label' => 'Devices',         'render' => 'bcc_trust_render_devices_tab' ],
            'rings'   => [ 'label' => 'Vote Rings',      'render' => 'bcc_trust_render_rings_tab' ],
            'ml'      => [ 'label' => 'ML Insights',     'render' => 'bcc_trust_render_ml_tab' ],
        ],
    ],
    'activity' => [
        'label'       => 'Activity',
        'default_sub' => 'log',
        'subs'        => [
            'log'  => [ 'label' => 'Activity Log', 'render' => 'bcc_trust_render_activity_tab' ],
            'push' => [ 'label' => 'Push Stats',    'render' => 'bcc_trust_render_push_tab' ],
        ],
    ],
];

// ── Legacy slug aliasing ──────────────────────────────────────
// Older URLs and external bookmarks used flat tab slugs. Map each
// legacy slug to its (cluster, sub) pair so old links continue to
// render the correct view. Where the legacy slug matches a current
// cluster slug ('pages', 'users', 'activity'), the alias is a no-op
// (the cluster's default_sub handles it).
$legacy_alias = [
    'all-pages' => [ 'cluster' => 'pages',    'sub' => 'all' ],
    'verified'  => [ 'cluster' => 'users',    'sub' => 'verified' ],
    'fraud'     => [ 'cluster' => 'signals',  'sub' => 'fraud' ],
    'devices'   => [ 'cluster' => 'signals',  'sub' => 'devices' ],
    'rings'     => [ 'cluster' => 'signals',  'sub' => 'rings' ],
    'ml'        => [ 'cluster' => 'signals',  'sub' => 'ml' ],
    'push'      => [ 'cluster' => 'activity', 'sub' => 'push' ],
];

// ── Resolve requested cluster + sub ───────────────────────────
$requested_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
$requested_sub = isset( $_GET['sub'] ) ? sanitize_key( $_GET['sub'] ) : '';

if ( isset( $legacy_alias[ $requested_tab ] ) ) {
    $current_cluster = $legacy_alias[ $requested_tab ]['cluster'];
    $current_sub     = $legacy_alias[ $requested_tab ]['sub'];
} elseif ( isset( $clusters[ $requested_tab ] ) ) {
    $current_cluster = $requested_tab;
    $cluster_subs    = $clusters[ $current_cluster ]['subs'] ?? null;
    if ( is_array( $cluster_subs ) && isset( $cluster_subs[ $requested_sub ] ) ) {
        $current_sub = $requested_sub;
    } else {
        $current_sub = $clusters[ $current_cluster ]['default_sub'] ?? '';
    }
} else {
    $current_cluster = 'overview';
    $current_sub     = '';
}

// Helper: build a URL for a (cluster, sub) pair. Single-page clusters
// omit the &sub= param so the URL stays clean for shareable links.
$build_url = static function ( string $cluster, string $sub = '' ) use ( $clusters ): string {
    $args = [ 'page' => 'bcc-trust-dashboard', 'tab' => $cluster ];
    if ( $sub !== '' && is_array( $clusters[ $cluster ]['subs'] ?? null ) ) {
        $args['sub'] = $sub;
    }
    return esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) );
};
?>

<nav class="nav-tab-wrapper">
    <?php foreach ( $clusters as $cluster_slug => $cluster ): ?>
        <a href="<?php echo $build_url( $cluster_slug ); ?>"
           class="nav-tab <?php echo $current_cluster === $cluster_slug ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html( $cluster['label'] ); ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php
// Sub-nav: rendered only when the current cluster has multiple subs.
$current_cluster_subs = $clusters[ $current_cluster ]['subs'] ?? null;
if ( is_array( $current_cluster_subs ) && count( $current_cluster_subs ) > 1 ):
?>
    <div class="bcc-subnav" style="margin:8px 0 0 0;padding:4px 0;border-bottom:1px solid #c3c4c7;">
        <?php foreach ( $current_cluster_subs as $sub_slug => $sub ):
            $is_active = $current_sub === $sub_slug;
            $highlight = $sub['highlight'] ?? null;
            $style     = $highlight ? ' style="background:' . esc_attr( $highlight ) . ';"' : '';
        ?>
            <a href="<?php echo $build_url( $current_cluster, $sub_slug ); ?>"
               class="button <?php echo $is_active ? 'button-primary' : ''; ?>"
               style="margin-right:4px;<?php echo $highlight && ! $is_active ? 'background:' . esc_attr( $highlight ) . ';' : ''; ?>">
                <?php echo esc_html( $sub['label'] ); ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="tab-content" style="padding-top:14px;">
    <?php
    // Dispatch to the right render function. Single-page clusters use
    // the cluster-level `render` key; multi-sub clusters look up the
    // sub-level `render`.
    if ( ! is_array( $current_cluster_subs ) ) {
        $render_fn = $clusters[ $current_cluster ]['render'] ?? 'bcc_trust_render_overview_tab';
    } else {
        $render_fn = $current_cluster_subs[ $current_sub ]['render']
            ?? $current_cluster_subs[ $clusters[ $current_cluster ]['default_sub'] ]['render']
            ?? 'bcc_trust_render_overview_tab';
    }

    if ( is_string( $render_fn ) && function_exists( $render_fn ) ) {
        $render_fn();
    } else {
        bcc_trust_render_overview_tab();
    }
    ?>
</div>
