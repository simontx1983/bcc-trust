<?php
/**
 * Ranks Endpoint
 *
 * GET /bcc/v1/ranks — rank catalog + viewer's current rank.
 *
 * Per contract §4.8. Anonymous OR Bearer auth: anonymous viewers see
 * the static catalog with viewer=null; authenticated viewers see the
 * catalog plus their current/auto-derived/is-admin-conferred block.
 *
 * Cache policy: public, max-age=300 (catalog rarely changes; viewer
 * block per-user but rank-changes are infrequent enough that 5 min
 * is acceptable for a Phase 1 endpoint).
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
        $viewer   = Plugin::instance()->rankService()->getViewerBlock($viewerId);

        $response = ApiResponse::ok([
            'ranks'  => RankCatalog::all(),
            'viewer' => $viewer,
        ]);
        $response->header('Cache-Control', 'public, max-age=300');

        return $response;
    }
}
