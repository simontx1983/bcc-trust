<?php
/**
 * Trust Engine — Verified Users Page
 *
 * Standalone submenu page listing all users who have completed any
 * identity verification signal: GitHub, Cosmos, Ethereum, or Solana.
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the Verified Users submenu page.
 */
function bcc_trust_render_verified_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Sorry, you are not allowed to access this page.' ) );
    }

    $repo = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();

    // ── Input sanitisation ────────────────────────────────────
    $search      = isset( $_GET['s'] )     ? sanitize_text_field( $_GET['s'] )       : '';
    $orderby_raw = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] )       : 'display_name';
    $order       = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'DESC' ? 'DESC' : 'ASC';
    $per_page    = BCC_TRUST_ADMIN_VERIFIED_PER_PAGE;
    $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $offset      = ( $current_page - 1 ) * $per_page;

    $orderby_map = [
        'display_name' => 'u.display_name',
        'user_email'   => 'u.user_email',
    ];
    $orderby_col = $orderby_map[ $orderby_raw ] ?? 'u.display_name';

    // ── WHERE conditions ──────────────────────────────────────
    $where  = [];
    $params = [];

    if ( $search ) {
        $like    = '%' . \BCC\Trust\Core\Repositories\AdminDashboardRepository::escLike( $search ) . '%';
        $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR gh.provider_username LIKE %s)';
        $params  = array_merge( $params, [ $like, $like, $like ] );
    }

    $where_sql = $where ? ( 'AND ' . implode( ' AND ', $where ) ) : '';

    // ── Data via repository ───────────────────────────────────
    $total_items = $repo->getAllVerifiedUsersCount( $where_sql, $params );
    $users       = $repo->getAllVerifiedUsersFiltered( $where_sql, $params, $orderby_col, $order, $per_page, $offset );

    // ── Decode NFT collections from wallet meta ───────────────
    foreach ( $users as $u ) {
        $u->eth_nfts    = bcc_trust_verified_extract_nfts( $u->eth_meta );
        $u->sol_nfts    = bcc_trust_verified_extract_nfts( $u->sol_meta );
        $u->cos_nfts    = bcc_trust_verified_extract_nfts( $u->cos_meta );
        $u->github_verified_email = ( $u->github_verified_email_flag === 'true' || $u->github_verified_email_flag === '1' );
        unset( $u->eth_meta, $u->sol_meta, $u->cos_meta, $u->github_verified_email_flag );
    }

    // ── Summary counts via repository ─────────────────────────
    $type_counts  = $repo->getVerificationTypeCounts();
    $count_github = $type_counts['github'];
    $count_eth    = $type_counts['wallet_ethereum'];
    $count_sol    = $type_counts['wallet_solana'];
    $count_cosmos = $type_counts['wallet_cosmos'];

    $total_pages = ceil( $total_items / $per_page );
    $base_url    = admin_url( 'admin.php?page=bcc-trust-verified' );
    ?>

    <div class="wrap bcc-admin-wrap">
        <h1>Verified Users</h1>
        <p style="color:#666;">Users who have completed at least one identity verification signal.</p>

        <!-- Summary Stats -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;">
            <div class="bcc-panel" style="text-align:center;border-left:5px solid #24292e;">
                <div style="font-size:28px;font-weight:bold;"><?php echo $count_github; ?></div>
                <div style="font-size:13px;color:#666;">GitHub</div>
            </div>
            <div class="bcc-panel" style="text-align:center;border-left:5px solid #627eea;">
                <div style="font-size:28px;font-weight:bold;"><?php echo $count_eth; ?></div>
                <div style="font-size:13px;color:#666;">Ethereum</div>
            </div>
            <div class="bcc-panel" style="text-align:center;border-left:5px solid #9945ff;">
                <div style="font-size:28px;font-weight:bold;"><?php echo $count_sol; ?></div>
                <div style="font-size:13px;color:#666;">Solana</div>
            </div>
            <div class="bcc-panel" style="text-align:center;border-left:5px solid #2e3148;">
                <div style="font-size:28px;font-weight:bold;"><?php echo $count_cosmos; ?></div>
                <div style="font-size:13px;color:#666;">Cosmos</div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="tablenav top">
            <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="page" value="bcc-trust-verified">

                <input
                    type="search"
                    name="s"
                    value="<?php echo esc_attr( $search ); ?>"
                    placeholder="Search name, email, GitHub username…"
                    style="min-width:260px;"
                >

                <select name="orderby">
                    <option value="display_name" <?php selected( $orderby_raw, 'display_name' ); ?>>Sort by Name</option>
                    <option value="user_email"   <?php selected( $orderby_raw, 'user_email' ); ?>>Sort by Email</option>
                </select>

                <select name="order">
                    <option value="ASC"  <?php selected( $order, 'ASC' ); ?>>Ascending</option>
                    <option value="DESC" <?php selected( $order, 'DESC' ); ?>>Descending</option>
                </select>

                <input type="submit" class="button" value="Apply">

                <?php if ( $search ): ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button">Clear</a>
                <?php endif; ?>

                <span class="displaying-num" style="margin-left:auto;">
                    <?php echo number_format( $total_items ); ?> user<?php echo $total_items !== 1 ? 's' : ''; ?>
                </span>
            </form>
        </div>

        <!-- Table -->
        <table class="wp-list-table widefat fixed striped" style="table-layout:auto;">
            <thead>
                <tr>
                    <th style="width:180px;">User</th>
                    <th style="width:180px;">Email</th>
                    <th style="width:160px;">GitHub</th>
                    <th>Cosmos NFTs</th>
                    <th>Ethereum NFTs</th>
                    <th>Solana NFTs</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $users ) ): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;padding:24px;">
                            <strong>No verified users found.</strong>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ( $users as $u ): ?>
                    <tr>

                        <!-- User -->
                        <td>
                            <strong><?php echo esc_html( $u->display_name ?: 'User #' . $u->user_id ); ?></strong>
                            <br><small style="color:#999;">ID: <?php echo intval( $u->user_id ); ?></small>
                        </td>

                        <!-- Email -->
                        <td><?php echo esc_html( $u->user_email ); ?></td>

                        <!-- GitHub -->
                        <td>
                            <?php if ( $u->github_username ): ?>
                                <strong><?php echo esc_html( $u->github_username ); ?></strong>
                                <?php if ( $u->github_verified_email ): ?>
                                    <br><small style="color:#4caf50;">✓ verified email</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Cosmos NFTs -->
                        <td><?php bcc_trust_verified_render_nfts( $u->cos_nfts, 'bcc-cos-' . intval( $u->user_id ) ); ?></td>

                        <!-- Ethereum NFTs -->
                        <td><?php bcc_trust_verified_render_nfts( $u->eth_nfts, 'bcc-eth-' . intval( $u->user_id ) ); ?></td>

                        <!-- Solana NFTs -->
                        <td><?php bcc_trust_verified_render_nfts( $u->sol_nfts, 'bcc-sol-' . intval( $u->user_id ) ); ?></td>

                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo number_format( $total_items ); ?> items</span>
                <?php
                echo paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => (int) $total_pages,
                    'current'   => $current_page,
                ] );
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php
}

/**
 * Extract NFT collection names from wallet meta JSON.
 *
 * @param string|null $meta_json  Raw JSON string from the meta column.
 * @return string[]               Array of NFT names.
 */
function bcc_trust_verified_extract_nfts( ?string $meta_json ): array {
    if ( ! $meta_json ) {
        return [];
    }
    $meta = json_decode( $meta_json, true );
    if ( ! is_array( $meta ) || empty( $meta['nft_collections'] ) ) {
        return [];
    }
    $names = [];
    foreach ( $meta['nft_collections'] as $nft ) {
        $name = $nft['name'] ?? '';
        if ( $name !== '' ) {
            $names[] = $name;
        }
    }
    return $names;
}

/**
 * Render an NFT list cell.
 *
 * Shows the first 2 NFTs inline. If there are more, adds a toggle button
 * that reveals the rest in a hidden list.
 *
 * @param string[] $nfts   NFT names.
 * @param string   $row_id Unique DOM id for the toggle target.
 */
function bcc_trust_verified_render_nfts( array $nfts, string $row_id ): void {
    if ( empty( $nfts ) ) {
        echo '<span style="color:#999;">—</span>';
        return;
    }

    $visible = array_slice( $nfts, 0, 2 );
    $hidden  = array_slice( $nfts, 2 );

    foreach ( $visible as $name ) {
        echo '<div>' . esc_html( $name ) . '</div>';
    }

    if ( ! empty( $hidden ) ) {
        $toggle_id = esc_attr( $row_id );
        $count     = count( $hidden );
        ?>
        <div id="<?php echo $toggle_id; ?>" style="display:none;">
            <?php foreach ( $hidden as $name ): ?>
                <div><?php echo esc_html( $name ); ?></div>
            <?php endforeach; ?>
        </div>
        <button
            type="button"
            class="button-link"
            style="font-size:11px;margin-top:4px;"
            onclick="(function(btn){
                var el = document.getElementById('<?php echo $toggle_id; ?>');
                var open = el.style.display !== 'none';
                el.style.display = open ? 'none' : 'block';
                btn.textContent  = open ? '+ <?php echo $count; ?> more' : 'Show less';
            })(this)"
        >+ <?php echo $count; ?> more</button>
        <?php
    }
}
