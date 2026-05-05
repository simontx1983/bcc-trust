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

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
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

    public static function register(): void
    {
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

        $viewerId = get_current_user_id();

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

        $userView = Plugin::instance()->userViewService();
        $items    = [];
        foreach ($userIds as $userId) {
            $summary = $userView->getSummary($userId, $viewerId);
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
        ];

        $response = ApiResponse::ok($payload);
        // Same posture as /directory + per-user list endpoints — short
        // shared cache that absorbs tab toggles without locking out
        // a fresh signup from showing up promptly.
        $response->header('Cache-Control', 'private, max-age=15');
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
