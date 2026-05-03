<?php
/**
 * Feed Endpoints — handles /bcc/v1/feed/* routes.
 *
 * Phase 1 routes registered:
 *   - GET /feed/hot — global trending / zero-follow fallback (§F2)
 *   - GET /feed     — personalized feed, three scopes (§N6)
 *
 * Future Phase 1 routes (will register here when they land):
 *   - GET /locals/:id/feed — Local-scoped feed
 *
 * All feed routes route through FeedRankingService (per §F3 — one brain).
 *
 * Cache policy:
 *   - /feed/hot — public, short TTL with stale-while-revalidate (anonymous-cacheable, SEO-relevant)
 *   - /feed     — private, no shared cache (per-viewer scope filtering + auth)
 *
 * ─────────────────────────────────────────────────────────────────
 *  Pagination convention (LOCKED — see also BinderEndpoint for the
 *  inverse rule):
 *
 *    Feed   = cursor pagination (next_cursor / has_more)
 *    Binder = offset pagination (page / page_size / total / total_pages)
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
