<?php
/**
 * Endorsement Leaderboard Block -- server-side render.
 *
 * Renders a container shell; data is fetched via REST endpoint
 * GET /bcc/v1/endorsements/top and rendered by view.js.
 *
 * Data source: REST endpoint (reads from read model via EndorsementLeaderboardEndpoint).
 *
 * @var array    $attributes Block attributes
 * @var string   $content    Inner content
 * @var WP_Block $block      Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

$limit               = max(1, min(100, (int) ($attributes['limit'] ?? 10)));
$context             = sanitize_key($attributes['context'] ?? '');
$show_endorser_count = (bool) ($attributes['showEndorserCount'] ?? true);

$tier_labels = [
    'elite'   => __('Elite',   'bcc-trust'),
    'trusted' => __('Trusted', 'bcc-trust'),
    'neutral' => __('Neutral', 'bcc-trust'),
    'caution' => __('Caution', 'bcc-trust'),
    'risky'   => __('Risky',   'bcc-trust'),
];

$wrapper_attributes = get_block_wrapper_attributes([
    'class'                   => 'bcc-elb',
    'data-limit'              => esc_attr((string) $limit),
    'data-context'            => esc_attr($context),
    'data-show-endorser-count' => $show_endorser_count ? '1' : '0',
    'data-tier-labels'        => esc_attr(wp_json_encode($tier_labels)),
]);
?>
<div <?php echo $wrapper_attributes; ?>>
    <div class="bcc-elb__header">
        <span class="bcc-elb__title">
            <?php
            if ($context) {
                printf(
                    esc_html__('Top Endorsed Pages (%s)', 'bcc-trust'),
                    esc_html(ucfirst($context))
                );
            } else {
                esc_html_e('Top Endorsed Pages', 'bcc-trust');
            }
            ?>
        </span>
    </div>
    <div class="bcc-elb__table">
        <div class="bcc-elb__loading">
            <span class="bcc-elb__spinner"></span>
        </div>
    </div>
</div>
