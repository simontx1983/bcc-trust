<?php
/**
 * ReviewRepliesEndpoint — F-345 review replies.
 *
 * Routes (all on one path; `{id}` is the review's id, the `id` the Reviews
 * lists already return — the vote id):
 *   - POST   /reviews/{id}/reply  {body} → 201 {reply}
 *   - PATCH  /reviews/{id}/reply  {body} → 200 {reply}
 *   - DELETE /reviews/{id}/reply         → 200 {deleted: true}
 *
 * Auth: required. Rules live in ReviewReplyService.
 * Cache: `no-store`.
 *
 * @package BCC\Trust\Core\REST
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

final class ReviewRepliesEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();
        $idArg    = ['required' => true, 'type' => 'integer', 'minimum' => 1];
        $bodyArg  = ['required' => true, 'type' => 'string'];

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/reviews/(?P<id>\d+)/reply',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$instance, 'create'],
                    'permission_callback' => '__return_true',
                    'args'                => ['id' => $idArg, 'body' => $bodyArg],
                ],
                [
                    'methods'             => 'PATCH',
                    'callback'            => [$instance, 'update'],
                    'permission_callback' => '__return_true',
                    'args'                => ['id' => $idArg, 'body' => $bodyArg],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [$instance, 'delete'],
                    'permission_callback' => '__return_true',
                    'args'                => ['id' => $idArg],
                ],
            ]
        );
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        $result   = Plugin::instance()->reviewReplyService()->create(
            $viewerId,
            (int) $request->get_param('id'),
            (string) $request->get_param('body')
        );
        return self::respond($result, $viewerId, 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        $result   = Plugin::instance()->reviewReplyService()->update(
            $viewerId,
            (int) $request->get_param('id'),
            (string) $request->get_param('body')
        );
        return self::respond($result, $viewerId, 200);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        $result   = Plugin::instance()->reviewReplyService()->delete(
            $viewerId,
            (int) $request->get_param('id')
        );
        return self::respond($result, $viewerId, 200);
    }

    /** @param array<string, mixed> $result */
    private static function respond(array $result, int $viewerId, int $okStatus): WP_REST_Response
    {
        if (isset($result['error'])) {
            $code = (string) $result['error'];
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), self::statusFor($code));
        }

        if (isset($result['reply']) && is_array($result['reply'])) {
            /** @var array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string} $reply */
            $reply           = $result['reply'];
            $result['reply'] = Plugin::instance()->reviewReplyService()->withAuthor($reply, $viewerId, null);
        }

        $resp = ApiResponse::ok($result, $okStatus);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    private static function statusFor(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'    => 401,
            'bcc_forbidden'       => 403,
            'bcc_not_found'       => 404,
            'bcc_conflict'        => 409,
            'bcc_invalid_request' => 422,
            'bcc_rate_limited'    => 429,
            'bcc_unavailable'     => 503,
            default               => 500,
        };
    }
}
