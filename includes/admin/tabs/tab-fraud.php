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
    $users    = $data['fraud_users'];
    $fraud_ok = ( $data['ok'] ?? true ) === true;
    // ⚠ A dash, not intval(). `intval()` on an unread figure prints a
    // confident 0 under a red "Critical Risk Users" heading, which reads as
    // "we checked and there are none".
    $fraud_num = static function ( $v ) use ( $fraud_ok ) {
        return $fraud_ok ? (string) intval( $v ) : '—';
    };
    ?>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
        <div class="bcc-panel" style="text-align:center;border-left:5px solid var(--bcc-danger);">
            <div style="font-size:32px;font-weight:bold;color:var(--bcc-danger);"><?php echo esc_html( $fraud_num( $data['critical_count'] ) ); ?></div>
            <div style="font-size:13px;color:var(--bcc-text-secondary);">Critical Risk Users</div>
        </div>
        <div class="bcc-panel" style="text-align:center;border-left:5px solid var(--bcc-highlight);">
            <div style="font-size:32px;font-weight:bold;color:var(--bcc-warning);"><?php echo esc_html( $fraud_num( $data['total'] ) ); ?></div>
            <div style="font-size:13px;color:var(--bcc-text-secondary);">High-Risk Users (≥<?php echo intval($data['high_risk_threshold']); ?>)</div>
        </div>
        <div class="bcc-panel" style="text-align:center;border-left:5px solid var(--bcc-accent);">
            <div style="font-size:32px;font-weight:bold;color:var(--bcc-accent);"><?php echo esc_html( $fraud_num( $data['total'] ) ); ?></div>
            <div style="font-size:13px;color:var(--bcc-text-secondary);">Showing (limit <?php echo intval($data['limit']); ?>)</div>
        </div>
    </div>

    <div class="bcc-panel">
        <h2>High-Risk Users</h2>

        <?php if ( ! $fraud_ok ): ?>
            <?php // ⚠ "✓ All users are within acceptable fraud score
                  // thresholds" is the single most dangerous sentence on this
                  // dashboard to print from a failed query. ?>
            <div class="notice notice-error inline" style="margin:0;">
                <p>
                    <strong><?php esc_html_e( 'The high-risk user list could not be read.', 'bcc-trust' ); ?></strong>
                    <?php esc_html_e( 'This is a database read failure, not a statement that no user is high-risk. Do not treat this as an all-clear. The failure is recorded in the application log.', 'bcc-trust' ); ?>
                </p>
            </div>
        <?php elseif ( empty( $users ) ): ?>
            <div style="padding:30px;text-align:center;color:var(--bcc-success);">
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
                            <br><small style="color:var(--bcc-text-secondary);"><?php echo esc_html( $u->user_email ); ?></small>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="width:70px;height:10px;background:var(--bcc-bg-code);border-radius:5px;overflow:hidden;">
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
                                <span style="color:var(--bcc-text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval($u->recent_analyses ?? 0); ?></td>
                        <td>
                            <?php if ( $u->is_suspended ): ?>
                                <span style="color:var(--bcc-danger);font-weight:bold;">✗ Suspended</span>
                            <?php else: ?>
                                <span style="color:var(--bcc-success);">✓ Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $u->is_verified ?? false ): ?>
                                <span style="color:var(--bcc-success);">✓ Verified</span>
                            <?php else: ?>
                                <span style="color:var(--bcc-text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $u->user_id ) ); ?>" class="button button-small">Investigate</a>
                        </td>
                    </tr>
                    <?php if ( ! empty( $signals ) ): ?>
                    <tr id="<?php echo esc_attr( $row_id ); ?>" style="display:none;background:var(--bcc-bg-card);">
                        <td colspan="8" style="padding:12px 16px;">
                            <strong style="font-size:13px;">Fraud signals detected:</strong>
                            <table style="width:100%;margin-top:8px;border-collapse:collapse;font-size:13px;">
                                <thead>
                                    <tr style="background:var(--bcc-bg-raised);">
                                        <th style="padding:6px 10px;text-align:left;border:1px solid var(--bcc-border);">Signal</th>
                                        <th style="padding:6px 10px;text-align:left;border:1px solid var(--bcc-border);">Description</th>
                                        <th style="padding:6px 10px;text-align:center;border:1px solid var(--bcc-border);width:80px;">Points</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $signals as $signal ): ?>
                                    <tr>
                                        <td style="padding:6px 10px;border:1px solid var(--bcc-border);font-weight:bold;white-space:nowrap;">
                                            <?php echo esc_html( ucwords( str_replace( '_', ' ', $signal['type'] ) ) ); ?>
                                        </td>
                                        <td style="padding:6px 10px;border:1px solid var(--bcc-border);">
                                            <?php echo esc_html( $signal['description'] ); ?>
                                        </td>
                                        <td style="padding:6px 10px;border:1px solid var(--bcc-border);text-align:center;color:var(--bcc-danger);font-weight:bold;">
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