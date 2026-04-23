<?php
/**
 * BCC Trust Engine - Block Patterns
 *
 * Pre-built page layouts that show admins how to assemble BCC blocks
 * into working pages (profiles, discovery, leaderboards, disputes).
 *
 * @package BCC_Trust_Engine
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'bcc_trust_register_block_patterns');

function bcc_trust_register_block_patterns(): void {

    register_block_pattern_category('bcc-trust', [
        'label' => 'Blue Collar Crypto',
    ]);

    // =========================================================================
    // Pattern 1: Trust Profile Page
    // =========================================================================
    //
    // For PeepSo profile pages. Vertical flow:
    //   Hero   — Builder Card (full-width, visual anchor)
    //   Middle — Two-column: Trust Signals + On-Chain Signals
    //   Detail — Trust Breakdown (full-width score components)
    //   Badges — Verification Badges (compact row)
    //   Owner  — Two-column: Wallet Verification + My Endorsements
    //   Admin  — Trust Dashboard (owner-only settings, at bottom)
    // =========================================================================

    register_block_pattern('bcc-trust/trust-profile-page', [
        'title'       => 'Trust Profile Page',
        'description' => 'Complete PeepSo profile layout: builder card hero, trust signals and on-chain data side by side, score breakdown, verification badges, wallet management, and owner dashboard.',
        'categories'  => ['bcc-trust'],
        'keywords'    => ['profile', 'trust', 'builder', 'peepso', 'wallet', 'signals', 'verification'],
        'blockTypes'  => [
            'bcc-trust/builder-card',
            'bcc-trust/trust-signals',
            'bcc-trust/on-chain-signals',
            'bcc-trust/trust-breakdown',
            'bcc-trust/verification-badges',
            'bcc-trust/wallet-verification',
            'bcc-trust/my-endorsements',
            'bcc-trust/trust-dashboard',
        ],
        'content'     =>
            // Hero: Builder Card at full width
            '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->' .
            '<div class="wp-block-group alignwide">' .

            '<!-- wp:bcc-trust/builder-card {"showScore":true,"showVerifications":true} /-->' .

            // Spacer between hero and content
            '<!-- wp:spacer {"height":"24px"} -->' .
            '<div style="height:24px" aria-hidden="true" class="wp-block-spacer"></div>' .
            '<!-- /wp:spacer -->' .

            // Two-column: Trust Signals + On-Chain Signals
            '<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"24px"}}}} -->' .
            '<div class="wp-block-columns">' .

            '<!-- wp:column {"width":"50%"} -->' .
            '<div class="wp-block-column" style="flex-basis:50%">' .
            '<!-- wp:bcc-trust/trust-signals /-->' .
            '</div>' .
            '<!-- /wp:column -->' .

            '<!-- wp:column {"width":"50%"} -->' .
            '<div class="wp-block-column" style="flex-basis:50%">' .
            '<!-- wp:bcc-trust/on-chain-signals /-->' .
            '</div>' .
            '<!-- /wp:column -->' .

            '</div>' .
            '<!-- /wp:columns -->' .

            // Spacer
            '<!-- wp:spacer {"height":"24px"} -->' .
            '<div style="height:24px" aria-hidden="true" class="wp-block-spacer"></div>' .
            '<!-- /wp:spacer -->' .

            // Full-width: Trust Breakdown
            '<!-- wp:bcc-trust/trust-breakdown /-->' .

            // Spacer
            '<!-- wp:spacer {"height":"16px"} -->' .
            '<div style="height:16px" aria-hidden="true" class="wp-block-spacer"></div>' .
            '<!-- /wp:spacer -->' .

            // Compact verification badges
            '<!-- wp:bcc-trust/verification-badges {"compact":true} /-->' .

            // Spacer
            '<!-- wp:spacer {"height":"32px"} -->' .
            '<div style="height:32px" aria-hidden="true" class="wp-block-spacer"></div>' .
            '<!-- /wp:spacer -->' .

            // Owner section: Wallet Verification + My Endorsements
            '<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"24px"}}}} -->' .
            '<div class="wp-block-columns">' .

            '<!-- wp:column {"width":"60%"} -->' .
            '<div class="wp-block-column" style="flex-basis:60%">' .
            '<!-- wp:bcc-trust/wallet-verification {"showEthereum":true,"showSolana":true,"showCosmos":true} /-->' .
            '</div>' .
            '<!-- /wp:column -->' .

            '<!-- wp:column {"width":"40%"} -->' .
            '<div class="wp-block-column" style="flex-basis:40%">' .
            '<!-- wp:bcc-trust/my-endorsements {"limit":5,"showStats":true} /-->' .
            '</div>' .
            '<!-- /wp:column -->' .

            '</div>' .
            '<!-- /wp:columns -->' .

            // Spacer before dashboard
            '<!-- wp:spacer {"height":"32px"} -->' .
            '<div style="height:32px" aria-hidden="true" class="wp-block-spacer"></div>' .
            '<!-- /wp:spacer -->' .

            // Owner dashboard at bottom
            '<!-- wp:bcc-trust/trust-dashboard {"showHistory":true,"showChecklist":true,"historyLimit":10} /-->' .

            '</div>' .
            '<!-- /wp:group -->',
    ]);

    // =========================================================================
    // Pattern 2: Discovery Page
    // =========================================================================
    //
    // Standalone page for browsing all community projects.
    //   Top    — Search bar (full-width, prominent)
    //   Below  — Discovery Hub grid (sorted by trust, filterable)
    // =========================================================================

    register_block_pattern('bcc-trust/discovery-page', [
        'title'       => 'Discovery Page',
        'description' => 'Main browsing page with prominent search bar and filterable project grid sorted by trust score. Works standalone — no PeepSo context needed.',
        'categories'  => ['bcc-trust'],
        'keywords'    => ['discovery', 'search', 'projects', 'browse', 'explore', 'directory'],
        'blockTypes'  => [
            'bcc-search/search-bar',
            'bcc-trust/project-discovery-hub',
        ],
        'content'     =>
            '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->' .
            '<div class="wp-block-group alignwide">' .

            // Search bar at top — prominent with type filter
            '<!-- wp:group {"style":{"spacing":{"padding":{"top":"32px","bottom":"24px"}}}} -->' .
            '<div class="wp-block-group" style="padding-top:32px;padding-bottom:24px">' .
            '<!-- wp:bcc-search/search-bar {"showType":true} /-->' .
            '</div>' .
            '<!-- /wp:group -->' .

            // Discovery hub — trust-sorted, all features on
            '<!-- wp:bcc-trust/project-discovery-hub {"sortBy":"trust","limit":12,"showSearch":false,"showFilters":true,"showTabs":true,"showFollowers":true,"showEndorsements":true,"showVerifiedBadge":true,"showCoverBackground":true} /-->' .

            '</div>' .
            '<!-- /wp:group -->',
    ]);

    // =========================================================================
    // Pattern 3: Leaderboard Page
    // =========================================================================
    //
    // Community rankings page. Three sections stacked:
    //   Section 1 — Validator Leaderboard (wide, Cosmos default)
    //   Section 2 — Two-column: NFT + Endorsement leaderboards
    // =========================================================================

    register_block_pattern('bcc-trust/leaderboard-page', [
        'title'       => 'Leaderboard Page',
        'description' => 'Community rankings: validator leaderboard up top, NFT collections and endorsement leaders side by side below.',
        'categories'  => ['bcc-trust'],
        'keywords'    => ['leaderboard', 'rankings', 'validators', 'nft', 'endorsements', 'community', 'top'],
        'blockTypes'  => [
            'bcc-trust/validator-leaderboard',
            'bcc-trust/nft-leaderboard',
            'bcc-trust/endorsement-leaderboard',
        ],
        'content'     =>
            '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->' .
            '<div class="wp-block-group alignwide">' .

            // Section heading
            '<!-- wp:heading {"level":2} -->' .
            '<h2 class="wp-block-heading">Community Leaders</h2>' .
            '<!-- /wp:heading -->' .

            // Spacer
            '<!-- wp:spacer {"height":"16px"} -->' .
            '<div style="height:16px" aria-hidden="true" class="wp-block-spacer"></div>' .
            '<!-- /wp:spacer -->' .

            // Validator Leaderboard — full-width, featured
            '<!-- wp:group {"style":{"spacing":{"padding":{"bottom":"32px"}}}} -->' .
            '<div class="wp-block-group" style="padding-bottom:32px">' .
            '<!-- wp:heading {"level":3} -->' .
            '<h3 class="wp-block-heading">Top Validators</h3>' .
            '<!-- /wp:heading -->' .
            '<!-- wp:bcc-trust/validator-leaderboard {"perPage":10,"showClaim":true,"defaultChain":"cosmos"} /-->' .
            '</div>' .
            '<!-- /wp:group -->' .

            // Two-column: NFT + Endorsement
            '<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"32px"}}}} -->' .
            '<div class="wp-block-columns">' .

            '<!-- wp:column -->' .
            '<div class="wp-block-column">' .
            '<!-- wp:heading {"level":3} -->' .
            '<h3 class="wp-block-heading">Top NFT Collections</h3>' .
            '<!-- /wp:heading -->' .
            '<!-- wp:bcc-trust/nft-leaderboard {"perPage":10,"showClaim":true,"defaultChain":"evm"} /-->' .
            '</div>' .
            '<!-- /wp:column -->' .

            '<!-- wp:column -->' .
            '<div class="wp-block-column">' .
            '<!-- wp:heading {"level":3} -->' .
            '<h3 class="wp-block-heading">Most Endorsed</h3>' .
            '<!-- /wp:heading -->' .
            '<!-- wp:bcc-trust/endorsement-leaderboard {"limit":10,"showEndorserCount":true} /-->' .
            '</div>' .
            '<!-- /wp:column -->' .

            '</div>' .
            '<!-- /wp:columns -->' .

            '</div>' .
            '<!-- /wp:group -->',
    ]);

    // =========================================================================
    // Pattern 4: Dispute Management
    // =========================================================================
    //
    // Dispute workflow page:
    //   Top    — Heading + explanation paragraph
    //   Form   — Dispute submission form (page owners)
    //   Queue  — Panelist review queue (eligible members)
    // =========================================================================

    register_block_pattern('bcc-trust/dispute-management', [
        'title'       => 'Dispute Management',
        'description' => 'Dispute workflow page: submission form for page owners and review queue for panelists. Place on a dedicated page.',
        'categories'  => ['bcc-trust'],
        'keywords'    => ['dispute', 'panel', 'vote', 'challenge', 'review', 'queue', 'moderation'],
        'blockTypes'  => [
            'bcc-disputes/dispute-form',
            'bcc-disputes/dispute-queue',
        ],
        'content'     =>
            '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->' .
            '<div class="wp-block-group alignwide">' .

            // Page heading
            '<!-- wp:heading {"level":2} -->' .
            '<h2 class="wp-block-heading">Dispute Center</h2>' .
            '<!-- /wp:heading -->' .

            '<!-- wp:paragraph -->' .
            '<p>Challenge unfair votes on your page, or review disputes assigned to you as a panelist.</p>' .
            '<!-- /wp:paragraph -->' .

            // Spacer
            '<!-- wp:spacer {"height":"24px"} -->' .
            '<div style="height:24px" aria-hidden="true" class="wp-block-spacer"></div>' .
            '<!-- /wp:spacer -->' .

            // Dispute form — page owners submit here
            '<!-- wp:bcc-disputes/dispute-form /-->' .

            // Spacer
            '<!-- wp:spacer {"height":"32px"} -->' .
            '<div style="height:32px" aria-hidden="true" class="wp-block-spacer"></div>' .
            '<!-- /wp:spacer -->' .

            // Separator between sections
            '<!-- wp:separator {"className":"is-style-wide"} -->' .
            '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>' .
            '<!-- /wp:separator -->' .

            // Spacer
            '<!-- wp:spacer {"height":"24px"} -->' .
            '<div style="height:24px" aria-hidden="true" class="wp-block-spacer"></div>' .
            '<!-- /wp:spacer -->' .

            // Panelist queue heading
            '<!-- wp:heading {"level":3} -->' .
            '<h3 class="wp-block-heading">Panelist Review Queue</h3>' .
            '<!-- /wp:heading -->' .

            // Dispute queue — panelists review here
            '<!-- wp:bcc-disputes/dispute-queue /-->' .

            '</div>' .
            '<!-- /wp:group -->',
    ]);

    // =========================================================================
    // Pattern 5: Landing Page
    // =========================================================================

    register_block_pattern('bcc-trust/landing-page', [
        'title'       => 'BCC Landing Page',
        'description' => 'Full-width landing page introducing Blue Collar Crypto. Hero with trust ring, how-it-works, features, tier breakdown, and signup CTA. Uses the [bcc_landing_page] shortcode.',
        'categories'  => ['bcc-trust'],
        'keywords'    => ['landing', 'home', 'hero', 'introduction', 'signup', 'about', 'cta'],
        'content'     =>
            '<!-- wp:shortcode -->' .
            '[bcc_landing_page]' .
            '<!-- /wp:shortcode -->',
    ]);
}
