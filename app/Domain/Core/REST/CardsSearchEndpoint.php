<?php
/**
 * Cards-Search Endpoint — GET /bcc/v1/cards/search.
 *
 * Powers the §G1 global nav-bar autocomplete. Thin wrapper over the
 * existing `bcc-search` plugin's `/bcc/v1/search` endpoint (FULLTEXT
 * index + trust enrichment + caching + rate limiting are already
 * solved there) — this endpoint's job is to map the flat
 * `SearchResult` rows into the canonical Card-shaped
 * `SearchSuggestion` view-model the headless frontend speaks per §A2.
 *
 * Why we wrap instead of consuming bcc-search directly from the
 * frontend:
 *   - bcc-search returns `tier: 'elite'` (reputation tier).
 *     §A2 forbids the frontend from mapping a tier onto a display
 *     label. The mapping must happen server-side.
 *   - bcc-search returns `category_slug: 'builder'` (legacy page_type).
 *     The §C1 contract speaks `card_kind: 'project'`. Same rule.
 *   - bcc-search returns the WordPress permalink in `page_url`. The
 *     headless frontend uses Next.js routes (`/v/:slug`, `/p/:slug`,
 *     `/c/:slug`). We resolve those server-side too.
 *
 * Response shape is intentionally smaller than the full Card view-model
 * — autocomplete needs name + handle + tier + click-through, not stats
 * / permissions / social proof. Reusing the larger Card type would
 * waste payload + cycle through CardViewService for every keystroke.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §G1 directory)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Core\Support\ReputationTierMap;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CardsSearchEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /**
     * Frontend route prefixes per card_kind. Same shape as the
     * CardViewService::buildLinks output — kept in this file (not
     * pulled from CardUrlMap) because we want the prefix as a string,
     * not a {self,...} object.
     *
     * @var array<string, string>
     */
    private const KIND_TO_ROUTE_PREFIX = [
        'validator' => '/v/',
        'project'   => '/p/',
        'creator'   => '/c/',
    ];

    /** @var list<string> */
    private const ALLOWED_KINDS = ['validator', 'project', 'creator'];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/cards/search',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'handle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'q' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'kind' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $query = trim((string) $request->get_param('q'));

        // Mirror bcc-search's quality gate at the boundary so callers
        // get a fast empty response on junk queries instead of the
        // wrapper round-tripping for nothing.
        if ($query === '' || mb_strlen($query) < 2) {
            return self::okEmpty();
        }

        $kindParam = (string) $request->get_param('kind');
        if ($kindParam !== '' && !in_array($kindParam, self::ALLOWED_KINDS, true)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'kind must be validator, project, or creator.',
                400
            );
        }

        // Translate canonical kind → bcc-search's category_slug
        // (legacy page_type). Empty kind = all categories. The
        // ALLOWED_KINDS guard above mirrors the map keys, so a hit
        // is guaranteed when $kindParam is non-empty.
        $categorySlug = $kindParam !== ''
            ? PageTypeMap::KIND_TO_PAGE_TYPE[$kindParam]
            : '';

        // Internal call — same WordPress request, no HTTP round-trip.
        // bcc-search's controller does FULLTEXT lookup + trust
        // enrichment + 60s cache + rate limit. We just consume the
        // result.
        $internal = new WP_REST_Request('GET', '/bcc/v1/search');
        $internal->set_param('q', $query);
        if ($categorySlug !== '') {
            $internal->set_param('type', $categorySlug);
        }
        $upstream = rest_do_request($internal);

        if ($upstream->is_error()) {
            // bcc-search returns 503 on degraded paths. Surface as an
            // empty list — autocomplete should never block a user
            // mid-type with an error toast.
            return self::okEmpty();
        }

        $body = $upstream->get_data();
        $rows = is_array($body) && isset($body['results']) && is_array($body['results'])
            ? $body['results']
            : [];

        // Batch-resolve on-chain claim-verified status for every result
        // page in a SINGLE bounded query (autocomplete tops out at
        // bcc-search's page cap) so per-row buildSuggestion() stays N+1-free.
        $resultPageIds = [];
        foreach ($rows as $row) {
            $rowArr = is_array($row) ? $row : (array) $row;
            $pid    = isset($rowArr['page_id']) ? (int) $rowArr['page_id'] : 0;
            if ($pid > 0) {
                $resultPageIds[] = $pid;
            }
        }
        $claimVerifiedMap = $resultPageIds !== []
            ? \BCC\Trust\Onchain\Repositories\ClaimRepository::getVerifiedPagesMap($resultPageIds)
            : [];

        // Prime the WP post + post-meta object caches in ONE batch so the
        // per-row get_post()/get_post_meta('_bcc_page_type') in buildSuggestion()
        // are served from cache — bcc-search hydrates via raw $wpdb and never
        // primes core's caches, so without this each row is an uncached
        // SELECT. Second arg false skips terms (unused); third true primes
        // meta (buildSuggestion reads _bcc_page_type). Keeps buildSuggestion
        // N+1-free.
        if ($resultPageIds !== []) {
            _prime_post_caches($resultPageIds, false, true);
        }

        $items = [];
        foreach ($rows as $row) {
            $rowArr = is_array($row) ? $row : (array) $row;
            $suggestion = self::buildSuggestion($rowArr, $claimVerifiedMap);
            if ($suggestion !== null) {
                $items[] = $suggestion;
            }
        }

        $response = ApiResponse::ok(['items' => $items]);
        $response->header('Cache-Control', 'private, max-age=15');
        return $response;
    }

    /**
     * Build a single SearchSuggestion from a bcc-search row, or null
     * when the row is missing required fields or its category is
     * outside our allowed kinds (e.g. 'dao' which is not a card kind
     * in V1 per §C1).
     *
     * @param array<string, mixed> $row
     * @param array<int, true> $claimVerifiedMap page_id => true for pages
     *        with a verified on-chain operator/creator claim.
     * @return array{
     *   id: int,
     *   name: string,
     *   handle: string,
     *   card_kind: string,
     *   reputation_tier: string,
     *   reputation_tier_label: string,
     *   trust_score: int|null,
     *   is_verified: bool,
     *   is_claim_verified: bool,
     *   href: string
     * }|null
     */
    private static function buildSuggestion(array $row, array $claimVerifiedMap = []): ?array
    {
        $pageId = isset($row['page_id']) ? (int) $row['page_id'] : 0;
        if ($pageId <= 0) {
            return null;
        }

        // Resolve handle from post_name (bcc-search doesn't include
        // it). Falls back to the page_id if the post is gone — the
        // CardsEndpoint route accepts numeric IDs as a slug per its
        // ID_PATTERN regex.
        $post = get_post($pageId);
        $handle = $post !== null && $post->post_name !== ''
            ? $post->post_name
            : (string) $pageId;

        // bcc-search's `category_slug` is the canonical source, but it
        // ships null when the search index hasn't been backfilled with
        // category data. Fall back to the `_bcc_page_type` post meta —
        // which IS the canonical card_kind store per PageTypeMap — so a
        // partial search index doesn't make every result invisible.
        $categorySlug = isset($row['category_slug']) && is_string($row['category_slug'])
            ? $row['category_slug']
            : '';
        $kind = $categorySlug !== ''
            ? PageTypeMap::kindForPageType($categorySlug)
            : null;
        if ($kind === null && $post !== null) {
            $pageType = (string) get_post_meta($pageId, '_bcc_page_type', true);
            if ($pageType !== '') {
                $kind = PageTypeMap::kindForPageType($pageType);
            }
        }
        if ($kind === null || !isset(self::KIND_TO_ROUTE_PREFIX[$kind])) {
            return null;
        }

        $tier = isset($row['tier']) && is_string($row['tier']) ? $row['tier'] : '';
        $rep  = ReputationTierMap::resolveReputation($tier);

        $trustScore = isset($row['trust_score']) && is_numeric($row['trust_score'])
            ? (int) $row['trust_score']
            : null;

        $name = isset($row['page_name']) && is_string($row['page_name']) ? $row['page_name'] : '';
        $isVerified = isset($row['verified']) ? (bool) $row['verified'] : false;
        // On-chain claim-verified (operator/creator) — distinct from the
        // owner-EMAIL `is_verified` above. Sourced from the pre-batched map
        // so no per-suggestion query fires.
        $isClaimVerified = isset($claimVerifiedMap[$pageId]);

        return [
            'id'                  => $pageId,
            'name'                => $name,
            'handle'              => $handle,
            'card_kind'           => $kind,
            'reputation_tier'       => $rep['reputation_tier'],
            'reputation_tier_label' => $rep['reputation_tier_label'],
            'trust_score'         => $trustScore,
            'is_verified'         => $isVerified,
            'is_claim_verified'   => $isClaimVerified,
            'href'                => self::KIND_TO_ROUTE_PREFIX[$kind] . $handle,
        ];
    }

    private static function okEmpty(): WP_REST_Response
    {
        $response = ApiResponse::ok(['items' => []]);
        $response->header('Cache-Control', 'private, max-age=15');
        return $response;
    }
}
