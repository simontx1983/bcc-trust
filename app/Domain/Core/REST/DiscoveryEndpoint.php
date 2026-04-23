<?php
/**
 * Project Discovery REST Endpoint
 *
 * Route: GET /wp-json/bcc/v1/discover
 *
 * Query params:
 *   types         comma-separated project types (builder,validator,nft,dao)
 *   sort          trust|endorsements|newest|followers
 *   verified_only 0|1
 *   limit         int (max 48)
 *   page          int (1-based)
 *   search        string
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use BCC\Trust\Core\Services\PageDiscoveryService;
use BCC\Trust\Core\Security\RateLimiter;

if (!defined('ABSPATH')) {
    exit;
}

class DiscoveryEndpoint {

    private const ROUTE_NAMESPACE = 'bcc/v1';

    /**
     * Register the REST route.
     *
     * @return void
     */
    public static function register() {
        $instance = new self();

        register_rest_route(self::ROUTE_NAMESPACE, '/discover', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$instance, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'types' => [
                    'required'          => false,
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'sort' => [
                    'required'          => false,
                    'default'           => 'trust',
                    'validate_callback' => function ($value) {
                        return in_array($value, ['trust', 'endorsements', 'newest', 'followers'], true);
                    },
                    'sanitize_callback' => 'sanitize_key',
                ],
                'verified_only' => [
                    'required'          => false,
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
                'limit' => [
                    'required'          => false,
                    'default'           => 12,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value >= 1;
                    },
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'required'          => false,
                    'default'           => 1,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value >= 1;
                    },
                    'sanitize_callback' => 'absint',
                ],
                'search' => [
                    'required'          => false,
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'tier' => [
                    'required'          => false,
                    'default'           => '',
                    'validate_callback' => function ($value) {
                        return $value === '' || in_array($value, ['elite', 'trusted', 'neutral', 'caution', 'risky'], true);
                    },
                    'sanitize_callback' => 'sanitize_key',
                ],
                'min_confidence' => [
                    'required'          => false,
                    'default'           => 0,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 1;
                    },
                    'sanitize_callback' => function ($value) { return (float) $value; },
                ],
                'new_only' => [
                    'required'          => false,
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
                'max_votes' => [
                    'required'          => false,
                    'default'           => 0,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value >= 0;
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Handle the incoming request.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error {
        // Rate limit: 30 requests per minute per user/IP.
        if (!RateLimiter::allow('discovery_read', 30, 60)) {
            return new WP_Error(
                'rate_limited',
                'Too many requests. Please try again shortly.',
                ['status' => 429]
            );
        }

        $types_raw = $request->get_param('types');
        $types     = $types_raw ? array_filter(explode(',', $types_raw)) : [];

        // Hard clamp limit and page at the boundary — defence in depth even if
        // validation is bypassed or the service is called directly.
        // Max page=20 caps the OFFSET at 20*50=1000 rows — prevents full-scan
        // filesort on deep pagination.
        $limit = min(50, max(1, (int) $request->get_param('limit')));
        $page  = min(20, max(1, (int) $request->get_param('page')));

        if ((int) $request->get_param('page') > 20) {
            return new WP_Error(
                'pagination_exceeded',
                'Page offset exceeds maximum (20). Use filters to narrow results.',
                ['status' => 400]
            );
        }

        $params = [
            'types'          => $types,
            'sort'           => $request->get_param('sort'),
            'verified_only'  => $request->get_param('verified_only'),
            'tier'           => $request->get_param('tier'),
            'min_confidence' => $request->get_param('min_confidence'),
            'new_only'       => $request->get_param('new_only'),
            'max_votes'      => $request->get_param('max_votes'),
            'limit'          => $limit,
            'page'           => $page,
            'search'         => $request->get_param('search'),
        ];

        // ── Canonicalize params for cache key ───────────────────────────
        // Normalize high-cardinality params to reduce cache key diversity.
        // Without this, 200 unique filter combos = 200 cache misses per
        // 30s window. With normalization: ~40 unique keys.
        $normalized = $params;
        sort($normalized['types']);                                          // canonical order
        $normalized['min_confidence'] = round((float) $normalized['min_confidence'], 1);  // 0.1 granularity
        $normalized['max_votes']      = (int) ceil((int) $normalized['max_votes'] / 10) * 10; // nearest 10
        $normalized['page']           = (int) ceil((int) $normalized['page'] / 5) * 5;    // nearest 5
        $normalized['search']         = mb_strtolower(trim((string) $normalized['search']));
        ksort($normalized);                                                  // deterministic key order

        // ── 30-second result cache with stampede lock ───────────────────
        // Discovery data is inherently stale-tolerant: trust scores are
        // recalculated every 5 minutes, so a 30-second cache introduces
        // negligible additional staleness while eliminating the hottest
        // read query at scale (500 identical queries/sec → 1 per 30s).
        $cacheKey = 'disc_' . md5(wp_json_encode($normalized) ?: '');
        $cached   = wp_cache_get($cacheKey, 'bcc_discovery');

        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        // Stampede lock: only one request rebuilds the cache on miss.
        // Losers serve stale data (empty array) instead of hammering DB.
        $lockKey = "lock_{$cacheKey}";
        if (!wp_cache_add($lockKey, 1, 'bcc_discovery', 3)) {
            // Another request is already rebuilding — serve stale or empty.
            $stale = wp_cache_get($cacheKey . '_stale', 'bcc_discovery');
            return new WP_REST_Response($stale !== false ? $stale : [], 200);
        }

        $service = new PageDiscoveryService();
        $result  = $service->query($params);

        // Store both fresh and stale copies. Stale copy persists longer
        // so stampede losers have something to serve during rebuilds.
        wp_cache_set($cacheKey, $result, 'bcc_discovery', 30);
        wp_cache_set($cacheKey . '_stale', $result, 'bcc_discovery', 120);
        wp_cache_delete($lockKey, 'bcc_discovery');

        return new WP_REST_Response($result, 200);
    }
}