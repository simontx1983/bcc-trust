<?php
/**
 * Unified Page Data Endpoint
 *
 * Thin REST controller. All data aggregation and caching is delegated
 * to PageDataLoader (wp_cache / Redis). This class handles:
 *   - route registration
 *   - request validation
 *   - rate limiting
 *   - HTTP response
 *
 * Route: GET /wp-json/bcc/v1/page/{id}
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use BCC\Trust\Core\Services\PageDataLoader;
use BCC\Trust\Core\Security\RateLimiter;

if (!defined('ABSPATH')) {
    exit;
}

class PageEndpoint {

    private const ROUTE_NAMESPACE = 'bcc/v1';

    /**
     * Register the REST route.
     *
     * @return void
     */
    public static function register() {
        $instance = new self();

        register_rest_route(self::ROUTE_NAMESPACE, '/page/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$instance, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value > 0;
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Handle the incoming request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle(WP_REST_Request $request) {
        // Rate limit: 60 requests per minute per user/IP
        if (!RateLimiter::allow('page_read', 60, 60)) {
            return new WP_Error(
                'bcc_rate_limited',
                'Too many requests. Please try again shortly.',
                ['status' => 429]
            );
        }

        $post_id   = (int) $request->get_param('id');
        $viewer_id = get_current_user_id();

        // Shared loader: wp_cache (Redis) backed, same cache all blocks use.
        $data = PageDataLoader::get( $post_id );

        if ( ! $data ) {
            return new WP_Error(
                'bcc_not_found',
                'Page not found.',
                ['status' => 404]
            );
        }

        // Viewer section is always fresh (never cached).
        $data['viewer'] = PageDataLoader::getViewer( $viewer_id, $post_id );

        // Strip internal builder ID from public responses to prevent user enumeration.
        if (isset($data['builder']['id'])) {
            unset($data['builder']['id']);
        }

        // Signal degradation to the frontend when trust-engine services
        // are unavailable. The frontend should show a banner and disable
        // voting/endorsing — users must not act on default/stale scores.
        //
        // OR with the aggregator's own flag so read-model fallback and
        // viewer-section failures (already encoded in $data['system_degraded']
        // and $data['viewer']['viewer_data_degraded']) are not overwritten
        // by this coarser ScoreReadService check.
        $serviceDegraded = !\BCC\Core\ServiceLocator::hasRealService(
            \BCC\Core\Contracts\ScoreReadServiceInterface::class
        );
        $data['system_degraded'] = !empty($data['system_degraded'])
            || !empty($data['viewer']['viewer_data_degraded'])
            || $serviceDegraded;

        return new WP_REST_Response($data, 200);
    }

    /* =================================================================
       Cache invalidation
    ================================================================= */

    /**
     * Delete the cached page data for a given post.
     *
     * Delegates to the shared PageDataLoader so every consumer
     * (blocks, REST, shortcodes) is invalidated in one call.
     *
     * @param int $post_id
     * @return void
     */
    public static function bustCache(int $post_id) {
        PageDataLoader::bust( $post_id );
    }

    /**
     * Bust cache for all pages owned by a user.
     * Used when wallet/GitHub changes affect all of a user's pages.
     *
     * @param int $user_id
     * @return void
     */
    public static function bustCacheForUser(int $user_id) {
        PageDataLoader::bustForUser( $user_id );
    }
}
