<?php
/**
 * MeToursSeenEndpoint — server half of the site-wide tour "seen" store.
 *
 *   GET  /bcc/v1/me/tours-seen              → { "seen": string[] }
 *   POST /bcc/v1/me/tours-seen { tour_id }  → { "seen": string[] } (idempotent add)
 *
 * Mirrors the frontend's two-tier store (`useToursSeen` unions this with
 * localStorage) so "seen" survives a device switch. Tour ids are
 * frontend-registry data (`src/lib/tour/registry.ts`) — deliberately NOT
 * enum-validated here, so adding a new tour stays a frontend-only change.
 * Only the slug shape is checked.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-07, tour engine)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MeToursSeenEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/me/tours-seen';

    /** wp_usermeta key — JSON array of tour-id strings. */
    private const META_KEY = 'bcc_tours_seen';

    /** Slug shape for a tour id: lowercase alnum, `-`/`_`, 1–64 chars. */
    private const TOUR_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** Defensive cap on stored ids — real registry is a handful; this only guards against a runaway client. */
    private const MAX_SEEN = 100;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_PATH,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'get'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_PATH,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'markSeen'],
                'permission_callback' => '__return_true',
                'args' => [
                    'tour_id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    public function get(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $resp = ApiResponse::ok(['seen' => self::readSeen($userId)]);
        $resp->header('Cache-Control', 'private, no-store');
        return $resp;
    }

    public function markSeen(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $tourId = trim((string) $request->get_param('tour_id'));
        if ($tourId === '' || preg_match(self::TOUR_ID_PATTERN, $tourId) !== 1) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid tour_id.', 422);
        }

        $seen = self::readSeen($userId);
        if (!in_array($tourId, $seen, true) && count($seen) < self::MAX_SEEN) {
            $seen[] = $tourId;
            update_user_meta($userId, self::META_KEY, wp_json_encode($seen));
        }

        $resp = ApiResponse::ok(['seen' => $seen]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * @return list<string>
     */
    private static function readSeen(int $userId): array
    {
        $raw = get_user_meta($userId, self::META_KEY, true);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_string'));
    }
}
