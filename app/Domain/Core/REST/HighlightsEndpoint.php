<?php
/**
 * Highlights Endpoint — handles /bcc/v1/me/highlights/* routes.
 *
 * Phase 1 routes registered:
 *   - GET  /me/highlights              — strip read (§O2 / §O2.1)
 *   - POST /me/highlights/:id/dismiss  — dismiss a single item (§O2)
 *
 * Both auth-required; auth is checked inside the handler so
 * unauthenticated calls return the canonical envelope.
 *
 * Cache: `private, no-store` on both — per-viewer + mutates on
 * dismissal / new highlight events.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §O2)
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

final class HighlightsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /**
     * Highlight id pattern: `h-{slot}-{stable_hash}`.
     * Limited to safe URL chars and capped at 128 — defense-in-depth
     * (the service ALSO validates and bounds-checks the id).
     */
    private const ID_PATTERN = '[A-Za-z0-9_-]{1,128}';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/highlights',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/highlights/(?P<id>' . self::ID_PATTERN . ')/dismiss',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'dismiss'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
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
        unset($request);

        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $payload = Plugin::instance()->highlightsService()->getHighlights($viewerId);

        $response = ApiResponse::ok($payload);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    public function dismiss(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $id = (string) $request->get_param('id');
        if ($id === '') {
            return ApiResponse::error('bcc_invalid_request', 'Highlight id is required.', 400);
        }

        $result = Plugin::instance()->highlightsService()->dismiss($viewerId, $id);
        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = $code === 'bcc_unauthorized' ? 401 : 400;
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), $status);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
