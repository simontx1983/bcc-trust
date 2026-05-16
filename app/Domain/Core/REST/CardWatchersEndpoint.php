<?php
/**
 * CardWatchersEndpoint — handles
 *   GET /bcc/v1/entities/{target_kind}/{target_id}/watchers
 *
 * Watchers-tab data plane for entity profiles. Offset-paginated to
 * match `/users/{handle}/followers` exactly (the FE reuses the
 * accumulator pattern).
 *
 * Auth-OPTIONAL: entity watchers are public per the §J trust-signal
 * doctrine. Cache mirrors CardReviewsEndpoint / CardDisputesEndpoint.
 *
 * @package BCC\Trust\Core\REST
 * @since 2026-05-14 (Phase 2 entity tab parity)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Services\CardWatchersService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CardWatchersEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** @var list<string> */
    private const ALLOWED_TARGET_KINDS = [
        'validator_card',
        'project_card',
        'creator_card',
    ];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/entities/(?P<target_kind>[a-z_]+)/(?P<target_id>\d+)/watchers',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                'permission_callback' => '__return_true',
                'args' => [
                    'target_kind' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'target_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'limit' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $targetKind = (string) $request->get_param('target_kind');
        if (!in_array($targetKind, self::ALLOWED_TARGET_KINDS, true)) {
            return self::noStore(ApiResponse::error(
                'bcc_invalid_request',
                'Invalid target_kind.',
                400
            ));
        }

        $targetId = (int) $request->get_param('target_id');
        if ($targetId <= 0) {
            return self::noStore(ApiResponse::error(
                'bcc_invalid_request',
                'Invalid target_id.',
                400
            ));
        }

        $offset = (int) $request->get_param('offset');
        $limit  = (int) $request->get_param('limit');
        $viewer = get_current_user_id();

        $data = (new CardWatchersService())
            ->listWatchers($targetId, $viewer, $offset, $limit);

        $response = ApiResponse::ok($data);
        if ($viewer > 0) {
            $response->header('Cache-Control', 'private, max-age=30');
            $response->header('Vary', 'Authorization, Cookie');
        } else {
            $response->header('Cache-Control', 'public, max-age=30');
        }
        return $response;
    }

    private static function noStore(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
