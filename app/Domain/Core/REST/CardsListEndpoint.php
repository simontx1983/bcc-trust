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
 *   - tier   (elite|trusted|neutral|      — reputation tier, verbatim
 *             caution|risky)                 (v1.57: no translation)
 *   - sort   (trust|newest|endorsements|  — passed through verbatim;
 *             followers|self_stake)         PageDiscoveryService validates.
 *                                           `self_stake` is validator-only.
 *   - q      (search string)              — passed through verbatim
 *   - good_standing_only (0|1)            — when true, restricts results to
 *                                            §E1 good-standing tiers (neutral,
 *                                            trusted, elite). Composes with
 *                                            `tier` via AND so the filter
 *                                            chip and per-row stamp can never
 *                                            disagree.
 *   - chain  (chain slug)                 — validator-scoped; JOINs through
 *                                            `_bcc_onchain_validator_id` meta.
 *   - status (active|jailed|inactive)     — validator-only on-chain status.
 *   - min_self_stake (number ≥ 0)         — validator-only bonded self-stake
 *                                            floor.
 *   - page   (1-based)                    — capped at 20 (offset-FS
 *                                            guard inherited from the
 *                                            now-retired DiscoveryEndpoint
 *                                            it superseded; same filesort-
 *                                            prevention budget on deep
 *                                            pagination)
 *   - per_page (1..50)                    — hard ceiling
 *
 * The validator-only axes (chain / status / min_self_stake / self_stake
 * sort) are served by the read-model query path's validator JOIN; the
 * legacy posts-table fallback does not implement them (same as chain).
 *
 * Deferred (per scope discipline §P):
 *   - view-toggle (grid/table) — pure frontend concern, nothing here
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §G1/§G2 directory)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\PageDiscoveryService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\PageCardPrefetcher;
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

    /** Hard ceilings — must match DiscoveryEndpoint's invariants. */
    private const PER_PAGE_MAX = 50;
    private const PAGE_MAX     = 20;

    /**
     * Card-tier (frontend canonical, per §C1) → reputation tier
     * (PageDiscoveryService internal). Risky is intentionally absent —
     * Accepted values for the `tier` filter — the reputation tiers, directly.
     *
     * v1.57: this was a rarity→reputation translation table
     * (legendary→elite, …). Two things changed with the retirement. The
     * client now speaks the same vocabulary the engine does, so there is no
     * translation step to drift; and `risky` became FILTERABLE. It was
     * previously unreachable — it had no rarity slug, so no client could ask
     * for it — which meant the one cohort an operator most needs to review
     * was the one the directory could not show them.
     *
     * @var list<string>
     */
    private const ALLOWED_TIERS = ['elite', 'trusted', 'neutral', 'caution', 'risky'];

    /** @var list<string> */
    private const ALLOWED_KINDS = ['validator', 'project', 'creator'];

    /**
     * `self_stake` is validator-only (sorts by bonded self-stake DESC).
     * It is served by the read-model path's validator JOIN — harmless on
     * non-validator kinds (the JOIN simply yields no rows, so the result
     * is empty rather than erroneous).
     *
     * @var list<string>
     */
    private const ALLOWED_SORTS = ['trust', 'newest', 'endorsements', 'followers', 'self_stake'];

    /**
     * Validator on-chain status filter values (subset of the column's
     * domain; 'unknown' is intentionally not selectable).
     *
     * @var list<string>
     */
    private const ALLOWED_STATUSES = ['active', 'jailed', 'inactive'];

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
                    'chain' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'status' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'min_self_stake' => [
                        'required' => false,
                        'type'     => 'number',
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

        // ── tier filter validation (no translation since v1.57) ────────
        $reputationTier = '';
        if ($tierParam !== '') {
            if (!in_array($tierParam, self::ALLOWED_TIERS, true)) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    'tier must be elite, trusted, neutral, caution, or risky.',
                    400
                );
            }
            $reputationTier = $tierParam;
        }

        // ── sort validation (defaults to 'trust' inside DiscoveryService
        //    when empty / unknown, but reject unknowns at the boundary
        //    so callers don't silently get the default). ───────────────
        if ($sortParam !== '' && !in_array($sortParam, self::ALLOWED_SORTS, true)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'sort must be trust, newest, endorsements, followers, or self_stake.',
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

        // ── chain filter (validator-scoped today; service handles the
        //    JOIN through the `_bcc_onchain_validator_id` post_meta the
        //    minter writes). Rejected at the boundary for unknown slugs
        //    so we never silently return empty results from a typo.
        $chainSlug = (string) $request->get_param('chain');
        if ($chainSlug !== '') {
            if (\BCC\Core\ServiceLocator::resolveChainRead()->getBySlug($chainSlug) === null) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    'chain must be a known chain slug.',
                    400
                );
            }
        }

        // ── status filter (validator-only; rejected at the boundary for
        //    unknown values, mirroring the chain-slug guard above).
        $statusParam = (string) $request->get_param('status');
        if ($statusParam !== '' && !in_array($statusParam, self::ALLOWED_STATUSES, true)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'status must be active, jailed, or inactive.',
                400
            );
        }

        // ── min_self_stake filter (validator-only; bonded self-stake floor).
        //    Negative values are nonsensical for a stake floor → reject.
        $minSelfStake = null;
        if ($request->get_param('min_self_stake') !== null) {
            $minSelfStake = (float) $request->get_param('min_self_stake');
            if ($minSelfStake < 0) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    'min_self_stake must be a non-negative number.',
                    400
                );
            }
        }

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
            'chain_slug'         => $chainSlug,
            'status'             => $statusParam,
            'min_self_stake'     => $minSelfStake,
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
        //
        // Two passes: collect the (page_id, kind) pairs first, then
        // prime one PageCardPrefetcher bundle for the whole page so
        // per-card hydration doesn't N+1 (~20 reads × 24 cards → a
        // flat batch set). Every discovery row is a page kind here —
        // kindForPageType never yields 'member'.
        $pairs = [];
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
            $pairs[] = [$postId, $kind];
        }

        $cardService = Plugin::instance()->cardViewService();
        $items = [];
        if ($pairs !== []) {
            $prefetched = PageCardPrefetcher::primeFor(
                array_map(static fn(array $pair): int => $pair[0], $pairs),
                $viewerId
            );
            foreach ($pairs as [$postId, $kind]) {
                $card = $cardService->getPageCardForList($kind, $postId, $viewerId, $prefetched);
                if ($card !== null) {
                    $items[] = $card;
                }
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
