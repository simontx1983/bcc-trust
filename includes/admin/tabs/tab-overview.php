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

    // ⚠⚠ "Nothing happened" and "we could not look" are different facts.
    //
    // Every figure on this tab is a COUNT, and a failed COUNT used to arrive
    // here as the integer 0 — then "New High-Fraud: 0" and "Suspensions: 0"
    // were painted GREEN, so an unreadable database was displayed as an
    // all-clear. The repository now reports whether its reads actually ran;
    // where they did not, this tab says so instead of colouring a number it
    // never received.
    //
    // `?? true` is deliberate for both: a snapshot written by the previous
    // build carries no 'ok' key, and treating that absence as a failure
    // would show every operator a read error on the first load after deploy.
    $overview_ok = ( $data['ok'] ?? true ) === true;
    $today_ok    = ( $today['ok'] ?? true ) === true;
    $is_stale    = ! empty( $data['stale'] );
    $stale_age   = (int) ( $data['stale_age'] ?? 0 );
    ?>

    <?php if ( ! $overview_ok || ! $today_ok ): ?>
        <div class="notice notice-error inline" style="margin:0 0 16px 0;">
            <p>
                <strong><?php esc_html_e( 'Some figures below could not be read from the database.', 'bcc-trust' ); ?></strong>
                <?php esc_html_e( 'Where a number could not be read it is shown as a dash, not as zero. A dash is not a statement that nothing happened — do not read it as an all-clear.', 'bcc-trust' ); ?>
            </p>
            <p style="margin-top:0;">
                <?php esc_html_e( 'The failure is recorded in the application log with the specific aggregate that failed.', 'bcc-trust' ); ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ( $is_stale ): ?>
        <div class="notice notice-warning inline" style="margin:0 0 16px 0;">
            <p>
                <strong><?php esc_html_e( 'Showing the last good snapshot.', 'bcc-trust' ); ?></strong>
                <?php
                printf(
                    /* translators: %s: human-readable age, e.g. "4 mins". */
                    esc_html__( 'The live read failed, so these totals are from a snapshot taken %s ago rather than fabricated zeros.', 'bcc-trust' ),
                    esc_html( human_time_diff( time() - $stale_age, time() ) )
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="bcc-panel" style="border-left:5px solid <?php echo $today_ok ? '#2196f3' : '#d63638'; ?>;">
        <h2 style="margin-top:0;">Today's Activity Summary</h2>
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;text-align:center;">
            <?php
            // ⚠ The colour is a CLAIM. Green on "New High-Fraud" asserts that
            // nobody crossed the threshold today, which only the number's
            // provenance can justify — so when the read failed every tile
            // goes neutral grey and the value becomes an em dash. There is no
            // branch here that can paint an unread number green.
            $summary_stats = $today_ok
                ? [
                    [ 'label' => 'New High-Fraud', 'value' => $today_fraud,         'color' => $today_fraud > 0         ? '#f44336' : '#4caf50' ],
                    [ 'label' => 'Suspensions',    'value' => $today_suspensions,   'color' => $today_suspensions > 0   ? '#ff9800' : '#4caf50' ],
                    [ 'label' => 'Verifications',  'value' => $today_verifications, 'color' => $today_verifications > 0 ? '#4caf50' : '#999' ],
                    [ 'label' => 'Votes Cast',     'value' => $today_votes,         'color' => '#2196f3' ],
                    [ 'label' => 'Admin Actions',  'value' => $today_admin_actions, 'color' => '#9c27b0' ],
                ]
                : [
                    [ 'label' => 'New High-Fraud', 'value' => '—', 'color' => '#646970' ],
                    [ 'label' => 'Suspensions',    'value' => '—', 'color' => '#646970' ],
                    [ 'label' => 'Verifications',  'value' => '—', 'color' => '#646970' ],
                    [ 'label' => 'Votes Cast',     'value' => '—', 'color' => '#646970' ],
                    [ 'label' => 'Admin Actions',  'value' => '—', 'color' => '#646970' ],
                ];
            foreach ( $summary_stats as $s ):
            ?>
                <div>
                    <div style="font-size:32px;font-weight:bold;color:<?php echo esc_attr($s['color']); ?>;"><?php echo esc_html($s['value']); ?></div>
                    <div style="font-size:12px;color:#666;"><?php echo esc_html( $s['label'] ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ( ! $today_ok ): ?>
            <p style="margin:12px 0 0 0;color:#646970;font-size:12px;">
                <?php esc_html_e( "Today's figures are unavailable because at least one of the five reads behind them did not run.", 'bcc-trust' ); ?>
            </p>
        <?php endif; ?>
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
        // ⚠ number_format() makes a fabricated zero look authoritative — this
        // is the line that rendered "Tracked Users 0" while the table held 3.
        // A dash cannot be mistaken for a measurement.
        if ( ! $overview_ok ) {
            foreach ( $headline as $i => $unused_h ) {
                $headline[ $i ]['value'] = '—';
            }
        }
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
            // ⚠ An all-zero distribution draws five empty bars, which reads as
            // "no user is at risk" — the same false all-clear as a green 0.
            //
            // ⚠⚠ ORDER MATTERS: the denominator is taken from the NUMBERS,
            // before any value becomes a dash. In PHP 8 `'—' / 1` throws a
            // TypeError, so dashing first and dividing later would replace a
            // misleading panel with a white screen. The $pct line below is
            // guarded with is_numeric() for the same reason.
            $total_users = max( 1, array_sum( array_column( $risk_rows, 'value' ) ) );
            if ( ! $overview_ok ) {
                foreach ( $risk_rows as $i => $unused_r ) {
                    $risk_rows[ $i ]['value'] = '—';
                }
            }
            foreach ( $risk_rows as $r ):
                // is_numeric: the value is a dash when the read failed, and
                // dividing by it would throw. A dashed row draws no bar.
                $pct = is_numeric( $r['value'] ) ? round( $r['value'] / $total_users * 100 ) : 0;
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
            // Same ordering rule as the risk panel above: denominator from
            // the numbers, dashes afterwards, never divide by a dash.
            $total_pages = max( 1, array_sum( array_column( $tier_rows, 'value' ) ) );
            if ( ! $overview_ok ) {
                foreach ( $tier_rows as $i => $unused_r ) {
                    $tier_rows[ $i ]['value'] = '—';
                }
            }
            foreach ( $tier_rows as $r ):
                // is_numeric: the value is a dash when the read failed, and
                // dividing by it would throw. A dashed row draws no bar.
                $pct = is_numeric( $r['value'] ) ? round( $r['value'] / $total_pages * 100 ) : 0;
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
            // ⚠ 'normal' is a SUBTRACTION in the repository:
            //     total_devices - (high_automation + suspicious)
            // so it goes NEGATIVE the moment those three reads disagree —
            // which is exactly what a partial read failure produces. The old
            // `max(0, …)` silenced that: a negative became a confident 0 and
            // the three bars quietly stopped summing to the total.
            //
            // The clamp stays (a negative bar is meaningless) but it is no
            // longer silent, and the denominator is now the three buckets
            // rather than a separately-read total, so the bars always sum to
            // 100% of what is actually displayed.
            $device_normal_raw = (int) $device_risk['normal'];
            $device_rows = [
                [ 'label' => 'High Automation (Bot likely)', 'value' => (int) $device_risk['high_automation'], 'color' => '#f44336' ],
                [ 'label' => 'Suspicious',                   'value' => (int) $device_risk['suspicious'],      'color' => '#ff9800' ],
                [ 'label' => 'Normal',                       'value' => max( 0, $device_normal_raw ),          'color' => '#4caf50' ],
            ];
            $total_devices = max( 1, array_sum( array_column( $device_rows, 'value' ) ) );
            if ( ! $overview_ok ) {
                foreach ( $device_rows as $i => $unused_r ) {
                    $device_rows[ $i ]['value'] = '—';
                }
            }
            foreach ( $device_rows as $r ):
                // is_numeric: the value is a dash when the read failed, and
                // dividing by it would throw. A dashed row draws no bar.
                $pct = is_numeric( $r['value'] ) ? round( $r['value'] / $total_devices * 100 ) : 0;
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
                <?php if ( $overview_ok ): ?>
                    <?php echo number_format( $counts['total_devices'] ); ?> unique devices tracked.
                    <?php echo intval($counts['recent_fraud_analyses']); ?> fraud analyses in last 24h.
                <?php else: ?>
                    <?php esc_html_e( 'Device and fraud-analysis totals could not be read.', 'bcc-trust' ); ?>
                <?php endif; ?>
            </p>
            <?php if ( $device_normal_raw < 0 ): ?>
                <p style="font-size:12px;color:#d63638;margin:6px 0 0 0;">
                    <?php esc_html_e( 'These device buckets do not reconcile: the high-automation and suspicious counts together exceed the unique-device total, so at least one of the three reads is inconsistent. The Normal figure is clamped at zero and should not be relied on.', 'bcc-trust' ); ?>
                </p>
            <?php endif; ?>
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