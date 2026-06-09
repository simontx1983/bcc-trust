<?php
/**
 * Suggestions Endpoints — handles /bcc/v1/suggestions/* routes.
 *
 * Routes registered:
 *   - GET /suggestions/users — personalized who-to-follow recommender
 *
 * AUTH REQUIRED. The suggestion set is personalized to the current
 * viewer's follow graph, group memberships, and validator backing —
 * there is no anonymous variant (anon → bcc_unauthorized 401, same
 * posture as GET /feed).
 *
 * AFFINITY-ONLY by product rule: the underlying SuggestionService scores
 * on relevance signals (reciprocity, mutual follows, shared Locals /
 * communities, shared validator backing) and NEVER on trust score or
 * follower count. Reputation is exclusion-only. See SuggestionService's
 * doctype for the full model.
 *
 * Cache policy: per-viewer personalization → private, short TTL. No
 * shared cache.
 *
 * @package BCC\Trust\Core\REST
 * @since   v1.25 (who-to-follow suggestions)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class SuggestionsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const DEFAULT_LIMIT   = 12;
    private const MAX_LIMIT       = 24;

    public static function register(): void
    {
        $instance = new self();

        // GET /suggestions/users — auth-required personalized recommender.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/suggestions/users',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'users'],
                // Auth checked inside the handler so anonymous requests
                // return the canonical bcc_unauthorized envelope.
                'permission_callback' => '__return_true',
                'args'                => [
                    'limit' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => self::DEFAULT_LIMIT,
                        'minimum'           => 1,
                        'maximum'           => self::MAX_LIMIT,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function users(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        // Clamp defensively even if the arg schema is bypassed.
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $items = Plugin::instance()->suggestionService()->getSuggestions($viewerId, $limit);

        $response = ApiResponse::ok(['items' => $items]);
        // Per-viewer personalization — short private cache, no shared cache.
        $response->header('Cache-Control', 'private, max-age=120');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }
}
