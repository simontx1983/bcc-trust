<?php
/**
 * Watching Endpoints — canonical /bcc/v1/me/watching/* routes.
 *
 * Routes:
 *   - GET    /me/watching                   — viewer's watchlist, paginated (§C2)
 *   - GET    /me/watching/summary           — §N9 identity-snapshot summary
 *   - POST   /me/watching/watch             — watch a card (creates follow + meta)
 *   - DELETE /me/watching/:follow_id        — unwatch (flips uf_follow=0, deletes meta)
 *
 * Per §C2: the watchlist is a UI-layer projection of PeepSo follows.
 * Mutations route through bcc-core's PeepSoFollowWriter (the canonical
 * write path) — BCC never INSERTs directly into peepso_user_followers.
 *
 * Auth: required. The watchlist is per-viewer; auth is checked inside
 * the handler so unauthenticated requests return the canonical
 * `{error: {code, message, status}}` envelope.
 *
 * Cache: `no-store`. The watchlist mutates via /watch and /unwatch;
 * returning a cached response would race with PeepSo's native unfollow path.
 *
 * ─────────────────────────────────────────────────────────────────
 *  Pagination convention (LOCKED — do not switch without a contract
 *  amendment; see also FeedEndpoint for the inverse rule):
 *
 *    Watching = offset pagination (page / page_size / total / total_pages)
 *    Feed     = cursor pagination (next_cursor / has_more)
 *
 *  Why: watchlist is a directory the user navigates by jumping pages;
 *  feed is an unbounded time-ordered stream where keyset cursors
 *  beat offset for stability + cost. Keep them separate so a future
 *  optimization on one doesn't accidentally break consumers of the
 *  other.
 * ─────────────────────────────────────────────────────────────────
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, Watching Phase 1)
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

final class WatchingEndpoint
{
    private const ROUTE_NAMESPACE   = 'bcc/v1';
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE     = 50;

    // Watch / unwatch rate limits migrated 2026-05-13 from hardcoded
    // class constants (Throttle::allow primitive) to the bcc-trust
    // RateLimiter buckets `watch` and `unwatch` (see
    // includes/config/limits.php). The path inherits trust-tier
    // multipliers + per-IP+per-user dual-bucket sliding window, so
    // legitimate batch curation by Trusted/Elite operators isn't
    // punished and subnet-coordinated farming can't multiply via
    // sock-puppets.

    public static function register(): void
    {
        $instance = new self();

        // ── Canonical /me/watching/* family ──────────────────────────

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/watching',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                'permission_callback' => '__return_true',
                'args'                => self::listArgs(),
            ]
        );

        // /me/watching/summary registered BEFORE /me/watching/(?P<follow_id>\d+)
        // so the literal 'summary' path is matched as a static route
        // rather than coerced into the numeric follow_id pattern.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/watching/summary',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'summary'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/watching/watch',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'watch'],
                'permission_callback' => '__return_true',
                'args'                => self::watchArgs(),
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/watching/(?P<follow_id>\d+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'unwatch'],
                'permission_callback' => '__return_true',
                'args'                => self::unwatchArgs(),
            ]
        );
    }

    /**
     * Arg spec for the list (GET /me/watching).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function listArgs(): array
    {
        return [
            'page' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'page_size' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => self::DEFAULT_PAGE_SIZE,
                'minimum'           => 1,
                'maximum'           => self::MAX_PAGE_SIZE,
                'sanitize_callback' => 'absint',
            ],
            // v1.76 additive (§4.5): `include=cards` attaches the full
            // §3.2 Card view-model to every item. Absent → the response
            // is byte-identical to pre-v1.76 (no `card` key at all).
            // The route arg max on page_size stays MAX_PAGE_SIZE; the
            // hydrated clamp (HYDRATED_MAX_PAGE_SIZE) is applied
            // silently in the service and echoed in pagination.
            'include' => [
                'required'          => false,
                'type'              => 'string',
                'enum'              => ['cards'],
                'sanitize_callback' => 'sanitize_key',
            ],
        ];
    }

    /**
     * Arg spec for the watch action (POST /me/watching/watch).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function watchArgs(): array
    {
        return [
            'target_kind' => [
                'required'          => true,
                'type'              => 'string',
                'enum'              => \BCC\Trust\Core\Services\WatchingService::VALID_TARGET_KINDS,
                'sanitize_callback' => 'sanitize_key',
            ],
            'target_id' => [
                'required' => true,
                'type'     => 'integer',
                'minimum'  => 1,
            ],
        ];
    }

    /**
     * Arg spec for the unwatch action (DELETE /me/watching/{follow_id}).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function unwatchArgs(): array
    {
        return [
            'follow_id' => [
                'required'          => true,
                'type'              => 'integer',
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            // V1.6 dual-source: 'peepso' (default, backward-compatible)
            // routes to PeepSoFollowWriter; 'page' routes to the
            // bcc_page_follows table. The follow id space is auto-
            // increment in both tables and can collide, so this
            // discriminator is required for unwatch on page-follow
            // items. Frontend echoes the value back from
            // WatchingItem.follow_source.
            'source' => [
                'required'          => false,
                'type'              => 'string',
                'enum'              => ['peepso', 'page'],
                'sanitize_callback' => 'sanitize_key',
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Handlers
    // ──────────────────────────────────────────────────────────────────

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $page     = (int) $request->get_param('page');
        $pageSize = (int) $request->get_param('page_size');
        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        // Only the literal 'cards' opts in — the enum already rejects
        // anything else, so this is belt-and-braces for a missing param.
        $includeCards = ((string) $request->get_param('include') === 'cards');

        $payload = Plugin::instance()->watchingService()
            ->getWatching($userId, $page, $pageSize, $includeCards);

        $response = ApiResponse::ok($payload);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function summary(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $payload = Plugin::instance()->watchingSummaryService()->compose($userId);

        $response = ApiResponse::ok($payload);
        // Per-viewer; no shared cache. Same posture as the list:
        // a freshly-watched card should reflect immediately.
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    public function watch(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Trust\Core\Security\RateLimiter::allow('watch')) {
            return ApiResponse::error('bcc_rate_limited', 'Too many watches. Please wait.', 429);
        }

        $targetKind = (string) $request->get_param('target_kind');
        $targetId   = (int) $request->get_param('target_id');

        $result = Plugin::instance()->watchingService()->watch($userId, $targetKind, $targetId);

        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = match ($code) {
                'bcc_not_found'        => 404,
                'bcc_invalid_request'  => 400,
                'bcc_internal_error'   => 500,
                default                => 400,
            };
            return ApiResponse::error($code, (string) $result['message'], $status);
        }

        $response = ApiResponse::ok([
            'status' => $result['status'],
            'item'   => $result['item'],
        ]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function unwatch(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Trust\Core\Security\RateLimiter::allow('unwatch')) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        $followId  = (int) $request->get_param('follow_id');
        $sourceRaw = (string) $request->get_param('source');
        $source    = $sourceRaw === 'page' ? 'page' : 'peepso';

        $result = Plugin::instance()->watchingService()->unwatch($userId, $followId, $source);

        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = match ($code) {
                'bcc_not_found'        => 404,
                'bcc_invalid_request'  => 400,
                'bcc_internal_error'   => 500,
                default                => 400,
            };
            return ApiResponse::error($code, (string) $result['message'], $status);
        }

        $response = ApiResponse::ok([
            'status'    => $result['status'],
            'follow_id' => $result['follow_id'],
        ]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
