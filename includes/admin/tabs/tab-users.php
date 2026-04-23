<?php
/**
 * Admin Dashboard — User Trust Tab
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_users_tab() {
    $data  = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getUsersData();
    $users = $data['users'];
    ?>
    <div class="bcc-panel">
        <h2>User Trust Overview</h2>
        <p>
            Showing top <?php echo $data['limit']; ?> users by fraud score. For full moderation,
            visit <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation' ) ); ?>">Trust Moderation</a>.
        </p>

        <?php if ( empty( $users ) ): ?>
            <p>No users found.</p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Fraud Score</th>
                    <th>Risk Label</th>
                    <th>Reputation</th>
                    <th>Tier</th>
                    <th>Vote Weight</th>
                    <th>Suspended</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $users as $u ): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $u->display_name ?: 'User #' . $u->user_id ); ?></strong>
                            <br><small>ID: <?php echo intval($u->user_id); ?></small>
                        </td>
                        <td><?php echo esc_html( $u->user_email ); ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <div style="width:60px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                    <div style="width:<?php echo esc_attr(intval($u->fraud_score)); ?>%;height:100%;background:<?php echo esc_attr($u->risk_color ?? '#999'); ?>;border-radius:4px;"></div>
                                </div>
                                <strong style="color:<?php echo esc_attr($u->risk_color ?? '#333'); ?>;"><?php echo intval($u->fraud_score); ?></strong>
                            </div>
                        </td>
                        <td>
                            <span style="padding:2px 8px;border-radius:3px;background:<?php echo esc_attr($u->risk_color ?? '#999'); ?>;color:#fff;font-size:12px;">
                                <?php echo esc_html( $u->risk_label ?? 'Unknown' ); ?>
                            </span>
                        </td>
                        <td><?php echo isset( $u->reputation_score ) ? round( $u->reputation_score, 1 ) : '—'; ?></td>
                        <td>
                            <?php if ( ! empty( $u->user_tier ) ):
                                $t = \BCC\Trust\Core\Support\Formatting::tierInfo( strtolower( $u->user_tier ) );
                            ?>
                                <span style="padding:2px 6px;border-radius:3px;background:<?php echo esc_attr($t['color']); ?>;color:#fff;font-size:11px;">
                                    <?php echo esc_html( ucfirst( $u->user_tier ) ); ?>
                                </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?php echo isset( $u->vote_weight ) ? round( $u->vote_weight, 2 ) : '—'; ?></td>
                        <td>
                            <?php if ( $u->is_suspended ): ?>
                                <span style="color:#f44336;font-weight:bold;">✗ Yes</span>
                            <?php else: ?>
                                <span style="color:#4caf50;">✓ No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $u->user_id ) ); ?>" class="button button-small">Manage</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <p style="margin-top:15px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation' ) ); ?>" class="button button-primary">
                Open Full Moderation Panel →
            </a>
        </p>
    </div>
    <?php
}

