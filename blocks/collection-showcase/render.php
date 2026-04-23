<?php
/**
 * Collection Showcase Block – server-side render.
 *
 * Shows NFT collection grid with floor price, holders, volume stats.
 * Includes "Claim as Creator/Holder" button for logged-in users.
 *
 * @var array    $attributes Block attributes
 * @var string   $content    Inner content
 * @var WP_Block $block      Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

$page_id    = (int) ($attributes['pageId'] ?? get_the_ID());
$per_page   = (int) ($attributes['perPage'] ?? 6);
$show_claim = (bool) ($attributes['showClaim'] ?? true);

// Fetch collections for this page.
$data = [];
if (class_exists('\\BCC\\Onchain\\Repositories\\CollectionRepository')) {
    $data = \BCC\Onchain\Repositories\CollectionRepository::getForProject($page_id, 1, $per_page);
}

$collections = $data['items'] ?? [];
$total       = $data['total'] ?? 0;
$total_pages = $data['pages'] ?? 0;

if (empty($collections) && !is_admin()) {
    return;
}

// Batch-load all claims for these collections (single query, not N+1).
$claims_map = [];
if (function_exists('bcc_onchain_claims_table') && !empty($collections)) {
    $cids = array_map(fn($c) => (int) $c->id, $collections);
    $claims_map = \BCC\Onchain\Repositories\ClaimRepository::getForEntityBatch('collection', $cids);
}

$user_id     = get_current_user_id();
$instance_id = 'collection-showcase-' . wp_unique_id();

// Helper: format currency values.
if (!function_exists('bcc_format_price')) {
    function bcc_format_price(?float $price, ?string $currency): string {
        if ($price === null || $price <= 0) return '--';
        $formatted = $price >= 1000 ? number_format($price, 0) : number_format($price, 2);
        return $formatted . ($currency ? " {$currency}" : '');
    }
}

$wrapper_attributes = get_block_wrapper_attributes([
    'class'           => 'bcc-entity-block bcc-collection-showcase',
    'id'              => esc_attr($instance_id),
    'data-page-id'    => esc_attr((string) $page_id),
    'data-per-page'   => esc_attr((string) $per_page),
    'data-total'      => esc_attr((string) $total),
    'data-show-claim' => $show_claim ? '1' : '0',
]);
?>
<div <?php echo $wrapper_attributes; ?>>

    <div class="bcc-entity-block__header">
        <h3 class="bcc-entity-block__title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            <?php esc_html_e('NFT Collections', 'bcc-trust'); ?>
            <?php if ($total > 0): ?>
            <span class="bcc-entity-block__count"><?php echo esc_html((string) $total); ?></span>
            <?php endif; ?>
        </h3>
    </div>

    <div class="bcc-entity-block__grid bcc-entity-block__grid--collections" role="list">
        <?php if (empty($collections)): ?>
        <p class="bcc-entity-block__empty"><?php esc_html_e('No NFT collections linked to this project.', 'bcc-trust'); ?></p>
        <?php else: ?>
            <?php foreach ($collections as $c):
                $cid       = (int) $c->id;
                $claimers  = $claims_map[$cid] ?? [];
                $chain     = $c->chain_name ?? ucfirst($c->chain_slug ?? '');
                $standard  = $c->token_standard ?? '';
                $floor     = bcc_format_price((float) ($c->floor_price ?? 0), $c->floor_currency ?? '');
                $holders   = (int) ($c->unique_holders ?? 0);
                $holdersStr = $holders >= 1000 ? round($holders / 1000, 1) . 'K' : (string) $holders;
                $supply    = (int) ($c->total_supply ?? 0);
                $supplyStr = $supply >= 1000 ? round($supply / 1000, 1) . 'K' : (string) $supply;
                $volume    = (float) ($c->total_volume ?? 0);
                $volumeStr = $volume >= 1000000 ? round($volume / 1000000, 1) . 'M' : ($volume >= 1000 ? round($volume / 1000, 1) . 'K' : number_format($volume, 0));
                $explorer  = $c->explorer_url ?? '';
                $contract  = $c->contract_address ?? '';
            ?>
            <div class="bcc-entity-block__card bcc-collection-card" role="listitem" data-entity-type="collection" data-entity-id="<?php echo esc_attr((string) $cid); ?>">

                <div class="bcc-collection-card__header">
                    <div class="bcc-collection-card__identity">
                        <span class="bcc-collection-card__name"><?php echo esc_html($c->collection_name ?: 'Unnamed Collection'); ?></span>
                        <span class="bcc-collection-card__chain-badge">
                            <?php echo esc_html($chain); ?>
                            <?php if ($standard): ?>
                            <span class="bcc-collection-card__standard"><?php echo esc_html($standard); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php
                        $hasCreator = false;
                        foreach ($claimers as $cl) {
                            if ($cl->claim_role === 'creator') { $hasCreator = true; break; }
                        }
                    ?>
                    <?php if (!empty($claimers)): ?>
                    <div class="bcc-entity-block__badges">
                        <?php foreach ($claimers as $claimer): ?>
                        <span class="bcc-entity-block__badge bcc-entity-block__badge--<?php echo esc_attr($claimer->claim_role); ?>" title="<?php echo esc_attr($claimer->claimer_name ?? ''); ?>">
                            <?php echo esc_html(ucfirst($claimer->claim_role)); ?>: <?php echo esc_html($claimer->claimer_name ?? ''); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!$hasCreator): ?>
                    <span class="bcc-entity-block__badge bcc-entity-block__badge--unclaimed" title="<?php esc_attr_e('No verified creator yet', 'bcc-trust'); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <?php esc_html_e('Unclaimed', 'bcc-trust'); ?>
                    </span>
                    <?php endif; ?>
                </div>

                <div class="bcc-collection-card__stats">
                    <div class="bcc-collection-card__stat">
                        <span class="bcc-collection-card__stat-value"><?php echo esc_html($floor); ?></span>
                        <span class="bcc-collection-card__stat-label"><?php esc_html_e('Floor', 'bcc-trust'); ?></span>
                    </div>
                    <div class="bcc-collection-card__stat">
                        <span class="bcc-collection-card__stat-value"><?php echo esc_html($holdersStr); ?></span>
                        <span class="bcc-collection-card__stat-label"><?php esc_html_e('Holders', 'bcc-trust'); ?></span>
                    </div>
                    <div class="bcc-collection-card__stat">
                        <span class="bcc-collection-card__stat-value"><?php echo esc_html($supplyStr); ?></span>
                        <span class="bcc-collection-card__stat-label"><?php esc_html_e('Supply', 'bcc-trust'); ?></span>
                    </div>
                    <div class="bcc-collection-card__stat">
                        <span class="bcc-collection-card__stat-value"><?php echo esc_html($volumeStr); ?></span>
                        <span class="bcc-collection-card__stat-label"><?php esc_html_e('Volume', 'bcc-trust'); ?></span>
                    </div>
                </div>

                <div class="bcc-entity-block__actions">
                    <?php if ($explorer && $contract): ?>
                    <a href="<?php echo esc_url($explorer . '/token/' . $contract); ?>" class="bcc-entity-block__link" target="_blank" rel="noopener">
                        <?php esc_html_e('Explorer', 'bcc-trust'); ?> &rarr;
                    </a>
                    <?php endif; ?>

                    <?php if ($show_claim && $user_id && empty($claimers)): ?>
                    <button class="bcc-entity-block__claim-btn" data-entity-type="collection" data-entity-id="<?php echo esc_attr((string) $cid); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <?php esc_html_e('Claim', 'bcc-trust'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
