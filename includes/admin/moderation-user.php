<?php
/**
 * Moderation — Single User Detail View
 *
 * Shows full profile, fraud/automation scores, suspension history,
 * fraud analysis history, devices, owned pages, recent votes, IPs,
 * recent activity — plus NEW: manual trust-score override form.
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_user_moderation( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }
    $repo = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();

    $user_info = $repo->getUserInfo( $user_id );
    $user      = get_userdata( $user_id );

    if ( ! $user || ! $user_info ) {
        echo '<div class="notice notice-error"><p>User not found.</p></div>';
        return;
    }

    $fraud_critical  = BCC_TRUST_FRAUD_CRITICAL;
    $fraud_high      = BCC_TRUST_FRAUD_HIGH;
    $automation_high = BCC_TRUST_AUTOMATION_HIGH;

    // ── Data queries (via repository) ─────────────────────────
    $suspension_history = $repo->getSuspensionHistory( $user_id );
    $fraud_history      = $repo->getFraudHistory( $user_id );
    $user_pages         = $repo->getUserPages( $user_id );
    $recent_votes       = $repo->getRecentVotesByUser( $user_id );
    $recent_activity    = $repo->getRecentActivityByUser( $user_id );
    $user_ips           = $repo->getUserIps( $user_id );
    $fingerprints       = $repo->getUserFingerprints( $user_id );
    ?>

    <div class="wrap bcc-admin-wrap">
        <h1>Moderate: <?php echo esc_html( $user->display_name ?: $user->user_login ); ?></h1>

        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation' ) ); ?>" class="button">&larr; Back to list</a>
            <a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>" class="button" target="_blank">Edit Profile</a>
        </p>

        <!-- ══ USER INFORMATION ════════════════════════════ -->
        <div class="bcc-panel">
            <h2>User Information</h2>
            <table class="form-table">
                <tr><th>ID</th>           <td><?php echo intval( $user_id ); ?></td></tr>
                <tr><th>Username</th>     <td><?php echo esc_html( $user->user_login ); ?></td></tr>
                <tr><th>Display Name</th> <td><?php echo esc_html( $user->display_name ); ?></td></tr>
                <tr><th>Email</th>        <td><?php echo esc_html( $user->user_email ); ?></td></tr>
                <tr><th>Registered</th>   <td><?php echo esc_html( $user->user_registered ); ?></td></tr>
                <tr><th>Roles</th>        <td><?php echo esc_html( implode( ', ', $user->roles ) ); ?></td></tr>
                <tr><th>Last Active</th>  <td><?php echo $user_info->usr_last_activity ? esc_html( $user_info->usr_last_activity ) : '—'; ?></td></tr>
                <tr><th>Verified</th>     <td><?php echo $user_info->is_verified ? '<span style="color:#4caf50;">✓ Yes</span>' : '✗ No'; ?></td></tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <span style="background:<?php echo esc_attr( $user_info->is_suspended ? '#f44336' : '#4caf50' ); ?>;color:#fff;padding:3px 8px;border-radius:3px;">
                            <?php echo $user_info->is_suspended ? 'Suspended' : 'Active'; ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ══ FRAUD ANALYSIS SUMMARY ══════════════════════ -->
        <div class="bcc-panel" style="border-left:5px solid <?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::riskColor( $user_info->risk_level ) ); ?>;">
            <h2>Fraud Analysis</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:20px;">

                <?php bcc_trust_stat_card( 'Fraud Score', function() use ( $user_info, $fraud_critical, $fraud_high ) { ?>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:100px;height:10px;background:#eee;border-radius:5px;overflow:hidden;">
                            <div style="width:<?php echo esc_attr( intval( $user_info->fraud_score ) ); ?>%;height:100%;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::scoreColor( $user_info->fraud_score ) ); ?>;"></div>
                        </div>
                        <strong style="font-size:22px;"><?php echo intval( $user_info->fraud_score ); ?></strong>
                    </div>
                    <?php if ( $user_info->fraud_score >= $fraud_critical ): ?>
                        <p style="color:#f44336;margin:4px 0 0;"><strong>CRITICAL</strong> — consider suspension</p>
                    <?php elseif ( $user_info->fraud_score >= $fraud_high ): ?>
                        <p style="color:#fd7e14;margin:4px 0 0;"><strong>HIGH RISK</strong> — monitor closely</p>
                    <?php endif; ?>
                <?php } ); ?>

                <?php bcc_trust_stat_card( 'Risk Level', function() use ( $user_info ) { ?>
                    <span style="font-size:20px;font-weight:bold;padding:5px 12px;border-radius:5px;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::riskColor( $user_info->risk_level ) ); ?>;color:#fff;">
                        <?php echo esc_html( ucfirst( $user_info->risk_level ?: 'Unknown' ) ); ?>
                    </span>
                <?php } ); ?>

                <?php bcc_trust_stat_card( 'Trust Rank', function() use ( $user_info ) { ?>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:80px;height:6px;background:#eee;border-radius:3px;overflow:hidden;">
                            <div style="width:<?php echo esc_attr( intval( $user_info->trust_rank * 100 ) ); ?>%;height:100%;background:#4caf50;"></div>
                        </div>
                        <strong style="font-size:20px;"><?php echo esc_html( number_format( $user_info->trust_rank, 2 ) ); ?></strong>
                    </div>
                <?php } ); ?>

                <?php bcc_trust_stat_card( 'Automation Score', function() use ( $user_info, $automation_high ) { ?>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:80px;height:10px;background:#eee;border-radius:5px;overflow:hidden;">
                            <div style="width:<?php echo esc_attr( intval( $user_info->automation_score ) ); ?>%;height:100%;background:#f44336;"></div>
                        </div>
                        <strong style="font-size:20px;"><?php echo intval( $user_info->automation_score ?: 0 ); ?>%</strong>
                    </div>
                    <?php if ( $user_info->automation_score >= $automation_high ): ?>
                        <p style="color:#f44336;margin:4px 0 0;"><strong>HIGH AUTOMATION</strong></p>
                    <?php endif; ?>
                <?php } ); ?>

                <?php bcc_trust_stat_card( 'Behavior Score', function() use ( $user_info ) { ?>
                    <strong style="font-size:22px;"><?php echo esc_html( $user_info->behavior_score ?: 'N/A' ); ?></strong>
                <?php } ); ?>

            </div>
        </div>

        <!-- ══ ACTIVITY STATISTICS ══════════════════════════ -->
        <div class="bcc-panel">
            <h2>Activity Statistics</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:15px;">
                <?php
                $stats = [
                    'Votes Cast'         => number_format( $user_info->votes_cast ),
                    'Endorsements Given' => number_format( $user_info->endorsements_given ),
                    'Pages Owned'        => number_format( $user_info->pages_owned ),
                    'Groups Owned'       => number_format( $user_info->groups_owned ),
                    'Posts Created'      => number_format( $user_info->posts_created ),
                    'Devices Used'       => count( $fingerprints ),
                ];
                foreach ( $stats as $label => $val ):
                ?>
                    <div style="background:#f8f9f9;padding:15px;text-align:center;border-radius:4px;">
                        <div style="font-size:11px;color:#666;margin-bottom:4px;"><?php echo esc_html( $label ); ?></div>
                        <div style="font-size:22px;font-weight:bold;color:#0073aa;"><?php echo esc_html( $val ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ══ MODERATION ACTIONS ═══════════════════════════ -->
        <div class="bcc-panel">
            <h2>Moderation Actions</h2>
            <div style="display:flex;flex-wrap:wrap;gap:10px;">

                <!-- Suspend / Unsuspend -->
                <form method="post" style="display:inline-flex;align-items:center;gap:6px;">
                    <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                    <input type="hidden" name="user_id" value="<?php echo intval( $user_id ); ?>">
                    <?php if ( $user_info->is_suspended ): ?>
                        <button name="unsuspend_user" class="button button-primary">Unsuspend User</button>
                    <?php else: ?>
                        <select name="suspend_reason">
                            <option value="manual_suspension">Manual</option>
                            <option value="fraud_detected">Fraud Detected</option>
                            <option value="vote_ring">Vote Ring</option>
                            <option value="automation">Automation/Bot</option>
                            <option value="abusive">Abusive Behaviour</option>
                            <option value="spam">Spam</option>
                        </select>
                        <input type="text" name="suspend_notes" placeholder="Optional notes…" style="width:200px;">
                        <button name="suspend_user" class="button" style="background:#f44336;border-color:#d32f2f;color:#fff;"
                                onclick="return confirm('Suspend this user?');">Suspend User</button>
                    <?php endif; ?>
                </form>

                <!-- Clear votes -->
                <form method="post">
                    <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                    <input type="hidden" name="user_id" value="<?php echo intval( $user_id ); ?>">
                    <button name="clear_votes" class="button" onclick="return confirm('Clear all votes by this user?');">Clear All Votes</button>
                </form>

                <!-- Clear fingerprints -->
                <form method="post">
                    <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                    <input type="hidden" name="user_id" value="<?php echo intval( $user_id ); ?>">
                    <button name="clear_fingerprints" class="button" onclick="return confirm('Clear device fingerprints?');">Clear Fingerprints</button>
                </form>

                <!-- Reanalyze -->
                <form method="post">
                    <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                    <input type="hidden" name="user_id" value="<?php echo intval( $user_id ); ?>">
                    <button name="reanalyze_user" class="button">Force Reanalyze</button>
                </form>

            </div>
        </div>

        <!-- ══ MANUAL TRUST SCORE OVERRIDE (NEW) ═══════════ -->
        <div class="bcc-panel" style="border-left:4px solid #ff9800;">
            <h2>Manual Trust Score Override</h2>
            <p style="color:#666;">Override the calculated trust score for all pages owned by this user. A reason is required and will be logged.</p>
            <form method="post" onsubmit="return confirm('Override trust score to ' + document.getElementById('override_score_input').value + '? This affects all pages owned by this user.');">
                <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                <table class="form-table" style="max-width:500px;">
                    <tr>
                        <th><label for="override_score_input">New Score (0–100)</label></th>
                        <td>
                            <input id="override_score_input" type="number" name="override_score_value"
                                   min="0" max="100" step="1" value="50" required style="width:80px;">
                            <small style="margin-left:8px;color:#666;">Current avg: <?php
                                $avg = $repo->getUserPagesAvgScore( $user_id );
                                echo esc_html( $avg !== null ? number_format( $avg, 1 ) : 'N/A' );
                            ?></small>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="override_reason">Reason</label></th>
                        <td>
                            <textarea id="override_reason" name="override_reason" rows="2"
                                      style="width:300px;" required placeholder="Required — explain why this override is needed…"></textarea>
                        </td>
                    </tr>
                </table>
                <p>
                    <button name="override_score" class="button" style="background:#ff9800;border-color:#e68900;color:#fff;">Apply Score Override</button>
                </p>
            </form>
        </div>

        <!-- ══ WHITELIST ════════════════════════════════════ -->
        <div class="bcc-panel">
            <h2>Whitelist User's IP / Device</h2>
            <p style="color:#666;">Whitelist an IP or fingerprint to exclude it from fraud scoring.</p>
            <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                <select name="whitelist_type">
                    <option value="ip">IP Address</option>
                    <option value="fingerprint">Device Fingerprint</option>
                </select>
                <input type="text" name="whitelist_value" placeholder="IP or fingerprint value" style="width:250px;" required>
                <input type="text" name="whitelist_note" placeholder="Note (optional)" style="width:180px;">
                <button name="add_whitelist" class="button">Add to Whitelist</button>
            </form>
        </div>

        <!-- ══ SUSPENSION HISTORY ═══════════════════════════ -->
        <?php if ( ! empty( $suspension_history ) ): ?>
        <div class="bcc-panel">
            <h2>Suspension History</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Suspended</th><th>By</th><th>Reason</th><th>Notes</th>
                    <th>Fraud Score</th><th>Lifted</th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $suspension_history as $s ):
                        $by   = $s->suspended_by   ? get_userdata( $s->suspended_by )   : null;
                        $lift = $s->unsuspended_by  ? get_userdata( $s->unsuspended_by ) : null;
                    ?>
                        <tr>
                            <td><?php echo esc_html( $s->suspended_at ); ?></td>
                            <td><?php echo $by ? esc_html( $by->display_name ) : 'System'; ?></td>
                            <td><?php echo esc_html( $s->reason ); ?></td>
                            <td><?php echo esc_html( $s->notes ?: '—' ); ?></td>
                            <td><?php echo esc_html( $s->fraud_score_at_time ?: '—' ); ?></td>
                            <td>
                                <?php if ( $s->unsuspended_at ): ?>
                                    <?php echo esc_html( $s->unsuspended_at ); ?>
                                    <br><small>by <?php echo $lift ? esc_html( $lift->display_name ) : 'System'; ?></small>
                                <?php else: ?>
                                    <strong style="color:#f44336;">Active</strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ══ FRAUD ANALYSIS HISTORY ═══════════════════════ -->
        <?php if ( ! empty( $fraud_history ) ): ?>
        <div class="bcc-panel">
            <h2>Fraud Analysis History</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Analysed</th><th>Score</th><th>Risk</th>
                    <th>Confidence</th><th>Triggers</th><th>Expires</th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $fraud_history as $fa ):
                        $triggers = $fa->triggers ? json_decode( $fa->triggers, true ) : [];
                    ?>
                        <tr>
                            <td><?php echo esc_html( $fa->analyzed_at ); ?></td>
                            <td><strong><?php echo intval( $fa->fraud_score ); ?></strong></td>
                            <td><span style="padding:2px 6px;border-radius:3px;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::riskColor( $fa->risk_level ) ); ?>;color:#fff;"><?php echo esc_html( ucfirst( $fa->risk_level ?: 'unknown' ) ); ?></span></td>
                            <td><?php echo intval( round( $fa->confidence * 100 ) ); ?>%</td>
                            <td><?php echo esc_html( implode( ', ', array_slice( $triggers, 0, 3 ) ) ?: '—' ); ?><?php echo count( $triggers ) > 3 ? '…' : ''; ?></td>
                            <td><?php echo esc_html( $fa->expires_at ?: 'Never' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ══ DEVICE FINGERPRINTS ══════════════════════════ -->
        <?php if ( ! empty( $fingerprints ) ): ?>
        <div class="bcc-panel">
            <h2>Device Fingerprints</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Fingerprint</th><th>Automation</th><th>Risk</th>
                    <th>First Seen</th><th>Last Seen</th><th>Actions</th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $fingerprints as $fp ): ?>
                        <tr>
                            <td><code><?php echo esc_html( substr( $fp->fingerprint, 0, 16 ) ); ?>…</code></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div style="width:50px;height:6px;background:#eee;border-radius:3px;overflow:hidden;">
                                        <div style="width:<?php echo esc_attr( intval( $fp->automation_score ) ); ?>%;height:100%;background:#f44336;"></div>
                                    </div>
                                    <?php echo intval( $fp->automation_score ); ?>%
                                </div>
                            </td>
                            <td><span style="padding:2px 6px;border-radius:3px;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::riskColor( $fp->risk_level ) ); ?>;color:#fff;"><?php echo esc_html( $fp->risk_level ?: 'unknown' ); ?></span></td>
                            <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $fp->first_seen ) ) ); ?></td>
                            <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $fp->last_seen ) ) ); ?></td>
                            <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&fingerprint=' . urlencode( $fp->fingerprint ) ) ); ?>" class="button button-small">Investigate</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ══ OWNED PAGES ══════════════════════════════════ -->
        <?php if ( ! empty( $user_pages ) ):
            $up_ids  = array_map( function( $pg ) { return (int) $pg->page_id; }, $user_pages );
            $up_cpts = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getCptMap( $up_ids );
        ?>
        <div class="bcc-panel">
            <h2>Owned Pages / Projects</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Page</th><th>Page ID</th><th>CPT ID</th><th>Score</th><th>Tier</th>
                    <th>Votes</th><th>Endorsements</th><th>Action</th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $user_pages as $pg ):
                        $pg_cpt = $up_cpts[ (int) $pg->page_id ] ?? null;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( $pg->post_title ?: 'Page #' . $pg->page_id ); ?></strong></td>
                            <td><?php echo intval( $pg->page_id ); ?></td>
                            <td><?php if ( $pg_cpt ): ?>
                                    <a href="<?php echo esc_url( get_edit_post_link( $pg_cpt['cpt_id'] ) ); ?>"><?php echo intval( $pg_cpt['cpt_id'] ); ?></a>
                                    <br><small style="color:#888;"><?php echo esc_html( $pg_cpt['cpt_type'] ); ?></small>
                                <?php else: ?>
                                    <span style="color:#999;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo esc_html( number_format( $pg->total_score, 1 ) ); ?></strong></td>
                            <td><span style="padding:3px 8px;border-radius:3px;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::tierColor( $pg->reputation_tier ) ); ?>;color:#fff;"><?php echo esc_html( $pg->reputation_tier ); ?></span></td>
                            <td><?php echo esc_html( number_format( $pg->vote_count ) ); ?></td>
                            <td><?php echo esc_html( number_format( $pg->endorsement_count ) ); ?></td>
                            <td><a href="<?php echo esc_url( get_permalink( $pg->page_id ) ); ?>" class="button button-small" target="_blank">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ══ RECENT VOTES ══════════════════════════════════ -->
        <?php if ( ! empty( $recent_votes ) ): ?>
        <div class="bcc-panel">
            <h2>Recent Votes Cast</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Page</th><th>Type</th><th>Weight</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ( $recent_votes as $v ): ?>
                        <tr>
                            <td><?php if ( $v->page_title ): ?>
                                    <a href="<?php echo esc_url( get_permalink( $v->page_id ) ); ?>" target="_blank"><?php echo esc_html( $v->page_title ); ?></a>
                                <?php else: ?>
                                    Page #<?php echo intval( $v->page_id ); ?>
                                <?php endif; ?></td>
                            <td><?php echo $v->vote_type > 0 ? '&#x2B06; Up' : '&#x2B07; Down'; ?></td>
                            <td><?php echo esc_html( $v->weight ); ?></td>
                            <td><?php echo esc_html( $v->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ══ IP ADDRESSES ══════════════════════════════════ -->
        <?php if ( ! empty( $user_ips ) ): ?>
        <div class="bcc-panel">
            <h2>IP Addresses Used</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>IP</th><th>Activity Count</th><th>Last Seen</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ( $user_ips as $ip_row ):
                        $ip_str = $ip_row->ip_address ? ( @inet_ntop( $ip_row->ip_address ) ?: 'Invalid' ) : '—';
                        $whitelisted = \BCC\Trust\Core\Plugin::instance()->moderationService()->isIpWhitelisted( $ip_str );
                    ?>
                        <tr>
                            <td>
                                <code><?php echo esc_html( $ip_str ); ?></code>
                                <?php if ( $whitelisted ): ?> <span style="color:#4caf50;font-size:11px;">✓ Whitelisted</span><?php endif; ?>
                            </td>
                            <td><?php echo intval( $ip_row->count ); ?></td>
                            <td><?php echo esc_html( $ip_row->last_seen ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&ip=' . urlencode( $ip_str ) ) ); ?>" class="button button-small">Investigate</a>
                                <?php if ( ! $whitelisted && $ip_str !== '—' ): ?>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field( 'bcc_trust_moderation' ); ?>
                                        <input type="hidden" name="user_id" value="<?php echo intval( $user_id ); ?>">
                                        <input type="hidden" name="whitelist_type" value="ip">
                                        <input type="hidden" name="whitelist_value" value="<?php echo esc_attr( $ip_str ); ?>">
                                        <input type="hidden" name="whitelist_note" value="Whitelisted from user #<?php echo intval( $user_id ); ?> detail page">
                                        <button name="add_whitelist" class="button button-small">Whitelist IP</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ══ RECENT ACTIVITY ════════════════════════════════ -->
        <?php if ( ! empty( $recent_activity ) ): ?>
        <div class="bcc-panel">
            <h2>Recent Activity Log</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Time</th><th>Action</th><th>Target</th></tr></thead>
                <tbody>
                    <?php foreach ( $recent_activity as $act ): ?>
                        <tr>
                            <td><?php echo esc_html( $act->created_at ); ?></td>
                            <td>
                                <?php echo esc_html( $act->action ); ?>
                                <?php if ( strpos( $act->action, 'admin_' ) === 0 ): ?>
                                    <span style="background:#0073aa;color:#fff;font-size:10px;padding:1px 4px;border-radius:3px;margin-left:4px;">ADMIN</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $act->target_id && $act->target_type === 'page' ): ?>
                                    <a href="<?php echo esc_url( get_permalink( $act->target_id ) ); ?>" target="_blank">
                                        <?php echo esc_html( get_the_title( $act->target_id ) ?: 'Page #' . $act->target_id ); ?>
                                    </a>
                                <?php elseif ( $act->target_id ): ?>
                                    <?php echo esc_html( $act->target_type . ' #' . $act->target_id ); ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>

    <style>
    .bcc-panel {
        background: #fff;
        padding: 20px;
        margin-top: 20px;
        border: 1px solid #ccd0d4;
        border-radius: 3px;
    }
    .bcc-panel h2 {
        margin-top: 0;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }
    </style>
    <?php
}

// ──────────────────────────────────────────────────────────────
// STAT CARD HELPER
// ──────────────────────────────────────────────────────────────

function bcc_trust_stat_card( $title, $content_cb ) {
    echo '<div style="background:#f8f9f9;padding:15px;border-radius:4px;">';
    echo '<div style="font-size:12px;color:#666;margin-bottom:8px;">' . esc_html( $title ) . '</div>';
    call_user_func( $content_cb );
    echo '</div>';
}