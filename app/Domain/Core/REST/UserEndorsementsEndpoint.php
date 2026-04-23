<?php
/**
 * User Endorsements REST Endpoint
 *
 * Returns endorsements given by the current user with page details and stats.
 * Powers the "My Endorsements" Gutenberg block.
 *
 * Routes:
 *   GET /bcc/v1/endorsements/mine        — list current user's endorsements
 *   GET /bcc/v1/endorsements/mine/stats   — aggregate stats
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

final class UserEndorsementsEndpoint
{
    public static function register(): void
    {
        register_rest_route('bcc/v1', '/endorsements/mine', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handleList'],
            'permission_callback' => function () {
                return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
            },
            'args'                => [
                'limit' => [
                    'type'    => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
        ]);

        register_rest_route('bcc/v1', '/endorsements/mine/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handleStats'],
            'permission_callback' => function () {
                return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
            },
        ]);
    }

    /**
     * GET /bcc/v1/endorsements/mine
     */
    public static function handleList(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('endorsements_mine', 30, 60)) {
            return new WP_REST_Response(['message' => 'Too many requests.'], 429);
        }

        $user_id = get_current_user_id();
        $limit   = (int) $request->get_param('limit');

        $endorsements = Plugin::instance()->endorsementService()
            ->getUserEndorsements($user_id, $limit);

        // Batch-fetch read model for pages to get current trust scores.
        $page_ids = array_map(static fn(array $e): int => (int) ($e['page_id'] ?? 0), $endorsements);
        $rm_rows  = [];
        if (!empty($page_ids)) {
            $rm_rows = Plugin::instance()->pageReadModelRepository()->getByPageIds($page_ids);
        }

        $items = [];
        foreach ($endorsements as $e) {
            $pid = (int) ($e['page_id'] ?? 0);
            $rm  = $rm_rows[$pid] ?? null;

            $avatar = '';
            if ($rm && $rm->owner_id) {
                $avatar = get_avatar_url((int) $rm->owner_id, ['size' => 64]);
            }

            $pageTitle = isset($e['page_title']) && is_string($e['page_title']) ? $e['page_title'] : null;
            $context   = isset($e['context'])    && is_string($e['context'])    ? $e['context']    : null;
            $reason    = isset($e['reason'])     && is_string($e['reason'])     ? $e['reason']     : null;
            $createdAt = isset($e['created_at']) && is_string($e['created_at']) ? $e['created_at'] : null;

            $items[] = [
                'id'           => (int) ($e['id'] ?? 0),
                'page_id'      => $pid,
                'page_title'   => $pageTitle ?? __('(Untitled)', 'bcc-trust'),
                'page_url'     => get_permalink($pid) ? esc_url(get_permalink($pid)) : '',
                'avatar_url'   => $avatar ? esc_url($avatar) : '',
                'trust_score'  => $rm ? (int) round((float) $rm->trust_score) : null,
                'tier'         => $rm ? sanitize_key($rm->reputation_tier ?? 'neutral') : 'unavailable',
                'weight'       => round((float) ($e['weight'] ?? 0), 2),
                'context'      => $context ?? 'general',
                'reason'       => $reason,
                'created_at'   => $createdAt,
            ];
        }

        return rest_ensure_response([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    /**
     * GET /bcc/v1/endorsements/mine/stats
     */
    public static function handleStats(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('endorsements_mine_stats', 30, 60)) {
            return new WP_REST_Response(['message' => 'Too many requests.'], 429);
        }

        $user_id = get_current_user_id();
        $stats   = Plugin::instance()->endorsementService()
            ->getUserEndorsementStats($user_id);

        return rest_ensure_response($stats);
    }
}
