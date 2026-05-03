<?php
/**
 * Posts Endpoint
 *
 * Routes:
 *   - POST   /bcc/v1/posts                  — create a status post or §D2 review
 *   - DELETE /bcc/v1/me/reviews/(?P<id>\d+) — remove the viewer's review on a page
 *
 * V1 scope: status posts (open to all signed-in viewers), reviews
 * (gated on Level 2 + reputation tier ≥ neutral via FeatureAccessService),
 * and §D6 blog posts (open to all signed-in viewers; long-form content
 * with an excerpt that surfaces on the Floor and a full_text that
 * surfaces in the per-user blog tab). Disputes and post-as-entity
 * remain V1.5/V2 per §P1.
 *
 * Auth: required (401 anonymously). The body shape mirrors the
 * §D1 Composer's contract — `kind` is reserved for future expansion;
 * V1 only accepts 'status'.
 *
 * Response is intentionally minimal: a feed_id pointer + the wp_post
 * ID and act_id. The frontend invalidates its cached feed query and
 * refetches via FeedRankingService — that's the single source of the
 * fully-hydrated FeedItem (avoids duplicating reactions/permissions/
 * social_proof hydration here).
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §D1 status composer)
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

final class PostsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/reviews/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'removeReview'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/posts',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'create'],
                'permission_callback' => '__return_true',
                'args' => [
                    'kind' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => static function ($value): string {
                            return is_string($value) ? sanitize_key($value) : '';
                        },
                    ],
                    'content' => [
                        'required'          => true,
                        'type'              => 'string',
                        // No wp_kses here — let PeepSo's add_post handle
                        // escaping (it htmlspecialchars's content as part
                        // of its canonical write path). Sanitizing twice
                        // would double-encode user input.
                    ],
                    // Review-only fields. The handler validates per-kind
                    // so these stay optional at the route level.
                    'target_page_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'grade' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => static function ($value): string {
                            return is_string($value) ? sanitize_key($value) : '';
                        },
                    ],
                    // Blog-only field. `content` carries the full_text;
                    // `excerpt` is the Floor-context teaser. The service
                    // validates lengths per §D6.
                    'excerpt' => [
                        'required'          => false,
                        'type'              => 'string',
                    ],
                ],
            ]
        );
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $kind = (string) ($request->get_param('kind') ?? 'status');
        if ($kind === '') {
            $kind = 'status';
        }

        $content = (string) $request->get_param('content');
        $service = Plugin::instance()->postsService();

        if ($kind === 'status') {
            $result = $service->createStatus($viewerId, $content);
        } elseif ($kind === 'review') {
            $targetPageId = (int) $request->get_param('target_page_id');
            $grade        = (string) ($request->get_param('grade') ?? '');
            $result       = $service->createReview($viewerId, $targetPageId, $grade, $content);
        } elseif ($kind === 'blog') {
            // §D6 — `content` carries the full_text; `excerpt` is the
            // Floor-context teaser. Service enforces length bounds.
            $excerpt = (string) ($request->get_param('excerpt') ?? '');
            $result  = $service->createBlog($viewerId, $excerpt, $content);
        } else {
            // Disputes / post-as-entity are explicit V1.5/V2 work —
            // reject unknown kinds with a clear contract.
            return ApiResponse::error(
                'bcc_invalid_request',
                'Unsupported post kind. V1 accepts "status", "review", or "blog".',
                400
            );
        }

        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = self::statusForCode($code);
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), $status);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function removeReview(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $pageId = (int) $request->get_param('id');
        $result = Plugin::instance()->postsService()->removeReview($viewerId, $pageId);

        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = self::statusForCode($code);
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), $status);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function statusForCode(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'    => 401,
            'bcc_forbidden'       => 403,
            'bcc_invalid_request' => 400,
            'bcc_rate_limited'    => 429,
            'bcc_unavailable'     => 503,
            default               => 400,
        };
    }
}
