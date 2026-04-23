<?php
/**
 * Project Discovery Hub – server-side render.
 *
 * Displays a filterable, sortable grid of project cards.
 * Initial page is server-rendered; subsequent pages loaded via REST.
 *
 * @var array    $attributes Block attributes from block.json
 * @var string   $content    Block inner content (always empty for dynamic blocks)
 * @var WP_Block $block      Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Core\Services\PageDiscoveryService;

// --- Helper: format large numbers ---
if (!function_exists('bcc_discovery_format_count')) {
    function bcc_discovery_format_count(int $count): string {
        if ($count >= 1000000) {
            return round($count / 1000000, 1) . 'M';
        }
        if ($count >= 1000) {
            return round($count / 1000, 1) . 'K';
        }
        return (string) $count;
    }
}

// --- Settings from attributes ---
$types              = (array) ($attributes['types'] ?? []);
$verified_only      = (bool) ($attributes['verifiedOnly'] ?? false);
$sort_by            = sanitize_key($attributes['sortBy'] ?? 'trust');
$limit              = (int) ($attributes['limit'] ?? 12);
$show_search        = (bool) ($attributes['showSearch'] ?? true);
$show_filters       = (bool) ($attributes['showFilters'] ?? true);
$show_cover_bg      = (bool) ($attributes['showCoverBackground'] ?? true);
$show_followers     = (bool) ($attributes['showFollowers'] ?? true);
$show_endorsements  = (bool) ($attributes['showEndorsements'] ?? true);
$show_verified      = (bool) ($attributes['showVerifiedBadge'] ?? true);
$show_tabs          = (bool) ($attributes['showTabs'] ?? true);
$needs_eval_max     = (int) ($attributes['needsEvalMaxVotes'] ?? 5);

// --- Fetch initial data ---
$service = \BCC\Trust\Core\Plugin::instance()->pageDiscoveryService();
$result  = $service->query([
    'types'         => $types,
    'sort'          => $sort_by,
    'verified_only' => $verified_only,
    'limit'         => $limit,
    'page'          => 1,
    'search'        => '',
]);

$projects    = $result['results'] ?? [];
$total       = $result['total'] ?? 0;
$total_pages = $result['pages'] ?? 0;

// --- Sort label map ---
$sort_labels = [
    'trust'        => __('Top Trusted', 'bcc-trust'),
    'endorsements' => __('Most Endorsed', 'bcc-trust'),
    'newest'       => __('Newest', 'bcc-trust'),
    'followers'    => __('Most Followers', 'bcc-trust'),
];

// --- Type label map ---
$type_labels = [
    'builder'   => __('Builders', 'bcc-trust'),
    'validator' => __('Validators', 'bcc-trust'),
    'nft'       => __('NFT', 'bcc-trust'),
    'dao'       => __('DAO', 'bcc-trust'),
];

// --- Tier color map ---
$tier_colors = [
    'elite'   => 'var(--bcc-tier-elite)',
    'trusted' => 'var(--bcc-tier-trusted)',
    'neutral' => 'var(--bcc-tier-neutral)',
    'caution' => 'var(--bcc-tier-caution)',
    'risky'   => 'var(--bcc-tier-risky)',
];

// --- Unique instance ID for multi-block pages ---
$instance_id = 'discovery-' . wp_unique_id();

// --- Data attributes for JS hydration ---
$wrapper_attributes = get_block_wrapper_attributes([
    'class'                  => 'bcc-discovery-hub',
    'id'                     => esc_attr($instance_id),
    'data-types'             => esc_attr(implode(',', $types)),
    'data-sort'              => esc_attr($sort_by),
    'data-verified-only'     => $verified_only ? '1' : '0',
    'data-limit'             => esc_attr((string) $limit),
    'data-total-pages'       => esc_attr((string) $total_pages),
    'data-total'             => esc_attr((string) $total),
    'data-current-page'      => '1',
    'data-show-cover'        => $show_cover_bg ? '1' : '0',
    'data-show-followers'    => $show_followers ? '1' : '0',
    'data-show-endorsements' => $show_endorsements ? '1' : '0',
    'data-show-verified'     => $show_verified ? '1' : '0',
    'data-show-tabs'         => $show_tabs ? '1' : '0',
    'data-needs-eval-max'    => esc_attr((string) $needs_eval_max),
]);

?>
<div <?php echo $wrapper_attributes; ?>>

    <?php if ($show_tabs): ?>
    <nav class="bcc-discovery-hub__tabs" role="tablist" aria-label="<?php esc_attr_e('Discovery feed', 'bcc-trust'); ?>">
        <button class="bcc-discovery-hub__tab is-active" role="tab" aria-selected="true" data-tab="top">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            <?php esc_html_e('Top', 'bcc-trust'); ?>
        </button>
        <button class="bcc-discovery-hub__tab" role="tab" aria-selected="false" data-tab="rising">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
            <?php esc_html_e('Rising', 'bcc-trust'); ?>
        </button>
        <button class="bcc-discovery-hub__tab" role="tab" aria-selected="false" data-tab="needs-eval">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php esc_html_e('Needs Evaluation', 'bcc-trust'); ?>
        </button>
    </nav>
    <?php endif; ?>

    <?php if ($show_search || $show_filters): ?>
    <div class="bcc-discovery-hub__toolbar">

        <?php if ($show_search): ?>
        <div class="bcc-discovery-hub__search">
            <input
                type="search"
                class="bcc-discovery-hub__search-input"
                placeholder="<?php esc_attr_e('Search projects...', 'bcc-trust'); ?>"
                aria-label="<?php esc_attr_e('Search projects', 'bcc-trust'); ?>"
            />
            <svg class="bcc-discovery-hub__search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        </div>
        <?php endif; ?>

        <?php if ($show_filters): ?>
        <div class="bcc-discovery-hub__filters" role="tablist" aria-label="<?php esc_attr_e('Filter projects by type', 'bcc-trust'); ?>">
            <button class="bcc-discovery-hub__filter-tab is-active" role="tab" aria-selected="true" data-type="">
                <?php esc_html_e('All', 'bcc-trust'); ?>
            </button>
            <?php foreach ($type_labels as $type_key => $type_label): ?>
            <button class="bcc-discovery-hub__filter-tab" role="tab" aria-selected="false" data-type="<?php echo esc_attr($type_key); ?>">
                <?php echo esc_html($type_label); ?>
            </button>
            <?php endforeach; ?>
            <?php if (!$verified_only): ?>
            <button class="bcc-discovery-hub__filter-tab" role="tab" aria-selected="false" data-type="verified">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                <?php esc_html_e('Verified', 'bcc-trust'); ?>
            </button>
            <?php endif; ?>
        </div>

        <div class="bcc-discovery-hub__trust-filters" role="group" aria-label="<?php esc_attr_e('Trust filters', 'bcc-trust'); ?>">
            <button class="bcc-discovery-hub__trust-chip is-active" data-trust-filter="">
                <?php esc_html_e('All', 'bcc-trust'); ?>
            </button>
            <button class="bcc-discovery-hub__trust-chip" data-trust-filter="verified">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                <?php esc_html_e('Verified', 'bcc-trust'); ?>
            </button>
            <button class="bcc-discovery-hub__trust-chip" data-trust-filter="tier-a">
                <span class="bcc-discovery-hub__trust-chip-dot" style="background: var(--bcc-tier-elite)"></span>
                <?php esc_html_e('A Tier', 'bcc-trust'); ?>
            </button>
            <button class="bcc-discovery-hub__trust-chip" data-trust-filter="tier-b">
                <span class="bcc-discovery-hub__trust-chip-dot" style="background: var(--bcc-tier-trusted)"></span>
                <?php esc_html_e('B Tier', 'bcc-trust'); ?>
            </button>
            <button class="bcc-discovery-hub__trust-chip" data-trust-filter="high-confidence">
                <?php esc_html_e('High Confidence', 'bcc-trust'); ?>
            </button>
            <button class="bcc-discovery-hub__trust-chip" data-trust-filter="new">
                <?php esc_html_e('New Projects', 'bcc-trust'); ?>
            </button>
        </div>
        <?php endif; ?>

        <div class="bcc-discovery-hub__sort">
            <label class="bcc-discovery-hub__sort-label" for="<?php echo esc_attr($instance_id); ?>-sort">
                <?php esc_html_e('Sort:', 'bcc-trust'); ?>
            </label>
            <select class="bcc-discovery-hub__sort-select" id="<?php echo esc_attr($instance_id); ?>-sort">
                <?php foreach ($sort_labels as $sort_key => $sort_label): ?>
                <option value="<?php echo esc_attr($sort_key); ?>" <?php selected($sort_key, $sort_by); ?>>
                    <?php echo esc_html($sort_label); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

    </div>
    <?php endif; ?>

    <div class="bcc-discovery-hub__grid" role="list">
        <?php if (empty($projects)): ?>
        <div class="bcc-discovery-hub__empty">
            <p><?php esc_html_e('No projects found.', 'bcc-trust'); ?></p>
        </div>
        <?php else: ?>
            <?php foreach ($projects as $project):
                $tier       = $project['tier'] ?? 'unavailable';
                $tier_color = $tier_colors[$tier] ?? ($tier_colors['neutral'] ?? 'var(--bcc-tier-neutral)');
                $has_cover  = $show_cover_bg && !empty($project['cover_url']);
                $card_class = 'bcc-discovery-hub__card';
                if ($has_cover) {
                    $card_class .= ' has-cover';
                }
            ?>
            <a href="<?php echo esc_url($project['page_url']); ?>"
               class="<?php echo esc_attr($card_class); ?>"
               role="listitem"
               data-page-id="<?php echo esc_attr((string) $project['page_id']); ?>"
               data-page-type="<?php echo esc_attr($project['page_type']); ?>">

                <?php if ($has_cover): ?>
                <div class="bcc-discovery-hub__card-cover">
                    <img
                        src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="
                        data-src="<?php echo esc_url($project['cover_url']); ?>"
                        alt=""
                        class="bcc-discovery-hub__card-cover-img lazyload"
                        loading="lazy"
                    />
                    <div class="bcc-discovery-hub__card-overlay"></div>
                </div>
                <?php else: ?>
                <div class="bcc-discovery-hub__card-cover bcc-discovery-hub__card-cover--default">
                    <div class="bcc-discovery-hub__card-overlay"></div>
                </div>
                <?php endif; ?>

                <div class="bcc-discovery-hub__card-content">
                    <div class="bcc-discovery-hub__card-header">
                        <?php if (!empty($project['avatar_url'])): ?>
                        <img
                            src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="
                            data-src="<?php echo esc_url($project['avatar_url']); ?>"
                            alt="<?php echo esc_attr($project['page_name']); ?>"
                            class="bcc-discovery-hub__card-avatar lazyload"
                            loading="lazy"
                            width="48"
                            height="48"
                        />
                        <?php else: ?>
                        <div class="bcc-discovery-hub__card-avatar bcc-discovery-hub__card-avatar--placeholder">
                            <?php echo esc_html(mb_strtoupper(mb_substr($project['page_name'], 0, 1))); ?>
                        </div>
                        <?php endif; ?>

                        <div class="bcc-discovery-hub__card-title-group">
                            <h3 class="bcc-discovery-hub__card-name">
                                <?php echo esc_html($project['page_name']); ?>
                                <?php if ($show_verified && $project['verified']): ?>
                                <svg class="bcc-discovery-hub__verified-badge" width="16" height="16" viewBox="0 0 24 24" fill="var(--bcc-primary)" aria-label="<?php esc_attr_e('Verified', 'bcc-trust'); ?>"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                <?php endif; ?>
                            </h3>
                            <span class="bcc-discovery-hub__card-type bcc-discovery-hub__card-type--<?php echo esc_attr($project['page_type']); ?>">
                                <?php echo esc_html(ucfirst($project['page_type'])); ?>
                            </span>
                        </div>
                    </div>

                    <div class="bcc-discovery-hub__card-stats">
                        <div class="bcc-discovery-hub__card-score" style="--tier-color: <?php echo esc_attr($tier_color); ?>">
                            <span class="bcc-discovery-hub__card-score-value"><?php echo esc_html((string) $project['trust_score']); ?></span>
                            <span class="bcc-discovery-hub__card-score-label"><?php esc_html_e('Trust', 'bcc-trust'); ?></span>
                        </div>

                        <?php if ($show_endorsements): ?>
                        <div class="bcc-discovery-hub__card-stat">
                            <span class="bcc-discovery-hub__card-stat-value"><?php echo esc_html((string) $project['endorsements']); ?></span>
                            <span class="bcc-discovery-hub__card-stat-label"><?php esc_html_e('Endorsed', 'bcc-trust'); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($show_followers): ?>
                        <div class="bcc-discovery-hub__card-stat">
                            <span class="bcc-discovery-hub__card-stat-value"><?php echo esc_html(bcc_discovery_format_count($project['followers'] ?? 0)); ?></span>
                            <span class="bcc-discovery-hub__card-stat-label"><?php esc_html_e('Followers', 'bcc-trust'); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="bcc-discovery-hub__footer">
        <button class="bcc-discovery-hub__load-more" data-loading-text="<?php esc_attr_e('Loading...', 'bcc-trust'); ?>">
            <?php esc_html_e('Load More', 'bcc-trust'); ?>
            <span class="bcc-discovery-hub__count">(<?php
                printf(
                    /* translators: %1$d: shown count, %2$d: total count */
                    esc_html__('%1$d of %2$d', 'bcc-trust'),
                    min($limit, $total),
                    $total
                );
            ?>)</span>
        </button>
    </div>
    <?php endif; ?>

</div>