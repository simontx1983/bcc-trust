<?php
/**
 * Users Endpoint — handles /bcc/v1/users/:handle/* routes.
 *
 * Phase 1 routes registered:
 *   - GET /users/:handle/shift-log — 52-week activity grid (§4.4)
 *   - GET /users/:handle/blog      — §D6 long-form posts, paginated
 *   - GET /users/:handle/reviews   — §V1.5 reviews-on-file, paginated
 *   - GET /users/:handle/disputes  — §V1.5 disputes-signed, paginated
 *   - GET /users/:handle/activity  — per-user wall (PeepSo "stream"), cursor
 *   - GET /members                 — paginated member directory (offset)
 *   - GET /users/mention-search    — §3.3.12 composer @-mention picker
 *
 * Future Phase 1 routes (will register here when they land):
 *   - GET /users/:handle — full User view-model (§4.4)
 *
 * Handle resolution: bcc_handle is stored in wp_usermeta (per §B6).
 * Resolved via WP's get_users() with a meta query — bounded to a
 * single result.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Core\Repositories\PeepSoPageRepository;
use BCC\Core\Security\Throttle;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\EndorsementRepository;
use BCC\Trust\Core\Repositories\FlagsRepository;
use BCC\Trust\Core\Repositories\GitHubRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Repositories\XRepository;
use BCC\Trust\Core\Services\Mentions\MentionSearchService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\ReactionTypeRegistry;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class UsersEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    /**
     * Canonical handle pattern — lowercase alphanumeric + hyphens,
     * 3–20 chars, must start and end with alphanumeric. Public so other
     * REST endpoints (e.g. UserGroupsEndpoint) can route on the same
     * shape; a single source of truth prevents accept-here-reject-there
     * drift.
     */
    public const HANDLE_PATTERN  = '[a-z0-9][a-z0-9-]{1,18}[a-z0-9]';
    private const DEFAULT_WEEKS   = 52;
    private const MAX_WEEKS       = 104;

    /** §D6 blog tab — same cursor cap as the Floor feed. */
    private const BLOG_DEFAULT_LIMIT = 20;
    private const BLOG_MAX_LIMIT     = 50;

    /** §V1.5 reviews/disputes — offset pagination (directory-style). */
    private const LIST_DEFAULT_PER_PAGE = 20;
    private const LIST_MAX_PER_PAGE     = 50;

    /** Activity wall — cursor pagination, mirrors the Floor feed. */
    private const ACTIVITY_DEFAULT_LIMIT = 20;
    private const ACTIVITY_MAX_LIMIT     = 50;

    /**
     * §3.3.12 — composer mention-picker throttle.
     *
     * Keystroke autocomplete is a high-frequency endpoint; the cap
     * is loose enough to absorb a typing burst (3–6 chars typed in
     * a second) but tight enough that a script can't enumerate the
     * directory through repeated 1-char prefix queries.
     */
    private const MENTION_SEARCH_RATE_LIMIT  = 60;  // requests per window
    private const MENTION_SEARCH_RATE_WINDOW = 60;  // seconds

    public static function register(): void
    {
        // GET /users/mention-search — §3.3.12 composer @-mention picker.
        //
        // Registered BEFORE the `/users/:handle` catch-all because the
        // handle pattern (`[a-z0-9][a-z0-9-]{1,18}[a-z0-9]`) accepts
        // the literal `mention-search` as a valid handle shape. WP
        // dispatches the first matching route in registration order, so
        // an out-of-order registration here routes /users/mention-search
        // into getUser('mention-search') → 404.
        //
        // Slim prefix-search backing the autocomplete dropdown. Distinct
        // from /members because keystroke autocomplete needs a tighter
        // privacy filter (no leak of hidden/banned/blocked users), a
        // smaller payload (8 rows × 4 fields), and a higher hit rate.
        // Routes through PeepSoUserSearch (NOT bcc-search's dormant
        // UserSearchRepository — that one bypasses the privacy filter
        // set; see the Phase 1d §11 scan report).
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/mention-search',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'mentionSearch'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'q' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => MentionSearchService::DEFAULT_LIMIT,
                        'minimum'           => 1,
                        'maximum'           => MentionSearchService::MAX_LIMIT,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // GET /users/:handle — full User view-model (§4.4)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<handle>' . self::HANDLE_PATTERN . ')',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'getUser'],
                'permission_callback' => '__return_true',
                'args' => [
                    'handle' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // GET /users/:handle/shift-log — 52-week activity grid (§4.4)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<handle>' . self::HANDLE_PATTERN . ')/shift-log',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'shiftLog'],
                'permission_callback' => '__return_true',
                'args' => [
                    'handle' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'weeks' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => self::DEFAULT_WEEKS,
                        'minimum'           => 1,
                        'maximum'           => self::MAX_WEEKS,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // GET /users/:handle/blog — §D6 long-form posts, cursor paginated
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<handle>' . self::HANDLE_PATTERN . ')/blog',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'blog'],
                'permission_callback' => '__return_true',
                'args' => [
                    'handle' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'cursor' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => self::BLOG_DEFAULT_LIMIT,
                        'minimum'           => 1,
                        'maximum'           => self::BLOG_MAX_LIMIT,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // GET /users/:handle/reviews — §V1.5 reviews-on-file (offset)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<handle>' . self::HANDLE_PATTERN . ')/reviews',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'reviews'],
                'permission_callback' => '__return_true',
                'args'                => self::listArgsSchema(),
            ]
        );

        // GET /users/:handle/disputes — §V1.5 disputes-signed (offset)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<handle>' . self::HANDLE_PATTERN . ')/disputes',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'disputes'],
                'permission_callback' => '__return_true',
                'args'                => self::listArgsSchema(),
            ]
        );

        // GET /users/:handle/activity — per-user wall (PeepSo "stream"
        // for one author). Cursor paginated, mirrors /feed/hot's shape.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<handle>' . self::HANDLE_PATTERN . ')/activity',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'activity'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'handle' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'cursor' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => self::ACTIVITY_DEFAULT_LIMIT,
                        'minimum'           => 1,
                        'maximum'           => self::ACTIVITY_MAX_LIMIT,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // GET /members — paginated directory of human members.
        // Sibling to /directory (entity cards: validator/project/creator).
        // Slim summary shape per UserViewService::getSummary; click-through
        // to /u/:handle for the full profile.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/members',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'members'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => self::LIST_DEFAULT_PER_PAGE,
                        'minimum'           => 1,
                        'maximum'           => self::LIST_MAX_PER_PAGE,
                        'sanitize_callback' => 'absint',
                    ],
                    'q' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'type' => [
                        'required'          => false,
                        'type'              => 'string',
                        'enum'              => ['validator', 'project', 'nft', 'dao'],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    /**
     * Shared route args for the offset-paginated list endpoints.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function listArgsSchema(): array
    {
        return [
            'handle' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'page' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => self::LIST_DEFAULT_PER_PAGE,
                'minimum'           => 1,
                'maximum'           => self::LIST_MAX_PER_PAGE,
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    public function getUser(WP_REST_Request $request): WP_REST_Response
    {
        $handle = (string) $request->get_param('handle');

        $userId = self::resolveHandle($handle);
        if ($userId === 0) {
            return ApiResponse::error('bcc_not_found', 'Handle not found.', 404);
        }

        $viewerId = get_current_user_id();
        // Rich shape per the §3.1 extension — wraps the canonical User
        // view-model with the profile-page additions (card, standing,
        // identity_meta, stats, shift_log, activity_breakdown,
        // live_shift, tabs). Other surfaces that only need the basic
        // User view-model still call userViewService()->getUser directly.
        $payload  = Plugin::instance()->memberProfileComposer()->compose($userId, $viewerId);

        if ($payload === null) {
            return ApiResponse::error('bcc_not_found', 'User not found.', 404);
        }

        $isSelf   = $viewerId > 0 && $viewerId === $userId;
        $cacheCtl = $isSelf ? 'no-store' : 'private, max-age=30';

        $response = ApiResponse::ok($payload);
        $response->header('Cache-Control', $cacheCtl);
        // Cache key MUST include the auth signal — if a single browser
        // switches accounts, the per-viewer payload (wallet.address gated
        // on $isSelf, etc.) would otherwise be served cross-user from
        // local cache. `private` prevents shared caches; `Vary` prevents
        // browser-local cross-account leaks.
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    public function blog(WP_REST_Request $request): WP_REST_Response
    {
        $handle = (string) $request->get_param('handle');

        $userId = self::resolveHandle($handle);
        if ($userId === 0) {
            return ApiResponse::error('bcc_not_found', 'Handle not found.', 404);
        }

        $cursorParam = $request->get_param('cursor');
        $cursor      = is_string($cursorParam) && $cursorParam !== '' ? $cursorParam : null;

        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = 20;
        }

        $viewerId = get_current_user_id();
        $payload  = Plugin::instance()->blogService()->getUserBlog($userId, $viewerId, $cursor, $limit);

        $response = ApiResponse::ok($payload);
        // Same posture as /feed — per-viewer + scope-filtered, short shared
        // cache to absorb tab switches but not stale enough to confuse a
        // user who just published.
        $response->header('Cache-Control', 'private, max-age=15');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    public function activity(WP_REST_Request $request): WP_REST_Response
    {
        $handle = (string) $request->get_param('handle');

        $userId = self::resolveHandle($handle);
        if ($userId === 0) {
            return ApiResponse::error('bcc_not_found', 'Handle not found.', 404);
        }

        $cursorParam = $request->get_param('cursor');
        $cursor      = is_string($cursorParam) && $cursorParam !== '' ? $cursorParam : null;

        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = self::ACTIVITY_DEFAULT_LIMIT;
        }

        $viewerId = get_current_user_id();
        $payload  = Plugin::instance()->feedRankingService()->getActivityForAuthor(
            $userId,
            $viewerId,
            $cursor,
            $limit
        );

        $response = ApiResponse::ok($payload);
        // Same posture as /feed and the blog tab — per-viewer (block list,
        // viewer reactions, follow badges all vary), short shared cache to
        // absorb tab toggles without stalling on fresh activity.
        $response->header('Cache-Control', 'private, max-age=15');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    public function members(WP_REST_Request $request): WP_REST_Response
    {
        $page    = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');
        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = self::LIST_DEFAULT_PER_PAGE;
        }

        $qParam = $request->get_param('q');
        $q      = is_string($qParam) ? trim($qParam) : '';

        $typeParam = $request->get_param('type');
        $type      = is_string($typeParam) && PeepSoPageRepository::isValidType($typeParam)
            ? $typeParam
            : '';

        $viewerId = get_current_user_id();

        // Global type-counts (independent of `q` / `type` filters) ride
        // on every /members response so the frontend's chip strip can
        // render `VALIDATORS · 5` without a second roundtrip. The
        // counts also survive the empty-result short-circuit below —
        // a filter-specific empty state still needs them to suggest
        // alternative chips with non-zero results.
        $typeCounts = PeepSoPageRepository::getGlobalOwnedPageUserCountsByType();

        // When `type` is set, pre-resolve the set of user_ids who own
        // ≥1 page of that type (one batched SQL on the page-categories
        // join). Pass that list to WP_User_Query via `include` so the
        // existing pagination + search logic keeps working — the
        // intersection of (search match) AND (in this id set) is what
        // WP_User_Query produces. Empty pre-resolved list = no rows
        // (short-circuit before issuing the user query).
        $typeRestrictedIds = null;
        if ($type !== '') {
            $typeRestrictedIds = PeepSoPageRepository::getUserIdsOwningPagesOfType($type);
            if ($typeRestrictedIds === []) {
                $emptyResponse = ApiResponse::ok([
                    'items'      => [],
                    'pagination' => [
                        'page'        => $page,
                        'per_page'    => $perPage,
                        'total'       => 0,
                        'total_pages' => 1,
                    ],
                    'type_counts' => $typeCounts,
                ]);
                $emptyResponse->header('Cache-Control', 'private, max-age=15');
                $emptyResponse->header('Vary', 'Authorization, Cookie');
                return $emptyResponse;
            }
        }

        // WP_User_Query handles offset pagination + search across
        // user_login + display_name + user_nicename. Email is excluded
        // via search_columns to avoid leaking address fragments back
        // through search hits.
        $args = [
            'number'  => $perPage,
            'offset'  => ($page - 1) * $perPage,
            'orderby' => 'registered',
            'order'   => 'DESC',
            'fields'  => 'ID',
            'count_total' => true,
        ];
        if ($typeRestrictedIds !== null) {
            $args['include'] = $typeRestrictedIds;
        }
        if ($q !== '') {
            // Bound the query string to a sane length so a deliberately
            // pathological search doesn't blow up the LIKE planner.
            $needle = mb_substr($q, 0, 64);
            $args['search']         = '*' . esc_sql($needle) . '*';
            $args['search_columns'] = ['user_login', 'display_name', 'user_nicename'];
        }

        $query = new \WP_User_Query($args);
        /** @var list<int|numeric-string> $rawIds */
        $rawIds = $query->get_results();
        $userIds = array_map('intval', $rawIds);

        // Prefetch the per-user signals that would otherwise N+1 across
        // the page (24 rows × 3-4 single-user queries each). Each repo
        // call below is one batched SQL keyed on `IN (userIds)`, so the
        // total query budget for these signals is bounded regardless of
        // `per_page`. Trust score is request-memoized on UserViewService
        // itself (`$trustScoreCache`); we don't need a separate prefetch
        // for it — the per-row `resolveAugmentedTrustScore` lookup is a
        // PK-on-`bcc_reputation_scores` read, cheap.
        // Solid reaction id can be null when the reaction set isn't
        // seeded yet (fresh install / dev). Skip the batch query in
        // that case — getSummary defaults solids_received to 0.
        $solidId = ReactionTypeRegistry::solidId();
        $solidsReceivedCounts = $solidId === null
            ? []
            : (new PeepSoReactionRepository())->countReceivedByUsers($userIds, $solidId);

        $prefetched = [
            'follower_counts'              => PeepSoFollowerRepository::getFollowersCountForUsers($userIds),
            'primary_locals'               => PeepSoGroupRepository::getPrimaryLocalForUsers($userIds),
            'owned_pages_counts'           => UserSyncRepository::getOwnedPageCountsForUsers($userIds),
            'owned_pages_by_type'          => PeepSoPageRepository::getOwnedPageTypeCountsForUsers($userIds),
            'endorsements_received_counts' => (new EndorsementRepository())->getReceivedCountsForUsers($userIds),
            'solids_received_counts'       => $solidsReceivedCounts,
            'reviews_written_counts'       => (new VoteRepository())->countByVoters($userIds),
            'disputes_signed_counts'       => (new FlagsRepository())->countByFlaggers($userIds),
            'wallets_verified_counts'      => WalletRepository::getVerifiedCountsForUsers($userIds),
            'x_connections'                => (new XRepository())->getConnectionsForUsers($userIds),
            'github_connections'           => (new GitHubRepository())->getConnectionsForUsers($userIds),
        ];

        $userView = Plugin::instance()->userViewService();
        $items    = [];
        foreach ($userIds as $userId) {
            $summary = $userView->getSummary($userId, $viewerId, $prefetched);
            if ($summary !== null) {
                $items[] = $summary;
            }
        }

        $total      = (int) $query->get_total();
        // $perPage is guaranteed >= 1 by the bounds-check above.
        $totalPages = (int) ceil($total / $perPage);

        $payload = [
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => max(1, $totalPages),
            ],
            'type_counts' => $typeCounts,
        ];

        $response = ApiResponse::ok($payload);
        // Same posture as /directory + per-user list endpoints — short
        // shared cache that absorbs tab toggles without locking out
        // a fresh signup from showing up promptly.
        $response->header('Cache-Control', 'private, max-age=15');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    /**
     * §3.3.12 / §4.4 — composer @-mention picker.
     *
     * Auth-gated: anonymous → 401. The mention picker is a composer-
     * only surface and the composer is auth-gated, so the endpoint
     * is too. (The picker MUST NOT be a directory-discovery surface
     * for unauthed scrapers.)
     *
     * Per-viewer throttle (60/60s) bounds enumeration via repeated
     * 1-char prefix queries.
     */
    public function mentionSearch(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $qParam = $request->get_param('q');
        $q      = is_string($qParam) ? trim($qParam) : '';
        if ($q === '') {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Query parameter "q" is required.',
                400
            );
        }
        if (mb_strlen($q) > MentionSearchService::MAX_QUERY_LEN) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf(
                    'Query caps at %d characters.',
                    MentionSearchService::MAX_QUERY_LEN
                ),
                400
            );
        }

        // Throttle key is per-viewer, not per-(viewer + q) — a typing
        // burst across changing prefixes (`a`, `al`, `alic`, `alice`)
        // is the normal case. The cap counts *requests*, not unique
        // prefixes, which is what we want for enumeration defense.
        $throttleKey = "mention_search:{$viewerId}";
        if (!Throttle::allow(
            $throttleKey,
            self::MENTION_SEARCH_RATE_LIMIT,
            self::MENTION_SEARCH_RATE_WINDOW
        )) {
            return ApiResponse::error(
                'bcc_rate_limited',
                'Too many requests. Slow down.',
                429
            );
        }

        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = MentionSearchService::DEFAULT_LIMIT;
        }
        if ($limit > MentionSearchService::MAX_LIMIT) {
            $limit = MentionSearchService::MAX_LIMIT;
        }

        $items = Plugin::instance()->mentionSearchService()->search($viewerId, $q, $limit);

        $response = ApiResponse::ok(['items' => $items]);
        // Short TTL: long enough to absorb a typing burst at the
        // steady-state prefix, short enough that a recent join /
        // ban / block visibility update lands within a session.
        $response->header('Cache-Control', 'private, max-age=10');
        $response->header('Vary', 'Authorization, Cookie');
        return $response;
    }

    public function reviews(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handleListEndpoint($request, static function (
            int $userId,
            int $viewerId,
            int $page,
            int $perPage
        ): array {
            return Plugin::instance()->userReviewsService()->getReviews(
                $userId, $viewerId, $page, $perPage
            );
        });
    }

    public function disputes(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handleListEndpoint($request, static function (
            int $userId,
            int $viewerId,
            int $page,
            int $perPage
        ): array {
            return Plugin::instance()->userDisputesService()->getDisputes(
                $userId, $viewerId, $page, $perPage
            );
        });
    }

    /**
     * Shared handler shape for both reviews + disputes — same handle
     * resolution, pagination read, cache headers. The kind-specific
     * service call is injected via $compose.
     *
     * @param callable(int, int, int, int): array<string, mixed> $compose
     */
    private function handleListEndpoint(WP_REST_Request $request, callable $compose): WP_REST_Response
    {
        $handle = (string) $request->get_param('handle');

        $userId = self::resolveHandle($handle);
        if ($userId === 0) {
            return ApiResponse::error('bcc_not_found', 'Handle not found.', 404);
        }

        $page    = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');
        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = self::LIST_DEFAULT_PER_PAGE;
        }

        $viewerId = get_current_user_id();
        $payload  = $compose($userId, $viewerId, $page, $perPage);

        $response = ApiResponse::ok($payload);
        // Same posture as /feed and /users/:handle/blog — per-viewer
        // (privacy can flip the result), short shared cache to absorb
        // tab toggles but not so stale a fresh post never lands.
        $response->header('Cache-Control', 'private, max-age=15');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    public function shiftLog(WP_REST_Request $request): WP_REST_Response
    {
        $handle = (string) $request->get_param('handle');
        $weeks  = (int) $request->get_param('weeks');
        if ($weeks < 1) {
            $weeks = self::DEFAULT_WEEKS;
        }

        $userId = self::resolveHandle($handle);
        if ($userId === 0) {
            return ApiResponse::error('bcc_not_found', 'Handle not found.', 404);
        }

        $payload = Plugin::instance()->shiftLogService()->getShiftLog($userId, $weeks);

        $response = ApiResponse::ok($payload);
        // Per contract §4.4
        $response->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');

        return $response;
    }

    /**
     * Resolve a handle to a wp_users.ID.
     *
     * Primary lookup is `wp_usermeta.bcc_handle` (the §B6 canonical
     * handle). Falls back to `wp_users.user_login` for legacy accounts
     * created before §B6's handle picker landed — without this fallback
     * those accounts get a 404 on their own profile page even though
     * they exist + are logged in. WordPress enforces unique
     * `user_login` so the fallback is collision-safe.
     *
     * Returns 0 when nothing matches either lookup.
     */
    private static function resolveHandle(string $handle): int
    {
        $handle = strtolower(trim($handle));
        if ($handle === '') {
            return 0;
        }

        // get_users uses $wpdb under the hood but is the WP-canonical
        // user-lookup API — repo-only-DB rule is about raw $wpdb in
        // our code. Bounded to a single match by 'number' => 1.
        $userIds = get_users([
            'meta_key'   => 'bcc_handle',
            'meta_value' => $handle,
            'number'     => 1,
            'fields'     => 'ID',
        ]);

        if (!empty($userIds)) {
            return (int) $userIds[0];
        }

        // ── Fallback: try wp_users.user_login. ──────────────────────────
        // get_user_by('login', ...) is case-insensitive on most WP
        // collations; that matches the §B6 lowercase-only handle rule.
        $userByLogin = get_user_by('login', $handle);
        if ($userByLogin instanceof \WP_User) {
            return (int) $userByLogin->ID;
        }

        return 0;
    }
}
