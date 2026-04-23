<?php
/**
 * Admin Dashboard — Repair Tools Tab
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_repair_tab() {
    $data = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getRepairData();

    // ── Flash messages ────────────────────────────────────────────
    // Simple GET-flag notices (kept for backward compat).
    $notice_map = [
        'owners_fixed'   => 'Page owners repaired.',
        'scores_recalc'  => 'Scores recalculated.',
        'devices_clean'  => 'Old device records cleaned up.',
        'page_repair'    => 'Complete page repair done.',
        'sync_done'      => 'User sync complete.',
    ];
    foreach ( $notice_map as $key => $msg ):
        if ( isset( $_GET[ $key ] ) ):
    ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?> (<?php echo intval( $_GET[ $key ] ); ?> records)</p></div>
    <?php
        endif;
    endforeach;

    // Rich results from the repair service transient (survives the POST→redirect).
    $rich_results = get_transient( 'bcc_trust_repair_results' );
    if ( is_array( $rich_results ) ) {
        delete_transient( 'bcc_trust_repair_results' );
        echo '<div class="notice notice-success is-dismissible"><p><strong>Repair complete</strong></p>';
        echo '<ul style="margin:0 0 10px 20px;">';
        foreach ( $rich_results as $key => $value ) {
            if ( $key === 'details' && is_array( $value ) ) {
                continue; // Render details below as a collapsed block.
            }
            if ( is_scalar( $value ) ) {
                echo '<li><code>' . esc_html( (string) $key ) . '</code>: ' . esc_html( (string) $value ) . '</li>';
            }
        }
        echo '</ul>';
        if ( ! empty( $rich_results['details'] ) && is_array( $rich_results['details'] ) ) {
            echo '<details style="margin:0 0 10px 0;"><summary>Details (' . intval( count( $rich_results['details'] ) ) . ')</summary>';
            echo '<pre style="max-height:300px;overflow:auto;background:#f6f7f7;padding:10px;margin-top:8px;">';
            foreach ( $rich_results['details'] as $line ) {
                echo esc_html( (string) $line ) . "\n";
            }
            echo '</pre></details>';
        }
        echo '</div>';
    }
    ?>

    <!-- ── Diagnostics ───────────────────────────────────────────── -->
    <?php bcc_trust_show_repair_diagnostics(); ?>

    <!-- ── Database table status ─────────────────────────────────── -->
    <div class="bcc-panel">
        <h2>Database Tables</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr><th>Table</th><th>Exists</th><th>Rows</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $data['tables'] as $name => $info ): ?>
                    <tr>
                        <td><code><?php echo esc_html( $info['name'] ); ?></code></td>
                        <td>
                            <?php if ( $info['exists'] ): ?>
                                <span style="color:#4caf50;font-weight:bold;">✓ Yes</span>
                            <?php else: ?>
                                <span style="color:#f44336;font-weight:bold;">✗ Missing</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $info['exists'] ? number_format( $info['count'] ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $data['orphaned_votes'] > 0 ): ?>
            <p style="color:#ff9800;margin-top:10px;">
                ⚠ <?php echo intval($data['orphaned_votes']); ?> orphaned vote records (votes for pages with no score entry).
            </p>
        <?php endif; ?>
    </div>

    <!-- ── Data retention config ─────────────────────────────────── -->
    <div class="bcc-panel">
        <h2>Data Retention Settings</h2>
        <table class="form-table" style="max-width:500px;">
            <?php
            $retention = [
                'Fingerprint records' => $data['cleanup_days']['fingerprints'],
                'ML patterns'         => $data['cleanup_days']['patterns'],
                'Fraud analysis'      => $data['cleanup_days']['fraud_analysis'],
                'Activity log'        => $data['cleanup_days']['activity'],
            ];
            foreach ( $retention as $label => $days ):
            ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td><?php echo intval($days); ?> days</td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- ── Repair tools ───────────────────────────────────────────── -->
    <div class="bcc-panel">
        <h2>Repair Tools</h2>
        <p>Each action is irreversible. Review diagnostics above before running.</p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

            <div style="border:1px solid #ddd;border-radius:6px;padding:16px;">
                <h3 style="margin-top:0;">Fix Page Owners</h3>
                <p style="font-size:13px;color:#666;">Syncs <code>page_owner_id</code> in the scores table with the actual post author.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'bcc_trust_admin_action' ); ?>
                    <input type="hidden" name="action" value="bcc_trust_repair_owners">
                    <button type="submit" class="button button-primary" onclick="return confirm('Fix page owners?');">Run Fix</button>
                </form>
            </div>

            <div style="border:1px solid #ddd;border-radius:6px;padding:16px;">
                <h3 style="margin-top:0;">Recalculate Scores</h3>
                <p style="font-size:13px;color:#666;">Recalculates trust scores for all pages based on current votes and weights.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'bcc_trust_admin_action' ); ?>
                    <input type="hidden" name="action" value="bcc_trust_recalc_scores">
                    <button type="submit" class="button button-primary" onclick="return confirm('Recalculate all scores? This may take a while.');">Run Recalculation</button>
                </form>
            </div>

            <div style="border:1px solid #ddd;border-radius:6px;padding:16px;">
                <h3 style="margin-top:0;">Clean Old Device Records</h3>
                <p style="font-size:13px;color:#666;">Removes device fingerprint records older than <?php echo intval($data['cleanup_days']['fingerprints']); ?> days.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'bcc_trust_admin_action' ); ?>
                    <input type="hidden" name="action" value="bcc_trust_clean_devices">
                    <button type="submit" class="button" onclick="return confirm('Delete old device records?');">Clean Devices</button>
                </form>
            </div>

            <div style="border:1px solid #ddd;border-radius:6px;padding:16px;">
                <h3 style="margin-top:0;">Sync Missing Users</h3>
                <p style="font-size:13px;color:#666;">Adds any WordPress users missing from the <code>user_info</code> table and updates existing records.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'bcc_trust_admin_action' ); ?>
                    <input type="hidden" name="action" value="bcc_trust_sync_users">
                    <button type="submit" class="button button-primary" onclick="return confirm('Sync all users to user_info table?');">Sync Users</button>
                </form>
            </div>

            <div style="border:1px solid #ddd;border-radius:6px;padding:16px;border-color:#ff9800;">
                <h3 style="margin-top:0;color:#ff9800;">⚡ Complete Page Repair</h3>
                <p style="font-size:13px;color:#666;">Full repair: fixes owners, creates missing score entries, recalculates everything.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'bcc_trust_admin_action' ); ?>
                    <input type="hidden" name="action" value="bcc_trust_complete_page_repair">
                    <button type="submit" class="button" style="background:#ff9800;color:#fff;border-color:#e68900;"
                            onclick="return confirm('Run complete page repair? This may take several minutes.');">
                        Run Complete Repair
                    </button>
                </form>
            </div>

            <?php
            /**
             * Extensibility hook — other plugins may render additional repair
             * grid-item divs here (e.g. bcc-peepso-integration adds shadow-CPT
             * rebuild). Handlers should echo a single
             * `<div style="border:1px solid #ddd;border-radius:6px;padding:16px;">…</div>`.
             */
            do_action( 'bcc_trust_repair_tab_extra_tools' );
            ?>

        </div>
    </div>
    <?php
}