<?php
/**
 * Feed Cold-Start Endpoint — GET /bcc/v1/feed/cold-start.
 *
 * Auth-permissive bridge surface for the home-feed empty state.
 * Anon viewers get the same three-block envelope without
 * chain-alignment personalization (see FeedColdStartService doctype
 * for why that's load-bearing).
 *
 * Route is intentionally NOT under /discover/* — that path is owned by
 * the legacy bcc-page-slider DiscoveryEndpoint and would collide. The
 * `cold-start` slug also matches what the surface IS (a cold-start
 * bridge) rather than what it APPEARS to be (a discovery panel).
 *
 * Cache posture: `Cache-Control: private, no-store`. The underlying
 * primitives (FeedRankingService::getHotFeed, UserSyncRepository
 * recency reads) own their own per-request caching; we don't want
 * shared caches keying off this URL because the operators block
 * stable-randomizes per viewer per day.
 *
 * @package BCC\Trust\Core\REST
 * @since   V1 (2026-05, Sprint 3 cold-start)
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

final class FeedColdStartEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/cold-start',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'handle'],
                // Auth-permissive — anon viewers get the panel shape
                // without chain-alignment personalization. See service
                // doctype for the civic-by-construction reasoning.
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        unset($request); // no query params

        $viewerId = get_current_user_id();
        $payload  = Plugin::instance()
            ->feedColdStartService()
            ->getColdStart($viewerId > 0 ? $viewerId : null);

        $response = ApiResponse::ok($payload);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
