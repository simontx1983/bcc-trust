<?php
/**
 * NFT Leaderboard Block – server-side render.
 *
 * Chain-separated tabs: EVM / Solana / Cosmos.
 * Each tab fetches independently — no cross-chain mixing.
 * Default tab is server-rendered; others load via REST on tab click.
 *
 * @var array    $attributes Block attributes
 * @var string   $content    Inner content
 * @var WP_Block $block      Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

$default_chain = sanitize_key($attributes['defaultChain'] ?? 'evm');
$per_page      = (int) ($attributes['perPage'] ?? 20);
$show_claim    = (bool) ($attributes['showClaim'] ?? true);
$user_id       = get_current_user_id();
$instance_id   = 'nft-lb-' . wp_unique_id();

$chain_tabs = [
    'evm'    => __('EVM', 'bcc-trust'),
    'solana' => __('Solana', 'bcc-trust'),
    'cosmos' => __('Cosmos', 'bcc-trust'),
];

// Load initial data for the default tab only.
$collections_data = [];
if (class_exists('\\BCC\\Onchain\\Repositories\\CollectionRepository')) {
    $collections_data = \BCC\Onchain\Repositories\CollectionRepository::getTopCollectionsByChainType($default_chain, 1, $per_page);
}

// Batch-load claims for initial data.
$collection_claims = [];
if (function_exists('bcc_onchain_claims_table') && class_exists('\\BCC\\Onchain\\Repositories\\ClaimRepository')) {
    if (!empty($collections_data['items'])) {
        $cids = array_map(fn($c) => (int) $c->id, $collections_data['items']);
        $collection_claims = \BCC\Onchain\Repositories\ClaimRepository::getForEntityBatch('collection', $cids);
    }
}

// Helpers.
if (!function_exists('bcc_lb_format_num')) {
    function bcc_lb_format_num($n, int $decimals = 1): string {
        if ($n === null) return '--';
        $n = (float) $n;
        if ($n >= 1000000) return round($n / 1000000, $decimals) . 'M';
        if ($n >= 1000) return round($n / 1000, $decimals) . 'K';
        return number_format($n, $decimals > 0 && $n < 100 ? $decimals : 0);
    }
}

$wrapper_attributes = get_block_wrapper_attributes([
    'class'            => 'bcc-entity-block bcc-nft-lb',
    'id'               => esc_attr($instance_id),
    'data-per-page'    => esc_attr((string) $per_page),
    'data-show-claim'  => $show_claim ? '1' : '0',
    'data-default-chain' => esc_attr($default_chain),
]);
?>
<div <?php echo $wrapper_attributes; ?>>

    <div class="bcc-nft-lb__tabs" role="tablist">
        <?php foreach ($chain_tabs as $chain_key => $chain_label): ?>
        <button class="bcc-nft-lb__tab <?php echo $chain_key === $default_chain ? 'is-active' : ''; ?>"
                role="tab"
                data-chain="<?php echo esc_attr($chain_key); ?>"
                aria-selected="<?php echo $chain_key === $default_chain ? 'true' : 'false'; ?>">
            <?php echo esc_html($chain_label); ?>
            <?php if ($chain_key === $default_chain && ($collections_data['total'] ?? 0)): ?>
            <span class="bcc-nft-lb__tab-count"><?php echo esc_html(bcc_lb_format_num($collections_data['total'], 0)); ?></span>
            <?php endif; ?>
        </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($chain_tabs as $chain_key => $chain_label): ?>
    <div class="bcc-nft-lb__panel <?php echo $chain_key === $default_chain ? 'is-active' : ''; ?>"
         data-chain-panel="<?php echo esc_attr($chain_key); ?>"
         <?php echo $chain_key !== $default_chain ? 'data-needs-fetch="1"' : ''; ?>>

        <?php if ($chain_key === $default_chain): ?>
            <?php /* Server-rendered default tab */ ?>
            <div class="bcc-nft-lb__table-header">
                <span class="bcc-nft-lb__col bcc-nft-lb__col--rank">#</span>
                <span class="bcc-nft-lb__col bcc-nft-lb__col--name"><?php esc_html_e('Collection', 'bcc-trust'); ?></span>
                <span class="bcc-nft-lb__col bcc-nft-lb__col--stat"><?php esc_html_e('Floor', 'bcc-trust'); ?></span>
                <span class="bcc-nft-lb__col bcc-nft-lb__col--stat"><?php esc_html_e('Volume', 'bcc-trust'); ?></span>
                <span class="bcc-nft-lb__col bcc-nft-lb__col--stat"><?php esc_html_e('Holders', 'bcc-trust'); ?></span>
                <span class="bcc-nft-lb__col bcc-nft-lb__col--action"></span>
            </div>
            <?php if (empty($collections_data['items'])): ?>
            <p class="bcc-entity-block__empty"><?php printf(esc_html__('No %s collections indexed yet.', 'bcc-trust'), esc_html($chain_label)); ?></p>
            <?php else: ?>
                <?php foreach ($collections_data['items'] as $rank => $c):
                    $cid       = (int) $c->id;
                    $claimers  = $collection_claims[$cid] ?? [];
                    $hasCreator = false;
                    foreach ($claimers as $cl) { if ($cl->claim_role === 'creator') { $hasCreator = true; break; } }
                    $chain_name = $c->chain_name ?? '';
                    $currency   = $c->floor_currency ?? $c->native_token ?? 'ETH';
                    $floor      = ($c->floor_price !== null && (float) $c->floor_price > 0)
                        ? bcc_lb_format_num((float) $c->floor_price) . ' ' . $currency : '--';
                    $volume     = ($c->total_volume !== null && (float) $c->total_volume > 0)
                        ? bcc_lb_format_num((float) $c->total_volume) : '--';
                    $holders    = ($c->unique_holders !== null && (int) $c->unique_holders > 0)
                        ? bcc_lb_format_num((int) $c->unique_holders, 0) : '--';
                    $explorer   = ($c->explorer_url ?? '') . '/token/' . ($c->contract_address ?? '');
                    $image      = $c->image_url ?? '';
                ?>
                <div class="bcc-nft-lb__row" data-entity-type="collection" data-entity-id="<?php echo esc_attr((string) $cid); ?>">
                    <span class="bcc-nft-lb__col bcc-nft-lb__col--rank"><?php echo esc_html((string) ($rank + 1)); ?></span>
                    <span class="bcc-nft-lb__col bcc-nft-lb__col--name">
                        <?php if ($image): ?>
                        <img src="<?php echo esc_url($image); ?>" alt="" class="bcc-nft-lb__avatar" width="32" height="32" loading="lazy" />
                        <?php endif; ?>
                        <span class="bcc-nft-lb__name-group">
                            <strong><?php echo esc_html($c->collection_name ?: 'Unnamed'); ?></strong>
                            <small><?php echo esc_html($chain_name); ?></small>
                        </span>
                        <?php if ($hasCreator): ?>
                            <?php $cr = $claimers[0]; ?>
                            <span class="bcc-entity-block__badge bcc-entity-block__badge--verified"><?php echo esc_html($cr->claimer_name ?? 'Verified'); ?></span>
                        <?php else: ?>
                            <span class="bcc-entity-block__badge bcc-entity-block__badge--unclaimed"><?php esc_html_e('Unclaimed', 'bcc-trust'); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="bcc-nft-lb__col bcc-nft-lb__col--stat"><?php echo esc_html($floor); ?></span>
                    <span class="bcc-nft-lb__col bcc-nft-lb__col--stat"><?php echo esc_html($volume); ?></span>
                    <span class="bcc-nft-lb__col bcc-nft-lb__col--stat"><?php echo esc_html($holders); ?></span>
                    <span class="bcc-nft-lb__col bcc-nft-lb__col--action">
                        <?php if ($c->contract_address && ($c->explorer_url ?? '')): ?>
                        <a href="<?php echo esc_url($explorer); ?>" class="bcc-entity-block__link" target="_blank" rel="noopener"><?php esc_html_e('View', 'bcc-trust'); ?></a>
                        <?php endif; ?>
                        <?php if ($show_claim && $user_id && !$hasCreator): ?>
                        <button class="bcc-entity-block__claim-btn" data-entity-type="collection" data-entity-id="<?php echo esc_attr((string) $cid); ?>">
                            <?php esc_html_e('Claim Your Community', 'bcc-trust'); ?>
                        </button>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php else: ?>
            <?php /* Placeholder for lazy-loaded tabs */ ?>
            <div class="bcc-nft-lb__loading" style="display:none;">
                <span class="bcc-nft-lb__spinner"></span>
            </div>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>

</div>
