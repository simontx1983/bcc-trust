<?php
/**
 * Canonical Page Data Schema
 *
 * Defines the exact shape of the payload returned by PageDataLoader::get().
 * Every block, REST endpoint, and JS consumer reads from this contract.
 *
 * ┌──────────────────────────────────────────────────────────────┐
 * │  PageDataLoader::get($pageId)                               │
 * │  Returns: array matching self::SCHEMA                       │
 * │                                                             │
 * │  Consumers:                                                 │
 * │    PHP  → render.php in every block                         │
 * │    REST → GET /bcc/v1/page/{id}                             │
 * │    JS   → bccPageStore.get(id) → trust-header.js,           │
 * │           page-header.js, trust-frontend.js                 │
 * └──────────────────────────────────────────────────────────────┘
 *
 * Rules:
 *   1. Every field listed here MUST be present in the payload (use defaults).
 *   2. Adding a field = add it here first, then implement in the aggregator.
 *   3. Removing a field = grep all consumers, remove usages, then remove here.
 *   4. Types are enforced by applyDefaults() — no nulls leak to consumers
 *      except where explicitly typed as nullable.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PageDataSchema {

    /**
     * Default payload structure with types and default values.
     *
     * Organized by section. Each section is its own associative array.
     * Nullable fields use null as the default — consumers must handle this.
     */
    public const SCHEMA = [

        // ─── Project identity ───────────────────────────────────
        // Source: WP_Post + PeepSoPagePhoto
        'project' => [
            'id'     => 0,         // int    Post ID
            'name'   => '',        // string Post title (sanitized)
            'slug'   => '',        // string URL-safe slug
            'avatar' => '',        // string Full URL to page avatar
            'cover'  => '',        // string Full URL to cover image
            'url'    => '',        // string Permalink
        ],

        // ─── Builder (page owner) ───────────────────────────────
        // Source: WP_User + PeepSoUser
        // Null when owner cannot be resolved.
        'builder' => [
            'id'          => 0,    // int    WP user ID (stripped from public REST responses)
            'name'        => '',   // string Display name
            'username'    => '',   // string user_nicename (URL slug, never login)
            'avatar'      => '',   // string Gravatar / PeepSo avatar URL
            'profile_url' => '',   // string PeepSo profile or author archive URL
        ],

        // ─── Trust score & voting ───────────────────────────────
        // Source: bcc_page_read_model (primary) → ScoreRepository (fallback)
        'trust' => [
            'score'         => null,          // ?int   0–100 composite trust score (null = unavailable)
            'tier'          => 'unavailable', // string elite|trusted|neutral|caution|risky|unavailable
            'confidence'    => 0.0,       // float  0.00–1.00 statistical confidence
            'votes_up'      => 0,         // int    Weighted positive vote total
            'votes_down'    => 0,         // int    Weighted negative vote total
            'unique_voters' => 0,         // int    Distinct users who voted
            'endorsements'  => 0,         // int    Active endorsement count
            'last_vote_at'  => null,      // ?string ISO 8601 datetime of most recent vote
            'score_breakdown' => [        // Score component breakdown (stored, never recomputed)
                'positive'          => 0.0,  // float  Weighted positive vote score
                'negative'          => 0.0,  // float  Weighted negative vote score
                'net'               => 0.0,  // float  positive − negative
                'endorsement_bonus' => 0.0,  // float  Bonus from endorsements
                'onchain_bonus'     => 0.0,  // float  Bonus from on-chain verification
                'community_weight'  => 0.5,  // float  0.0–1.0 proportion of score from votes + endorsements
                'identity_weight'   => 0.5,  // float  0.0–1.0 proportion from verifications + on-chain (always 1 − community_weight)
            ],
        ],

        // ─── Identity verification status ───────────────────────
        // Source: UserInfoRepository + helper functions
        // Boolean flags: has the owner verified this provider?
        'verification' => [
            'email'  => false,     // bool  Email verified via token
            'wallet' => false,     // bool  At least one wallet connected
            'github' => false,     // bool  GitHub OAuth active
            'x'      => false,     // bool  X (Twitter) OAuth active
        ],

        // ─── Verified social profiles ─────────────────────────────
        // Source: VerificationRepository (GitHub + X data)
        // Only populated when the corresponding provider is connected.
        'profiles' => [
            'github' => null,  // ?object {username, followers, repos}
            'x'      => null,  // ?object {username}
        ],

        // ─── Wallet connections (per-chain detail) ──────────────
        // Source: WalletRepository::getAllConnections()
        // Each chain is false (not connected) or an object with detail.
        'wallets' => [
            'ethereum' => false,   // bool|object  Connected chain data
            'solana'   => false,   // bool|object  Connected chain data
            'cosmos'   => false,   // bool|object  Connected chain data
        ],

        // ─── On-chain metrics ───────────────────────────────────
        // Source: Chain indexer (future). Null until populated.
        'onchain' => [
            'wallet_age'   => null, // ?float Years since first on-chain tx
            'transactions' => null, // ?int   Total transaction count
            'contracts'    => null, // ?int   Deployed contract count
            'total_boost'  => 0.0,  // float  Sum of all chain trust boosts
            'nft_collections' => [], // array  NFT badge objects [{name, image, role, chain, trust_boost}]
        ],

        // ─── Per-chain wallet detail ───────────────────────────────
        // Source: WalletSignalRepository
        // Keyed by chain name; each entry has address, role, boost, NFTs.
        'wallet_detail' => [],

        // ─── Flags ─────────────────────────────────────────────────
        'flags' => [
            'count' => 0,         // int  Number of flags on this page
        ],

        // ─── Reputation signals ─────────────────────────────────
        // Source: UserInfoRepository + WP_Post dates + PeepSo meta
        'reputation' => [
            'account_age'        => null, // ?float Years since WP registration
            'account_registered' => null, // ?string ISO 8601 registration date
            'project_age'        => null, // ?float Years since post_date
            'project_created'    => null, // ?string ISO 8601 post creation date
            'followers'          => 0,    // int    PeepSo follower count
            'identity_count'     => 0,    // int    Number of verified identity providers
            'identity_types'     => [],   // string[] Active provider slugs ['github','x','wallet_ethereum']
        ],

        // ─── Page categories ──────────────────────────────────────
        // Source: PeepSo page categories + bcc_get_category_map()
        // Array of objects: [{id, slug, label}]
        'categories' => [],

        // ─── Viewer context (NEVER cached — always fresh) ───────
        // Source: VoteService + EndorsementService + WalletRepository
        // Null when viewer is logged out.
        'viewer' => [
            'is_owner'             => false, // bool   Viewer owns this page
            'vote_type'            => 0,     // int    1 = positive, -1 = risk, 0 = none
            'has_endorsed'         => false, // bool   Viewer has active endorsement
            'has_flagged'          => false, // bool   Viewer has flagged this page
            'wallet_connected'     => false, // bool   Viewer has at least one wallet connected
            'holds_collections'    => [],    // array  Collection contract addresses the viewer holds
            'quest_progress'       => null,  // ?array Quest progress data for the viewer
            'viewer_data_degraded' => false, // bool   At least one viewer-specific lookup failed — UI must disable viewer-dependent controls
        ],
    ];

    /**
     * Merge raw aggregator output with the schema defaults.
     *
     * Guarantees every key exists with the correct type.
     * Unknown keys from the aggregator pass through (forward-compat).
     *
     * @param array<string, mixed> $raw  Output from PageDataAggregator::build()
     * @return array<string, mixed>       Payload matching SCHEMA shape.
     */
    public static function applyDefaults( array $raw ): array {
        $result = [];

        foreach ( self::SCHEMA as $section => $defaults ) {
            $source = $raw[ $section ] ?? [];
            if ( ! is_array( $source ) ) {
                $source = [];
            }

            // Merge section: raw values win, missing keys get defaults.
            $result[ $section ] = array_merge( $defaults, $source );
        }

        // Pass through any extra sections the aggregator added
        // that aren't in the schema yet (forward-compat).
        foreach ( $raw as $key => $value ) {
            if ( ! isset( $result[ $key ] ) ) {
                $result[ $key ] = $value;
            }
        }

        return $result;
    }

    /**
     * Validate that a payload has all required sections.
     *
     * Useful in tests. Returns an array of missing keys (empty = valid).
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    public static function validate( array $data ): array {
        $missing = [];

        foreach ( self::SCHEMA as $section => $defaults ) {
            if ( ! array_key_exists( $section, $data ) ) {
                $missing[] = $section;
                continue;
            }

            if ( is_array( $data[ $section ] ) ) {
                foreach ( $defaults as $key => $default ) {
                    if ( ! array_key_exists( $key, $data[ $section ] ) ) {
                        $missing[] = $section . '.' . $key;
                    }
                }
            }
        }

        return $missing;
    }
}
