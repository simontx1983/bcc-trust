<?php
/**
 * GroupJoinRequestsEndpoint — F-272 routes for getting into closed and
 * secret plain groups (§4.7.3). Thin: throttle, delegate to
 * GroupJoinRequestService, map its result.
 *
 *   Requester (closed groups):
 *     POST /me/groups/{id}/request-join
 *     POST /me/groups/{id}/request-join/cancel
 *   Invitee:
 *     GET  /me/group-invites
 *     POST /me/groups/{id}/invite/accept
 *     POST /me/groups/{id}/invite/decline
 *   Owner / manager:
 *     GET  /me/groups/{id}/requests
 *     POST /me/groups/{id}/requests/{user_id}/approve
 *     POST /me/groups/{id}/requests/{user_id}/deny
 *     GET  /me/groups/{id}/invites
 *     POST /me/groups/{id}/invites            body: { user_id }
 *     POST /me/groups/{id}/invites/{user_id}/revoke
 *
 * @package BCC\Trust\Core\REST
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\GroupJoinRequestService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupJoinRequestsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    private const GROUP  = '/me/groups/(?P<id>\d+)';
    private const MEMBER = '(?P<user_id>\d+)';

    public static function register(): void
    {
        $self = new self();
        $id   = ['id' => ['required' => true, 'sanitize_callback' => 'absint']];
        $both = $id + ['user_id' => ['required' => true, 'sanitize_callback' => 'absint']];
        $page = $id + [
            'offset' => ['required' => false, 'default' => 0, 'sanitize_callback' => 'absint'],
            'limit'  => ['required' => false, 'default' => GroupJoinRequestService::DEFAULT_LIMIT, 'sanitize_callback' => 'absint'],
        ];

        // One literal register_rest_route() per path: the contract-parity
        // guard resolves routes statically and cannot see through a loop.
        register_rest_route(self::ROUTE_NAMESPACE, self::GROUP . '/request-join', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$self, 'postRequestJoin'],
            'permission_callback' => '__return_true',
            'args'                => $id,
        ]);
        register_rest_route(self::ROUTE_NAMESPACE, self::GROUP . '/request-join/cancel', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$self, 'postCancelRequest'],
            'permission_callback' => '__return_true',
            'args'                => $id,
        ]);
        register_rest_route(self::ROUTE_NAMESPACE, self::GROUP . '/invite/accept', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$self, 'postAcceptInvite'],
            'permission_callback' => '__return_true',
            'args'                => $id,
        ]);
        register_rest_route(self::ROUTE_NAMESPACE, self::GROUP . '/invite/decline', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$self, 'postDeclineInvite'],
            'permission_callback' => '__return_true',
            'args'                => $id,
        ]);
        register_rest_route(self::ROUTE_NAMESPACE, self::GROUP . '/requests', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$self, 'getRequests'],
            'permission_callback' => '__return_true',
            'args'                => $page,
        ]);
        register_rest_route(self::ROUTE_NAMESPACE, self::GROUP . '/requests/' . self::MEMBER . '/approve', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$self, 'postApprove'],
            'permission_callback' => '__return_true',
            'args'                => $both,
        ]);
        register_rest_route(self::ROUTE_NAMESPACE, self::GROUP . '/requests/' . self::MEMBER . '/deny', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$self, 'postDeny'],
            'permission_callback' => '__return_true',
            'args'                => $both,
        ]);
        register_rest_route(self::ROUTE_NAMESPACE, self::GROUP . '/invites/' . self::MEMBER . '/revoke', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$self, 'postRevokeInvite'],
            'permission_callback' => '__return_true',
            'args'                => $both,
        ]);

        // GET + POST on one path: register both handlers together.
        register_rest_route(self::ROUTE_NAMESPACE, self::GROUP . '/invites', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$self, 'getInvites'],
                'permission_callback' => '__return_true',
                'args'                => $page,
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$self, 'postInvite'],
                'permission_callback' => '__return_true',
                'args'                => $id + ['user_id' => ['required' => true, 'sanitize_callback' => 'absint']],
            ],
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, '/me/group-invites', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$self, 'getMyInvites'],
            'permission_callback' => '__return_true',
            'args'                => [
                'offset' => ['required' => false, 'default' => 0, 'sanitize_callback' => 'absint'],
                'limit'  => ['required' => false, 'default' => GroupJoinRequestService::DEFAULT_LIMIT, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    // ── handlers ────────────────────────────────────────────────────────

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function postRequestJoin(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_request', 10, 60, fn (int $me): array =>
            self::service()->requestJoin($me, self::int($request, 'id')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function postCancelRequest(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_request', 10, 60, fn (int $me): array =>
            self::service()->cancelRequest($me, self::int($request, 'id')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function postAcceptInvite(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_invite_answer', 20, 60, fn (int $me): array =>
            self::service()->acceptInvite($me, self::int($request, 'id')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function postDeclineInvite(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_invite_answer', 20, 60, fn (int $me): array =>
            self::service()->declineInvite($me, self::int($request, 'id')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function getRequests(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_pending_list', 60, 60, fn (int $me): array =>
            self::service()->listPending($me, self::int($request, 'id'), PeepSoGroupRepository::PENDING_REQUEST, self::int($request, 'offset'), self::int($request, 'limit')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function postApprove(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_decide', 60, 60, fn (int $me): array =>
            self::service()->approve($me, self::int($request, 'user_id'), self::int($request, 'id')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function postDeny(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_decide', 60, 60, fn (int $me): array =>
            self::service()->deny($me, self::int($request, 'user_id'), self::int($request, 'id')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function getInvites(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_pending_list', 60, 60, fn (int $me): array =>
            self::service()->listPending($me, self::int($request, 'id'), PeepSoGroupRepository::PENDING_INVITE, self::int($request, 'offset'), self::int($request, 'limit')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function postInvite(WP_REST_Request $request): WP_REST_Response
    {
        $me = get_current_user_id();
        // Invitations notify another member: a daily cap on top of the burst
        // bucket keeps an inviter from using them as a spam channel.
        if ($me > 0 && !\BCC\Core\Security\Throttle::allow('group_invite_daily:' . $me, 50, DAY_IN_SECONDS)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many invitations today.', 429);
        }
        return $this->run('group_invite', 10, 60, fn (int $me): array =>
            self::service()->invite($me, self::int($request, 'user_id'), self::int($request, 'id')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function postRevokeInvite(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_decide', 60, 60, fn (int $me): array =>
            self::service()->revokeInvite($me, self::int($request, 'user_id'), self::int($request, 'id')));
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    public function getMyInvites(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run('group_pending_list', 60, 60, fn (int $me): array =>
            self::service()->listMyInvites($me, self::int($request, 'offset'), self::int($request, 'limit')));
    }

    // ── plumbing ────────────────────────────────────────────────────────

    /**
     * Sign-in check, then the throttle (before any permission read), then
     * the service call, then the mechanical result → response mapping.
     *
     * @param callable(int): (array{ok: true, data: array<string, mixed>}|array{error: string, message: string, status: int}) $call
     */
    private function run(string $bucket, int $max, int $window, callable $call): WP_REST_Response
    {
        $me = get_current_user_id();
        if ($me <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        if (!\BCC\Core\Security\Throttle::allow($bucket . ':' . $me, $max, $window)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $result = $call($me);
        if (isset($result['error'])) {
            return ApiResponse::error($result['error'], $result['message'], $result['status']);
        }
        return ApiResponse::ok($result['data']);
    }

    /** @param WP_REST_Request<array<string, mixed>> $request */
    private static function int(WP_REST_Request $request, string $key): int
    {
        $value = $request->get_param($key);
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function service(): GroupJoinRequestService
    {
        return Plugin::instance()->groupJoinRequestService();
    }
}
