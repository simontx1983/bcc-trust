<?php
/**
 * Moderation — IP Investigation View
 *
 * @package BCC\Trust\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_ip_moderation( $ip ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }
    $ip_bin = @inet_pton( $ip );
    if ( $ip_bin === false ) {
        echo '<div class="notice notice-error"><p>Invalid IP address format.</p></div>';
        return;
    }

    $repo = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();

    $automation_high   = BCC_TRUST_AUTOMATION_HIGH;
    $automation_medium = BCC_TRUST_AUTOMATION_MEDIUM;

    $activities   = $repo->getActivitiesByIp( $ip_bin );
    $users        = $repo->getUsersByIp( $ip_bin );
    $fingerprints = $repo->getFingerprintsByIp( $ip_bin );
    $recent_votes = $repo->getVotesByIp( $ip_bin );

    $is_whitelisted = \BCC\Trust\Core\Plugin::instance()->moderationService()->isIpWhitelisted( $ip );
    ?>

    <div class="wrap bcc-admin-wrap">
        <h1>IP Investigation: <code><?php echo esc_html( $ip ); ?></code>
            <?php if ( $is_whitelisted ): ?>
                <span style="background:#4caf50;color:#fff;font-size:13px;padding:3px 10px;border-radius:3px;margin-left:8px;">✓ Whitelisted</span>
            <?php endif; ?>
        </h1>

        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation' ) ); ?>" class="button">&larr; Back</a>

            <?php if ( ! $is_whitelisted ): ?>
                <form method="post" style="display:inline;" action="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation' ) ); ?>">
                    <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                    <input type="hidden" name="user_id" value="0">
                    <input type="hidden" name="whitelist_type" value="ip">
                    <input type="hidden" name="whitelist_value" value="<?php echo esc_attr( $ip ); ?>">
                    <input type="text" name="whitelist_note" placeholder="Whitelist reason…" style="width:200px;">
                    <button name="add_whitelist" class="button">Whitelist this IP</button>
                </form>
            <?php else: ?>
                <form method="post" style="display:inline;" action="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation' ) ); ?>">
                    <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                    <input type="hidden" name="user_id" value="0">
                    <input type="hidden" name="whitelist_type" value="ip">
                    <input type="hidden" name="whitelist_value" value="<?php echo esc_attr( $ip ); ?>">
                    <button name="remove_whitelist" class="button">Remove from Whitelist</button>
                </form>
            <?php endif; ?>
        </p>

        <!-- ── Summary stats ──────────────────────────────── -->
        <div class="bcc-panel">
            <h2>IP Summary</h2>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;">
                <?php foreach ( [
                    'Total Activities' => count( $activities ),
                    'Unique Users'     => count( $users ),
                    'Unique Devices'   => count( $fingerprints ),
                    'Votes Cast'       => count( $recent_votes ),
                ] as $label => $val ): ?>
                    <div style="text-align:center;">
                        <div style="font-size:30px;font-weight:bold;"><?php echo esc_html($val); ?></div>
                        <div style="color:#666;font-size:12px;"><?php echo esc_html( $label ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Users from this IP ─────────────────────────── -->
        <?php if ( ! empty( $users ) ): ?>
        <div class="bcc-panel">
            <h2>Users from this IP (<?php echo count( $users ); ?>)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>User</th><th>Activity</th><th>Fraud Score</th>
                    <th>Risk</th><th>Suspended</th><th>Last Seen</th><th>Action</th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $users as $u ): ?>
                        <tr>
                            <td><strong><?php echo esc_html( $u->display_name ?: $u->user_login ); ?></strong><br><small>ID: <?php echo intval($u->user_id); ?></small></td>
                            <td><?php echo intval($u->activity_count); ?></td>
                            <td><span style="color:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::scoreColor( $u->fraud_score ?: 0 ) ); ?>;font-weight:bold;"><?php echo intval($u->fraud_score ?: 0); ?></span></td>
                            <td><span style="padding:2px 6px;border-radius:3px;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::riskColor( $u->risk_level ?: 'unknown' ) ); ?>;color:#fff;"><?php echo esc_html( ucfirst( $u->risk_level ?: 'Unknown' ) ); ?></span></td>
                            <td><?php echo $u->is_suspended ? '✓' : '✗'; ?></td>
                            <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $u->last_seen ) ) ); ?></td>
                            <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $u->user_id ) ); ?>" class="button button-small">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── Devices from this IP ────────────────────────── -->
        <?php if ( ! empty( $fingerprints ) ): ?>
        <div class="bcc-panel">
            <h2>Devices from this IP</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Fingerprint</th><th>Devices</th><th>Max Automation</th>
                    <th>Risk</th><th>Last Seen</th><th>Action</th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $fingerprints as $fp ): ?>
                        <tr>
                            <td><code><?php echo esc_html( substr( $fp->fingerprint, 0, 16 ) ); ?>…</code></td>
                            <td><?php echo intval($fp->device_count); ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div style="width:50px;height:6px;background:#eee;border-radius:3px;overflow:hidden;">
                                        <div style="width:<?php echo esc_attr(intval($fp->max_automation)); ?>%;height:100%;background:#f44336;"></div>
                                    </div>
                                    <?php echo intval($fp->max_automation); ?>%
                                    <?php if ( $fp->max_automation >= $automation_high ): ?>
                                        <span style="color:#f44336;font-size:11px;">HIGH</span>
                                    <?php elseif ( $fp->max_automation >= $automation_medium ): ?>
                                        <span style="color:#ff9800;font-size:11px;">MED</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><span style="padding:2px 6px;border-radius:3px;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::riskColor( $fp->risk_level ) ); ?>;color:#fff;"><?php echo esc_html( $fp->risk_level ?: 'unknown' ); ?></span></td>
                            <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $fp->last_seen ) ) ); ?></td>
                            <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&fingerprint=' . urlencode( $fp->fingerprint ) ) ); ?>" class="button button-small">Investigate</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── Votes from this IP ──────────────────────────── -->
        <?php if ( ! empty( $recent_votes ) ): ?>
        <div class="bcc-panel">
            <h2>Recent Votes from this IP</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Time</th><th>Voter</th><th>Page</th><th>Type</th><th>Weight</th></tr></thead>
                <tbody>
                    <?php foreach ( $recent_votes as $v ): ?>
                        <tr>
                            <td><?php echo esc_html( $v->created_at ); ?></td>
                            <td><?php if ( $v->voter_name ): ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $v->voter_user_id ) ); ?>"><?php echo esc_html( $v->voter_name ); ?></a>
                                <?php else: ?>User #<?php echo intval($v->voter_user_id); ?><?php endif; ?></td>
                            <td><?php if ( $v->page_title ): ?>
                                    <a href="<?php echo esc_url( get_permalink( $v->page_id ) ); ?>" target="_blank"><?php echo esc_html( $v->page_title ); ?></a>
                                <?php else: ?>Page #<?php echo intval($v->page_id); ?><?php endif; ?></td>
                            <td><?php echo $v->vote_type > 0 ? '⬆ Up' : '⬇ Down'; ?></td>
                            <td><?php echo intval($v->weight); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── Full activity log ──────────────────────────── -->
        <div class="bcc-panel">
            <h2>Activity Log from this IP</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Time</th><th>Action</th><th>User</th><th>Target</th></tr></thead>
                <tbody>
                    <?php foreach ( $activities as $act ): ?>
                        <tr>
                            <td><?php echo esc_html( $act->created_at ); ?></td>
                            <td><?php echo esc_html( $act->action ); ?></td>
                            <td><?php if ( $act->user_id ): ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $act->user_id ) ); ?>"><?php echo esc_html( $act->display_name ?: $act->user_login ?: 'User #' . $act->user_id ); ?></a>
                                <?php else: ?>—<?php endif; ?></td>
                            <td><?php if ( $act->target_id && $act->target_type === 'page' ): ?>
                                    <a href="<?php echo esc_url( get_permalink( $act->target_id ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $act->target_id ) ?: 'Page #' . $act->target_id ); ?></a>
                                <?php elseif ( $act->target_id ): ?>
                                    <?php echo esc_html( $act->target_type . ' #' . $act->target_id ); ?>
                                <?php else: ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}