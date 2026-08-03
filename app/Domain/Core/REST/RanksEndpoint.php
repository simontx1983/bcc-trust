<?php
/**
 * Ranks Endpoint
 *
 * GET /bcc/v1/ranks — rank catalog + viewer's member state.
 *
 * Per contract §4.8. Anonymous OR Bearer auth: anonymous viewers see
 * the static catalog with an all-null viewer block; authenticated
 * viewers see the catalog plus their member-state block
 * (member_state / rank / rank_label from RankStateService — the Rank
 * redesign Phase 5 read facade; a New Member is an explicit state,
 * never an error shape).
 *
 * Cache policy: the viewer block is personal for logged-in users, so
 * authed responses are `private, max-age=60`; anonymous responses stay
 * `public, max-age=300` (catalog-only payload, rarely changes).
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\RankCatalog;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class RanksEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/ranks';

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_PATH, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [new self(), 'handle'],
            'permission_callback' => '__return_true', // anon allowed per contract §4.8
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        unset($request); // endpoint takes no parameters; signature required by WP REST.

        $viewerId = get_current_user_id();

        // Anonymous: all-null block (distinct from a New Member, whose
        // member_state is the explicit 'new_member' string).
        $viewer = $viewerId > 0
            ? Plugin::instance()->rankStateService()->memberState($viewerId)
            : ['member_state' => null, 'rank' => null, 'rank_label' => null];

        $response = ApiResponse::ok([
            'ranks'  => RankCatalog::all(),
            'viewer' => $viewer,
        ]);
        $response->header(
            'Cache-Control',
            $viewerId > 0 ? 'private, max-age=60' : 'public, max-age=300'
        );

        return $response;
    }
}
