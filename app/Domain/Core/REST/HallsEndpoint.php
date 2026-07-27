<?php
/**
 * Halls Endpoint
 *
 * Routes:
 *   - GET    /bcc/v1/halls                     — paginated catalog (§4.7)
 *   - GET    /bcc/v1/halls/(?P<slug>...)       — single Hall detail
 *   - POST   /bcc/v1/me/halls/(?P<id>\d+)/primary    — set viewer's home Hall
 *   - DELETE /bcc/v1/me/halls/primary                — clear viewer's home Hall
 *   - POST   /bcc/v1/me/halls/(?P<id>\d+)/membership — join the Hall
 *   - DELETE /bcc/v1/me/halls/(?P<id>\d+)/membership — leave the Hall
 *
 * Per contract §4.7. Read endpoints accept anonymous OR bearer auth;
 * mutation endpoints require auth and 401 anonymously. Set-primary is
 * gated on actual membership (returns bcc_forbidden if the user isn't
 * already a member of the group). Join/leave delegate to PeepSo's
 * canonical group write API via PeepSoGroupWriter (§C2 single-graph
 * rule); leaving the primary Hall atomically clears the primary
 * pointer.
 *
 * Pagination: offset envelope (page / page_size / total / total_pages)
 * per §1.5 — Halls is a directory, not a time-ordered feed.
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

final class HallsEndpoint
{
    private const ROUTE_NAMESPACE   = 'bcc/v1';
    private const ROUTE_PATH        = '/halls';
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE     = 50;

    /** Slug pattern — same as PeepSo's post_name conventions. Bounded. */
    private const SLUG_PATTERN = '[a-z0-9][a-z0-9-]{0,99}';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_PATH, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$instance, 'handle'],
            'permission_callback' => '__return_true', // anon allowed per contract §4.7
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
                'chain' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/halls/(?P<slug>' . self::SLUG_PATTERN . ')',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'showBySlug'],
                'permission_callback' => '__return_true',
                'args' => [
                    'slug' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/halls/(?P<id>\d+)/primary',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'setPrimary'],
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
            '/me/halls/primary',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'clearPrimary'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/halls/(?P<id>\d+)/membership',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$instance, 'join'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [$instance, 'leave'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        $page     = (int) $request->get_param('page');
        $pageSize = (int) $request->get_param('page_size');
        $chainRaw = $request->get_param('chain');
        $chain    = is_string($chainRaw) && $chainRaw !== '' ? $chainRaw : null;

        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        $payload = Plugin::instance()->hallsService()->getHalls(
            $viewerId,
            $page,
            $pageSize,
            $chain
        );

        $response = ApiResponse::ok($payload);
        $response->header('Cache-Control', 'public, max-age=300');

        return $response;
    }

    public function showBySlug(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');
        if ($slug === '') {
            return ApiResponse::error('bcc_invalid_request', 'Slug is required.', 400);
        }

        $viewerId = get_current_user_id();
        $payload  = Plugin::instance()->hallsService()->getHall($viewerId, $slug);

        if ($payload === null) {
            return ApiResponse::error('bcc_not_found', 'Hall not found.', 404);
        }

        $response = ApiResponse::ok($payload);
        // Same per-viewer behaviour as the directory: short cache for
        // anonymous reads, no-store when the response carries viewer
        // membership state (any logged-in viewer).
        $cacheCtl = $viewerId > 0 ? 'private, no-store' : 'public, max-age=300';
        $response->header('Cache-Control', $cacheCtl);

        return $response;
    }

    public function setPrimary(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $groupId = (int) $request->get_param('id');
        $result  = Plugin::instance()->hallsService()->setPrimaryHall($viewerId, $groupId);

        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = match ($code) {
                'bcc_unauthorized'    => 401,
                'bcc_forbidden'       => 403,
                'bcc_invalid_request' => 400,
                default               => 400,
            };
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), $status);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function clearPrimary(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $result = Plugin::instance()->hallsService()->clearPrimaryHall($viewerId);

        if (isset($result['error'])) {
            $code = (string) $result['error'];
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), 400);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function join(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Rate-limit FIRST (throttle-before-credentials rule): the cheap
        // fail-closed counter rejects floods before the suspension gate's
        // permission read. Parity with the plain-group join bucket
        // (MyGroupsEndpoint) so this membership-write door can't be used
        // to spray peepso_group_user rows.
        if (!\BCC\Core\Security\Throttle::allow('hall_join:' . $viewerId, 10, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        // Suspended accounts must not walk back into a community. The join
        // lands members via the trusted PeepSoGroupWriter door (bypassing
        // PeepSo's own UI approval), so the suspension gate has to be
        // enforced HERE — HallsService gates type/privacy but never
        // suspension. Admin bypass off: a suspended account is blocked
        // regardless of role. Parity with MyGroupsEndpoint::postJoin
        // [audit M — group-rejoin].
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($viewerId, false)) {
            return ApiResponse::error('bcc_forbidden', 'Your account is suspended.', 403);
        }

        $groupId = (int) $request->get_param('id');
        $result  = Plugin::instance()->hallsService()->joinHall($viewerId, $groupId);

        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = self::statusForCode($code);
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), $status);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function leave(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $groupId = (int) $request->get_param('id');
        $result  = Plugin::instance()->hallsService()->leaveHall($viewerId, $groupId);

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
            'bcc_not_found'       => 404,
            'bcc_invalid_request' => 400,
            'bcc_unavailable'     => 503,
            default               => 400,
        };
    }
}
