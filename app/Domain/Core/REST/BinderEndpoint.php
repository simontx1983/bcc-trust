<?php
/**
 * Binder Endpoints — handles /bcc/v1/me/binder/* routes.
 *
 * Routes registered (this file):
 *   - GET    /me/binder              — viewer's binder, paginated (§C2)
 *   - GET    /me/binder/summary      — §N9 identity-snapshot summary
 *   - POST   /me/binder/pull         — pull a card (creates a follow + meta)
 *   - DELETE /me/binder/:follow_id   — unpull (flips uf_follow=0, deletes meta)
 *
 * Future Phase 3 work (no new routes; same POST):
 *   - 10-minute rolling-window batch aggregator (§C3)
 *
 * Per §C2: the binder is a UI-layer projection of PeepSo follows.
 * Mutations route through bcc-core's PeepSoFollowWriter (the canonical
 * write path) — BCC never INSERTs directly into peepso_user_followers.
 *
 * Auth: required. The binder is per-viewer; auth is checked inside
 * the handler so unauthenticated requests return the canonical
 * `{error: {code, message, status}}` envelope.
 *
 * Cache: `no-store`. The binder mutates via /pull and /unpull (when
 * those land); even in Phase 1, returning a cached response would
 * race with PeepSo's native unfollow path.
 *
 * ─────────────────────────────────────────────────────────────────
 *  Pagination convention (LOCKED — do not switch without a contract
 *  amendment; see also FeedEndpoint for the inverse rule):
 *
 *    Binder = offset pagination (page / page_size / total / total_pages)
 *    Feed   = cursor pagination (next_cursor / has_more)
 *
 *  Why: binder is a directory the user navigates by jumping pages;
 *  feed is an unbounded time-ordered stream where keyset cursors
 *  beat offset for stability + cost. Keep them separate so a future
 *  optimization on one doesn't accidentally break consumers of the
 *  other.
 * ─────────────────────────────────────────────────────────────────
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, Binder Phase 1)
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

final class BinderEndpoint
{
    private const ROUTE_NAMESPACE   = 'bcc/v1';
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE     = 50;

    // Pull / unpull rate limits migrated 2026-05-13 from hardcoded
    // class constants (Throttle::allow primitive) to the bcc-trust
    // RateLimiter buckets `pull` and `unpull`. The new path inherits
    // trust-tier multipliers + per-IP+per-user dual-bucket sliding
    // window, so legitimate batch curation by Trusted/Elite operators
    // isn't punished and subnet-coordinated farming can't multiply
    // via sock-puppets. Limits live in includes/config/limits.php.

    public static function register(): void
    {
        $instance = new self();

        // GET /me/binder — paginated read (Phase 1)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/binder',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                // Auth checked inside the handler so unauthenticated
                // requests can return the canonical error envelope.
                'permission_callback' => '__return_true',
                'args' => [
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
                ],
            ]
        );

        // GET /me/binder/summary — §N9 identity-snapshot. Registered
        // BEFORE /me/binder/(?P<follow_id>\d+) so the literal 'summary'
        // path is matched as a static route rather than coerced into
        // the numeric follow_id pattern. (The route below uses \d+ so
        // it wouldn't actually match 'summary', but ordering keeps the
        // intent explicit and protects against pattern relaxation.)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/binder/summary',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'summary'],
                'permission_callback' => '__return_true',
            ]
        );

        // POST /me/binder/pull — create a follow + pull_meta (Phase 2)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/binder/pull',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'pull'],
                'permission_callback' => '__return_true',
                'args' => [
                    'target_kind' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => \BCC\Trust\Core\Services\BinderService::VALID_TARGET_KINDS,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'target_id' => [
                        'required' => true,
                        'type'     => 'integer',
                        'minimum'  => 1,
                    ],
                ],
            ]
        );

        // DELETE /me/binder/:follow_id — unpull (Phase 2)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/binder/(?P<follow_id>\d+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'unpull'],
                'permission_callback' => '__return_true',
                'args' => [
                    'follow_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

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

        $payload = Plugin::instance()->binderService()->getBinder($userId, $page, $pageSize);

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

        $payload = Plugin::instance()->binderSummaryService()->compose($userId);

        $response = ApiResponse::ok($payload);
        // Per-viewer; no shared cache. Same posture as the binder
        // list: a freshly-pulled card should reflect immediately.
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    public function pull(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Trust\Core\Security\RateLimiter::allow('pull')) {
            return ApiResponse::error('bcc_rate_limited', 'Too many pulls. Please wait.', 429);
        }

        $targetKind = (string) $request->get_param('target_kind');
        $targetId   = (int) $request->get_param('target_id');

        $result = Plugin::instance()->binderService()->pull($userId, $targetKind, $targetId);

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

    public function unpull(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Trust\Core\Security\RateLimiter::allow('unpull')) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        $followId = (int) $request->get_param('follow_id');

        $result = Plugin::instance()->binderService()->unpull($userId, $followId);

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
