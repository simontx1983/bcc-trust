<?php
/**
 * Moderation — Device Fingerprint Investigation View
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_fingerprint_moderation( $fingerprint ) {
    $repo = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();

    $records = $repo->getFingerprintRecords( $fingerprint );

    if ( empty( $records ) ) {
        echo '<div class="notice notice-error"><p>Fingerprint not found.</p></div>';
        return;
    }

    $user_ids       = array_unique( array_filter( array_column( $records, 'user_id' ) ) );
    $user_count     = count( $user_ids );
    $auto_scores    = array_column( $records, 'automation_score' );
    $max_automation = $auto_scores ? max( $auto_scores ) : 0;

    $automation_high   = BCC_TRUST_AUTOMATION_HIGH;
    $automation_medium = BCC_TRUST_AUTOMATION_MEDIUM;
    $ring_min_size     = BCC_TRUST_RING_MIN_SIZE;

    $is_whitelisted = \BCC\Trust\Core\Plugin::instance()->moderationService()->isFingerprintWhitelisted( $fingerprint );

    // Risk level for the panel border
    $panel_color = $max_automation >= $automation_high
        ? '#f44336'
        : ( $user_count >= $ring_min_size ? '#ff9800' : '#ccd0d4' );
    ?>

    <div class="wrap bcc-admin-wrap">
        <h1>Device Fingerprint Investigation
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
                    <input type="hidden" name="whitelist_type" value="fingerprint">
                    <input type="hidden" name="whitelist_value" value="<?php echo esc_attr( $fingerprint ); ?>">
                    <input type="text" name="whitelist_note" placeholder="Whitelist reason…" style="width:200px;">
                    <button name="add_whitelist" class="button">Whitelist this Device</button>
                </form>
            <?php else: ?>
                <form method="post" style="display:inline;" action="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation' ) ); ?>">
                    <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                    <input type="hidden" name="user_id" value="0">
                    <input type="hidden" name="whitelist_type" value="fingerprint">
                    <input type="hidden" name="whitelist_value" value="<?php echo esc_attr( $fingerprint ); ?>">
                    <button name="remove_whitelist" class="button">Remove from Whitelist</button>
                </form>
            <?php endif; ?>
        </p>

        <!-- ── Fingerprint summary ─────────────────────────── -->
        <div class="bcc-panel" style="border-left:5px solid <?php echo esc_attr($panel_color); ?>;">
            <h2>Fingerprint Summary</h2>
            <table class="form-table" style="max-width:600px;">
                <tr>
                    <th>Fingerprint Hash</th>
                    <td><code><?php echo esc_html( $fingerprint ); ?></code></td>
                </tr>
                <tr>
                    <th>Associated Users</th>
                    <td>
                        <strong><?php echo intval($user_count); ?></strong>
                        <?php if ( $user_count >= $ring_min_size ): ?>
                            <span style="color:#f44336;font-weight:bold;margin-left:8px;">⚠ Possible Vote Ring</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Total Records</th>
                    <td><?php echo intval( count( $records ) ); ?></td>
                </tr>
                <tr>
                    <th>Max Automation Score</th>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:100px;height:10px;background:#eee;border-radius:5px;overflow:hidden;">
                                <div style="width:<?php echo esc_attr(intval($max_automation)); ?>%;height:100%;background:#f44336;"></div>
                            </div>
                            <strong><?php echo intval($max_automation); ?>%</strong>
                            <?php if ( $max_automation >= $automation_high ): ?>
                                <span style="color:#f44336;font-weight:bold;">HIGH AUTOMATION — Bot likely</span>
                            <?php elseif ( $max_automation >= $automation_medium ): ?>
                                <span style="color:#ff9800;font-weight:bold;">MEDIUM AUTOMATION — Suspicious</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ── Users on this device ────────────────────────── -->
        <div class="bcc-panel">
            <h2>Users on this Device (<?php echo intval($user_count); ?>)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>User</th><th>Fraud Score</th><th>Risk</th>
                    <th>Automation</th><th>First Seen</th><th>Last Seen</th>
                    <th>IP</th><th>Actions</th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $records as $r ): ?>
                        <tr>
                            <td>
                                <?php if ( $r->user_id ): ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $r->user_id ) ); ?>">
                                        <?php echo esc_html( $r->user_name ?: 'User #' . $r->user_id ); ?>
                                    </a>
                                <?php else: ?>Unknown<?php endif; ?>
                            </td>
                            <td><span style="color:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::scoreColor( $r->fraud_score ?: 0 ) ); ?>;font-weight:bold;"><?php echo intval($r->fraud_score ?: 0); ?></span></td>
                            <td><span style="padding:2px 6px;border-radius:3px;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::riskColor( $r->risk_level ?: 'unknown' ) ); ?>;color:#fff;"><?php echo esc_html( ucfirst( $r->risk_level ?: 'Unknown' ) ); ?></span></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div style="width:50px;height:6px;background:#eee;border-radius:3px;overflow:hidden;">
                                        <div style="width:<?php echo esc_attr(intval($r->automation_score)); ?>%;height:100%;background:#f44336;"></div>
                                    </div>
                                    <?php echo intval($r->automation_score); ?>%
                                </div>
                            </td>
                            <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $r->first_seen ) ) ); ?></td>
                            <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $r->last_seen ) ) ); ?></td>
                            <td>
                                <?php if ( $r->ip_address ):
                                    $ip = @inet_ntop( $r->ip_address );
                                    if ( $ip ): ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&ip=' . urlencode( $ip ) ) ); ?>"><?php echo esc_html( $ip ); ?></a>
                                    <?php else: ?>Invalid<?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $r->user_id ): ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $r->user_id ) ); ?>" class="button button-small">View User</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}