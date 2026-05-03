<?php
/**
 * Admin Dashboard — Fraud Detection Tab
 *
 * @package BCC\Trust\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_fraud_tab() {
    $data  = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getFraudData();
    $users = $data['fraud_users'];
    ?>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
        <div class="bcc-panel" style="text-align:center;border-left:5px solid #f44336;">
            <div style="font-size:32px;font-weight:bold;color:#f44336;"><?php echo intval($data['critical_count']); ?></div>
            <div style="font-size:13px;color:#666;">Critical Risk Users</div>
        </div>
        <div class="bcc-panel" style="text-align:center;border-left:5px solid #ff9800;">
            <div style="font-size:32px;font-weight:bold;color:#ff9800;"><?php echo intval($data['total']); ?></div>
            <div style="font-size:13px;color:#666;">High-Risk Users (≥<?php echo intval($data['high_risk_threshold']); ?>)</div>
        </div>
        <div class="bcc-panel" style="text-align:center;border-left:5px solid #2196f3;">
            <div style="font-size:32px;font-weight:bold;color:#2196f3;"><?php echo intval($data['total']); ?></div>
            <div style="font-size:13px;color:#666;">Showing (limit <?php echo intval($data['limit']); ?>)</div>
        </div>
    </div>

    <div class="bcc-panel">
        <h2>High-Risk Users</h2>

        <?php if ( empty( $users ) ): ?>
            <div style="padding:30px;text-align:center;color:#4caf50;">
                <strong>✓ No high-risk users detected.</strong>
                <p>All users are within acceptable fraud score thresholds.</p>
            </div>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Fraud Score</th>
                    <th>Risk Level</th>
                    <th>Signals</th>
                    <th>Recent Analyses (30d)</th>
                    <th>Suspended</th>
                    <th>Verified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $users as $u ):
                    $score_color = \BCC\Trust\Core\Support\Formatting::scoreColor( $u->fraud_score );
                    $risk_color  = \BCC\Trust\Core\Support\Formatting::riskColor( $u->risk_level ?? 'unknown' );
                    $signals     = $u->signals ?? [];
                    $row_id      = 'bcc-signals-' . (int) $u->user_id;
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $u->display_name ?: 'User #' . $u->user_id ); ?></strong>
                            <br><small style="color:#666;"><?php echo esc_html( $u->user_email ); ?></small>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="width:70px;height:10px;background:#eee;border-radius:5px;overflow:hidden;">
                                    <div style="width:<?php echo esc_attr(intval($u->fraud_score)); ?>%;height:100%;background:<?php echo esc_attr($score_color); ?>;border-radius:5px;"></div>
                                </div>
                                <strong style="color:<?php echo esc_attr($score_color); ?>;font-size:15px;"><?php echo intval($u->fraud_score); ?></strong>
                            </div>
                        </td>
                        <td>
                            <span style="padding:3px 8px;border-radius:3px;background:<?php echo esc_attr($risk_color); ?>;color:#fff;">
                                <?php echo esc_html( ucfirst( $u->risk_level ?? 'unknown' ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( ! empty( $signals ) ): ?>
                                <button
                                    type="button"
                                    class="button button-small"
                                    onclick="var el=document.getElementById('<?php echo esc_js( $row_id ); ?>');el.style.display=el.style.display==='none'?'table-row':'none';"
                                >
                                    <?php echo count( $signals ); ?> signal<?php echo count( $signals ) !== 1 ? 's' : ''; ?>
                                </button>
                            <?php else: ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval($u->recent_analyses ?? 0); ?></td>
                        <td>
                            <?php if ( $u->is_suspended ): ?>
                                <span style="color:#f44336;font-weight:bold;">✗ Suspended</span>
                            <?php else: ?>
                                <span style="color:#4caf50;">✓ Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $u->is_verified ?? false ): ?>
                                <span style="color:#4caf50;">✓ Verified</span>
                            <?php else: ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $u->user_id ) ); ?>" class="button button-small">Investigate</a>
                        </td>
                    </tr>
                    <?php if ( ! empty( $signals ) ): ?>
                    <tr id="<?php echo esc_attr( $row_id ); ?>" style="display:none;background:#fafafa;">
                        <td colspan="8" style="padding:12px 16px;">
                            <strong style="font-size:13px;">Fraud signals detected:</strong>
                            <table style="width:100%;margin-top:8px;border-collapse:collapse;font-size:13px;">
                                <thead>
                                    <tr style="background:#f0f0f0;">
                                        <th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Signal</th>
                                        <th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Description</th>
                                        <th style="padding:6px 10px;text-align:center;border:1px solid #ddd;width:80px;">Points</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $signals as $signal ): ?>
                                    <tr>
                                        <td style="padding:6px 10px;border:1px solid #ddd;font-weight:bold;white-space:nowrap;">
                                            <?php echo esc_html( ucwords( str_replace( '_', ' ', $signal['type'] ) ) ); ?>
                                        </td>
                                        <td style="padding:6px 10px;border:1px solid #ddd;">
                                            <?php echo esc_html( $signal['description'] ); ?>
                                        </td>
                                        <td style="padding:6px 10px;border:1px solid #ddd;text-align:center;color:#f44336;font-weight:bold;">
                                            +<?php echo (int) $signal['points']; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}