<?php
/**
 * CardDisputesEndpoint — handles
 *   GET /bcc/v1/entities/{target_kind}/{target_id}/disputes
 *
 * Disputes-tab data plane for entity profiles. Mirrors
 * {@see CardReviewsEndpoint} exactly; differs only in which service it
 * calls and which list of flags it surfaces (open disputes against the
 * card, not reviews of it).
 *
 * Auth-OPTIONAL: anonymous viewers see disputes. Per §D5 disputes are
 * evidence — surfacing them publicly is the intentional adversarial-
 * signal posture for entity profiles.
 *
 * Cache: same as CardReviewsEndpoint — anon `public, max-age=30`,
 * authed `private, max-age=30 + Vary`.
 *
 * @package BCC\Trust\Core\REST
 * @since 2026-05-14 (Phase 2 entity tab parity)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\CardDisputesService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CardDisputesEndpoint
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
            '/entities/(?P<target_kind>[a-z_]+)/(?P<target_id>\d+)/disputes',
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
                    'page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
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

        $page    = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');
        $viewer  = get_current_user_id();

        $data = (new CardDisputesService(Plugin::instance()->flagsRepository()))
            ->getDisputes($targetId, $viewer, $page, $perPage);

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
