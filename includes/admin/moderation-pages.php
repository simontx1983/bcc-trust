<?php
/**
 * Moderation — Page List View
 *
 * Renders a paginated, filterable table of all pages with trust data.
 * Queries page_read_model (denormalized) + page_flags for flag counts.
 * Links to Trust Debug Panel for deep-dive.
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_render_page_list() {
    $repo = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();

    // ── Filters & pagination ──────────────────────────────────
    $search = isset( $_GET['s'] )      ? sanitize_text_field( $_GET['s'] ) : '';
    $filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] )   : 'all';
    $sort   = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'trust_score';
    $order  = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';

    $allowed_sorts = [
        'trust_score'       => 'trust_score',
        'vote_count'        => 'vote_count',
        'endorsement_count' => 'endorsement_count',
        'flag_count'        => 'flag_count',
        'updated_at'        => 'updated_at',
    ];
    $sort_column = $allowed_sorts[ $sort ] ?? 'trust_score';

    $current_page = max( 1, intval( $_GET['paged'] ?? 1 ) );
    $per_page     = 30;
    $offset       = ( $current_page - 1 ) * $per_page;

    // ── Query builder ─────────────────────────────────────────
    $where  = [ '1=1' ];
    $params = [];

    if ( $search ) {
        $like     = '%' . \BCC\Trust\Core\Repositories\AdminDashboardRepository::escLike( $search ) . '%';
        $where[]  = '(post_title LIKE %s OR owner_id = %d)';
        $params[] = $like;
        $params[] = (int) $search;
    }

    switch ( $filter ) {
        case 'flagged':
            $where[] = 'flag_count > 0';
            break;
        case 'low_score':
            $where[] = 'trust_score < 30';
            break;
        case 'high_score':
            $where[] = 'trust_score >= 80';
            break;
        case 'no_votes':
            $where[] = 'vote_count = 0';
            break;
        case 'risky':
            $where[] = "reputation_tier = 'risky'";
            break;
        case 'caution':
            $where[] = "reputation_tier = 'caution'";
            break;
    }

    $where_clause = implode( ' AND ', $where );

    $flags_exists = $repo->pageFlagsTableExists();
    $total_items  = $repo->getPageListCount( $where_clause, $params, $flags_exists );
    $pages        = $repo->getPageListData( $where_clause, $params, $sort_column, $order, $per_page, $offset, $flags_exists );

    $base_url = admin_url( 'admin.php?page=bcc-trust-moderation&tab=pages' );
    ?>

    <div style="margin-top:0;">

        <!-- ── Filters ────────────────────────────────────── -->
        <form method="get">
            <input type="hidden" name="page" value="bcc-trust-moderation">
            <input type="hidden" name="tab" value="pages">
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="filter">
                        <option value="all"        <?php selected( $filter, 'all' ); ?>>All Pages</option>
                        <option value="flagged"    <?php selected( $filter, 'flagged' ); ?>>Flagged</option>
                        <option value="low_score"  <?php selected( $filter, 'low_score' ); ?>>Low Score (&lt;30)</option>
                        <option value="high_score" <?php selected( $filter, 'high_score' ); ?>>High Score (80+)</option>
                        <option value="no_votes"   <?php selected( $filter, 'no_votes' ); ?>>No Votes</option>
                        <option value="risky"      <?php selected( $filter, 'risky' ); ?>>Risky Tier</option>
                        <option value="caution"    <?php selected( $filter, 'caution' ); ?>>Caution Tier</option>
                    </select>
                    <input type="submit" class="button" value="Filter">
                    <?php if ( $search || $filter !== 'all' ): ?>
                        <a href="<?php echo esc_url( $base_url ); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </div>
                <div class="alignright">
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by title or owner ID...">
                    <input type="submit" class="button" value="Search">
                </div>
            </div>
        </form>

        <!-- ── Table ──────────────────────────────────────── -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <?php
                    $columns = [
                        'trust_score'       => 'Score',
                        '' => 'Page',
                        '' => 'Owner',
                        '' => 'Tier',
                        'vote_count'        => 'Votes',
                        'endorsement_count' => 'Endorsements',
                        'flag_count'        => 'Flags',
                        '' => 'Verified',
                        'updated_at'        => 'Last Updated',
                        '' => 'Actions',
                    ];
                    ?>
                    <th width="70">
                        <?php bcc_trust_sortable_th( 'Score', 'trust_score', $sort, $order, $base_url, $filter, $search ); ?>
                    </th>
                    <th>Page</th>
                    <th>Owner</th>
                    <th width="80">Tier</th>
                    <th width="60">
                        <?php bcc_trust_sortable_th( 'Votes', 'vote_count', $sort, $order, $base_url, $filter, $search ); ?>
                    </th>
                    <th width="100">
                        <?php bcc_trust_sortable_th( 'Endorse', 'endorsement_count', $sort, $order, $base_url, $filter, $search ); ?>
                    </th>
                    <th width="60">
                        <?php bcc_trust_sortable_th( 'Flags', 'flag_count', $sort, $order, $base_url, $filter, $search ); ?>
                    </th>
                    <th width="70">Verified</th>
                    <th width="120">
                        <?php bcc_trust_sortable_th( 'Updated', 'updated_at', $sort, $order, $base_url, $filter, $search ); ?>
                    </th>
                    <th width="80">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $pages ) ): ?>
                    <tr>
                        <td colspan="10" style="text-align:center; padding:20px;">
                            <strong>No pages found.</strong>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ( $pages as $pg ): ?>
                        <?php
                        $tier_info = bcc_trust_get_tier_info_from_score( $pg->trust_score );
                        $flag_count = (int) $pg->flag_count;
                        ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div style="width:40px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                        <div style="width:<?php echo esc_attr( $pg->trust_score ); ?>%;height:100%;background:<?php echo esc_attr( $tier_info['color'] ); ?>;"></div>
                                    </div>
                                    <strong><?php echo esc_html( round( $pg->trust_score, 1 ) ); ?></strong>
                                </div>
                            </td>

                            <td>
                                <strong><?php echo esc_html( $pg->post_title ?: '(No title)' ); ?></strong>
                                <br><small>ID: <?php echo (int) $pg->page_id; ?></small>
                            </td>

                            <td>
                                <?php if ( $pg->owner_id ): ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-moderation&user_id=' . $pg->owner_id ) ); ?>">
                                        <?php echo esc_html( $pg->owner_name ?: 'User #' . $pg->owner_id ); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color:#999;">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span style="padding:2px 8px;border-radius:3px;background:<?php echo esc_attr( $tier_info['color'] ); ?>;color:#fff;font-size:0.85em;">
                                    <?php echo esc_html( $tier_info['label'] ); ?>
                                </span>
                            </td>

                            <td>
                                <?php echo (int) $pg->vote_count; ?>
                                <?php if ( $pg->unique_voters ): ?>
                                    <br><small style="color:#888;"><?php echo (int) $pg->unique_voters; ?> unique</small>
                                <?php endif; ?>
                            </td>

                            <td><?php echo (int) $pg->endorsement_count; ?></td>

                            <td>
                                <?php if ( $flag_count > 0 ): ?>
                                    <span style="color:#b45309;font-weight:600;"><?php echo $flag_count; ?></span>
                                <?php else: ?>
                                    <span style="color:#999;">0</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                $badges = [];
                                if ( $pg->is_verified )      $badges[] = 'Email';
                                if ( $pg->github_username )  $badges[] = 'GH';
                                if ( $pg->has_wallet )       $badges[] = 'Wallet';
                                echo $badges ? esc_html( implode( ', ', $badges ) ) : '<span style="color:#999;">&mdash;</span>';
                                ?>
                            </td>

                            <td>
                                <?php echo $pg->updated_at ? esc_html( date( 'M j', strtotime( $pg->updated_at ) ) ) : '&mdash;'; ?>
                            </td>

                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bcc-trust-debug&page_id=' . $pg->page_id ) ); ?>"
                                   class="button button-small" title="Open in Trust Debug Panel">Debug</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- ── Pagination ─────────────────────────────────── -->
        <?php
        $total_pages = ceil( $total_items / $per_page );
        if ( $total_pages > 1 ):
        ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo number_format( $total_items ); ?> pages</span>
                <?php echo paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => (int) $total_pages,
                    'current'   => $current_page,
                ] ); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render a sortable column header link.
 */
function bcc_trust_sortable_th( string $label, string $col, string $current_sort, string $current_order, string $base_url, string $filter, string $search ): void {
    $is_active  = ( $current_sort === $col );
    $next_order = ( $is_active && $current_order === 'DESC' ) ? 'ASC' : 'DESC';
    $arrow      = $is_active ? ( $current_order === 'DESC' ? ' &#9660;' : ' &#9650;' ) : '';

    $url = add_query_arg( [
        'orderby' => $col,
        'order'   => $next_order,
        'filter'  => $filter,
        's'       => $search,
    ], $base_url );

    printf(
        '<a href="%s" style="text-decoration:none;%s">%s%s</a>',
        esc_url( $url ),
        $is_active ? 'font-weight:700;' : '',
        esc_html( $label ),
        $arrow
    );
}
