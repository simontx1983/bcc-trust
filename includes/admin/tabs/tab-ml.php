<?php
/**
 * Admin Dashboard — ML Insights Tab
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_ml_tab() {
    $data           = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getMlData();
    $patterns       = $data['patterns'];
    $pattern_counts = $data['pattern_counts'];
    ?>

    <!-- ── Pattern type summary ─────────────────────────────────── -->
    <?php if ( ! empty( $pattern_counts ) ): ?>
    <div class="bcc-panel">
        <h2>Pattern Summary (Last 30 Days)</h2>
        <div style="display:flex;flex-wrap:wrap;gap:12px;">
            <?php foreach ( $pattern_counts as $pc ): ?>
                <div style="background:#f5f5f5;border-radius:6px;padding:12px 20px;text-align:center;">
                    <div style="font-size:24px;font-weight:bold;color:#2196f3;"><?php echo intval($pc->count); ?></div>
                    <div style="font-size:12px;color:#666;"><?php echo esc_html( $pc->pattern_type ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Recent pattern detections ─────────────────────────────── -->
    <div class="bcc-panel">
        <h2>Recent ML Pattern Detections</h2>
        <p style="color:#666;">Last <?php echo intval($data['limit']); ?> detections.</p>

        <?php if ( empty( $patterns ) ): ?>
            <p>No patterns detected yet.</p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Detected At</th>
                    <th>Pattern Type</th>
                    <th>User</th>
                    <th>Confidence</th>
                    <th>Severity</th>
                    <th>Details</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $patterns as $p ):
                    $confidence = isset( $p->confidence ) ? round( $p->confidence * 100 ) : null;
                    $severity   = $p->severity ?? 'unknown';
                    $sev_color  = match ( strtolower( $severity ) ) {
                        'critical' => '#f44336',
                        'high'     => '#ff9800',
                        'medium'   => '#ffc107',
                        'low'      => '#4caf50',
                        default    => '#999',
                    };
                ?>
                    <tr>
                        <td style="font-size:12px;"><?php echo esc_html( $p->detected_at ); ?></td>
                        <td><code><?php echo esc_html( $p->pattern_type ); ?></code></td>
                        <td>
                            <?php if ( $p->user_id ): ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $p->user_id ) ); ?>">
                                    <?php echo esc_html( $p->display_name ?: 'User #' . $p->user_id ); ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $confidence !== null ): ?>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div style="width:50px;height:6px;background:#eee;border-radius:3px;overflow:hidden;">
                                        <div style="width:<?php echo esc_attr(intval($confidence)); ?>%;height:100%;background:#2196f3;border-radius:3px;"></div>
                                    </div>
                                    <?php echo intval($confidence); ?>%
                                </div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <span style="padding:2px 6px;border-radius:3px;background:<?php echo esc_attr($sev_color); ?>;color:#fff;font-size:11px;">
                                <?php echo esc_html( ucfirst( $severity ) ); ?>
                            </span>
                        </td>
                        <td style="font-size:11px;color:#666;max-width:200px;white-space:normal;">
                            <?php echo esc_html( $p->details ?? '' ); ?>
                        </td>
                        <td>
                            <?php if ( $p->user_id ): ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $p->user_id ) ); ?>" class="button button-small">Investigate</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}