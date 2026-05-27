<?php
/**
 * Admin Dashboard — Overview Tab
 *
 * @package BCC\Trust\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_overview_tab() {
    $repo = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();

    $data           = $repo->getOverviewData();
    $counts         = $data['counts'];
    $risk           = $data['risk_distribution'];
    $tiers          = $data['tier_distribution'];
    $device_risk    = $data['device_risk'];

    // ── Today's activity summary (via repository) ─────────────────
    $today                = $repo->getTodaySummary();
    $today_fraud          = $today['today_fraud'];
    $today_suspensions    = $today['today_suspensions'];
    $today_verifications  = $today['today_verifications'];
    $today_votes          = $today['today_votes'];
    $today_admin_actions  = $today['today_admin_actions'];
    ?>

    <div class="bcc-panel" style="border-left:5px solid #2196f3;">
        <h2 style="margin-top:0;">Today's Activity Summary</h2>
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;text-align:center;">
            <?php
            $summary_stats = [
                [ 'label' => 'New High-Fraud', 'value' => $today_fraud,         'color' => $today_fraud > 0         ? '#f44336' : '#4caf50' ],
                [ 'label' => 'Suspensions',    'value' => $today_suspensions,   'color' => $today_suspensions > 0   ? '#ff9800' : '#4caf50' ],
                [ 'label' => 'Verifications',  'value' => $today_verifications, 'color' => $today_verifications > 0 ? '#4caf50' : '#999' ],
                [ 'label' => 'Votes Cast',     'value' => $today_votes,         'color' => '#2196f3' ],
                [ 'label' => 'Admin Actions',  'value' => $today_admin_actions, 'color' => '#9c27b0' ],
            ];
            foreach ( $summary_stats as $s ):
            ?>
                <div>
                    <div style="font-size:32px;font-weight:bold;color:<?php echo esc_attr($s['color']); ?>;"><?php echo esc_html($s['value']); ?></div>
                    <div style="font-size:12px;color:#666;"><?php echo esc_html( $s['label'] ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Headline stats ───────────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;">
        <?php
        $headline = [
            [ 'label' => 'Total Votes',        'value' => number_format( $counts['total_votes'] ),        'icon' => '🗳' ],
            [ 'label' => 'Total Endorsements',  'value' => number_format( $counts['total_endorsements'] ), 'icon' => '👍' ],
            [ 'label' => 'Tracked Pages',       'value' => number_format( $counts['total_pages'] ),        'icon' => '📄' ],
            [ 'label' => 'Tracked Users',       'value' => number_format( $counts['total_users'] ),        'icon' => '👤' ],
        ];
        foreach ( $headline as $h ):
        ?>
            <div class="bcc-panel" style="text-align:center;padding:16px;">
                <div style="font-size:28px;"><?php echo $h['icon']; ?></div>
                <div style="font-size:26px;font-weight:bold;"><?php echo $h['value']; ?></div>
                <div style="font-size:12px;color:#666;"><?php echo esc_html( $h['label'] ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;">

        <!-- ── User risk distribution ─── -->
        <div class="bcc-panel">
            <h3>User Risk Distribution</h3>
            <?php
            $risk_rows = [
                [ 'label' => 'Critical Risk', 'value' => $risk['critical_risk'] ?? 0, 'color' => '#9c27b0' ],
                [ 'label' => 'High Risk',     'value' => $risk['high_risk'],           'color' => '#dc3545' ],
                [ 'label' => 'Medium Risk',   'value' => $risk['medium_risk'],         'color' => '#fd7e14' ],
                [ 'label' => 'Low Risk',      'value' => $risk['low_risk'],            'color' => '#ffc107' ],
                [ 'label' => 'Minimal Risk',  'value' => $risk['minimal_risk'],        'color' => '#28a745' ],
            ];
            $total_users = max( 1, array_sum( array_column( $risk_rows, 'value' ) ) );
            foreach ( $risk_rows as $r ):
                $pct = round( $r['value'] / $total_users * 100 );
            ?>
                <div style="margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <span style="font-size:13px;"><?php echo esc_html( $r['label'] ); ?></span>
                        <strong><?php echo esc_html($r['value']); ?></strong>
                    </div>
                    <div style="height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                        <div style="width:<?php echo esc_attr(intval($pct)); ?>%;height:100%;background:<?php echo esc_attr($r['color']); ?>;border-radius:4px;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Page tier distribution ─── -->
        <div class="bcc-panel">
            <h3>Page Tier Distribution</h3>
            <?php
            $tier_rows = [
                [ 'label' => 'Elite',   'value' => $tiers['elite'],   'color' => '#4caf50' ],
                [ 'label' => 'Trusted', 'value' => $tiers['trusted'], 'color' => '#8bc34a' ],
                [ 'label' => 'Neutral', 'value' => $tiers['neutral'], 'color' => '#ffc107' ],
                [ 'label' => 'Caution', 'value' => $tiers['caution'], 'color' => '#ff9800' ],
                [ 'label' => 'Risky',   'value' => $tiers['risky'],   'color' => '#f44336' ],
            ];
            $total_pages = max( 1, array_sum( array_column( $tier_rows, 'value' ) ) );
            foreach ( $tier_rows as $r ):
                $pct = round( $r['value'] / $total_pages * 100 );
            ?>
                <div style="margin-bottom:8px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                        <span style="font-size:13px;"><?php echo esc_html( $r['label'] ); ?></span>
                        <strong><?php echo esc_html($r['value']); ?></strong>
                    </div>
                    <div style="height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                        <div style="width:<?php echo esc_attr(intval($pct)); ?>%;height:100%;background:<?php echo esc_attr($r['color']); ?>;border-radius:4px;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Device risk ─── -->
        <div class="bcc-panel">
            <h3>Device Automation Risk</h3>
            <?php
            $device_rows = [
                [ 'label' => 'High Automation (Bot likely)', 'value' => $device_risk['high_automation'], 'color' => '#f44336' ],
                [ 'label' => 'Suspicious',                   'value' => $device_risk['suspicious'],      'color' => '#ff9800' ],
                [ 'label' => 'Normal',                       'value' => max( 0, $device_risk['normal'] ), 'color' => '#4caf50' ],
            ];
            $total_devices = max( 1, $counts['total_devices'] );
            foreach ( $device_rows as $r ):
                $pct = round( $r['value'] / $total_devices * 100 );
            ?>
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <span style="font-size:13px;"><?php echo esc_html( $r['label'] ); ?></span>
                        <strong><?php echo esc_html($r['value']); ?></strong>
                    </div>
                    <div style="height:10px;background:#eee;border-radius:5px;overflow:hidden;">
                        <div style="width:<?php echo esc_attr(intval($pct)); ?>%;height:100%;background:<?php echo esc_attr($r['color']); ?>;border-radius:5px;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <p style="font-size:12px;color:#666;margin:0;">
                <?php echo number_format( $counts['total_devices'] ); ?> unique devices tracked.
                <?php echo intval($counts['recent_fraud_analyses']); ?> fraud analyses in last 24h.
            </p>
        </div>

    </div>

    <!-- ── Quick links ─────────────────────────────────────────── -->
    <div class="bcc-panel">
        <h3>Quick Actions</h3>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php
            $links = [
                [ 'label' => 'View High-Risk Users',  'url' => admin_url( 'admin.php?page=bcc-trust-moderation&filter=high_fraud' ) ],
                [ 'label' => 'View Suspended Users',  'url' => admin_url( 'admin.php?page=bcc-trust-moderation&filter=suspended' ) ],
                [ 'label' => 'Vote Ring Detection',   'url' => admin_url( 'admin.php?page=bcc-trust-dashboard&tab=signals&sub=rings' ) ],
                [ 'label' => 'Fraud Detection',       'url' => admin_url( 'admin.php?page=bcc-trust-dashboard&tab=fraud' ) ],
                [ 'label' => 'Device Investigation',  'url' => admin_url( 'admin.php?page=bcc-trust-dashboard&tab=signals&sub=devices' ) ],
                [ 'label' => 'Moderation',            'url' => admin_url( 'admin.php?page=bcc-trust-moderation' ) ],
            ];
            foreach ( $links as $link ):
            ?>
                <a href="<?php echo esc_url( $link['url'] ); ?>" class="button"><?php echo esc_html( $link['label'] ); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
}