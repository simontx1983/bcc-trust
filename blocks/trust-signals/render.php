<?php
/**
 * Trust Signals Block – server-side render.
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
        echo bcc_trust_block_placeholder(
            'Trust Signals',
            'Requires a page ID. Place on a PeepSo profile page.'
        );
        return;
    }
    return;
}

if (!bcc_trust_require_peepso_page($page_id, $attributes, 'Trust Signals')) {
    return;
}

if (!class_exists('\\BCC\\Trust\\Core\\Services\\PageDataLoader')) {
    return;
}

$data = \BCC\Trust\Core\Services\PageDataLoader::get($page_id);
if (!$data) {
    return;
}

$unique_voters      = (int) ($data['trust']['unique_voters'] ?? 0);
$last_vote_at_raw   = $data['trust']['last_vote_at'] ?? null;
$last_vote_at       = $last_vote_at_raw ? date_create($last_vote_at_raw) : null;
$confidence_percent = intval(($data['trust']['confidence'] ?? 0) * 100);
$tier               = $data['trust']['tier'] ?? 'unavailable';

$owner_registered        = $data['reputation']['account_registered'] ?? null;
$verified_identity_count = (int) ($data['reputation']['identity_count'] ?? 0);
$verified_identity_types = (array) ($data['reputation']['identity_types'] ?? []);

$has_signals = ($unique_voters > 0 || $last_vote_at || $owner_registered || $verified_identity_count > 0);
if (!$has_signals) {
    return;
}

$wrapper_attributes = get_block_wrapper_attributes(['class' => 'bcc-trust-signals']);
?>
<div <?php echo $wrapper_attributes; ?>>
    <h4 class="bcc-section-title">Trust Signals</h4>
    <ul class="bcc-trust-signal-list">
        <?php if ($unique_voters > 0): ?>
        <li class="bcc-trust-signal bcc-trust-signal--voters">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Trusted by <?php echo intval($unique_voters); ?> member<?php echo $unique_voters !== 1 ? 's' : ''; ?></span>
        </li>
        <?php endif; ?>

        <?php
        // Skip confidence row when embedded in the trust header — it's
        // already shown next to the grade in the score-meta section.
        $is_embedded = !empty($attributes['embedded']);
        if ($confidence_percent > 0 && !$is_embedded): ?>
        <li class="bcc-trust-signal bcc-trust-signal--confidence">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?php
            $conf_tooltip = __( 'How reliable this score is. Higher means more diverse voters and stronger signals.', 'bcc-trust' );
            ?>
            <span title="<?php echo esc_attr( $conf_tooltip ); ?>"><?php esc_html_e( 'Score confidence', 'bcc-trust' ); ?></span>
            <?php
            $conf_valuetext = sprintf(
                /* translators: 1: confidence percentage, 2: trust tier name */
                __( '%1$d%% confidence, %2$s tier', 'bcc-trust' ),
                $confidence_percent,
                ucfirst( $tier )
            );
            ?>
            <div class="bcc-trust-signal__bar"
                 role="progressbar"
                 aria-valuemin="0"
                 aria-valuemax="100"
                 aria-valuenow="<?php echo esc_attr((string) $confidence_percent); ?>"
                 aria-valuetext="<?php echo esc_attr($conf_valuetext); ?>">
                <div class="bcc-trust-signal__bar-fill bcc-tier-bg--<?php echo esc_attr($tier); ?>" style="width:<?php echo esc_attr((string) $confidence_percent); ?>%"></div>
            </div>
            <span class="bcc-trust-signal__value"><?php echo esc_html((string) $confidence_percent); ?>%</span>
        </li>
        <?php endif; ?>

        <?php if ($last_vote_at): ?>
        <li class="bcc-trust-signal bcc-trust-signal--activity">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span>Last vote <?php echo esc_html(human_time_diff($last_vote_at->getTimestamp(), current_time('timestamp'))); ?> ago</span>
        </li>
        <?php endif; ?>

        <?php if ($owner_registered): ?>
        <li class="bcc-trust-signal bcc-trust-signal--age">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Member since <?php echo esc_html(date('Y', strtotime($owner_registered))); ?></span>
        </li>
        <?php endif; ?>

        <?php if ($verified_identity_count > 0): ?>
        <li class="bcc-trust-signal bcc-trust-signal--verifications">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><?php echo intval($verified_identity_count); ?> verified identit<?php echo $verified_identity_count !== 1 ? 'ies' : 'y'; ?></span>
            <span class="bcc-trust-signal__icons">
                <?php foreach ($verified_identity_types as $vtype): ?>
                    <?php if ($vtype === 'github'): ?>
                        <span class="bcc-trust-signal__icon" title="GitHub">
                            <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                        </span>
                    <?php elseif ($vtype === 'x'): ?>
                        <span class="bcc-trust-signal__icon" title="X">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                        </span>
                    <?php elseif ($vtype === 'wallet_ethereum'): ?>
                        <span class="bcc-trust-signal__icon" title="Ethereum">&#x2B21;</span>
                    <?php elseif ($vtype === 'wallet_solana'): ?>
                        <span class="bcc-trust-signal__icon" title="Solana">&#x25CE;</span>
                    <?php elseif ($vtype === 'wallet_cosmos'): ?>
                        <span class="bcc-trust-signal__icon" title="Cosmos">&#x269B;</span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </span>
        </li>
        <?php endif; ?>
    </ul>
</div>
