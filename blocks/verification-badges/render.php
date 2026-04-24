<?php
/**
 * Verification Badges Block -- server-side render.
 *
 * Horizontal bar of verification icons for a user/page:
 * email, GitHub, X/Twitter, wallet connections, total count.
 *
 * Data source: PageDataLoader (single source of truth).
 *
 * @var array    $attributes Block attributes
 * @var string   $content    Inner content
 * @var WP_Block $block      Block instance
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
        echo bcc_trust_block_placeholder(
            'Verification Badges',
            'Requires a page ID. Place on a PeepSo profile page or set a Page ID in block settings.'
        );
        return;
    }
    return;
}

if (!bcc_trust_require_peepso_page($page_id, $attributes, 'Verification Badges')) {
    return;
}

$compact = (bool) ($attributes['compact'] ?? false);

if (!class_exists('\\BCC\\Trust\\Core\\Services\\PageDataLoader')) {
    return;
}

$data = \BCC\Trust\Core\Services\PageDataLoader::get($page_id);
if (!$data) {
    return;
}

$verification = $data['verification'] ?? [];
$profiles     = $data['profiles'] ?? [];
$wallets_data = $data['wallets'] ?? [];

/* --- Build badges from PageDataLoader data --- */
$badges = [];

// 1. Email connected
$email_verified = (bool) ($verification['email'] ?? false);
$badges[] = [
    'key'       => 'email',
    'label'     => __('Email', 'bcc-trust'),
    'tooltip'   => $email_verified
        ? __('Email connected', 'bcc-trust')
        : __('Email not connected', 'bcc-trust'),
    'verified'  => $email_verified,
    'icon'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
];

// 2. GitHub connected
$github_connected = (bool) ($verification['github'] ?? false);
$github_username  = $profiles['github']['username'] ?? null;
$gh_tooltip = $github_connected && $github_username
    ? sprintf(__('GitHub: %s', 'bcc-trust'), $github_username)
    : __('GitHub not connected', 'bcc-trust');
$badges[] = [
    'key'       => 'github',
    'label'     => __('GitHub', 'bcc-trust'),
    'tooltip'   => $gh_tooltip,
    'verified'  => $github_connected,
    'icon'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>',
];

// 3. X/Twitter connected
$x_connected = (bool) ($verification['x'] ?? false);
$x_username  = $profiles['x']['username'] ?? null;
$x_tooltip = $x_connected && $x_username
    ? sprintf(__('X: @%s', 'bcc-trust'), $x_username)
    : __('X not connected', 'bcc-trust');
$badges[] = [
    'key'       => 'x',
    'label'     => __('X', 'bcc-trust'),
    'tooltip'   => $x_tooltip,
    'verified'  => $x_connected,
    'icon'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
];

// 4. Wallet connected — derive chain list from wallets section (booleans).
$wallet_connected = (bool) ($verification['wallet'] ?? false);
$wallet_chains    = [];
foreach ($wallets_data as $chain => $connected) {
    if ($connected) {
        $wallet_chains[] = $chain;
    }
}
$wallet_count = count($wallet_chains);

$wallet_tooltip = $wallet_connected
    ? sprintf(
        _n('%d wallet connected (%s)', '%d wallets connected (%s)', $wallet_count, 'bcc-trust'),
        $wallet_count,
        implode(', ', array_map('ucfirst', $wallet_chains))
    )
    : __('No wallets connected', 'bcc-trust');
$badges[] = [
    'key'       => 'wallet',
    'label'     => __('Wallet', 'bcc-trust'),
    'tooltip'   => $wallet_tooltip,
    'verified'  => $wallet_connected,
    'icon'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
];

// Count total verified
$verified_count = 0;
foreach ($badges as $b) {
    if ($b['verified']) {
        $verified_count++;
    }
}

$wrapper_class = 'bcc-vb';
if ($compact) {
    $wrapper_class .= ' bcc-vb--compact';
}

$wrapper_attributes = get_block_wrapper_attributes(['class' => $wrapper_class]);
?>
<?php
$svg_kses = [
    'svg'      => ['width' => [], 'height' => [], 'viewBox' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [], 'aria-hidden' => [], 'focusable' => []],
    'path'     => ['d' => [], 'fill' => []],
    'polyline' => ['points' => []],
    'circle'   => ['cx' => [], 'cy' => [], 'r' => []],
    'rect'     => ['x' => [], 'y' => [], 'width' => [], 'height' => [], 'rx' => [], 'ry' => []],
];
// Inject aria-hidden on the outer <svg> so the decorative icon isn't
// announced separately from the badge's aria-label.
$decorate_svg = static function (string $svg): string {
    return preg_replace('/<svg\b/', '<svg aria-hidden="true" focusable="false"', $svg, 1);
};
$count_label = sprintf(
    /* translators: 1: connected count, 2: total badges */
    __('%1$d of %2$d identity signals connected', 'bcc-trust'),
    $verified_count,
    count($badges)
);
?>
<div <?php echo $wrapper_attributes; ?>>
    <div class="bcc-vb__badges">
        <?php foreach ($badges as $badge): ?>
        <span class="bcc-vb__badge <?php echo $badge['verified'] ? 'bcc-vb__badge--verified' : 'bcc-vb__badge--unverified'; ?>"
              role="img"
              aria-label="<?php echo esc_attr($badge['tooltip']); ?>"
              title="<?php echo esc_attr($badge['tooltip']); ?>">
            <span class="bcc-vb__icon"><?php echo wp_kses($decorate_svg($badge['icon']), $svg_kses); ?></span>
            <?php if (!$compact): ?>
            <span class="bcc-vb__label" aria-hidden="true"><?php echo esc_html($badge['label']); ?></span>
            <?php endif; ?>
            <?php if ($badge['verified']): ?>
            <svg class="bcc-vb__check" aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
            <?php endif; ?>
        </span>
        <?php endforeach; ?>
        <span class="bcc-vb__count"
              role="status"
              aria-label="<?php echo esc_attr($count_label); ?>"
              title="<?php echo esc_attr($count_label); ?>">
            <?php echo esc_html($verified_count . '/' . count($badges)); ?>
        </span>
    </div>
</div>
