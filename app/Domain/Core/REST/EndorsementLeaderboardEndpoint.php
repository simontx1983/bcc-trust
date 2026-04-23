<?php
/**
 * Endorsement Leaderboard REST Endpoint
 *
 * Returns top endorsed pages using the bcc_page_read_model as source of truth.
 * This endpoint serves the endorsement-leaderboard Gutenberg block via JS fetch.
 *
 * Route: GET /bcc/v1/endorsements/top
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class EndorsementLeaderboardEndpoint
{
    /**
     * Register REST routes.
     */
    public static function register(): void
    {
        register_rest_route('bcc/v1', '/endorsements/top', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'limit' => [
                    'type'              => 'integer',
                    'default'           => 10,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
                'context' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    /**
     * Handle GET /bcc/v1/endorsements/top
     */
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('endorsement_lb', 30, 60)) {
            return new WP_REST_Response(
                ['code' => 'rate_limited', 'message' => 'Too many requests.'],
                429
            );
        }

        $limit   = (int) $request->get_param('limit');
        $context = $request->get_param('context') ?: null;

        // Read from EndorsementRepository (reads endorsement aggregates).
        $rows = [];
        try {
            $endorsement_repo = new \BCC\Trust\Core\Repositories\EndorsementRepository();
            $rows = $endorsement_repo->getTopEndorsed($context, $limit);
        } catch (\Exception $e) {
            return new WP_REST_Response(
                ['code' => 'query_error', 'message' => 'Failed to fetch endorsements.'],
                500
            );
        }

        if (empty($rows)) {
            return rest_ensure_response(['items' => [], 'total' => 0]);
        }

        // Batch-fetch read model rows for tier data (single query, not N+1).
        $page_ids = array_map(fn($r) => (int) $r->page_id, $rows);
        $rm_repo  = Plugin::instance()->pageReadModelRepository();
        $rm_rows  = $rm_repo->getByPageIds($page_ids);

        $items = [];
        foreach ($rows as $rank => $row) {
            $row_page_id  = (int) $row->page_id;
            $rm           = $rm_rows[$row_page_id] ?? null;
            $page_title   = $row->page_title ?: __('(Untitled)', 'bcc-trust');
            $page_url     = get_permalink($row_page_id);

            // Owner avatar from read model owner_id.
            $row_owner_id = $rm ? (int) $rm->owner_id : 0;
            if (!$row_owner_id) {
                try {
                    $row_owner_id = (int) Plugin::instance()->pageOwnerResolver()->getPageOwner($row_page_id);
                } catch (\Exception $e) { /* silent */ }
            }
            $avatar_url = $row_owner_id ? get_avatar_url($row_owner_id, ['size' => 64]) : '';

            // Tier from read model (no ScoreRepository call).
            $tier = $rm ? sanitize_key($rm->reputation_tier ?? 'neutral') : 'neutral';

            $items[] = [
                'rank'              => $rank + 1,
                'page_id'           => $row_page_id,
                'page_title'        => $page_title,
                'page_url'          => $page_url ? esc_url($page_url) : '',
                'avatar_url'        => $avatar_url ? esc_url($avatar_url) : '',
                'endorsement_count' => (int) ($row->endorsement_count ?? 0),
                'total_weight'      => (float) ($row->total_weight ?? 0),
                'tier'              => $tier,
            ];
        }

        return rest_ensure_response([
            'items' => $items,
            'total' => count($items),
        ]);
    }
}
