<?php
/**
 * Feed Endpoints — handles /bcc/v1/feed/* routes.
 *
 * Phase 1 routes registered:
 *   - GET /feed/hot — global trending / zero-follow fallback (§F2)
 *   - GET /feed     — personalized feed, three scopes (§N6)
 *
 * Locals do NOT get their own feed route. A Local is a semantic wrapper
 * around a PeepSo group; the Local detail page consumes
 * GET /bcc/v1/groups/:id/feed (registered in GroupsDetailEndpoint) via
 * the same FeedRankingService::getGroupFeed() path. See §4.7 composition
 * note in docs/api-contract-v1.md.
 *
 * All feed routes route through FeedRankingService (per §F3 — one brain).
 *
 * Cache policy:
 *   - /feed/hot — public, short TTL with stale-while-revalidate (anonymous-cacheable, SEO-relevant)
 *   - /feed     — private, no shared cache (per-viewer scope filtering + auth)
 *
 * ─────────────────────────────────────────────────────────────────
 *  Pagination convention (LOCKED — see also WatchingEndpoint for the
 *  inverse rule):
 *
 *    Feed     = cursor pagination (next_cursor / has_more)
 *    Watching = offset pagination (page / page_size / total / total_pages)
 *
 *  Feed responses MUST emit cursor pagination. Do not "optimize" to
 *  offset later — keyset cursors are required for stable feed paging
 *  under continuous insert pressure.
 * ─────────────────────────────────────────────────────────────────
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

final class FeedEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const DEFAULT_LIMIT   = 20;
    private const MAX_LIMIT       = 50;

    /** @var list<string> */
    private const VALID_SCOPES = ['for_you', 'following', 'signals'];

    public static function register(): void
    {
        $instance = new self();

        $sharedArgs = [
            'cursor' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'limit' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => self::DEFAULT_LIMIT,
                'minimum'           => 1,
                'maximum'           => self::MAX_LIMIT,
                'sanitize_callback' => 'absint',
            ],
        ];

        // GET /feed/hot — anonymous-OK trending feed (§F2)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/hot',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'hot'],
                'permission_callback' => '__return_true',
                'args'                => $sharedArgs,
            ]
        );

        // GET /feed/tag — anonymous-OK hashtag feed. Same posture +
        // envelope as /feed/hot; narrowed to one hashtag (§F3 — one brain).
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/tag',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'tagFeed'],
                'permission_callback' => '__return_true',
                'args'                => $sharedArgs + [
                    'tag' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // GET /feed — auth-required personalized feed (§N6)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'feed'],
                // Auth is checked inside the handler so unauthenticated
                // requests can return the canonical error envelope.
                'permission_callback' => '__return_true',
                'args'                => $sharedArgs + [
                    'scope' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => 'for_you',
                        'enum'              => self::VALID_SCOPES,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );

        // GET /feed/{id} — single-activity permalink, anonymous-OK
        // (numeric-only so it never collides with /feed/hot, /feed/tag).
        // Backs the post-detail page; the frontend already calls this.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'feedItem'],
                'permission_callback' => '__return_true',
            ]
        );

        // GET /feed/{code} — shortcode permalink resolver, anonymous-OK.
        // Exactly 8 ASCII letters, which is disjoint from the numeric
        // route above (\d+) AND from /feed/hot + /feed/tag (3-letter
        // literals can never match {8}); PostShortcodeRepository's
        // RESERVED list backstops that by construction. Backs the
        // /u/{handle}/post/{code} post-detail page.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/(?P<id>[a-zA-Z]{8})',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'feedItemByShortcode'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function hot(WP_REST_Request $request): WP_REST_Response
    {
        [$cursor, $limit] = $this->paginationArgs($request);

        $payload = Plugin::instance()->feedRankingService()->getHotFeed($cursor, $limit);

        $response = ApiResponse::ok($payload);
        // §F2 hot feed is anonymous-cacheable; volatile but tolerable to
        // serve slightly stale to anonymous viewers.
        $response->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=120');

        return $response;
    }

    public function tagFeed(WP_REST_Request $request): WP_REST_Response
    {
        // Public, same posture as the anon hot feed. 0 = anonymous viewer.
        $viewerId = get_current_user_id();

        [$cursor, $limit] = $this->paginationArgs($request);

        // Strip a leading '#' the client may have passed; the repository
        // LIKE rebuilds the '#tag' token itself.
        $tagParam = $request->get_param('tag');
        $tag      = is_string($tagParam) ? ltrim(trim($tagParam), '#') : '';
        if ($tag === '') {
            return ApiResponse::error('bcc_invalid_request', 'A tag is required.', 400);
        }

        $payload = Plugin::instance()->feedRankingService()->getTagFeed($viewerId, $tag, $cursor, $limit);

        $response = ApiResponse::ok($payload);
        // Same cache style as the existing personalized feed: viewer state
        // (viewer_reaction, can_report) varies the response, so no shared cache.
        $response->header('Cache-Control', 'private, max-age=15');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    public function feed(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        [$cursor, $limit] = $this->paginationArgs($request);

        $scopeParam = $request->get_param('scope');
        $scope      = is_string($scopeParam) && in_array($scopeParam, self::VALID_SCOPES, true)
            ? $scopeParam
            : 'for_you';

        /** @var 'for_you'|'following'|'signals' $scope */
        $payload = Plugin::instance()->feedRankingService()->getFeed($viewerId, $scope, $cursor, $limit);

        $response = ApiResponse::ok($payload);
        // Per-viewer scope filtering — no shared cache.
        $response->header('Cache-Control', 'private, max-age=15');
        // The scope tag varies the response; keep CDNs honest.
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    /**
     * GET /feed/{id} — single-activity permalink (post-detail). Same
     * hydration + visibility gates as the list feed (§F3 — one brain) via
     * FeedRankingService::getActivityById(); 404s rather than leaking a
     * not-found/not-visible distinction to the client.
     */
    public function feedItem(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respondWithFeedItem((int) $request->get_param('id'));
    }

    /**
     * GET /feed/{code} — 8-letter shortcode permalink. Resolves the code
     * via PostShortcodeRepository, then shares feedItem()'s body — same
     * gates, same envelope, same 404 posture (unknown code and hidden /
     * not-visible item are indistinguishable to the client).
     */
    public function feedItemByShortcode(WP_REST_Request $request): WP_REST_Response
    {
        $code  = $request->get_param('id');
        $actId = is_string($code)
            ? Plugin::instance()->postShortcodeRepository()->resolveActId($code)
            : null;

        if ($actId === null) {
            return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
        }

        return $this->respondWithFeedItem($actId);
    }

    /**
     * Shared single-activity body for the numeric + shortcode permalink
     * routes — one gate path, one envelope, one cache posture.
     */
    private function respondWithFeedItem(int $actId): WP_REST_Response
    {
        $viewerId = get_current_user_id();

        $item = Plugin::instance()->feedRankingService()->getActivityById($actId, $viewerId);
        if ($item === null) {
            return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
        }

        $response = ApiResponse::ok($item);
        $response->header('Cache-Control', 'private, max-age=15');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private function paginationArgs(WP_REST_Request $request): array
    {
        $cursorParam = $request->get_param('cursor');
        $cursor      = is_string($cursorParam) && $cursorParam !== '' ? $cursorParam : null;

        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        return [$cursor, $limit];
    }
}
