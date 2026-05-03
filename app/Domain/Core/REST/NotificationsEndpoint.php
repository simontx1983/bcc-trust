<?php
/**
 * Notifications Endpoint — handles /bcc/v1/me/notifications/* routes.
 *
 * Routes:
 *   - GET  /me/notifications                      — paginated list
 *   - GET  /me/notifications/unread-count         — bell badge count
 *   - POST /me/notifications/mark-read            — single + all
 *
 * Why a single mark-read endpoint instead of two:
 *   POST /me/notifications/mark-read with no body = mark all.
 *   Same endpoint with `{ "id": 123 }` = mark one. Single route, two
 *   modes — the frontend's bell dropdown wants the single-id path
 *   on click-through, the "Mark all read" button wants the bulk
 *   path. Splitting into two routes would just multiply surface area
 *   without changing the contract.
 *
 * All routes auth-required. Unauthenticated callers get the standard
 * `bcc_unauthorized` envelope.
 *
 * Cache: `private, no-store` everywhere. Per-viewer state, mutates
 * on every read/mark.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §I1)
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

final class NotificationsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Hard ceiling per request — protects against abuse. */
    private const PER_PAGE_MAX = 50;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/notifications',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                'permission_callback' => '__return_true',
                'args' => [
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
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/notifications/unread-count',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'unreadCount'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/notifications/mark-read',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'markRead'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = (int) BCC_NOTIFICATION_PAGE_SIZE;
        }
        if ($limit > self::PER_PAGE_MAX) {
            $limit = self::PER_PAGE_MAX;
        }

        $cursorRaw = $request->get_param('cursor');
        $cursor = is_string($cursorRaw) && $cursorRaw !== '' ? $cursorRaw : null;

        $payload = Plugin::instance()
            ->notificationViewService()
            ->getNotifications($viewerId, $limit, $cursor);

        $response = ApiResponse::ok($payload);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    public function unreadCount(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $count = Plugin::instance()
            ->notificationViewService()
            ->getUnreadCount($viewerId);

        $response = ApiResponse::ok(['unread_count' => $count]);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    public function markRead(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $id = (int) $request->get_param('id');
        $repo = Plugin::instance()->notificationRepository();

        if ($id > 0) {
            $updated = $repo->markRead($viewerId, $id) ? 1 : 0;
        } else {
            $updated = $repo->markAllReadForUser($viewerId);
        }

        $response = ApiResponse::ok(['ok' => true, 'updated' => $updated]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
