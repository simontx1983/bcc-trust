<?php
/**
 * Cards-List Endpoint — GET /bcc/v1/cards (per §G1/§G2 directory).
 *
 * Sibling to the per-card CardsEndpoint (`/cards/:type/:id`). Returns a
 * paginated list of Card view-models for the headless `/directory`
 * surface — same shape per item, just batched with filter + sort
 * controls on the outside.
 *
 * Speaks Card view-models per §A2 / §L5 — the frontend renders
 * server-pre-formatted strings verbatim and never reshapes them.
 * `PageDiscoveryService` does the SQL; this endpoint wraps it for
 * view-model output. Single source of trust per §A4.
 *
 * V1 filter set (per §G2 launch checklist):
 *   - kind   (validator|project|creator)  — translates to legacy
 *                                            page_type via PageTypeMap
 *   - tier   (legendary|rare|uncommon|    — translates to reputation
 *             common)                       tier via the §C1 mapping
 *   - sort   (trust|newest|endorsements|  — passed through verbatim;
 *             followers)                    PageDiscoveryService validates
 *   - q      (search string)              — passed through verbatim
 *   - good_standing_only (0|1)            — when true, restricts results to
 *                                            §E1 good-standing tiers (neutral,
 *                                            trusted, elite). Composes with
 *                                            `tier` via AND so the filter
 *                                            chip and per-row stamp can never
 *                                            disagree.
 *   - page   (1-based)                    — capped at 20 (filesort
 *                                            invariant on offset-
 *                                            paginated queries)
 *   - per_page (1..50)                    — 50-row hard ceiling
 *                                            (same invariant)
 *
 * Deferred to V1.5 (per scope discipline §P):
 *   - chain filter             — bcc_page_read_model has no chain column
 *   - self_bonded filter (§G2) — would need a sync extension to denormalize
 *                                 bcc_onchain_validators.self_stake
 *   - view-toggle (grid/table) — pure frontend concern, nothing here
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §G1/§G2 directory)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\PageDiscoveryService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\PageTypeMap;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CardsListEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Hard ceilings — filesort/memory invariants on offset-paginated queries. */
    private const PER_PAGE_MAX = 50;
    private const PAGE_MAX     = 20;

    /**
     * Card-tier (frontend canonical, per §C1) → reputation tier
     * (PageDiscoveryService internal). Risky is intentionally absent —
     * risky pages don't surface in card UI per §C1, so a client cannot
     * filter for them.
     *
     * @var array<string, string>
     */
    private const CARD_TIER_TO_REPUTATION = [
        'legendary' => 'elite',
        'rare'      => 'trusted',
        'uncommon'  => 'neutral',
        'common'    => 'caution',
    ];

    /** @var list<string> */
    private const ALLOWED_KINDS = ['validator', 'project', 'creator'];

    /** @var list<string> */
    private const ALLOWED_SORTS = ['trust', 'newest', 'endorsements', 'followers'];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/cards',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'handle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'kind' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'tier' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'sort' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'q' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'good_standing_only' => [
                        'required' => false,
                        // Accept '1', 'true', 0, etc. — boolish coerce
                        // happens at the handler boundary below, not
                        // in REST args (which would reject 'true' as
                        // a non-integer).
                        'type'     => 'string',
                    ],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();

        $kindParam = (string) $request->get_param('kind');
        $tierParam = (string) $request->get_param('tier');
        $sortParam = (string) $request->get_param('sort');
        $query     = (string) $request->get_param('q');

        $page = (int) $request->get_param('page');
        if ($page <= 0) {
            $page = 1;
        }
        if ($page > self::PAGE_MAX) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf('page must be ≤ %d. Use filters to narrow results.', self::PAGE_MAX),
                400
            );
        }

        $perPage = (int) $request->get_param('per_page');
        if ($perPage <= 0) {
            $perPage = 24;
        }
        if ($perPage > self::PER_PAGE_MAX) {
            $perPage = self::PER_PAGE_MAX;
        }

        // ── kind → page_type translation ────────────────────────────────
        $types = [];
        if ($kindParam !== '') {
            if (!in_array($kindParam, self::ALLOWED_KINDS, true)) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    'kind must be validator, project, or creator.',
                    400
                );
            }
            $types = [PageTypeMap::KIND_TO_PAGE_TYPE[$kindParam]];
        }

        // ── card-tier → reputation tier translation ────────────────────
        $reputationTier = '';
        if ($tierParam !== '') {
            if (!array_key_exists($tierParam, self::CARD_TIER_TO_REPUTATION)) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    'tier must be legendary, rare, uncommon, or common.',
                    400
                );
            }
            $reputationTier = self::CARD_TIER_TO_REPUTATION[$tierParam];
        }

        // ── sort validation (defaults to 'trust' inside DiscoveryService
        //    when empty / unknown, but reject unknowns at the boundary
        //    so callers don't silently get the default). ───────────────
        if ($sortParam !== '' && !in_array($sortParam, self::ALLOWED_SORTS, true)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'sort must be trust, newest, endorsements, or followers.',
                400
            );
        }
        $sort = $sortParam !== '' ? $sortParam : 'trust';

        // ── good_standing_only (boolish: '1'|'true'|'on'|true → true) ───
        $goodStandingRaw  = (string) $request->get_param('good_standing_only');
        $goodStandingOnly = in_array(
            strtolower($goodStandingRaw),
            ['1', 'true', 'on', 'yes'],
            true
        );

        // ── Run discovery ───────────────────────────────────────────────
        $discoveryService = new PageDiscoveryService();
        $discoveryResult = $discoveryService->query([
            'types'              => $types,
            'sort'               => $sort,
            'tier'               => $reputationTier,
            'limit'              => $perPage,
            'page'               => $page,
            'search'             => $query,
            'good_standing_only' => $goodStandingOnly,
        ]);

        $rows = isset($discoveryResult['results']) && is_array($discoveryResult['results'])
            ? $discoveryResult['results']
            : [];
        $totalPages = isset($discoveryResult['pages']) ? (int) $discoveryResult['pages'] : 1;

        // ── Hydrate to Card view-models ─────────────────────────────────
        // PageDiscoveryService::buildCard{,FromReadModel} emits row keys
        // `page_id` + `page_type`. Earlier this loop read `ID` — the
        // WP_Post-style key — so $postId silently defaulted to 0 and
        // every row got skipped, leaving items[] empty even when
        // pagination reported many pages of results.
        $cardService = Plugin::instance()->cardViewService();
        $items = [];
        foreach ($rows as $row) {
            $rowArr = is_array($row) ? $row : (array) $row;
            $postId = isset($rowArr['page_id']) ? (int) $rowArr['page_id'] : 0;
            $pageType = isset($rowArr['page_type']) ? (string) $rowArr['page_type'] : '';
            if ($postId <= 0 || $pageType === '') {
                continue;
            }
            $kind = PageTypeMap::kindForPageType($pageType);
            if ($kind === null) {
                // Unknown page_type (e.g. 'dao' which isn't a card kind
                // in V1). Skip rather than surface a half-shaped row.
                continue;
            }

            $card = $cardService->getCard($kind, (string) $postId, $viewerId);
            if ($card !== null) {
                $items[] = $card;
            }
        }

        $payload = [
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
                'has_more'    => $page < $totalPages && $page < self::PAGE_MAX,
            ],
        ];

        $response = ApiResponse::ok($payload);
        // Per-viewer permissions vary the response (claim, voted, etc.) →
        // private cache only, brief TTL. The underlying discovery query
        // is cached for 30s server-side, so this short client TTL is just
        // a courtesy for back-button nav.
        $response->header('Cache-Control', 'private, max-age=15');
        $response->header('Vary', 'Authorization, Cookie');
        return $response;
    }
}
