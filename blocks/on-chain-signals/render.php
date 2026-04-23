<?php
/**
 * On-Chain Signals Block – server-side render.
 *
 * Shows wallet chain badges, trust boosts from wallets, and NFT collection badges.
 *
 * Data source: PageDataLoader ONLY (single source of truth).
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

if (!defined('ABSPATH')) {
    exit;
}

$page_id = (int) ($attributes['pageId'] ?? 0);
if (!$page_id) {
    $page_id = get_the_ID();
}
if (!$page_id) {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        echo '<div class="bcc-block-placeholder" style="padding:20px;background:#f0f0f0;border:1px dashed #ccc;color:#666;text-align:center;border-radius:4px;">'
           . '<strong>On-Chain Signals</strong><br>'
           . '<small>Requires a page ID and linked wallets.</small>'
           . '</div>';
        return;
    }
    return;
}

if (!class_exists('\\BCC\\Trust\\Core\\Services\\PageDataLoader')) {
    return;
}

$data = \BCC\Trust\Core\Services\PageDataLoader::get($page_id);
if (!$data) {
    return;
}

// Per-chain detail loaded separately (not in main cached payload — keeps it lean).
$wallet_detail = \BCC\Trust\Core\Services\PageDataLoader::getWalletDetail($page_id);
if (empty($wallet_detail)) {
    return;
}

$chain_meta = [
    'ethereum' => ['label' => 'Ethereum', 'icon' => "\u{2B21}"],
    'solana'   => ['label' => 'Solana',   'icon' => "\u{25CE}"],
    'cosmos'   => ['label' => 'Cosmos',   'icon' => "\u{269B}"],
];

// Check if on-chain data enrichment is available (API keys configured).
$signals_available = defined('BCC_ETHERSCAN_API_KEY') && BCC_ETHERSCAN_API_KEY !== ''
    || defined('BCC_ALCHEMY_API_KEY') && BCC_ALCHEMY_API_KEY !== '';

// Total boost from the aggregated onchain section.
$total_boost = (float) ($data['onchain']['total_boost'] ?? 0);

// Collect NFTs from onchain section (already aggregated by PageDataLoader).
$all_nfts    = [];
$cached_nfts = $data['onchain']['nft_collections'] ?? [];

if (!empty($cached_nfts)) {
    foreach ($cached_nfts as $nft) {
        if (in_array($nft['role'] ?? '', ['creator', 'holder'], true)) {
            $all_nfts[] = $nft;
        }
    }
} else {
    // Fallback: derive from wallet_detail.
    foreach ($wallet_detail as $chain => $conn) {
        if (!$total_boost) {
            $total_boost += (float) ($conn['trust_boost'] ?? 0);
        }
        $nfts = $conn['nft_collections'] ?? [];
        foreach ($nfts as $nft) {
            if (in_array($nft['role'] ?? '', ['creator', 'holder'], true)) {
                $nft['chain'] = $chain;
                $all_nfts[]   = $nft;
            }
        }
    }
}

$wrapper_attributes = get_block_wrapper_attributes(['class' => 'bcc-onchain-signals']);
?>
<div <?php echo $wrapper_attributes; ?>>
    <h4 class="bcc-section-title">On-Chain Signals</h4>

    <!-- Connected chains -->
    <div class="bcc-onchain-chains">
        <?php foreach ($wallet_detail as $chain => $conn):
            $meta  = $chain_meta[$chain] ?? ['label' => ucfirst($chain), 'icon' => ''];
            $boost = (float) ($conn['trust_boost'] ?? 0);
        ?>
        <div class="bcc-onchain-chain-badge">
            <span class="bcc-onchain-chain-icon"><?php echo esc_html($meta['icon']); ?></span>
            <span class="bcc-onchain-chain-label"><?php echo esc_html($meta['label']); ?></span>
            <?php if ($boost > 0): ?>
                <span class="bcc-onchain-chain-boost">+<?php echo esc_html(number_format($boost, 1)); ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- On-chain activity stats (populated by chain indexer) -->
    <?php
    $wallet_age   = $data['onchain']['wallet_age'] ?? null;
    $transactions = $data['onchain']['transactions'] ?? null;
    $contracts    = $data['onchain']['contracts'] ?? null;
    $has_indexer_data = ($wallet_age !== null || $transactions !== null || $contracts !== null);
    ?>
    <?php if ($has_indexer_data): ?>
    <div class="bcc-onchain-stats">
        <?php if ($wallet_age !== null): ?>
        <div class="bcc-onchain-stat">
            <span class="bcc-onchain-stat-value"><?php echo esc_html(number_format($wallet_age, 1)); ?>y</span>
            <span class="bcc-onchain-stat-label">Wallet Age</span>
        </div>
        <?php endif; ?>
        <?php if ($transactions !== null): ?>
        <div class="bcc-onchain-stat">
            <span class="bcc-onchain-stat-value"><?php echo esc_html($transactions >= 1000 ? number_format($transactions / 1000, 1) . 'K' : (string) $transactions); ?></span>
            <span class="bcc-onchain-stat-label">Transactions</span>
        </div>
        <?php endif; ?>
        <?php if ($contracts !== null && $contracts > 0): ?>
        <div class="bcc-onchain-stat">
            <span class="bcc-onchain-stat-value"><?php echo esc_html((string) $contracts); ?></span>
            <span class="bcc-onchain-stat-label">Contracts</span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($total_boost > 0): ?>
    <div class="bcc-onchain-total-boost">
        <span class="bcc-onchain-total-label">Total on-chain trust boost</span>
        <span class="bcc-onchain-total-value">+<?php echo esc_html(number_format($total_boost, 1)); ?> pts</span>
    </div>
    <?php endif; ?>

    <!-- NFT collection badges -->
    <?php if (!empty($all_nfts)): ?>
    <div class="bcc-onchain-nfts">
        <h5 class="bcc-onchain-nfts-title">NFT Collections</h5>
        <div class="bcc-nft-badge-list">
            <?php foreach ($all_nfts as $nft):
                $nft_role  = $nft['role'];
                $nft_name  = $nft['name']  ?? '';
                $nft_image = $nft['image'] ?? '';
                $nft_boost = (float) ($nft['trust_boost'] ?? 0);
            ?>
            <div class="bcc-nft-badge bcc-nft-badge--<?php echo esc_attr($nft_role); ?>">
                <?php if ($nft_image): ?>
                    <img class="bcc-nft-badge-img" src="<?php echo esc_url($nft_image); ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="bcc-nft-badge-body">
                    <span class="bcc-nft-badge-name"><?php echo esc_html($nft_name); ?></span>
                    <span class="bcc-nft-badge-role"><?php echo $nft_role === 'creator' ? 'Artist/Creator' : esc_html($nft_name) . ' Member'; ?></span>
                    <?php if ($nft_boost > 0): ?>
                        <span class="bcc-nft-badge-boost">+<?php echo esc_html(number_format($nft_boost, 1)); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$signals_available && !$has_indexer_data && $total_boost == 0): ?>
    <p class="bcc-onchain-notice" style="font-size:0.75em;color:#9ca3af;margin:8px 0 0;text-align:center;">
        On-chain activity data is currently unavailable.
    </p>
    <?php endif; ?>
</div>
