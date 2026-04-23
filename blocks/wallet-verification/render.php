<?php
/**
 * Wallet Verification Block – server-side render.
 *
 * Data source: PageDataLoader ONLY (single source of truth).
 * Per-chain wallet detail comes from the wallet_detail section.
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
           . '<strong>Wallet Verification</strong><br>'
           . '<small>Only visible to the page owner. Requires PeepSo profile context.</small>'
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

$page_owner_id = (int) ($data['builder']['id'] ?? 0);
if (!$page_owner_id) {
    return;
}

$current_user_id = get_current_user_id();
$viewer          = $current_user_id ? \BCC\Trust\Core\Services\PageDataLoader::getViewer($current_user_id, $page_id) : null;
$is_owner        = (bool) ($viewer['is_owner'] ?? false);
$compact_mode    = (bool) ($attributes['compact'] ?? false);

$wallet_chains = [
    'ethereum' => ['label' => 'Ethereum', 'icon' => "\u{2B21}", 'wallet_name' => 'MetaMask',  'enabled' => (bool) ($attributes['showEthereum'] ?? true)],
    'solana'   => ['label' => 'Solana',   'icon' => "\u{25CE}", 'wallet_name' => 'Phantom',   'enabled' => (bool) ($attributes['showSolana'] ?? true)],
    'cosmos'   => ['label' => 'Cosmos',   'icon' => "\u{269B}", 'wallet_name' => 'Keplr',     'enabled' => (bool) ($attributes['showCosmos'] ?? true)],
];

$role_labels = ['creator' => 'Creator', 'holder' => 'Holder', 'team' => 'Team', 'none' => 'Verified'];

// Per-chain detail loaded separately (not in main cached payload — keeps it lean).
$wallet_detail = \BCC\Trust\Core\Services\PageDataLoader::getWalletDetail($page_id);

$wrapper_attributes = get_block_wrapper_attributes([
    'class'          => 'bcc-wallet-verification',
    'data-page-id'   => esc_attr((string) $page_id),
    'data-page-owner' => esc_attr((string) $page_owner_id),
    'data-nonce'     => esc_attr(wp_create_nonce('wp_rest')),
]);
?>
<div <?php echo $wrapper_attributes; ?>>
    <h4 class="bcc-verif-section-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M16 12h.01"/></svg>
        Wallet
    </h4>

    <?php
    $has_any = false;
    foreach ($wallet_chains as $chain => $info):
        if (!$info['enabled']) continue;

        $conn      = $wallet_detail[$chain] ?? null;
        $connected = ($conn !== null && !empty($conn['wallet_address']));

        if (!$connected && !$is_owner) continue;
        $has_any = true;

        $role      = $connected ? ($conn['wallet_role'] ?? 'none') : '';
        $roleLabel = $role_labels[$role] ?? 'Verified';
    ?>
        <div class="bcc-wallet-section" data-chain="<?php echo esc_attr($chain); ?>">
            <div class="bcc-wallet-chain-header">
                <span class="bcc-wallet-chain-icon"><?php echo esc_html($info['icon']); ?></span>
                <span class="bcc-wallet-chain-name"><?php echo esc_html($info['label']); ?></span>
                <?php if ($connected): ?>
                    <span class="bcc-wallet-role-badge" data-role="<?php echo esc_attr($role); ?>"><?php echo esc_html($roleLabel); ?></span>
                    <span class="bcc-verif-status bcc-verif-status--connected">&#10003;</span>
                <?php endif; ?>
            </div>

            <?php if ($connected): ?>
                <div class="bcc-wallet-connected">
                    <?php
                    $addr = $conn['wallet_address'];
                    $display_addr = $compact_mode
                        ? (strlen($addr) > 12 ? substr($addr, 0, 4) . '…' . substr($addr, -4) : $addr)
                        : (strlen($addr) > 16 ? substr($addr, 0, 6) . '…' . substr($addr, -4) : $addr);
                    ?>
                    <span class="bcc-wallet-address" title="<?php echo esc_attr($addr); ?>"><?php echo esc_html($display_addr); ?></span>
                    <button class="bcc-wallet-copy-btn" type="button" aria-label="Copy wallet address" data-address="<?php echo esc_attr($addr); ?>">Copy</button>
                    <?php if (((float) ($conn['trust_boost'] ?? 0)) > 0 && !$compact_mode): ?>
                        <span class="bcc-wallet-boost">+<?php echo esc_html(number_format((float) $conn['trust_boost'], 1)); ?> trust</span>
                    <?php endif; ?>
                    <?php if ($is_owner): ?>
                        <button class="bcc-wallet-disconnect-btn" data-chain="<?php echo esc_attr($chain); ?>">Disconnect</button>
                    <?php endif; ?>
                </div>

                <?php
                $nft_collections = $conn['nft_collections'] ?? [];
                $display_collections = array_filter($nft_collections, function($c) {
                    return in_array($c['role'] ?? '', ['creator', 'holder'], true);
                });
                if (!empty($display_collections)):
                ?>
                <div class="bcc-nft-badge-list">
                    <?php foreach ($display_collections as $nft):
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
                            <?php if ($nft_boost > 0 && !$compact_mode): ?>
                                <span class="bcc-nft-badge-boost">+<?php echo esc_html(number_format($nft_boost, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            <?php elseif ($is_owner): ?>
                <div class="bcc-wallet-connect-area">
                    <button class="bcc-verif-btn bcc-verif-btn--connect bcc-wallet-connect-btn"
                            data-chain="<?php echo esc_attr($chain); ?>"
                            data-post-id="<?php echo esc_attr((string) $page_id); ?>"
                            data-label="Connect <?php echo esc_attr($info['wallet_name']); ?>">
                        Connect <?php echo esc_html($info['wallet_name']); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!$has_any && $is_owner && !$compact_mode): ?>
        <p class="bcc-wallet-empty">Connect a wallet to verify your blockchain identity.</p>
    <?php endif; ?>

    <?php
    $onchain_keys_configured = (defined('BCC_ETHERSCAN_API_KEY') && BCC_ETHERSCAN_API_KEY !== '')
        || (defined('BCC_ALCHEMY_API_KEY') && BCC_ALCHEMY_API_KEY !== '');
    if ($has_any && !$onchain_keys_configured && !$compact_mode): ?>
        <p class="bcc-wallet-degraded-notice" style="font-size:0.75em;color:#9ca3af;margin:8px 0 0;text-align:center;">
            On-chain enrichment unavailable — trust boost may not be applied.
        </p>
    <?php endif; ?>

    <div class="bcc-wallet-status"></div>
</div>
