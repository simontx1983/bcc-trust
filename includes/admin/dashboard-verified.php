<?php
/**
 * Trust Engine Dashboard - Verified Users Tab
 *
 * Displays users with GitHub verification and their trust impact
 * 
 * @package BCC\Trust\Core
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verified Tab - Shows users with GitHub verification
 */
function bcc_trust_render_verified_tab() {
    $repo = \BCC\Trust\Core\Plugin::instance()->adminDashboardRepository();

    // Get filter/sort parameters
    $search            = isset($_GET['s'])       ? sanitize_text_field($_GET['s'])   : '';
    $verification_type = isset($_GET['type'])    ? sanitize_key($_GET['type'])        : 'github';
    $orderby           = isset($_GET['orderby']) ? sanitize_key($_GET['orderby'])     : 'verified_at';
    $order             = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';

    // Pagination
    $per_page     = BCC_TRUST_ADMIN_VERIFIED_PER_PAGE;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset       = ($current_page - 1) * $per_page;

    // Validate orderby — only allow safe column references
    $orderby_map = [
        'verified_at'  => 'v.verified_at',
        'display_name' => 'u.display_name',
        'net_impact'   => 'net_impact',
        'followers'    => 'followers',
    ];
    $orderby_col = $orderby_map[$orderby] ?? 'v.verified_at';

    // Use config constants for thresholds
    $trustBoostElite   = BCC_TRUST_WEIGHT_ELITE * 100;
    $trustBoostTrusted = BCC_TRUST_WEIGHT_TRUSTED * 100;
    $fraudMedium       = BCC_TRUST_FRAUD_MEDIUM;

    // Base WHERE: only active GitHub verifications
    $where  = ["v.type = 'github'", "v.status = 'active'"];
    $params = [];

    if ($search) {
        $where[]     = "(u.display_name LIKE %s OR u.user_email LIKE %s OR v.provider_username LIKE %s)";
        $search_term = '%' . \BCC\Trust\Core\Repositories\AdminDashboardRepository::escLike($search) . '%';
        $params[]    = $search_term;
        $params[]    = $search_term;
        $params[]    = $search_term;
    }

    $where_clause = implode(' AND ', $where);

    // Total count + paginated data via repository
    $total_items    = $repo->getVerifiedUsersCount($where_clause, $params);
    $verified_users = $repo->getVerifiedUsersFiltered($where_clause, $params, $orderby_col, $order, $per_page, $offset);

    // Decode and process meta data for each row
    foreach ($verified_users as $user) {
        $meta = $user->meta ? json_decode($user->meta, true) : [];

        $user->github_followers    = (int) ($meta['followers'] ?? 0);
        $user->github_public_repos = (int) ($meta['public_repos'] ?? 0);
        $user->github_org_count    = (int) ($meta['org_count'] ?? 0);
        $user->account_age_days    = (int) ($meta['account_age_days'] ?? 0);
        $user->has_verified_email  = (bool) ($meta['has_verified_email'] ?? false);

        // Calculate quality score based on GitHub stats
        $quality_score = 0;
        if ($user->github_followers > 10) $quality_score += 20;
        if ($user->github_followers > 100) $quality_score += 20;
        if ($user->github_public_repos > 5) $quality_score += 20;
        if ($user->github_public_repos > 20) $quality_score += 20;
        if ($user->github_org_count > 0) $quality_score += 20;
        if ($user->account_age_days > 365) $quality_score += 20;
        if ($user->has_verified_email) $quality_score += 20;

        $user->quality_score = min(100, $quality_score);

        unset($user->meta);
    }

    // Summary statistics via repository
    $summary              = $repo->getVerifiedSummaryStats();
    $total_verified       = $summary['total_verified'];
    $avg_net_impact       = $summary['avg_net_impact'];
    $high_quality_count   = $summary['high_quality_count'];
    $recent_verifications = $summary['recent_verifications'];
    ?>

    <div class="wrap bcc-admin-wrap">
        <h2>✓ Verified Users</h2>

        <!-- Summary Stats with Config Context -->
        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            <div class="stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px; border-left: 4px solid #46b450;">
                <h3 style="margin:0 0 5px 0; font-size:14px; color:#555;">Total Verified</h3>
                <div class="stat-value" style="font-size: 32px; font-weight: bold;"><?php echo number_format($total_verified); ?></div>
                <small><?php echo esc_html($recent_verifications); ?> in last 30 days</small>
            </div>

            <div class="stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
                <h3 style="margin:0 0 5px 0; font-size:14px; color:#555;">Avg Net Impact</h3>
                <div class="stat-value" style="font-size: 32px; font-weight: bold;"><?php echo round($avg_net_impact ?: 0, 1); ?></div>
                <small>Trust Boost - Fraud Reduction</small>
            </div>

            <div class="stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
                <h3 style="margin:0 0 5px 0; font-size:14px; color:#555;">High Quality</h3>
                <div class="stat-value" style="font-size: 32px; font-weight: bold;"><?php echo number_format($high_quality_count); ?></div>
                <small>100+ GitHub followers</small>
            </div>

            <div class="stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
                <h3 style="margin:0 0 5px 0; font-size:14px; color:#555;">Trust Boost Tiers</h3>
                <div class="stat-value" style="font-size: 14px;">
                    <span style="color:#4caf50;">Elite: <?php echo intval($trustBoostElite); ?>%</span> |
                    <span style="color:#2196f3;">Trusted: <?php echo intval($trustBoostTrusted); ?>%</span>
                </div>
                <small>From config.php</small>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="tablenav top">
            <form method="get" style="display: inline-block; width: 100%;">
                <input type="hidden" name="page" value="bcc-trust-dashboard">
                <input type="hidden" name="tab" value="verified">

                <div class="alignleft actions">
                    <select name="orderby">
                        <option value="verified_at"  <?php selected($orderby, 'verified_at'); ?>>Sort by Verification Date</option>
                        <option value="display_name" <?php selected($orderby, 'display_name'); ?>>Sort by Name</option>
                        <option value="net_impact"   <?php selected($orderby, 'net_impact'); ?>>Sort by Net Impact</option>
                        <option value="followers"    <?php selected($orderby, 'followers'); ?>>Sort by Followers</option>
                    </select>

                    <select name="order">
                        <option value="DESC" <?php selected($order, 'DESC'); ?>>Descending</option>
                        <option value="ASC"  <?php selected($order, 'ASC'); ?>>Ascending</option>
                    </select>

                    <input type="submit" class="button" value="Apply">
                    
                    <?php if ($search): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=bcc-trust-dashboard&tab=verified')); ?>" class="button">Clear Search</a>
                    <?php endif; ?>
                </div>

                <div class="alignright">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search users or GitHub...">
                    <input type="submit" class="button" value="Search">
                </div>
            </form>
        </div>

        <!-- Verified Users Table -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>GitHub Profile</th>
                    <th>GitHub Stats</th>
                    <th>Quality</th>
                    <th>Trust Impact</th>
                    <th>Verified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($verified_users)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:20px;">
                            <strong>No verified users found.</strong>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($verified_users as $user):
                        $impact_color = $user->net_impact >= 0 ? '#4caf50' : '#f44336';
                        $impact_sign  = $user->net_impact >= 0 ? '+' : '';
                        
                        // Determine quality badge
                        $quality_badge = '';
                        $quality_color = '';
                        if ($user->quality_score >= 80) {
                            $quality_badge = 'Elite';
                            $quality_color = '#9c27b0';
                        } elseif ($user->quality_score >= 60) {
                            $quality_badge = 'High';
                            $quality_color = '#2196f3';
                        } elseif ($user->quality_score >= 40) {
                            $quality_badge = 'Medium';
                            $quality_color = '#ffc107';
                        } else {
                            $quality_badge = 'Basic';
                            $quality_color = '#6c757d';
                        }
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($user->display_name); ?></strong>
                                <br><small>ID: <?php echo intval($user->user_id); ?></small>
                                <br><small><?php echo esc_html($user->user_email); ?></small>
                                <?php if ($user->fraud_score > $fraudMedium): ?>
                                    <br><span style="color:#f44336; font-weight:bold;">⚠️ Risk: <?php echo intval($user->fraud_score); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="https://github.com/<?php echo esc_attr($user->github_username); ?>" target="_blank" style="display: flex; align-items: center; text-decoration: none;">
                                    <?php if ($user->github_avatar): ?>
                                        <img src="<?php echo esc_url($user->github_avatar); ?>" width="32" height="32" style="border-radius: 50%; margin-right: 8px;">
                                    <?php else: ?>
                                        <svg height="32" width="32" viewBox="0 0 16 16" style="fill: #24292e; margin-right: 8px;">
                                            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"></path>
                                        </svg>
                                    <?php endif; ?>
                                    <div>
                                        <strong>@<?php echo esc_html($user->github_username); ?></strong>
                                        <?php if ($user->has_verified_email): ?>
                                            <br><small style="color:#4caf50;">✓ Verified Email</small>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            </td>
                            <td>
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <span title="Followers">👥 <?php echo esc_html(number_format($user->github_followers)); ?> followers</span>
                                    <span title="Public Repos">📦 <?php echo esc_html(number_format($user->github_public_repos)); ?> repos</span>
                                    <?php if ($user->github_org_count > 0): ?>
                                        <span title="Organizations">🏢 <?php echo esc_html(number_format($user->github_org_count)); ?> orgs</span>
                                    <?php endif; ?>
                                    <?php if ($user->account_age_days > 0): ?>
                                        <span title="Account Age">📅 <?php echo floor($user->account_age_days / 365); ?>+ years</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="text-align: center;">
                                    <div style="width: 60px; height: 60px; margin: 0 auto; position: relative;">
                                        <svg viewBox="0 0 36 36" style="width: 60px; height: 60px;">
                                            <circle cx="18" cy="18" r="16" fill="none" stroke="#eee" stroke-width="2"></circle>
                                            <circle cx="18" cy="18" r="16" fill="none" 
                                                    stroke="<?php echo esc_attr($quality_color); ?>" 
                                                    stroke-width="2"
                                                    stroke-dasharray="<?php echo $user->quality_score; ?>, 100"
                                                    stroke-dashoffset="25"
                                                    transform="rotate(-90 18 18)"></circle>
                                        </svg>
                                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold;">
                                            <?php echo $user->quality_score; ?>
                                        </div>
                                    </div>
                                    <span style="color: <?php echo esc_attr($quality_color); ?>; font-weight: bold;">
                                        <?php echo esc_html($quality_badge); ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div style="text-align: center;">
                                    <span style="font-weight: bold; color: <?php echo esc_attr($impact_color); ?>; font-size: 18px;">
                                        <?php echo esc_html($impact_sign . number_format($user->net_impact, 1)); ?>
                                    </span>
                                    <br>
                                    <small>Boost: +<?php echo esc_html(number_format($user->trust_boost, 1)); ?></small>
                                    <br>
                                    <small>Reduction: -<?php echo esc_html($user->fraud_reduction); ?></small>
                                </div>
                            </td>
                            <td>
                                <?php if ($user->github_verified_at): ?>
                                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($user->github_verified_at))); ?>
                                    <br><small><?php echo esc_html(human_time_diff(strtotime($user->github_verified_at), current_time('timestamp'))); ?> ago</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; flex-direction: column; gap: 5px;">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=bcc-trust-moderation&user_id=' . intval($user->user_id))); ?>" class="button button-small">View User</a>
                                    <a href="https://github.com/<?php echo esc_attr($user->github_username); ?>" class="button button-small" target="_blank">GitHub Profile</a>
                                    <?php if ($user->fraud_score > $fraudMedium): ?>
                                        <span style="color:#f44336; font-size:11px;">⚠️ Review needed</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php
        $total_pages = ceil($total_items / $per_page);
        if ($total_pages > 1):
        ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo number_format($total_items); ?> items</span>
                <?php
                echo paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => (int) $total_pages,
                    'current'   => $current_page
                ]);
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}