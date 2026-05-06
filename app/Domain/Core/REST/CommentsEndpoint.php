<?php
/**
 * Comments Endpoint — handles /bcc/v1/posts/:feed_id/comments routes.
 *
 * Routes registered:
 *   - GET    /posts/:feed_id/comments                — paginated list
 *   - POST   /posts/:feed_id/comments                — create
 *   - DELETE /posts/:feed_id/comments/:comment_id    — delete (own only)
 *
 * Auth posture (matches service-level rules):
 *   - GET is anonymous-friendly on non-gated posts. On
 *     holder-groups-gated posts it requires viewer membership;
 *     non-members get `bcc_forbidden`.
 *   - POST and DELETE require auth — handlers return `bcc_unauthorized`
 *     on anonymous calls.
 *
 * Cache:
 *   - GET responses use `private, no-store`. The list is per-viewer
 *     (delete permission depends on identity) and mutates on every
 *     comment write.
 *
 * @package BCC\Trust\Core\REST
 * @since v1.5 (2026-05, hybrid PeepSo-proxy comments)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\CommentService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CommentsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Default page size — same shape as NotificationsEndpoint. */
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX     = 50;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/posts/(?P<feed_id>[a-z0-9_]+)/comments',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$instance, 'list'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'feed_id' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'limit' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'cursor' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$instance, 'create'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'feed_id' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'body' => [
                            'required'          => true,
                            'type'              => 'string',
                            // No sanitize_text_field here — PeepSo's
                            // add_comment owns content sanitization
                            // (htmlspecialchars + strip_content +
                            // length cap). Pre-stripping in WP would
                            // double-encode entities and shorten
                            // intent before PeepSo sees it.
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/posts/(?P<feed_id>[a-z0-9_]+)/comments/(?P<comment_id>[a-z0-9_]+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'delete'],
                'permission_callback' => '__return_true',
                'args' => [
                    'feed_id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'comment_id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = (int) get_current_user_id();
        $feedId   = (string) $request->get_param('feed_id');

        $rawLimit = (int) ($request->get_param('limit') ?? 0);
        $limit    = $rawLimit > 0 ? min($rawLimit, self::PER_PAGE_MAX) : self::PER_PAGE_DEFAULT;

        $cursor = $request->get_param('cursor');
        $cursor = is_string($cursor) ? $cursor : null;

        $result = $this->commentService()->listByFeedId($feedId, $viewerId, $cursor, $limit);
        if (isset($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['message'] ?? 'Comments unavailable.',
                self::statusFor($result['error'])
            );
        }

        $response = ApiResponse::ok([
            'items'       => $result['items'],
            'next_cursor' => $result['next_cursor'],
        ]);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $authorId = (int) get_current_user_id();
        if ($authorId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $feedId  = (string) $request->get_param('feed_id');
        $body    = (string) ($request->get_param('body') ?? '');

        $result = $this->commentService()->createComment($feedId, $authorId, $body);
        if (isset($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['message'] ?? 'Could not post comment.',
                self::statusFor($result['error'])
            );
        }

        $response = ApiResponse::ok(['comment' => $result['comment']]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = (int) get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $feedId    = (string) $request->get_param('feed_id');
        $commentId = (string) $request->get_param('comment_id');

        $result = $this->commentService()->deleteComment($feedId, $commentId, $viewerId);
        if (isset($result['error'])) {
            return ApiResponse::error(
                $result['error'],
                $result['message'] ?? 'Could not delete comment.',
                self::statusFor($result['error'])
            );
        }

        $response = ApiResponse::ok(['comment_id' => $result['comment_id']]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private function commentService(): CommentService
    {
        return Plugin::instance()->commentService();
    }

    /**
     * Map BCC error codes to HTTP statuses. Mirrors ReactionsEndpoint /
     * PostsEndpoint conventions so the frontend's error handling stays
     * uniform across endpoints.
     */
    private static function statusFor(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'    => 401,
            'bcc_forbidden'       => 403,
            'bcc_not_found'       => 404,
            'bcc_invalid_request' => 400,
            'bcc_rate_limited'    => 429,
            'bcc_unavailable'     => 503,
            'bcc_internal_error'  => 500,
            default               => 500,
        };
    }
}
