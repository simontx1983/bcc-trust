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
 *   - v1.70: the All-scope response merges the groups + users
 *     verticals server-side (community + member rows) under a
 *     deterministic floor/quota allocation, and `kind=member|community`
 *     routes to that single vertical. The merge lives HERE so the
 *     frontend keeps its single-request-per-keystroke design — the
 *     dropdown never fans out client-side.
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

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\InternalPath;
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
    private const ALLOWED_KINDS = ['validator', 'project', 'creator', 'member', 'community'];

    /**
     * Response cap (contract §4.9, documented v1.70). Mirrors
     * bcc-search SearchController::LIMIT — the pages vertical never
     * returns more than 12 rows. Deliberately NOT a public `limit`
     * param: the endpoint has exactly one consumer (GlobalSearch) and
     * it has never passed one; revisit only when a second consumer
     * needs a variable N.
     */
    private const MAX_ITEMS = 12;

    /** All-scope per-vertical quotas (contract §4.9 allocation). */
    private const COMMUNITY_QUOTA = 3;
    private const MEMBER_QUOTA    = 3;

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
                'kind must be validator, project, creator, member, or community.',
                400
            );
        }

        // Scoped single-vertical paths (v1.70): member/community skip
        // the pages pipeline entirely — a scoped keystroke costs
        // exactly one vertical query.
        if ($kindParam === 'community') {
            return self::okItems($this->communitySuggestions($query, self::MAX_ITEMS));
        }
        if ($kindParam === 'member') {
            return self::okItems($this->memberSuggestions($query, self::MAX_ITEMS));
        }

        // Translate canonical kind → bcc-search's category_slug
        // (legacy page_type). Empty kind = all categories. Only the
        // three page kinds can reach this map — member/community
        // returned above — so a hit is guaranteed when $kindParam is
        // non-empty.
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

        // Page-kind scope: pages only, capped.
        if ($kindParam !== '') {
            return self::okItems(array_slice($items, 0, self::MAX_ITEMS));
        }

        // All scope (v1.70): merge in the community + member verticals
        // under the deterministic floor/quota allocation (§4.9).
        $communities = $this->communitySuggestions($query, self::COMMUNITY_QUOTA);
        $members     = $this->memberSuggestions($query, self::MEMBER_QUOTA);

        return self::okItems(
            self::allocateSuggestions($items, $communities, $members, self::MAX_ITEMS)
        );
    }

    /**
     * Deterministic All-scope allocation (contract §4.9, v1.70).
     *
     * Floors first, in priority order — 1 page, then 1 community, then
     * 1 member, each only when that vertical has results and capacity
     * remains — then communities top up to COMMUNITY_QUOTA, members to
     * MEMBER_QUOTA, and pages absorb ALL remaining capacity (unused
     * community/member quota flows back to pages). Display grouping is
     * pages → communities → members; within a vertical the upstream
     * ranking order is preserved. Never exceeds $limit; may return
     * fewer (vertical shortfall).
     *
     * Public static: pure, exercised directly by unit tests. Not part
     * of any external API.
     *
     * @param list<array<string, mixed>> $pages
     * @param list<array<string, mixed>> $communities
     * @param list<array<string, mixed>> $members
     * @return list<array<string, mixed>>
     */
    public static function allocateSuggestions(
        array $pages,
        array $communities,
        array $members,
        int $limit
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $p = 0;
        $c = 0;
        $m = 0;
        $cap = $limit;

        // No $cap guard on the first floor: $cap === $limit ≥ 1 here
        // (the ≤ 0 case returned above).
        if ($pages !== []) {
            $p = 1;
            $cap--;
        }
        if ($communities !== [] && $cap > 0) {
            $c = 1;
            $cap--;
        }
        if ($members !== [] && $cap > 0) {
            $m = 1;
            $cap--;
        }

        $add = min(min(count($communities), self::COMMUNITY_QUOTA) - $c, $cap);
        if ($add > 0) {
            $c += $add;
            $cap -= $add;
        }
        $add = min(min(count($members), self::MEMBER_QUOTA) - $m, $cap);
        if ($add > 0) {
            $m += $add;
            $cap -= $add;
        }
        $add = min(count($pages) - $p, $cap);
        if ($add > 0) {
            $p += $add;
        }

        return array_merge(
            array_slice($pages, 0, $p),
            array_slice($communities, 0, $c),
            array_slice($members, 0, $m)
        );
    }

    /**
     * Fetch + normalize community suggestions from the groups vertical.
     *
     * Zero queries of its own — id/name/slug/kind/group_url all arrive
     * in the proxied payload (bcc-search batches the kind meta in its
     * single three-JOIN query).
     *
     * @return list<array<string, mixed>>
     */
    private function communitySuggestions(string $query, int $limit): array
    {
        $internal = new WP_REST_Request('GET', '/bcc/v1/search/groups');
        $internal->set_param('q', $query);
        $internal->set_param('limit', $limit);
        $upstream = rest_do_request($internal);

        if ($upstream->is_error()) {
            // Degraded / rate-limited vertical → silently absent from
            // the merge (same "never block mid-type" rule as pages).
            return [];
        }

        $body = $upstream->get_data();
        $rows = is_array($body) && isset($body['results']) && is_array($body['results'])
            ? $body['results']
            : [];

        $out = [];
        foreach ($rows as $row) {
            $suggestion = self::buildCommunitySuggestion(is_array($row) ? $row : (array) $row);
            if ($suggestion !== null) {
                $out[] = $suggestion;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Fetch + normalize member suggestions from the users vertical,
     * enriched with REAL reputation values.
     *
     * ONE bounded batch (≤ $limit ≤ 12 ids) via
     * ReputationRepository::primeByUserIds warms the row memo, so the
     * per-row getTier()/getScore() reads below are free — the
     * MemberCardPrefetcher::primeFor pattern. No per-row queries.
     *
     * @return list<array<string, mixed>>
     */
    private function memberSuggestions(string $query, int $limit): array
    {
        $internal = new WP_REST_Request('GET', '/bcc/v1/search/users');
        $internal->set_param('q', $query);
        $internal->set_param('limit', $limit);
        $upstream = rest_do_request($internal);

        if ($upstream->is_error()) {
            return [];
        }

        $body = $upstream->get_data();
        $rows = is_array($body) && isset($body['results']) && is_array($body['results'])
            ? $body['results']
            : [];
        if ($rows === []) {
            return [];
        }

        $userIds = [];
        foreach ($rows as $row) {
            $rowArr = is_array($row) ? $row : (array) $row;
            $uid    = isset($rowArr['id']) ? (int) $rowArr['id'] : 0;
            if ($uid > 0) {
                $userIds[] = $uid;
            }
        }

        $repo = new ReputationRepository();
        if ($userIds !== []) {
            $repo->primeByUserIds($userIds);
        }

        $out = [];
        foreach ($rows as $row) {
            $rowArr = is_array($row) ? $row : (array) $row;
            $uid    = isset($rowArr['id']) ? (int) $rowArr['id'] : 0;
            if ($uid <= 0) {
                continue;
            }

            // Real values — memo reads after the batch prime. ALL
            // tiers surface, risky/caution included: autocomplete is a
            // lookup surface, not an endorsement surface (§4.9).
            $tier  = $repo->getTier($uid);
            $label = ReputationTierMap::toReputationTierLabel($tier);
            $score = (int) round($repo->getScore($uid));

            $suggestion = self::buildMemberSuggestion($rowArr, $tier, $label, $score);
            if ($suggestion !== null) {
                $out[] = $suggestion;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Build a community SearchSuggestion from a /search/groups row, or
     * null when required fields are missing or the href fails
     * internal-path validation.
     *
     * Field semantics (contract §4.9 / §3.2.4, v1.70):
     *   - Trust placeholders: tier 'neutral' + label null (the FE tier
     *     chip is null-label-suppressed) + trust_score null (this shape
     *     admits null, unlike the Card shape's 0).
     *   - is_claim_verified false — §3.2: communities are not
     *     on-chain-claimed.
     *   - NO `is_verified` key: no owner-email verification meaning
     *     exists for a community on this surface. Omitted, never false.
     *   - href is CONSUMED from the vertical's kind-aware relative
     *     group_url — this class builds no community URL of its own, so
     *     CardUrlMap's no-inline-prefix rule is not in play. The
     *     InternalPath gate drops any absolute/protocol-relative URL a
     *     version-skewed (pre-v1.70) bcc-search could still emit.
     *
     * Public static: pure, exercised directly by unit tests.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function buildCommunitySuggestion(array $row): ?array
    {
        $id   = isset($row['id']) ? (int) $row['id'] : 0;
        $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
        $slug = isset($row['slug']) && is_string($row['slug']) ? $row['slug'] : '';
        $url  = isset($row['group_url']) && is_string($row['group_url']) ? $row['group_url'] : '';

        if ($id <= 0 || $name === '' || !InternalPath::isValid($url)) {
            return null;
        }

        return [
            'id'                    => $id,
            'name'                  => $name,
            'handle'                => $slug,
            'card_kind'             => 'community',
            'reputation_tier'       => 'neutral',
            'reputation_tier_label' => null,
            'trust_score'           => null,
            'is_claim_verified'     => false,
            'href'                  => $url,
        ];
    }

    /**
     * Build a member SearchSuggestion from a /search/users row plus its
     * batch-resolved reputation values, or null when required fields
     * are missing or the href fails internal-path validation.
     *
     * Field semantics (contract §4.9, v1.70):
     *   - REAL tier/label/score — the same values the member Card
     *     carries. Callers resolve them via the primed
     *     ReputationRepository memo (no per-row queries).
     *   - is_claim_verified false — §3.2: member self-pages
     *     (ID_BASE + user_id) can never key an on-chain claim.
     *   - NO `is_verified` key: /search/users does not provide the
     *     authoritative email-verification signal
     *     (bcc_trust_user_info.is_verified) and no bounded batch
     *     reader exists for it. Omission means UNAVAILABLE, not false —
     *     members do have a real email-verification value; emitting
     *     false would assert something untrue about verified members.
     *   - href is CONSUMED from the vertical's relative profile_url
     *     (/u/{handle}, v1.46-hardened) after the InternalPath gate.
     *
     * Public static: pure, exercised directly by unit tests.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function buildMemberSuggestion(
        array $row,
        string $tier,
        string $label,
        int $score
    ): ?array {
        $id       = isset($row['id']) ? (int) $row['id'] : 0;
        $username = isset($row['username']) && is_string($row['username']) ? $row['username'] : '';
        $display  = isset($row['display_name']) && is_string($row['display_name']) ? $row['display_name'] : '';
        $url      = isset($row['profile_url']) && is_string($row['profile_url']) ? $row['profile_url'] : '';

        if ($id <= 0 || $username === '' || !InternalPath::isValid($url)) {
            return null;
        }

        return [
            'id'                    => $id,
            'name'                  => $display !== '' ? $display : $username,
            'handle'                => $username,
            'card_kind'             => 'member',
            'reputation_tier'       => $tier,
            'reputation_tier_label' => $label,
            'trust_score'           => $score,
            'is_claim_verified'     => false,
            'href'                  => $url,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function okItems(array $items): WP_REST_Response
    {
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
