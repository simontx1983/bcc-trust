<?php
/**
 * UserFollowsEndpoint — §3.1 Watching tab data plane.
 *
 *   GET /bcc/v1/users/{slug}/followers   "Being Watched"
 *     People who follow this member.
 *
 *   GET /bcc/v1/users/{slug}/following   "Keeping Tabs"
 *     People this member follows.
 *
 * Both routes share a single instance + composer (UserFollowsService).
 * Privacy gate runs in the service; a watching_hidden profile yields
 * 403 with reason_code = "watching_hidden" so the frontend can render
 * the correct empty-state instead of a generic error.
 *
 * Response shape mirrors `/groups/:id/members`:
 *   { items: MemberSummary[], pagination: { offset, limit, total, has_more } }
 *
 * Per §1 the only DB seam is repositories. Per §9 every response is
 * envelope-wrapped via ApiResponse.
 *
 * @package BCC\Trust\Core\REST
 * @since   §3.1 Watching tab (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Services\UserFollowsService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\UserSlugResolver;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class UserFollowsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<slug>' . UsersEndpoint::HANDLE_PATTERN . ')/followers',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'listFollowers'],
                'permission_callback' => '__return_true',
                'args' => self::pageArgs(),
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/users/(?P<slug>' . UsersEndpoint::HANDLE_PATTERN . ')/following',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'listFollowing'],
                'permission_callback' => '__return_true',
                'args' => self::pageArgs(),
            ]
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function pageArgs(): array
    {
        return [
            'slug'   => ['required' => true, 'sanitize_callback' => 'sanitize_user'],
            'offset' => [
                'required'          => false,
                'sanitize_callback' => 'absint',
                'default'           => 0,
            ],
            'limit'  => [
                'required'          => false,
                'sanitize_callback' => 'absint',
                'default'           => UserFollowsService::DEFAULT_LIMIT,
            ],
        ];
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listFollowers(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond($request, UserFollowsService::RELATION_FOLLOWERS);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function listFollowing(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond($request, UserFollowsService::RELATION_FOLLOWING);
    }

    private function respond(WP_REST_Request $request, string $relation): WP_REST_Response
    {
        $slug     = (string) $request->get_param('slug');
        $targetId = UserSlugResolver::resolve($slug);
        if ($targetId === 0) {
            return ApiResponse::error('bcc_not_found', 'User not found.', 404);
        }

        $offset = (int) $request->get_param('offset');
        $limit  = (int) $request->get_param('limit');
        $viewer = get_current_user_id();

        $service = new UserFollowsService();
        $result  = $relation === UserFollowsService::RELATION_FOLLOWERS
            ? $service->listFollowers($targetId, $viewer, $offset, $limit)
            : $service->listFollowing($targetId, $viewer, $offset, $limit);

        if (isset($result['error']) && is_string($result['error'])) {
            // Privacy sentinel — mirror the /groups/:id/members 403 shape
            // so the frontend's error pipeline handles both surfaces with
            // one branch.
            $message = isset($result['message']) && is_string($result['message'])
                ? $result['message']
                : 'Hidden.';
            return ApiResponse::error($result['error'], $message, 403);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'private, max-age=30');
        return $response;
    }

}
