<?php
/**
 * Moderation — User List View
 *
 * Renders the paginated, filterable user table with bulk-action support.
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_user_list() {
    $repo = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();

    // ── Filters & pagination ──────────────────────────────────
    $search     = isset( $_GET['s'] )          ? sanitize_text_field( $_GET['s'] )   : '';
    $filter     = isset( $_GET['filter'] )     ? sanitize_key( $_GET['filter'] )     : 'all';
    $risk_level = isset( $_GET['risk_level'] ) ? sanitize_key( $_GET['risk_level'] ) : '';

    $fraud_high   = BCC_TRUST_FRAUD_HIGH;
    $fraud_medium = BCC_TRUST_FRAUD_MEDIUM;
    $fraud_low    = BCC_TRUST_FRAUD_LOW;

    $current_page = max( 1, intval( $_GET['paged'] ?? 1 ) );
    $per_page     = BCC_TRUST_ADMIN_USERS_PER_PAGE;
    $offset       = ( $current_page - 1 ) * $per_page;

    // ── Query builder ─────────────────────────────────────────
    $where  = [ '1=1' ];
    $params = [];

    if ( $search ) {
        $like       = '%' . \BCC\Trust\Core\Repositories\AdminDashboardRepository::escLike( $search ) . '%';
        $where[]    = '(display_name LIKE %s OR user_email LIKE %s OR user_login LIKE %s)';
        $params[]   = $like;
        $params[]   = $like;
        $params[]   = $like;
    }

    if ( $risk_level ) {
        $where[]  = 'risk_level = %s';
        $params[] = $risk_level;
    }

    switch ( $filter ) {
        case 'suspended':
            $where[] = 'is_suspended = 1';
            break;
        case 'verified':
            $where[] = 'is_verified = 1';
            break;
        case 'high_fraud':
            $where[]  = 'fraud_score >= %d';
            $params[] = $fraud_high;
            break;
        case 'medium_fraud':
            $where[]  = 'fraud_score >= %d AND fraud_score < %d';
            $params[] = $fraud_medium;
            $params[] = $fraud_high;
            break;
        case 'low_fraud':
            $where[]  = 'fraud_score >= %d AND fraud_score < %d';
            $params[] = $fraud_low;
            $params[] = $fraud_medium;
            break;
    }

    $where_clause = implode( ' AND ', $where );

    $total_items = $repo->getModerationUserCount( $where_clause, $params );
    $users       = $repo->getModerationUsers( $where_clause, $params, $per_page, $offset );

    // ── Sync handler ──────────────────────────────────────────
    if ( isset( $_GET['sync'] ) && $_GET['sync'] === '1' ) {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'bcc_sync_users' ) ) {
            wp_die( __( 'Security check failed.', 'bcc-trust-engine' ) );
        }
        $synced = \BCC\Trust\Core\Plugin::instance()->userSyncService()->sync();
        wp_safe_redirect( admin_url( 'admin.php?page=bcc-trust-moderation&synced=' . intval( $synced ) ) );
        exit;
    }

    // ── Flash messages ────────────────────────────────────────
    if ( isset( $_GET['bulk'] ) ) {
        $n = intval( $_GET['bulk'] );
        echo '<div class="notice notice-success is-dismissible"><p>Bulk action applied to ' . $n . ' user(s).</p></div>';
    }
    ?>

    <div style="margin-top:0;">
        <div style="margin: 10px 0 15px;">
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bcc-trust-moderation&sync=1' ), 'bcc_sync_users' ) ); ?>" class="button">Sync All Users</a>
            <?php if ( isset( $_GET['synced'] ) ): ?>
                <span style="margin-left:10px; color:green;">Synced <?php echo intval( $_GET['synced'] ); ?> users.</span>
            <?php endif; ?>
        </div>

        <!-- ── Filters ────────────────────────────────────── -->
        <form method="get">
            <input type="hidden" name="page" value="bcc-trust-moderation">
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="filter">
                        <option value="all"         <?php selected( $filter, 'all' ); ?>>All Users</option>
                        <option value="suspended"   <?php selected( $filter, 'suspended' ); ?>>Suspended</option>
                        <option value="verified"    <?php selected( $filter, 'verified' ); ?>>Verified</option>
                        <option value="high_fraud"  <?php selected( $filter, 'high_fraud' ); ?>>High Fraud (<?php echo $fraud_high; ?>+)</option>
                        <option value="medium_fraud"<?php selected( $filter, 'medium_fraud' ); ?>>Medium Fraud (<?php echo $fraud_medium; ?>–<?php echo $fraud_high - 1; ?>)</option>
                        <option value="low_fraud"   <?php selected( $filter, 'low_fraud' ); ?>>Low Fraud (<?php echo $fraud_low; ?>–<?php echo $fraud_medium - 1; ?>)</option>
                    </select>

                    <select name="risk_level">
                        <option value="">All Risk Levels</option>
                        <?php foreach ( [ 'critical', 'high', 'medium', 'low', 'minimal' ] as $rl ): ?>
                            <option value="<?php echo $rl; ?>" <?php selected( $risk_level, $rl ); ?>><?php echo ucfirst( $rl ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="submit" class="button" value="Filter">

                    <?php if ( $search || $filter !== 'all' || $risk_level ): ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation' ) ); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </div>

                <div class="alignright">
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search users…">
                    <input type="submit" class="button" value="Search">
                </div>
            </div>
        </form>

        <!-- ── Table ──────────────────────────────────────── -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="30"><input type="checkbox" id="bcc-select-all"></th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Fraud Score</th>
                    <th>Risk Level</th>
                    <th>Last Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $users ) ): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:20px;">
                            <strong>No users found.</strong>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ( $users as $u ): ?>
                        <tr>
                            <td><input type="checkbox" class="bcc-user-cb" value="<?php echo esc_attr($u->user_id); ?>"></td>

                            <td>
                                <strong><?php echo esc_html( $u->display_name ?: $u->user_login ); ?></strong>
                                <br><small>ID: <?php echo intval($u->user_id); ?></small>
                            </td>

                            <td><?php echo esc_html( $u->user_email ); ?></td>

                            <td>
                                <?php if ( $u->is_suspended || $u->active_suspensions > 0 ): ?>
                                    <span style="background:#f44336;color:#fff;padding:3px 8px;border-radius:3px;">Suspended</span>
                                <?php else: ?>
                                    <span style="background:#4caf50;color:#fff;padding:3px 8px;border-radius:3px;">Active</span>
                                <?php endif; ?>
                                <?php if ( $u->is_verified ): ?>
                                    <br><small style="color:#4caf50;">✓ Verified</small>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div style="width:60px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                        <div style="width:<?php echo esc_attr(intval($u->fraud_score)); ?>%;height:100%;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::scoreColor( $u->fraud_score ) ); ?>;"></div>
                                    </div>
                                    <?php echo intval($u->fraud_score); ?>
                                </div>
                            </td>

                            <td>
                                <span style="padding:3px 8px;border-radius:3px;background:<?php echo esc_attr( \BCC\Trust\Core\Support\Formatting::riskColor( $u->risk_level ) ); ?>;color:#fff;">
                                    <?php echo esc_html( ucfirst( $u->risk_level ?: 'unknown' ) ); ?>
                                </span>
                            </td>

                            <td>
                                <?php echo $u->usr_last_activity
                                    ? esc_html( date( 'Y-m-d H:i', strtotime( $u->usr_last_activity ) ) )
                                    : '—'; ?>
                            </td>

                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $u->user_id ) ); ?>" class="button button-small">Manage</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- ── Bulk actions + pagination ─────────────────── -->
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <select id="bcc-bulk-select">
                    <option value="-1">Bulk Actions</option>
                    <option value="suspend">Suspend</option>
                    <option value="unsuspend">Unsuspend</option>
                    <option value="clear_votes">Clear Votes</option>
                    <option value="reanalyze">Reanalyze</option>
                </select>
                <button type="button" id="bcc-bulk-apply" class="button action">Apply</button>
            </div>

            <?php
            $total_pages = ceil( $total_items / $per_page );
            if ( $total_pages > 1 ):
            ?>
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo number_format( $total_items ); ?> items</span>
                <?php echo paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => (int) $total_pages,
                    'current'   => $current_page,
                ] ); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    jQuery( function( $ ) {
        $( '#bcc-select-all' ).on( 'change', function () {
            $( '.bcc-user-cb' ).prop( 'checked', this.checked );
        } );

        $( '#bcc-bulk-apply' ).on( 'click', function () {
            var action = $( '#bcc-bulk-select' ).val();
            var ids    = $( '.bcc-user-cb:checked' ).map( function () { return this.value; } ).get();

            if ( action === '-1' ) { alert( 'Please choose a bulk action.' ); return; }
            if ( ! ids.length )   { alert( 'Please select at least one user.' ); return; }

            if ( confirm( 'Apply "' + action + '" to ' + ids.length + ' user(s)?' ) ) {
                // Security: submit via POST, not GET. Destructive operations
                // must never use GET (cacheable, leaks in referrer headers).
                var form = document.createElement( 'form' );
                form.method = 'POST';
                form.action = '<?php echo esc_js( admin_url( 'admin-post.php' ) ); ?>';
                var fields = {
                    'action':      'bcc_trust_bulk_action',
                    'bulk_action': action,
                    'users':       ids.join( ',' ),
                    '_wpnonce':    '<?php echo esc_js( wp_create_nonce( 'bcc_trust_bulk_action' ) ); ?>'
                };
                for ( var key in fields ) {
                    var input = document.createElement( 'input' );
                    input.type  = 'hidden';
                    input.name  = key;
                    input.value = fields[ key ];
                    form.appendChild( input );
                }
                document.body.appendChild( form );
                form.submit();
            }
        } );
    } );
    </script>
    <?php
}