<?php
/**
 * BCC Trust Engine – Gutenberg Block Registration
 *
 * Registers all dynamic blocks and the shared editor script.
 *
 * @package BCC_Trust_Engine
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'bcc_trust_register_blocks');

function bcc_trust_register_blocks() {
    // Register editor script shared by all blocks
    wp_register_script(
        'bcc-trust-blocks-editor',
        BCC_TRUST_URL . 'assets/js/blocks-editor.js',
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render'],
        BCC_TRUST_VERSION,
        true
    );

    // Register Trust Header viewScript + style so they are available
    // when register_block_type reads block.json handles.
    $js_dir  = BCC_TRUST_URL . 'assets/js/';
    $css_dir = BCC_TRUST_URL . 'assets/css/';
    $blocks_dir = BCC_TRUST_URL . 'blocks/';

    wp_register_script(
        'bcc-trust-header',
        $js_dir . 'trust-header.js',
        ['bcc-page-store', 'bcc-trust-frontend', 'wp-api-fetch'],
        BCC_TRUST_VERSION,
        true
    );
    wp_register_style(
        'bcc-trust-header',
        $css_dir . 'trust-header.css',
        ['bcc-trust-frontend'],
        BCC_TRUST_VERSION
    );

    // Grade thresholds — PHP (Formatting::scoreToGrade) is the single source of truth.
    // JS reads these instead of hardcoding the ladder.
    wp_localize_script('bcc-trust-header', 'bccGradeThresholds', [
        ['min' => 95, 'grade' => 'A+'],
        ['min' => 90, 'grade' => 'A'],
        ['min' => 85, 'grade' => 'A-'],
        ['min' => 80, 'grade' => 'B+'],
        ['min' => 75, 'grade' => 'B'],
        ['min' => 70, 'grade' => 'B-'],
        ['min' => 65, 'grade' => 'C+'],
        ['min' => 60, 'grade' => 'C'],
        ['min' => 55, 'grade' => 'C-'],
        ['min' => 50, 'grade' => 'D+'],
        ['min' => 45, 'grade' => 'D'],
        ['min' => 40, 'grade' => 'D-'],
    ]);

    // Shared stores (dedup, caching, subscribe pattern)
    wp_register_script(
        'bcc-discovery-store',
        $blocks_dir . 'shared/discovery-store.js',
        [],
        BCC_TRUST_VERSION,
        true
    );
    wp_localize_script('bcc-discovery-store', 'bccDiscoveryData', [
        'endpoint' => esc_url_raw(rest_url('bcc/v1/discover')),
        'nonce'    => wp_create_nonce('wp_rest'),
    ]);
    wp_register_script(
        'bcc-collection-store',
        $blocks_dir . 'shared/collection-store.js',
        [],
        BCC_TRUST_VERSION,
        true
    );
    wp_localize_script('bcc-collection-store', 'bccCollectionData', [
        'restUrl' => esc_url_raw(rest_url('bcc/v1/nft/collections')),
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);

    // Discovery Hub block assets
    wp_register_script(
        'bcc-discovery-hub',
        $blocks_dir . 'project-discovery-hub/view.js',
        ['bcc-discovery-store'],
        BCC_TRUST_VERSION,
        true
    );
    wp_register_style(
        'bcc-discovery-hub-style',
        $blocks_dir . 'project-discovery-hub/style.css',
        [],
        BCC_TRUST_VERSION
    );
    wp_register_style(
        'bcc-discovery-hub-editor',
        $blocks_dir . 'project-discovery-hub/editor.css',
        ['bcc-discovery-hub-style'],
        BCC_TRUST_VERSION
    );

    // Entity blocks (Validator Stats + Collection Showcase) shared assets
    wp_register_script(
        'bcc-entity-claims',
        $blocks_dir . 'shared/entity-claims.js',
        [],
        BCC_TRUST_VERSION,
        true
    );
    wp_localize_script('bcc-entity-claims', 'bccEntityClaim', [
        'restUrl' => esc_url_raw(rest_url('bcc/v1/claim')),
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);
    wp_register_style(
        'bcc-entity-blocks-style',
        $blocks_dir . 'shared/entity-blocks.css',
        [],
        BCC_TRUST_VERSION
    );

    // NFT Leaderboard block assets
    wp_register_script(
        'bcc-nft-leaderboard',
        $blocks_dir . 'nft-leaderboard/view.js',
        ['bcc-entity-claims', 'bcc-collection-store'],
        BCC_TRUST_VERSION,
        true
    );
    wp_localize_script('bcc-nft-leaderboard', 'bccNftLb', [
        'restUrl' => esc_url_raw(rest_url('bcc/v1/nft/collections')),
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);

    // Validator Leaderboard block assets
    wp_register_script(
        'bcc-validator-leaderboard',
        $blocks_dir . 'validator-leaderboard/view.js',
        ['bcc-entity-claims'],
        BCC_TRUST_VERSION,
        true
    );
    wp_localize_script('bcc-validator-leaderboard', 'bccVLB', [
        'restUrl' => esc_url_raw(rest_url('bcc/v1/validators/top')),
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);
    wp_register_style(
        'bcc-validator-leaderboard-style',
        $blocks_dir . 'validator-leaderboard/style.css',
        [],
        BCC_TRUST_VERSION
    );

    // Verification Badges block style
    wp_register_style(
        'bcc-verification-badges-style',
        $blocks_dir . 'verification-badges/style.css',
        [],
        BCC_TRUST_VERSION
    );

    // Endorsement Leaderboard block assets
    wp_register_script(
        'bcc-endorsement-leaderboard',
        $blocks_dir . 'endorsement-leaderboard/view.js',
        [],
        BCC_TRUST_VERSION,
        true
    );
    wp_localize_script('bcc-endorsement-leaderboard', 'bccELB', [
        'restUrl' => esc_url_raw(rest_url('bcc/v1/endorsements/top')),
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);
    wp_register_style(
        'bcc-endorsement-leaderboard-style',
        $blocks_dir . 'endorsement-leaderboard/style.css',
        [],
        BCC_TRUST_VERSION
    );

    // My Endorsements block assets
    wp_register_script(
        'bcc-my-endorsements',
        $blocks_dir . 'my-endorsements/view.js',
        [],
        BCC_TRUST_VERSION,
        true
    );
    wp_localize_script('bcc-my-endorsements', 'bccMyEndorsements', [
        'listUrl'   => esc_url_raw(rest_url('bcc/v1/endorsements/mine')),
        'revokeUrl' => esc_url_raw(rest_url('bcc-trust/v1/revoke-endorsement')),
        'nonce'     => wp_create_nonce('wp_rest'),
    ]);

    // Trust Breakdown block assets
    wp_register_script(
        'bcc-trust-breakdown',
        $blocks_dir . 'trust-breakdown/view.js',
        ['bcc-page-store'],
        BCC_TRUST_VERSION,
        true
    );
    wp_register_style(
        'bcc-trust-breakdown-style',
        $blocks_dir . 'trust-breakdown/style.css',
        ['bcc-trust-frontend'],
        BCC_TRUST_VERSION
    );

    // Register each block from its block.json
    $blocks = [
        'builder-card',
        'wallet-verification',
        'trust-signals',
        'on-chain-signals',
        'project-discovery-hub',
        'validator-stats',
        'collection-showcase',
        'nft-leaderboard',
        'validator-leaderboard',
        'verification-badges',
        'endorsement-leaderboard',
        'trust-breakdown',
        'trust-dashboard',
        'my-endorsements',
    ];

    foreach ($blocks as $block) {
        register_block_type(BCC_TRUST_PATH . 'blocks/' . $block);
    }

    // Block patterns — pre-built page layouts for admins
    require_once __DIR__ . '/block-patterns.php';
}

/**
 * Register a custom block category for BCC Trust blocks.
 */
add_filter('block_categories_all', 'bcc_trust_block_category', 10, 2);

function bcc_trust_block_category($categories, $context) {
    return array_merge(
        [
            [
                'slug'  => 'bcc-trust',
                'title' => 'BCC Trust Engine',
                'icon'  => 'shield',
            ],
        ],
        $categories
    );
}
