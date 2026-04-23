<?php
/**
 * Admin Dashboard - Top Pages & All Pages Tabs
 *
 * @package BCC_Trust_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bcc_trust_get_peepso_page_url( int $page_id ): string {
    if ( $page_id > 0 && class_exists( 'PeepSoPage' ) ) {
        $page = new PeepSoPage( $page_id );
        $url  = $page->get_url();

        if ( is_string( $url ) && $url !== '' ) {
            return $url;
        }
    }

    return (string) get_permalink( $page_id );
}

function bcc_trust_get_peepso_page_settings_url( int $page_id ): string {
    $url = bcc_trust_get_peepso_page_url( $page_id );

    if ( $url === '' ) {
        return '';
    }

    return trailingslashit( $url ) . 'settings/';
}

function bcc_trust_render_pages_tab() {
    $data    = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getPagesData();
    $pages   = $data['pages'];
    $cpt_map = $data['cpt_map'] ?? [];
    ?>
    <div class="bcc-panel">
        <h2>Top Pages by Trust Score</h2>
        <p style="color:#666;">Showing top <?php echo intval($data['limit']); ?> pages by total score.</p>

        <?php if ( empty( $pages ) ) : ?>
            <p>No pages scored yet.</p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Page Title</th>
                    <th>Page ID</th>
                    <th>CPT ID</th>
                    <th>Trust Score</th>
                    <th>Tier</th>
                    <th>Votes</th>
                    <th>Confidence</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $pages as $i => $page ) :
                    $tier_info = bcc_trust_get_tier_info_from_score( $page->total_score ?? 0 );
                    $pid       = (int) $page->page_id;
                    $cpt       = $cpt_map[ $pid ] ?? null;
                    $page_url  = bcc_trust_get_peepso_page_url( $pid );
                ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td>
                            <strong>
                                <?php if ( ! empty( $page->post_title ) ) : ?>
                                    <a href="<?php echo esc_url( $page_url ); ?>" target="_blank"><?php echo esc_html( $page->post_title ); ?></a>
                                <?php else : ?>
                                    Page #<?php echo $page->page_id; ?>
                                <?php endif; ?>
                            </strong>
                        </td>
                        <td><?php echo $pid; ?></td>
                        <td>
                            <?php if ( $cpt ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $cpt['cpt_id'] ) ); ?>"><?php echo $cpt['cpt_id']; ?></a>
                                <br><small style="color:#888;"><?php echo esc_html( $cpt['cpt_type'] ); ?></small>
                            <?php else : ?>
                                <span style="color:#999;">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="width:80px;height:8px;background:#eee;border-radius:4px;overflow:hidden;">
                                    <div style="width:<?php echo esc_attr( min( 100, $page->total_score ?? 0 ) ); ?>%;height:100%;background:<?php echo esc_attr($tier_info['color']); ?>;border-radius:4px;"></div>
                                </div>
                                <strong><?php echo round( $page->total_score ?? 0, 1 ); ?></strong>
                            </div>
                        </td>
                        <td>
                            <span style="padding:2px 8px;border-radius:3px;background:<?php echo esc_attr($tier_info['color']); ?>;color:#fff;font-size:12px;">
                                <?php echo esc_html( $tier_info['label'] ); ?>
                            </span>
                        </td>
                        <td><?php echo intval( $page->vote_count ?? 0 ); ?></td>
                        <td><?php echo isset( $page->confidence_score ) ? round( $page->confidence_score, 1 ) . '%' : '&mdash;'; ?></td>
                        <td>
                            <a href="<?php echo esc_url( $page_url ); ?>" target="_blank" class="button button-small">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

function bcc_trust_render_all_pages_tab() {
    $data    = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository()->getAllPagesData();
    $pages   = $data['pages'];
    $cpt_map = $data['cpt_map'] ?? [];
    ?>
    <div class="bcc-panel">
        <h2>All Pages (<?php echo number_format( $data['total'] ); ?>)</h2>
        <p style="color:#666;">All peepso-page posts. Showing up to <?php echo intval($data['limit']); ?>.</p>

        <?php if ( empty( $pages ) ) : ?>
            <p>No pages found.</p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Page ID</th>
                    <th>CPT ID</th>
                    <th>Title</th>
                    <th>Trust Score</th>
                    <th>Tier</th>
                    <th>Votes</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $pages as $page ) :
                    $score     = $page->total_score ?? null;
                    $tier_info = $score !== null ? bcc_trust_get_tier_info_from_score( $score ) : [ 'label' => 'Unscored', 'color' => '#999' ];
                    $pid       = (int) $page->ID;
                    $cpt       = $cpt_map[ $pid ] ?? null;
                    $page_url  = bcc_trust_get_peepso_page_url( $pid );
                    $edit_url  = bcc_trust_get_peepso_page_settings_url( $pid );
                ?>
                    <tr>
                        <td><?php echo $pid; ?></td>
                        <td>
                            <?php if ( $cpt ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $cpt['cpt_id'] ) ); ?>"><?php echo $cpt['cpt_id']; ?></a>
                                <br><small style="color:#888;"><?php echo esc_html( $cpt['cpt_type'] ); ?></small>
                            <?php else : ?>
                                <span style="color:#999;">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $page_url ); ?>" target="_blank">
                                <?php echo esc_html( $page->post_title ?: 'Untitled #' . $pid ); ?>
                            </a>
                        </td>
                        <td>
                            <?php if ( $score !== null ) : ?>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div style="width:60px;height:6px;background:#eee;border-radius:3px;overflow:hidden;">
                                        <div style="width:<?php echo esc_attr( min( 100, $score ) ); ?>%;height:100%;background:<?php echo esc_attr($tier_info['color']); ?>;border-radius:3px;"></div>
                                    </div>
                                    <?php echo round( $score, 1 ); ?>
                                </div>
                            <?php else : ?>
                                <span style="color:#999;">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="padding:2px 6px;border-radius:3px;background:<?php echo esc_attr($tier_info['color']); ?>;color:#fff;font-size:11px;">
                                <?php echo esc_html( $tier_info['label'] ); ?>
                            </span>
                        </td>
                        <td><?php echo intval( $page->vote_count ?? 0 ); ?></td>
                        <td><?php echo esc_html( $page->post_status ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $page_url ); ?>" target="_blank" class="button button-small">View</a>
                            <a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" class="button button-small">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
