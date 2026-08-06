<?php
/**
 * User Endorsements REST Endpoint
 *
 * Returns endorsements given by the current user with page details and stats.
 * Consumed by the headless Next.js frontend (bcc-frontend).
 *
 * Routes:
 *   GET /bcc/v1/endorsements/mine        — list current user's endorsements
 *   GET /bcc/v1/endorsements/mine/stats   — aggregate stats
 *
 * The per-handle public read (`/users/:handle/endorsements`) lives in
 * UsersEndpoint per the `/users/:handle/*` convention. Both endpoints
 * call `EndorsementService::hydrateEndorsementItems()` to emit the
 * same row shape — single source of trust per §A4.
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

final class UserEndorsementsEndpoint
{
    public static function register(): void
    {
        register_rest_route('bcc/v1', '/endorsements/mine', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handleList'],
            'permission_callback' => function () {
                return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
            },
            'args'                => [
                'limit' => [
                    'type'    => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
        ]);

        register_rest_route('bcc/v1', '/endorsements/mine/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handleStats'],
            'permission_callback' => function () {
                return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
            },
        ]);
    }

    /**
     * GET /bcc/v1/endorsements/mine
     */
    public static function handleList(WP_REST_Request $request): WP_REST_Response
    {
        // Canonical error envelope (not a bare {message}) so the
        // Envelope passes it through as {error:{...}} and the 429
        // Retry-After injection engages instead of SUCCESS-wrapping.
        if (!\BCC\Core\Security\Throttle::allow('endorsements_mine', 30, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $user_id = get_current_user_id();
        $limit   = (int) $request->get_param('limit');

        $service      = Plugin::instance()->endorsementService();
        $endorsements = $service->getUserEndorsements($user_id, $limit);
        $items        = $service->hydrateEndorsementItems($endorsements);

        return rest_ensure_response([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    /**
     * GET /bcc/v1/endorsements/mine/stats
     */
    public static function handleStats(WP_REST_Request $request): WP_REST_Response
    {
        // Same canonical 429 envelope as handleList.
        if (!\BCC\Core\Security\Throttle::allow('endorsements_mine_stats', 30, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $user_id = get_current_user_id();
        $stats   = Plugin::instance()->endorsementService()
            ->getUserEndorsementStats($user_id);

        return rest_ensure_response($stats);
    }
}
