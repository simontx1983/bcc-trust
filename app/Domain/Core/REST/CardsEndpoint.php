<?php
/**
 * Cards Endpoint — handles /bcc/v1/cards/:type/:id (per contract §L5).
 *
 * Polymorphic: one route shape, four card kinds. The frontend's
 * <CardFactory> reads `card_kind` and dispatches to the right face — but
 * the outer envelope is identical across kinds.
 *
 * Type enum (§N1 + §C1):
 *   - validator
 *   - project
 *   - creator
 *   - member
 *
 * URL :id is:
 *   - For validator/project/creator: numeric post_id OR post_name (slug)
 *   - For member: bcc_handle
 *
 * Cache policy: per-viewer permissions vary the response, so we use a
 * private cache and a short TTL. Anonymous responses could be more
 * aggressive (CDN-cacheable) — that's a V1.5 optimization.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04)
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

final class CardsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const TYPE_PATTERN    = 'validator|project|creator|member';
    // Allows numeric post_id, alphanumeric post_name slugs, and bcc_handle.
    // Capped at 100 chars defensively; the type-specific service does the
    // real validation (handle pattern per §B6 enforced inside resolution).
    private const ID_PATTERN      = '[A-Za-z0-9][A-Za-z0-9_-]{0,99}';

    public static function register(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/cards/(?P<type>' . self::TYPE_PATTERN . ')/(?P<id>' . self::ID_PATTERN . ')',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'handle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'type' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => ['validator', 'project', 'creator', 'member'],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $type = (string) $request->get_param('type');
        $id   = (string) $request->get_param('id');

        if ($type === '' || $id === '') {
            return ApiResponse::error('bcc_invalid_request', 'type and id are required.', 400);
        }

        $viewerId = get_current_user_id();
        $payload  = Plugin::instance()->cardViewService()->getCard($type, $id, $viewerId);

        if ($payload === null) {
            return ApiResponse::error('bcc_not_found', 'Card not found.', 404);
        }

        $response = ApiResponse::ok($payload);
        // Per-viewer permissions vary the response → not shared-cacheable in V1.
        $response->header('Cache-Control', 'private, max-age=30');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }
}
