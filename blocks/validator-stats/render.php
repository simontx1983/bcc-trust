<?php
/**
 * Validator Stats Block – server-side render.
 *
 * Shows validator grid with commission, stake, uptime, governance stats.
 * Includes "Claim as Operator" button for logged-in users.
 *
 * @var array    $attributes Block attributes
 * @var string   $content    Inner content
 * @var WP_Block $block      Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

$page_id    = (int) ($attributes['pageId'] ?? get_the_ID());
$per_page   = (int) ($attributes['perPage'] ?? 4);
$show_claim = (bool) ($attributes['showClaim'] ?? true);

// Fetch validators for this page.
$data = \BCC\Trust\Onchain\Repositories\ValidatorRepository::getForProject($page_id, 1, $per_page);

$validators  = $data['items'] ?? [];
$total       = $data['total'] ?? 0;
$total_pages = $data['pages'] ?? 0;

if (empty($validators) && !is_admin()) {
    return; // Don't render empty block on frontend.
}

// Batch-load all claims for these validators (single query, not N+1).
$claims_map = [];
if (!empty($validators)) {
    $vids = array_map(fn($v) => (int) $v->id, $validators);
    $all_claims = \BCC\Trust\Onchain\Repositories\ClaimRepository::getForEntityBatch('validator', $vids);
    foreach ($all_claims as $vid => $claims) {
        $claims_map[$vid] = $claims[0]; // First verified claimer per validator.
    }
}

$user_id     = get_current_user_id();
$instance_id = 'validator-stats-' . wp_unique_id();

$wrapper_attributes = get_block_wrapper_attributes([
    'class'          => 'bcc-entity-block bcc-validator-stats',
    'id'             => esc_attr($instance_id),
    'data-page-id'   => esc_attr((string) $page_id),
    'data-per-page'  => esc_attr((string) $per_page),
    'data-total'     => esc_attr((string) $total),
    'data-show-claim' => $show_claim ? '1' : '0',
]);
?>
<div <?php echo $wrapper_attributes; ?>>

    <div class="bcc-entity-block__header">
        <h3 class="bcc-entity-block__title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?php esc_html_e('Validators', 'bcc-trust'); ?>
            <?php if ($total > 0): ?>
            <span class="bcc-entity-block__count"><?php echo esc_html((string) $total); ?></span>
            <?php endif; ?>
        </h3>
    </div>

    <div class="bcc-entity-block__grid" role="list">
        <?php if (empty($validators)): ?>
        <p class="bcc-entity-block__empty"><?php esc_html_e('No validators linked to this project.', 'bcc-trust'); ?></p>
        <?php else: ?>
            <?php foreach ($validators as $v):
                $vid       = (int) $v->id;
                $claimed   = isset($claims_map[$vid]);
                $claimer   = $claimed ? $claims_map[$vid] : null;
                $uptime    = $v->uptime_30d !== null ? round((float) $v->uptime_30d, 1) : null;
                $commission = $v->commission_rate !== null ? round((float) $v->commission_rate, 2) : null;
                $stake     = (float) ($v->total_stake ?? 0);
                $stakeStr  = $stake >= 1000000 ? round($stake / 1000000, 1) . 'M' : ($stake >= 1000 ? round($stake / 1000, 1) . 'K' : number_format($stake, 0));
                $token     = $v->native_token ?? '';
                $chain     = $v->chain_name ?? ucfirst($v->chain_slug ?? '');
                $explorer  = $v->explorer_url ?? '';
                $jailed    = (int) ($v->jailed_count ?? 0);
            ?>
            <div class="bcc-entity-block__card bcc-validator-card" role="listitem" data-entity-type="validator" data-entity-id="<?php echo esc_attr((string) $vid); ?>">

                <div class="bcc-validator-card__header">
                    <div class="bcc-validator-card__identity">
                        <span class="bcc-validator-card__moniker"><?php echo esc_html($v->moniker ?: 'Unknown'); ?></span>
                        <span class="bcc-validator-card__chain"><?php echo esc_html($chain); ?></span>
                    </div>
                    <?php if ($claimed): ?>
                    <span class="bcc-entity-block__badge bcc-entity-block__badge--verified" title="<?php echo esc_attr(sprintf(__('Claimed by %s', 'bcc-trust'), $claimer->claimer_name ?? '')); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                        <?php echo esc_html($claimer->claimer_name ?? __('Verified Operator', 'bcc-trust')); ?>
                    </span>
                    <?php else: ?>
                    <span class="bcc-entity-block__badge bcc-entity-block__badge--unclaimed" title="<?php esc_attr_e('No verified operator yet', 'bcc-trust'); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <?php esc_html_e('Unclaimed', 'bcc-trust'); ?>
                    </span>
                    <?php endif; ?>
                </div>

                <div class="bcc-validator-card__stats">
                    <?php if ($commission !== null): ?>
                    <div class="bcc-validator-card__stat">
                        <span class="bcc-validator-card__stat-value"><?php echo esc_html($commission . '%'); ?></span>
                        <span class="bcc-validator-card__stat-label"><?php esc_html_e('Commission', 'bcc-trust'); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="bcc-validator-card__stat">
                        <span class="bcc-validator-card__stat-value"><?php echo esc_html($stakeStr . ($token ? " {$token}" : '')); ?></span>
                        <span class="bcc-validator-card__stat-label"><?php esc_html_e('Total Stake', 'bcc-trust'); ?></span>
                    </div>

                    <?php if ($uptime !== null): ?>
                    <div class="bcc-validator-card__stat">
                        <span class="bcc-validator-card__stat-value <?php echo $uptime >= 99 ? 'is-excellent' : ($uptime >= 95 ? 'is-good' : 'is-warning'); ?>"><?php echo esc_html($uptime . '%'); ?></span>
                        <span class="bcc-validator-card__stat-label"><?php esc_html_e('Uptime 30d', 'bcc-trust'); ?></span>
                    </div>
                    <?php endif; ?>

                </div>

                <?php if ($v->delegator_count || $v->voting_power_rank): ?>
                <div class="bcc-validator-card__meta">
                    <?php if ($v->delegator_count): ?>
                    <span><?php echo esc_html(number_format((int) $v->delegator_count)); ?> <?php esc_html_e('delegators', 'bcc-trust'); ?></span>
                    <?php endif; ?>
                    <?php if ($v->voting_power_rank): ?>
                    <span><?php printf(esc_html__('Rank #%d', 'bcc-trust'), (int) $v->voting_power_rank); ?></span>
                    <?php endif; ?>
                    <?php if ($jailed > 0): ?>
                    <span class="bcc-validator-card__jailed"><?php printf(esc_html__('Jailed %d×', 'bcc-trust'), $jailed); ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="bcc-entity-block__actions">
                    <?php if ($explorer && !empty($v->operator_address)): ?>
                    <a href="<?php echo esc_url($explorer . '/validators/' . $v->operator_address); ?>" class="bcc-entity-block__link" target="_blank" rel="noopener">
                        <?php esc_html_e('Explorer', 'bcc-trust'); ?> &rarr;
                    </a>
                    <?php endif; ?>

                    <?php if ($show_claim && $user_id && !$claimed): ?>
                    <button class="bcc-entity-block__claim-btn" data-entity-type="validator" data-entity-id="<?php echo esc_attr((string) $vid); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <?php esc_html_e('Claim as Operator', 'bcc-trust'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
