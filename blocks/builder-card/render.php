<?php
/**
 * Builder Card Block – server-side render.
 *
 * Compact card: avatar, display name, trust tier badge, score, verification icons.
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
           . '<strong>Builder Card</strong><br>'
           . '<small>Requires a page ID. Place on a PeepSo profile page or set a Page ID in block settings.</small>'
           . '</div>';
        return;
    }
    return;
}

$show_score         = (bool) ($attributes['showScore'] ?? true);
$show_verifications = (bool) ($attributes['showVerifications'] ?? true);

/* --- Cached page data --- */
if (!class_exists('\\BCC\\Trust\\Core\\Services\\PageDataLoader')) {
    return;
}

$data = \BCC\Trust\Core\Services\PageDataLoader::get($page_id);
if (!$data) {
    return;
}

$display_name = $data['builder']['name'] ?? '';
$avatar_url   = $data['builder']['avatar'] ?? '';
if (!$display_name) {
    return;
}

$raw_score   = $data['trust']['score'] ?? null;
$total_score = $raw_score !== null ? (int) $raw_score : null;
$tier        = $data['trust']['tier'] ?? 'unavailable';

$tier_labels = [
    'elite' => 'Elite', 'trusted' => 'Trusted', 'neutral' => 'Neutral',
    'caution' => 'Caution', 'risky' => 'Risky', 'unavailable' => 'Unavailable',
];
$tier_label = $tier_labels[$tier] ?? ucfirst($tier);

/* --- Verifications --- */
$verification_icons = [];
if ($show_verifications) {
    if (!empty($data['verification']['github']))  $verification_icons[] = ['title' => 'GitHub',   'abbr' => 'GH'];
    if (!empty($data['verification']['x']))       $verification_icons[] = ['title' => 'X',        'abbr' => 'X'];
    if (!empty($data['wallets']['ethereum']))      $verification_icons[] = ['title' => 'Ethereum', 'abbr' => 'ETH'];
    if (!empty($data['wallets']['solana']))        $verification_icons[] = ['title' => 'Solana',   'abbr' => 'SOL'];
    if (!empty($data['wallets']['cosmos']))        $verification_icons[] = ['title' => 'Cosmos',   'abbr' => 'ATOM'];
}

$wrapper_attributes = get_block_wrapper_attributes(['class' => 'bcc-builder-card']);
?>
<div <?php echo $wrapper_attributes; ?>>
    <div class="bcc-builder-card__avatar">
        <img src="<?php echo esc_url($avatar_url); ?>" alt="<?php echo esc_attr($display_name); ?>" width="64" height="64" loading="lazy" />
    </div>
    <div class="bcc-builder-card__body">
        <div class="bcc-builder-card__name"><?php echo esc_html($display_name); ?></div>
        <span class="bcc-tier-badge bcc-tier-badge--<?php echo esc_attr($tier); ?>"><?php echo esc_html($tier_label); ?></span>
        <?php if ($show_score): ?>
            <span class="bcc-builder-card__score bcc-tier-color--<?php echo esc_attr($tier); ?>"><?php echo $total_score !== null ? esc_html((string) round($total_score)) . '/100' : esc_html__('—', 'bcc-trust'); ?></span>
        <?php endif; ?>
        <?php if (!empty($verification_icons)): ?>
            <div class="bcc-builder-card__verifications">
                <?php foreach ($verification_icons as $vi): ?>
                    <span class="bcc-builder-card__verif-icon" title="<?php echo esc_attr($vi['title']); ?>"><?php echo esc_html($vi['abbr']); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
