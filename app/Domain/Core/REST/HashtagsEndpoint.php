<?php
/**
 * Hashtags Endpoints — handles /bcc/v1/hashtags/* routes.
 *
 * Routes registered:
 *   - GET /hashtags/trending — most-used hashtags (non-personalized)
 *
 * Read-only projection over PeepSo's own `peepso_hashtags` counter
 * table via bcc-core's PeepSoHashtagRepository. We do NOT maintain a
 * parallel BCC tag counter (§11) — PeepSo owns the write path and the
 * `ht_count` accounting.
 *
 * Cache policy: the trending list is identical for every viewer (no
 * per-viewer state), so it carries a public, short-TTL shared cache.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-06, trending-hashtags surface)
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\Repositories\PeepSoHashtagRepository;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class HashtagsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const DEFAULT_LIMIT   = 8;
    private const MAX_LIMIT       = 20;

    public static function register(): void
    {
        $instance = new self();

        // GET /hashtags/trending — public, non-personalized.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/hashtags/trending',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'trending'],
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

    public function trending(WP_REST_Request $request): WP_REST_Response
    {
        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        // Repository clamps to [1,20] defensively too; clamp here so the
        // doc'd contract bound holds even if the arg schema is bypassed.
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $items = [];
        foreach (PeepSoHashtagRepository::getTrending($limit) as $row) {
            $items[] = [
                // PeepSo stores ht_name bare, but strip a defensive leading
                // '#' in case a row was ever written with one.
                'tag'   => ltrim((string) $row->ht_name, '#'),
                'count' => (int) $row->ht_count,
            ];
        }

        $response = ApiResponse::ok(['items' => $items]);
        // Non-personalized — safe to share-cache for a few minutes.
        $response->header('Cache-Control', 'public, max-age=300');

        return $response;
    }
}
