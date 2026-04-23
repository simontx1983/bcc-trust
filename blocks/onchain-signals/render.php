<?php
/**
 * Server-side render for the bcc-onchain/signals block.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block inner content (unused).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use BCC\Trust\Onchain\Repositories\SignalRepository;

$page_id = ! empty( $attributes['pageId'] ) ? (int) $attributes['pageId'] : 0;

if ( ! $page_id ) {
    $page_id = get_the_ID() ?: 0;
}

// In the block editor (REST request), show a placeholder when no page ID is available.
if ( ! $page_id && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
    printf(
        '<div %s><p style="padding:1em;background:#f0f0f0;border:1px solid #ccc;border-radius:4px;color:#555;text-align:center;">'
        . '%s</p></div>',
        get_block_wrapper_attributes(),
        esc_html__( 'On-Chain Signals — requires a page ID. Place on a PeepSo profile page or set a Page ID in block settings.', 'bcc-onchain' )
    );
    return;
}

// On the frontend with no page ID, render nothing.
if ( ! $page_id ) {
    return;
}

$signals = SignalRepository::get_for_page( $page_id );

ob_start();
include BCC_TRUST_PATH . 'templates/signals-widget.php';
$output = ob_get_clean();

printf( '<div %s>%s</div>', get_block_wrapper_attributes(), $output );
