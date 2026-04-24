<?php
/**
 * Trust Score Breakdown Block - server-side render.
 *
 * Displays a user-facing breakdown of why a trust score is what it is.
 * Consumes the same PageDataLoader payload as every other block.
 *
 * CRITICAL: Never render raw score_breakdown floats (positive/negative/net).
 *           Only render integer vote counts and public verification status.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$page_id = (int) ( $attributes['pageId'] ?? 0 );
if ( ! $page_id ) {
    $page_id = get_the_ID();
}
if ( ! $page_id ) {
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        echo bcc_trust_block_placeholder(
            'Trust Score Breakdown',
            'Requires a page ID. Place on a PeepSo profile page or set a Page ID in block settings.'
        );
        return;
    }
    return;
}

if ( ! bcc_trust_require_peepso_page( $page_id, $attributes, 'Trust Score Breakdown' ) ) {
    return;
}

if ( ! class_exists( '\\BCC\\Trust\\Core\\Services\\PageDataLoader' ) ) {
    return;
}

$data = \BCC\Trust\Core\Services\PageDataLoader::get( $page_id );
if ( ! $data ) {
    return;
}

// --- Extract sections ---
$trust      = $data['trust'] ?? [];
$breakdown  = $trust['score_breakdown'] ?? [];
$verif      = $data['verification'] ?? [];
$onchain    = $data['onchain'] ?? [];
$reputation = $data['reputation'] ?? [];
$viewer     = $data['viewer'] ?? [];

$raw_score        = $trust['score'] ?? null;
$score            = $raw_score !== null ? (int) $raw_score : null;
$tier             = sanitize_key( $trust['tier'] ?? 'unavailable' );
$confidence       = (float) ( $trust['confidence'] ?? 0 );
$confidence_pct   = (int) round( $confidence * 100 );
$votes_up         = (int) ( $trust['votes_up'] ?? 0 );
$votes_down       = (int) ( $trust['votes_down'] ?? 0 );
$unique_voters    = (int) ( $trust['unique_voters'] ?? 0 );
$endorsements     = (int) ( $trust['endorsements'] ?? 0 );
$community_weight = (float) ( $breakdown['community_weight'] ?? 0.5 );
$identity_weight  = (float) ( $breakdown['identity_weight'] ?? 0.5 );

$is_owner = (bool) ( $viewer['is_owner'] ?? false );

// Verification counts
$verif_providers = [ 'email', 'wallet', 'github', 'x' ];
$verif_total     = count( $verif_providers );
$verif_count     = 0;
foreach ( $verif_providers as $p ) {
    if ( ! empty( $verif[ $p ] ) ) {
        $verif_count++;
    }
}

// On-chain summary
$wallet_age    = $onchain['wallet_age'] ?? null;
$transactions  = $onchain['transactions'] ?? null;
$onchain_boost = (float) ( $onchain['total_boost'] ?? 0 );
$nft_count     = is_array( $onchain['nft_collections'] ?? null ) ? count( $onchain['nft_collections'] ) : 0;

// Confidence level for color theming
if ( $confidence < 0.30 ) {
    $conf_level = 'low';
} elseif ( $confidence < 0.70 ) {
    $conf_level = 'mid';
} else {
    $conf_level = 'high';
}

// Is this a completely empty project? (no votes, no verifications, no onchain)
$is_empty = ( $unique_voters === 0 && $endorsements === 0 && $verif_count === 0 && $onchain_boost <= 0 );

$wrapper_attributes = get_block_wrapper_attributes( [
    'class'          => 'bcc-trust-breakdown bcc-trust-breakdown--conf-' . $conf_level,
    'data-page-id'   => (string) $page_id,
    'data-is-owner'  => $is_owner ? '1' : '0',
] );
?>
<div <?php echo $wrapper_attributes; ?>>
    <h4 class="bcc-tb__title"><?php esc_html_e( 'Trust Score Breakdown', 'bcc-trust' ); ?></h4>

    <?php if ( $is_empty ) : ?>
        <!-- Empty state -->
        <div class="bcc-tb__empty">
            <div class="bcc-tb__score-bar">
                <div class="bcc-tb__score-fill" style="width:0%" data-field="score-fill"></div>
            </div>
            <p class="bcc-tb__score-label">
                <span class="bcc-tb__score-value" data-field="score">&mdash;</span> / 100
            </p>
            <p class="bcc-tb__meta"><?php esc_html_e( 'No score yet', 'bcc-trust' ); ?></p>

            <?php if ( $is_owner ) : ?>
                <p class="bcc-tb__hint"><?php esc_html_e( 'Connect your accounts to build trust.', 'bcc-trust' ); ?></p>
                <p class="bcc-tb__hint"><?php esc_html_e( 'Share your project to receive feedback.', 'bcc-trust' ); ?></p>
            <?php else : ?>
                <p class="bcc-tb__hint"><?php esc_html_e( 'Be the first to support this project.', 'bcc-trust' ); ?></p>
            <?php endif; ?>
        </div>

    <?php else : ?>
        <!-- Score bar -->
        <?php
        $score_valuetext = $score !== null
            ? sprintf(
                /* translators: 1: numeric score out of 100, 2: tier name */
                __( '%1$d out of 100, %2$s tier', 'bcc-trust' ),
                (int) $score,
                ucfirst( $tier )
            )
            : __( 'No score yet', 'bcc-trust' );
        ?>
        <div class="bcc-tb__score-bar"
             role="progressbar"
             aria-valuemin="0"
             aria-valuemax="100"
             aria-valuenow="<?php echo esc_attr( (string) ( $score ?? 0 ) ); ?>"
             aria-valuetext="<?php echo esc_attr( $score_valuetext ); ?>">
            <div class="bcc-tb__score-fill bcc-tier-bg--<?php echo esc_attr( $tier ); ?>" style="width:<?php echo esc_attr( (string) ( $score ?? 0 ) ); ?>%" data-field="score-fill"></div>
        </div>
        <p class="bcc-tb__score-label">
            <span class="bcc-tb__score-value" data-field="score"><?php echo $score !== null ? esc_html( (string) $score ) : '—'; ?></span><?php echo $score !== null ? ' / 100' : ''; ?>
        </p>
        <p class="bcc-tb__meta bcc-tb__meta--conf-<?php echo esc_attr( $conf_level ); ?>">
            <span class="bcc-tier-text--<?php echo esc_attr( $tier ); ?>" data-field="tier"><?php echo esc_html( ucfirst( $tier ) ); ?></span>
            &middot;
            <span data-field="confidence"><?php echo esc_html( (string) $confidence_pct ); ?></span>% <?php esc_html_e( 'confidence', 'bcc-trust' ); ?>
        </p>

        <!-- Weight bar -->
        <div class="bcc-tb__weight-bar">
            <div class="bcc-tb__weight-community" style="width:<?php echo esc_attr( (string) ( $community_weight * 100 ) ); ?>%" data-field="community-bar">
                <span class="bcc-tb__weight-label"><?php esc_html_e( 'Community', 'bcc-trust' ); ?> <?php echo esc_html( (string) round( $community_weight * 100 ) ); ?>%</span>
            </div>
            <div class="bcc-tb__weight-identity" style="width:<?php echo esc_attr( (string) ( $identity_weight * 100 ) ); ?>%" data-field="identity-bar">
                <span class="bcc-tb__weight-label"><?php esc_html_e( 'Identity', 'bcc-trust' ); ?> <?php echo esc_html( (string) round( $identity_weight * 100 ) ); ?>%</span>
            </div>
        </div>

        <!-- Community Support -->
        <div class="bcc-tb__section">
            <div class="bcc-tb__section-header">
                <svg class="bcc-tb__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span class="bcc-tb__section-title"><?php esc_html_e( 'Community Support', 'bcc-trust' ); ?></span>
                <span class="bcc-tb__section-value">
                    +<span data-field="votes-up"><?php echo esc_html( (string) $votes_up ); ?></span>
                    / -<span data-field="votes-down"><?php echo esc_html( (string) $votes_down ); ?></span>
                    <?php esc_html_e( 'votes', 'bcc-trust' ); ?>
                </span>
            </div>
            <p class="bcc-tb__section-detail">
                <span data-field="endorsements"><?php echo esc_html( (string) $endorsements ); ?></span> <?php esc_html_e( 'endorsements', 'bcc-trust' ); ?>
            </p>
            <?php if ( $unique_voters === 0 && $is_owner ) : ?>
                <p class="bcc-tb__hint"><?php esc_html_e( 'Share your project to receive community feedback.', 'bcc-trust' ); ?></p>
            <?php endif; ?>
        </div>

        <!-- Verified Identities -->
        <div class="bcc-tb__section">
            <div class="bcc-tb__section-header">
                <svg class="bcc-tb__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <span class="bcc-tb__section-title"><?php esc_html_e( 'Verified Identities', 'bcc-trust' ); ?></span>
                <span class="bcc-tb__section-value">
                    <span data-field="verif-count"><?php echo esc_html( (string) $verif_count ); ?></span>
                    <?php esc_html_e( 'of', 'bcc-trust' ); ?>
                    <?php echo esc_html( (string) $verif_total ); ?>
                </span>
            </div>
            <div class="bcc-tb__verif-list">
                <?php foreach ( $verif_providers as $provider ) :
                    $is_verified = ! empty( $verif[ $provider ] );
                    $label       = ucfirst( $provider === 'x' ? 'X' : $provider );
                ?>
                    <span class="bcc-tb__verif-item <?php echo $is_verified ? 'bcc-tb__verif-item--yes' : 'bcc-tb__verif-item--no'; ?>">
                        <?php echo $is_verified ? '&#10003;' : '&#10007;'; ?>
                        <?php echo esc_html( $label ); ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php
            // Owner hint: show first missing verification
            if ( $is_owner && $verif_count < $verif_total ) :
                $missing = '';
                foreach ( $verif_providers as $p ) {
                    if ( empty( $verif[ $p ] ) ) {
                        $missing = $p === 'x' ? 'X' : ucfirst( $p );
                        break;
                    }
                }
                if ( $missing ) : ?>
                    <p class="bcc-tb__hint" data-field="verif-hint">
                        <?php
                        printf(
                            /* translators: %s: verification provider name */
                            esc_html__( 'Connect %s to strengthen your score.', 'bcc-trust' ),
                            esc_html( $missing )
                        );
                        ?>
                    </p>
                <?php endif;
            endif;
            ?>
        </div>

        <!-- On-Chain Activity -->
        <div class="bcc-tb__section">
            <div class="bcc-tb__section-header">
                <svg class="bcc-tb__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                <span class="bcc-tb__section-title"><?php esc_html_e( 'On-Chain Activity', 'bcc-trust' ); ?></span>
                <span class="bcc-tb__section-value">
                    <?php if ( $onchain_boost > 0 ) : ?>
                        +<?php echo esc_html( (string) round( $onchain_boost, 1 ) ); ?> <?php esc_html_e( 'boost', 'bcc-trust' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'No data yet', 'bcc-trust' ); ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ( $wallet_age !== null || $transactions !== null || $nft_count > 0 ) : ?>
                <p class="bcc-tb__section-detail">
                    <?php
                    $parts = [];
                    if ( $wallet_age !== null ) {
                        $parts[] = round( $wallet_age, 1 ) . 'yr wallet';
                    }
                    if ( $transactions !== null ) {
                        $parts[] = number_format( $transactions ) . ' txns';
                    }
                    if ( $nft_count > 0 ) {
                        $parts[] = $nft_count . ' NFT badge' . ( $nft_count !== 1 ? 's' : '' );
                    }
                    echo esc_html( implode( ' &middot; ', $parts ) );
                    ?>
                </p>
            <?php endif; ?>
            <?php if ( $onchain_boost <= 0 && $is_owner ) : ?>
                <p class="bcc-tb__hint"><?php esc_html_e( 'Connect a wallet to unlock on-chain trust.', 'bcc-trust' ); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php
    // ── Flag section (signal only — no score impact) ────────────────
    $flags       = $data['flags'] ?? [];
    $flag_count  = (int) ( $flags['count'] ?? 0 );
    $has_flagged = (bool) ( $viewer['has_flagged'] ?? false );
    ?>
    <div class="bcc-tb__section bcc-tb__flag-section" data-field="flag-section">
        <?php if ( $flag_count > 0 ) : ?>
            <p class="bcc-tb__flag-count" data-field="flag-count" style="color:#b45309;font-size:0.85em;">
                <?php echo esc_html( $flag_count . ( $flag_count === 1 ? ' user flagged this page' : ' users flagged this page' ) ); ?>
            </p>
        <?php else : ?>
            <span data-field="flag-count" style="display:none;color:#b45309;font-size:0.85em;"></span>
        <?php endif; ?>

        <?php if ( $is_owner ) : ?>
            <?php if ( $flag_count > 0 ) : ?>
                <p class="bcc-tb__flag-owner-msg" data-field="flag-msg" style="color:#888;font-size:0.8em;">
                    <?php echo esc_html( $flag_count . ' community ' . ( $flag_count === 1 ? 'flag' : 'flags' ) ); ?>
                </p>
            <?php else : ?>
                <span data-field="flag-msg" style="display:none;"></span>
            <?php endif; ?>
            <span data-field="flag-btn" style="display:none;"></span>
        <?php elseif ( is_user_logged_in() ) : ?>
            <button class="bcc-tb__flag-btn" data-field="flag-btn" data-action="<?php echo $has_flagged ? 'unflag' : 'flag'; ?>" style="background:none;border:1px solid #d1d5db;border-radius:4px;padding:4px 12px;cursor:pointer;font-size:0.8em;color:#6b7280;">
                <?php echo $has_flagged ? esc_html__( 'Remove Flag', 'bcc-trust' ) : esc_html__( 'Flag Page', 'bcc-trust' ); ?>
            </button>
            <span data-field="flag-msg" style="display:none;"></span>
        <?php endif; ?>
    </div>
</div>
