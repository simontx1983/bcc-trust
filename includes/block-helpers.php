<?php
/**
 * Shared helpers for BCC block render templates.
 *
 * @package BCC_Trust
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'bcc_trust_block_placeholder' ) ) {
    /**
     * Standard editor-time placeholder for BCC blocks.
     *
     * Renders a dashed outline with the block's title and a context
     * message. Use in the editor (REST_REQUEST) when the block can't
     * render real content — never emit on the frontend.
     *
     * The inline stylesheet is printed once per request.
     *
     * @param string $title   Block title shown bold.
     * @param string $message Short context sentence shown underneath.
     * @return string HTML (pre-escaped).
     */
    function bcc_trust_block_placeholder( string $title, string $message ): string {
        static $style_printed = false;

        $css = '';
        if ( ! $style_printed ) {
            $style_printed = true;
            $css = '<style>'
                . '.bcc-block-placeholder{padding:20px;background:#f0f0f0;border:1px dashed #ccc;color:#666;text-align:center;border-radius:4px}'
                . '.bcc-block-placeholder strong{display:block;margin-bottom:4px}'
                . '</style>';
        }

        return $css . sprintf(
            '<div class="bcc-block-placeholder"><strong>%s</strong><small>%s</small></div>',
            esc_html( $title ),
            esc_html( $message )
        );
    }
}

if ( ! function_exists( 'bcc_trust_require_peepso_page' ) ) {
    /**
     * Confirm a block is rendering in a PeepSo profile page context.
     *
     * Blocks that target profile pages (Builder Card, Wallet Verification,
     * Trust Signals, etc.) silently return nothing when placed on a
     * non-profile post type. This helper surfaces that mistake to editors
     * via a placeholder and stays silent on the frontend.
     *
     * A manually set `pageId` attribute always overrides the check —
     * editors who deliberately pin the block to a specific PeepSo page
     * from any post type will still render.
     *
     * @param int    $page_id     Resolved page ID.
     * @param array  $attributes  Block attributes (checked for manual pageId override).
     * @param string $block_title Title to show in the placeholder.
     * @return bool true → caller may proceed; false → caller should return.
     */
    function bcc_trust_require_peepso_page( int $page_id, array $attributes, string $block_title ): bool {
        if ( ! empty( $attributes['pageId'] ) ) {
            return true;
        }
        if ( $page_id && get_post_type( $page_id ) === 'peepso-page' ) {
            return true;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            echo bcc_trust_block_placeholder(
                $block_title,
                'This block only renders on PeepSo profile pages. Set a Page ID in block settings to preview here.'
            );
        }
        return false;
    }
}
