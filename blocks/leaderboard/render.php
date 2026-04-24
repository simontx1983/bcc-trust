<?php
/**
 * Trust Leaderboard — unified block render dispatcher.
 *
 * Delegates to a type-specific view based on the `type` attribute.
 * Editors pick the type at insert time via the block variation picker
 * (NFT / Validator / Endorsement). Each instance renders one type.
 *
 * Assets are enqueued conditionally per type so only the matching
 * script + style load on the page.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$type = sanitize_key( $attributes['type'] ?? 'nft' );

switch ( $type ) {
    case 'validator':
        wp_enqueue_style( 'bcc-validator-leaderboard-style' );
        wp_enqueue_script( 'bcc-validator-leaderboard' );
        include __DIR__ . '/views/validator.php';
        return;

    case 'endorsement':
        wp_enqueue_style( 'bcc-endorsement-leaderboard-style' );
        wp_enqueue_script( 'bcc-endorsement-leaderboard' );
        include __DIR__ . '/views/endorsement.php';
        return;

    case 'nft':
    default:
        wp_enqueue_style( 'bcc-entity-blocks-style' );
        wp_enqueue_script( 'bcc-nft-leaderboard' );
        include __DIR__ . '/views/nft.php';
        return;
}
