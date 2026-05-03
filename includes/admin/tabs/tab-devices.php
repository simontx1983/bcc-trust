<?php
/**
 * Admin Dashboard — Devices Tab
 *
 * @package BCC\Trust\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_devices_tab() {
    $data        = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getDevicesData();
    $devices     = $data['devices'];
    $thresholds  = $data['thresholds'];
    ?>

    <div class="bcc-panel">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
            <h2 style="margin:0;">Device Fingerprints</h2>
            <span style="color:#666;font-size:13px;">
                High automation threshold: ≥<?php echo $thresholds['high']; ?>% &nbsp;|&nbsp;
                Suspicious: ≥<?php echo $thresholds['medium']; ?>%
            </span>
        </div>

        <?php if ( empty( $devices ) ): ?>
            <p>No device records found.</p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Fingerprint</th>
                    <th>User</th>
                    <th>Automation Score</th>
                    <th>Risk Level</th>
                    <th>Users on Device</th>
                    <th>First Seen</th>
                    <th>Last Seen</th>
                    <th>IP</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $devices as $d ):
                    $fp_short   = substr( $d->fingerprint, 0, 16 ) . '…';
                    $is_wl      = \BCC\Trust\Core\Plugin::instance()->moderationService()->isFingerprintWhitelisted( $d->fingerprint );
                    $risk_color = \BCC\Trust\Core\Support\Formatting::riskColor( $d->risk_level ?? 'unknown' );
                ?>
                    <tr>
                        <td>
                            <code style="font-size:11px;"><?php echo esc_html( $fp_short ); ?></code>
                            <?php if ( $is_wl ): ?>
                                <span style="background:#4caf50;color:#fff;font-size:10px;padding:1px 4px;border-radius:2px;margin-left:4px;">WL</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $d->user_id ): ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $d->user_id ) ); ?>">
                                    <?php echo esc_html( $d->display_name ?: 'User #' . $d->user_id ); ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <div style="width:60px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                    <div style="width:<?php echo esc_attr(intval($d->automation_score)); ?>%;height:100%;background:<?php echo esc_attr($d->automation_color ?? '#4caf50'); ?>;border-radius:4px;"></div>
                                </div>
                                <strong style="color:<?php echo esc_attr($d->automation_color ?? '#333'); ?>;"><?php echo intval($d->automation_score); ?>%</strong>
                                <?php if ( $d->automation_score >= $thresholds['high'] ): ?>
                                    <span style="color:#f44336;font-size:11px;font-weight:bold;">HIGH</span>
                                <?php elseif ( $d->automation_score >= $thresholds['medium'] ): ?>
                                    <span style="color:#ff9800;font-size:11px;font-weight:bold;">MED</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span style="padding:2px 6px;border-radius:3px;background:<?php echo esc_attr($risk_color); ?>;color:#fff;font-size:11px;">
                                <?php echo esc_html( ucfirst( $d->risk_level ?? 'unknown' ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo $d->user_count; ?>
                            <?php if ( $d->user_count >= BCC_TRUST_RING_MIN_SIZE ): ?>
                                <span style="color:#f44336;font-size:11px;font-weight:bold;margin-left:4px;">⚠ Ring?</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;"><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $d->first_seen ) ) ); ?></td>
                        <td style="font-size:12px;"><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $d->last_seen ) ) ); ?></td>
                        <td style="font-size:11px;">
                            <?php if ( ! empty( $d->ip_address ) ):
                                $ip = @inet_ntop( $d->ip_address );
                                if ( $ip ): ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&ip=' . urlencode( $ip ) ) ); ?>"><?php echo esc_html( $ip ); ?></a>
                                <?php endif;
                            endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&fingerprint=' . urlencode( $d->fingerprint ) ) ); ?>" class="button button-small">Investigate</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}