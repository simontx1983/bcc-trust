<?php
/**
 * GET /bcc/v1/me/posts/{id}/analytics — F-324 owner-only post analytics.
 *
 * Three independent sections — reach, engagement, trust — never blended
 * into a composite score, each with its own `available` flag so one
 * section's outage cannot make another look like zeros.
 *
 * ⚠ OWNER-ONLY, enforced in the service against the post's author, not by
 * hiding a control. A non-owner gets **403 `bcc_forbidden`**, deliberately
 * NOT the 404 that `MeFindingAppealEndpoint` returns: that endpoint 404s to
 * avoid revealing whether a finding exists, whereas a post's existence is
 * already public, so 403 leaks nothing here and matches the sibling
 * owner-only read on `GET /posts/{id}`.
 *
 * ⚠ `trust` always returns `available: false`. Attestations target profiles
 * and cards, never an activity post, and per-post vouching was deliberately
 * migrated away — so this is a decision, not an unfinished section.
 *
 * @package BCC\Trust\Core\REST
 * @since F-324 post analytics V1 (2026-11-12)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Services\PostAnalyticsService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MePostAnalyticsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/me/posts/(?P<id>\d+)/analytics';

    /** A member checking their own post's numbers; generous but bounded. */
    private const RATE_LIMIT  = 60;
    private const RATE_WINDOW = 300;

    /** @var array<string, int> error code → HTTP status */
    private const ERROR_STATUS = [
        'bcc_unauthorized'    => 401,
        'bcc_invalid_request' => 400,
        'bcc_not_found'       => 404,
        'bcc_forbidden'       => 403,
    ];

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_PATH, [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [new self(), 'handle'],
            // Auth in-handler (MeProgressionEndpoint / MeFindingAppealEndpoint
            // pattern) so an anonymous caller gets a clean 401 envelope
            // rather than WordPress's own.
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => static fn ($value): bool => is_numeric($value),
                ],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in to see your post analytics.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow(
            'me_post_analytics_' . $viewerId,
            self::RATE_LIMIT,
            self::RATE_WINDOW
        )) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $actId  = (int) $request->get_param('id');
        $result = (new PostAnalyticsService())->forOwner($actId, $viewerId);

        if (isset($result['error'])) {
            $code = (string) $result['error'];

            return ApiResponse::error(
                $code,
                (string) ($result['message'] ?? 'Request failed.'),
                self::ERROR_STATUS[$code] ?? 400
            );
        }

        $response = ApiResponse::ok($result);

        // Owner-private data: never store it in a shared cache, and never
        // let an intermediary serve it to a different member.
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
