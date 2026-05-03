<?php
/**
 * Celebrations Endpoint — handles /bcc/v1/me/celebrations/* routes.
 *
 * Routes:
 *   - GET  /me/celebrations/pending   — read the pending Heavy
 *                                       celebration (rank-up, etc.)
 *   - POST /me/celebrations/consume   — clear the stash after the
 *                                       toast renders
 *
 * Both routes are auth-required; auth is checked inside the handler so
 * unauthenticated calls return the canonical envelope. Cache:
 * `private, no-store` on both — per-viewer state that changes on
 * consume.
 *
 * Why two routes (read + consume) vs. a single read-and-clear:
 *   - Render-and-consume races: the frontend mounts the toast, fires
 *     the animation, THEN calls consume. If the user's tab crashes
 *     mid-animation, the celebration survives to the next session.
 *   - Read failures shouldn't drop celebrations: a 500 on the GET
 *     would otherwise eat the celebration silently.
 *
 * The stash is single-slot per §O1.2 — a second celebration that
 * lands before consume overwrites the first. Acceptable: rank-ups
 * are rare, and stacking Heavy moments would dilute them.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §O1.2)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\CelebrationStash;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CelebrationsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/celebrations/pending',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'pending'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/celebrations/consume',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'consume'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function pending(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $stashed = CelebrationStash::peek($viewerId);
        $payload = [
            'celebration' => $stashed === null ? null : [
                'kind'  => $stashed['kind'],
                'label' => $stashed['label'],
                'icon'  => $stashed['icon'],
            ],
        ];

        $response = ApiResponse::ok($payload);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    public function consume(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Idempotent — `clear` returns false when nothing was stashed,
        // but that's still a 200 from the caller's perspective. The
        // contract is "after this call, no celebration is pending."
        CelebrationStash::clear($viewerId);

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
