<?php
/**
 * Admin Dashboard — Activity Log Tab
 *
 * @package BCC\Trust\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_activity_tab() {
    $repo = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();

    $data           = $repo->getActivityData();
    $activity       = $data['activity'];

    // ── Filter ────────────────────────────────────────────────────
    $filter_admin = isset( $_GET['admin_only'] ) && $_GET['admin_only'] === '1';

    if ( $filter_admin ) {
        $activity = $repo->getAdminOnlyActivity( $data['limit'] );
    }

    $base_url = admin_url( 'admin.php?page=bcc-trust-dashboard&tab=activity' );
    ?>

    <div class="bcc-panel">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
            <h2 style="margin:0;">Activity Log</h2>
            <div>
                <?php if ( $filter_admin ): ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button">Show All</a>
                    <strong style="margin-left:8px;color:#9c27b0;">Showing admin actions only</strong>
                <?php else: ?>
                    <a href="<?php echo esc_url( add_query_arg( 'admin_only', '1', $base_url ) ); ?>" class="button">Admin Actions Only</a>
                <?php endif; ?>
            </div>
        </div>

        <p style="color:#666;">Last <?php echo intval($data['limit']); ?> entries. <?php echo intval( count( $activity ) ); ?> shown.</p>

        <?php if ( empty( $activity ) ): ?>
            <p>No activity found.</p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="150">Time</th>
                    <th width="200">Action</th>
                    <th>User</th>
                    <th>Target</th>
                    <th>IP</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $activity as $a ):
                    $is_admin_action = str_starts_with( $a->action, 'admin_' );
                    $row_style       = $is_admin_action ? 'background:#f3e5f5;' : '';
                ?>
                    <tr style="<?php echo esc_attr($row_style); ?>">
                        <td style="font-size:12px;"><?php echo esc_html( $a->created_at ); ?></td>
                        <td>
                            <?php if ( $is_admin_action ): ?>
                                <span style="background:#9c27b0;color:#fff;font-size:11px;padding:1px 5px;border-radius:3px;margin-right:4px;">ADMIN</span>
                            <?php endif; ?>
                            <?php echo esc_html( $a->action ); ?>
                        </td>
                        <td>
                            <?php if ( $a->user_id ): ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $a->user_id ) ); ?>">
                                    <?php echo esc_html( $a->user_name ?: 'User #' . $a->user_id ); ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $a->target_id ) && ! empty( $a->target_type ) && $a->target_type === 'page' ): ?>
                                <a href="<?php echo esc_url( get_permalink( $a->target_id ) ); ?>" target="_blank">
                                    <?php echo esc_html( get_the_title( $a->target_id ) ?: 'Page #' . $a->target_id ); ?>
                                </a>
                            <?php elseif ( ! empty( $a->target_id ) ): ?>
                                <?php echo esc_html( ( $a->target_type ?? 'item' ) . ' #' . $a->target_id ); ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="font-size:11px;">
                            <?php if ( ! empty( $a->ip_address ) ):
                                $ip = @inet_ntop( $a->ip_address );
                                if ( $ip ):
                            ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&ip=' . urlencode( $ip ) ) ); ?>"><?php echo esc_html( $ip ); ?></a>
                            <?php endif; endif; ?>
                        </td>
                        <td style="font-size:11px;color:#666;"><?php echo esc_html( $a->details ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}