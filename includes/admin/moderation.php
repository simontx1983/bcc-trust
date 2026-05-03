<?php
/**
 * Trust Engine Moderation — Entry Point
 *
 * Registers the admin menu, routes requests to the correct view,
 * and defines shared colour-helper functions used across all mod views.
 *
 * Sub-files loaded here:
 *   moderation-list.php      – user list view
 *   moderation-user.php      – single-user detail view
 *   moderation-ip.php        – IP investigation view
 *   moderation-device.php    – device fingerprint investigation view
 *
 * @package BCC\Trust\Core
 * @version 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/moderation-list.php';
require_once __DIR__ . '/moderation-user.php';
require_once __DIR__ . '/moderation-ip.php';
require_once __DIR__ . '/moderation-device.php';
require_once __DIR__ . '/moderation-pages.php';

// ──────────────────────────────────────────────
// ADMIN-POST HOOKS (ring & bulk actions)
// ──────────────────────────────────────────────

add_action( 'admin_post_bcc_trust_ring_action', function () {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized', 403 ); }
    check_admin_referer( 'bcc_trust_ring_action' );
    $userIds = array_filter( array_map( 'absint', (array) ( $_POST['user_ids'] ?? [] ) ) );
    $action  = sanitize_text_field( $_POST['ring_action'] ?? '' );
    \BCC\Trust\Core\Plugin::instance()->moderationService()->handleRingAction( $userIds, $action );
} );

add_action( 'admin_post_bcc_trust_bulk_action', function () {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized', 403 ); }
    check_admin_referer( 'bcc_trust_bulk_action' );
    $userIds = array_filter( array_map( 'absint', (array) ( $_POST['user_ids'] ?? [] ) ) );
    $action  = sanitize_text_field( $_POST['bulk_action'] ?? '' );
    \BCC\Trust\Core\Plugin::instance()->moderationService()->handleBulkAction( $userIds, $action );
} );

// ──────────────────────────────────────────────
// MENU REGISTRATION
// ──────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_submenu_page(
        'bcc-trust-dashboard',
        'Moderation',
        'Moderation',
        'manage_options',
        'bcc-trust-moderation',
        'bcc_trust_render_moderation'
    );
} );

// ──────────────────────────────────────────────
// MAIN ROUTER
// ──────────────────────────────────────────────

function bcc_trust_render_moderation() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Sorry, you are not allowed to access this page.' ) );
    }

    // Handle POST actions (nonce verified before dispatch)
    $mod = \BCC\Trust\Core\Plugin::instance()->moderationService();

    $post_actions = [
        'suspend_user'       => static function () use ( $mod ) {
            $userId = absint( $_POST['user_id'] ?? 0 );
            $reason = sanitize_text_field( $_POST['suspend_reason'] ?? 'manual_suspension' );
            $notes  = sanitize_textarea_field( $_POST['suspend_notes'] ?? '' );
            $mod->suspendUser( $userId, $reason, $notes );
        },
        'unsuspend_user'     => static function () use ( $mod ) {
            $mod->unsuspendUser( absint( $_POST['user_id'] ?? 0 ) );
        },
        'clear_votes'        => static function () use ( $mod ) {
            $mod->clearVotes( absint( $_POST['user_id'] ?? 0 ) );
        },
        'clear_fingerprints' => static function () use ( $mod ) {
            $mod->clearFingerprints( absint( $_POST['user_id'] ?? 0 ) );
        },
        'reanalyze_user'     => static function () use ( $mod ) {
            $mod->reanalyzeUser( absint( $_POST['user_id'] ?? 0 ) );
        },
        'override_score'     => static function () use ( $mod ) {
            $userId   = absint( $_POST['user_id'] ?? 0 );
            $newScore = absint( $_POST['override_score_value'] ?? 0 );
            $reason   = sanitize_textarea_field( $_POST['override_reason'] ?? '' );
            $mod->overrideScore( $userId, $newScore, $reason );
        },
        'add_whitelist'      => static function () use ( $mod ) {
            $type  = sanitize_text_field( $_POST['whitelist_type'] ?? '' );
            $value = sanitize_text_field( $_POST['whitelist_value'] ?? '' );
            $mod->addToWhitelist( $type, $value );
        },
        'remove_whitelist'   => static function () use ( $mod ) {
            $type  = sanitize_text_field( $_POST['whitelist_type'] ?? '' );
            $value = sanitize_text_field( $_POST['whitelist_value'] ?? '' );
            $mod->removeFromWhitelist( $type, $value );
        },
    ];

    foreach ( $post_actions as $key => $handler ) {
        if ( isset( $_POST[ $key ] ) && check_admin_referer( 'bcc_trust_moderation' ) ) {
            $handler();
            break;
        }
    }

    // Route to correct view
    $user_id     = isset( $_GET['user_id'] )    ? absint( $_GET['user_id'] )              : 0;
    $ip          = isset( $_GET['ip'] )          ? sanitize_text_field( $_GET['ip'] )      : '';
    $fingerprint = isset( $_GET['fingerprint'] ) ? sanitize_text_field( $_GET['fingerprint'] ) : '';
    $tab         = isset( $_GET['tab'] )         ? sanitize_key( $_GET['tab'] )            : 'users';

    // Detail views bypass tabs
    if ( $user_id ) {
        bcc_trust_render_user_moderation( $user_id );
        return;
    }
    if ( $ip ) {
        bcc_trust_render_ip_moderation( $ip );
        return;
    }
    if ( $fingerprint ) {
        bcc_trust_render_fingerprint_moderation( $fingerprint );
        return;
    }

    // ── Tab navigation ──────────────────────────────────────────
    $base_url = admin_url( 'admin.php?page=bcc-trust-moderation' );
    ?>
    <div class="wrap bcc-admin-wrap">
        <h1 class="wp-heading-inline">Trust Moderation</h1>
        <nav class="nav-tab-wrapper" style="margin-bottom:15px;">
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'users', $base_url ) ); ?>"
               class="nav-tab <?php echo $tab === 'users' ? 'nav-tab-active' : ''; ?>">Users</a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'pages', $base_url ) ); ?>"
               class="nav-tab <?php echo $tab === 'pages' ? 'nav-tab-active' : ''; ?>">Pages</a>
        </nav>

    <?php
    if ( $tab === 'pages' ) {
        bcc_trust_render_page_list();
    } else {
        bcc_trust_render_user_list();
    }
    ?>
    </div>
    <?php
}

// ──────────────────────────────────────────────
// SCORE → TIER HELPER
// (All colour/tier helpers live in \BCC\Trust\Core\Support\Formatting)
// ──────────────────────────────────────────────

/**
 * Map a numeric trust score (0–100) to tier name, then return its info array.
 * Delegates to \BCC\Trust\Core\Support\Formatting::tierInfo( $tier_name ).
 *
 * @param  int|float $score
 * @return array{label:string, color:string, icon:string}
 */
function bcc_trust_get_tier_info_from_score( $score ): array {
    $score = (float) $score;
    if ( $score >= BCC_TRUST_TIER_ELITE )   $name = 'elite';
    elseif ( $score >= BCC_TRUST_TIER_TRUSTED ) $name = 'trusted';
    elseif ( $score >= BCC_TRUST_TIER_NEUTRAL ) $name = 'neutral';
    elseif ( $score >= BCC_TRUST_TIER_CAUTION ) $name = 'caution';
    else                                         $name = 'risky';

    return \BCC\Trust\Core\Support\Formatting::tierInfo( $name );
}