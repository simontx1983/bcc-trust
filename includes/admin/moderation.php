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

/**
 * Vote-ring actions: invalidate a ring's votes, or suspend every member.
 *
 * ── ⚠⚠ MEMBERSHIP IS RESOLVED SERVER-SIDE, NOT SUBMITTED ───────────────
 * This used to read the ids straight out of $_POST['user_ids'], which the
 * tab posted as a hidden field. Two things were wrong with that, and both
 * became reachable the moment a ring snapshot was actually persisted:
 *
 *  1. ⚠⚠ THE RING WAS SILENTLY TRUNCATED TO ITS FIRST MEMBER. The form
 *     posted a COMMA-SEPARATED STRING ("11,12,13") and this cast it with
 *     `(array)`, producing `['11,12,13']`; absint() of that whole string is
 *     11. So "Suspend ALL 3 members" suspended exactly ONE, and
 *     invalidate-votes touched one member's votes. Silently — the redirect
 *     reported `suspended_1` and nobody reads that as a failure.
 *
 *  2. The set of users to act on arrived from the browser. The capability
 *     check means only an administrator can reach this, so it was never a
 *     privilege escalation — an admin can already suspend anyone. But it
 *     meant the reason recorded against the suspension (`vote_ring`) and the
 *     audit row (`ring_users`) were claims nothing verified.
 *
 * The form now posts only a FINGERPRINT of the ring, and the members come
 * from the stored snapshot. Membership is therefore never submitted and
 * never has to be trusted; a fingerprint that no longer matches a detected
 * ring refuses the action instead of falling back to anything.
 *
 * ⚠ POST ONLY. admin-post.php dispatches on $_REQUEST, so this fired for a
 * GET as happily as a POST. A nonce in a URL is a normal WordPress pattern
 * for a link, but these two actions suspend accounts and soft-delete votes;
 * a destructive action should not be reachable by anything that can be
 * prefetched, bookmarked, or logged in a referrer.
 */
add_action( 'admin_post_bcc_trust_ring_action', function () {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized', 403 ); }
    if ( strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
        wp_die( 'This action requires a POST request.', 405 );
    }
    check_admin_referer( 'bcc_trust_ring_action' );

    $action      = sanitize_text_field( $_POST['ring_action'] ?? '' );
    $fingerprint = sanitize_text_field( $_POST['ring_fingerprint'] ?? '' );
    $ring        = \BCC\Trust\Core\Support\RingSnapshot::findRingByFingerprint( $fingerprint );

    if ( $ring === null ) {
        // No snapshot, no matching ring, or a page older than the snapshot.
        // Nothing is acted on, and the operator is told which it was.
        // ⚠ BCC\Core\Log\Logger, not BCC\Trust\Core\Log\Logger. The Logger
        // lives in the bcc-core plugin, and the Trust-namespaced class does
        // not exist — so the first version of this line would have fatalled
        // on the refusal path, which is the SAFETY path.
        //
        // ⚠ Nothing caught it: phpstan.neon analyses `app/` only, so no file
        // under includes/ is type-checked at all, and a class that does not
        // exist is invisible here.
        \BCC\Core\Log\Logger::warning( '[bcc-trust] ring action refused: no matching ring', [
            'action'      => $action,
            'fingerprint' => $fingerprint,
        ] );
        wp_safe_redirect( add_query_arg(
            [ 'tab' => 'rings', 'ring_msg' => 'ring_not_found' ],
            admin_url( 'admin.php?page=bcc-trust-dashboard' )
        ) );
        exit;
    }

    $userIds = array_values( array_filter( array_map( 'absint', $ring['users'] ) ) );
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
 * Resolve the display info array for a page's tier.
 *
 * Prefers the STORED reputation_tier when the caller has it. Since the §J.12
 * elite gate, a tier can no longer be derived from a score alone — `elite`
 * additionally requires the cross-table eligibility flag and a native-conduct
 * floor, neither of which is visible from a bare number. Re-deriving here
 * would show `Elite` in the admin table for a page the engine has gated down
 * to `Trusted`.
 *
 * The score-only fallback remains for callers whose query genuinely has no
 * tier column; it is an APPROXIMATION that assumes the gate passes, and will
 * over-report elite. Pass $stored_tier wherever it is available.
 *
 * Delegates to \BCC\Trust\Core\Support\Formatting::tierInfo( $tier_name ).
 *
 * @param  int|float   $score
 * @param  string|null $stored_tier The row's reputation_tier column, if present.
 * @return array{label:string, color:string, icon:string}
 */
function bcc_trust_get_tier_info_from_score( $score, ?string $stored_tier = null ): array {
    if ( is_string( $stored_tier ) && $stored_tier !== '' ) {
        return \BCC\Trust\Core\Support\Formatting::tierInfo( $stored_tier );
    }

    $score = (float) $score;
    $name  = \BCC\Trust\Core\Services\TrustScoreService::tierFor( $score, $score, true );

    return \BCC\Trust\Core\Support\Formatting::tierInfo( $name );
}