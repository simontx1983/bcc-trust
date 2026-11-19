<?php
/**
 * Feed Cold-Start Endpoint — GET /bcc/v1/feed/cold-start.
 *
 * Auth-permissive bridge surface for the home-feed empty state.
 * Anon viewers get the same three-block envelope without
 * chain-alignment personalization (see FeedColdStartService doctype
 * for why that's load-bearing).
 *
 * Route is intentionally NOT under /discover/* — the `cold-start` slug
 * matches what the surface IS (a cold-start bridge) rather than what
 * it APPEARS to be (a discovery panel). (Historical note: /bcc/v1/discover
 * was the legacy bcc-page-slider endpoint, retired 2026-05-15.)
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

use BCC\Core\Contracts\ActivityReadFailedException;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\FeedUnavailable;
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
        try {
            $payload = Plugin::instance()
                ->feedColdStartService()
                ->getColdStart($viewerId > 0 ? $viewerId : null);
        } catch (ActivityReadFailedException) {
            // F-275: the hot-posts block reads the feed, and each operator
            // row's `recent_action` reads that member's latest activity. A
            // read that did not run answers "try again" for the panel, never
            // a panel whose posts block is silently empty or whose operators
            // carry the fallback line as if they had posted nothing.
            return FeedUnavailable::response();
        }

        $response = ApiResponse::ok($payload);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
