<?php
/**
 * My Endorsements Block -- server-side render.
 *
 * Shows the current user's endorsement stats summary and a container
 * that the view.js hydrates with the full endorsement list via REST.
 *
 * Data source: EndorsementQueryService (stats), REST /bcc/v1/endorsements/mine (list).
 *
 * @var array    $attributes Block attributes
 * @var string   $content    Inner content
 * @var WP_Block $block      Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        echo '<div class="bcc-block-placeholder" style="padding:20px;background:#f0f0f0;border:1px dashed #ccc;color:#666;text-align:center;border-radius:4px;">'
           . '<strong>My Endorsements</strong><br>'
           . '<small>Only visible to logged-in users on their own profile.</small>'
           . '</div>';
        return;
    }
    return; // Not logged in.
}

$limit       = max(1, min(50, (int) ($attributes['limit'] ?? 10)));
$show_stats  = (bool) ($attributes['showStats'] ?? true);
$show_revoke = (bool) ($attributes['showRevoke'] ?? true);

// Fetch aggregate stats server-side (cheap — cached).
$stats = null;
if ($show_stats && class_exists('\\BCC\\Trust\\Core\\Plugin')) {
    try {
        $stats = \BCC\Trust\Core\Plugin::instance()->endorsementService()
            ->getUserEndorsementStats($current_user_id);
    } catch (\Exception $e) {
        // Stats retrieval failed — show zeros, but log so a broken repo
        // or missing service registration doesn't disappear into /dev/null
        // on every dashboard render.
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::info('[bcc-trust] my-endorsements stats query failed', [
                'user_id' => $current_user_id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}

$total_given    = (int) ($stats['total_endorsements_given'] ?? 0);
$unique_pages   = (int) ($stats['unique_pages_endorsed'] ?? 0);
$avg_weight     = (float) ($stats['endorsement_weight_avg'] ?? 0);
$last_endorsed  = $stats['last_endorsement'] ?? null;

$wrapper_attributes = get_block_wrapper_attributes([
    'class'          => 'bcc-me',
    'data-limit'     => esc_attr((string) $limit),
    'data-show-revoke' => $show_revoke ? '1' : '0',
]);
?>
<div <?php echo $wrapper_attributes; ?>>

    <?php if ($show_stats): ?>
    <div class="bcc-me__stats">
        <div class="bcc-me__stat">
            <span class="bcc-me__stat-value"><?php echo esc_html((string) $total_given); ?></span>
            <span class="bcc-me__stat-label"><?php esc_html_e('Endorsements Given', 'bcc-trust'); ?></span>
        </div>
        <div class="bcc-me__stat">
            <span class="bcc-me__stat-value"><?php echo esc_html((string) $unique_pages); ?></span>
            <span class="bcc-me__stat-label"><?php esc_html_e('Pages Endorsed', 'bcc-trust'); ?></span>
        </div>
        <div class="bcc-me__stat">
            <span class="bcc-me__stat-value"><?php echo esc_html(number_format($avg_weight, 1)); ?></span>
            <span class="bcc-me__stat-label"><?php esc_html_e('Avg Weight', 'bcc-trust'); ?></span>
        </div>
        <?php if ($last_endorsed): ?>
        <div class="bcc-me__stat">
            <span class="bcc-me__stat-value"><?php echo esc_html(human_time_diff(strtotime($last_endorsed)) . ' ago'); ?></span>
            <span class="bcc-me__stat-label"><?php esc_html_e('Last Endorsement', 'bcc-trust'); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($total_given === 0): ?>
    <div class="bcc-me__empty">
        <p><?php esc_html_e("You haven't endorsed any pages yet.", 'bcc-trust'); ?></p>
        <p class="bcc-me__hint"><?php esc_html_e('Visit a project page and click "Endorse" to support builders you trust.', 'bcc-trust'); ?></p>
    </div>
    <?php else: ?>
    <div class="bcc-me__list">
        <div class="bcc-me__loading">
            <span class="bcc-me__spinner"></span>
            <?php esc_html_e('Loading endorsements...', 'bcc-trust'); ?>
        </div>
    </div>
    <?php endif; ?>

</div>
