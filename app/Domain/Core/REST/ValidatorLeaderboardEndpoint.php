<?php
/**
 * Validator Leaderboard REST Endpoint
 *
 * Returns paginated validator data as JSON for the validator-leaderboard block.
 * Replaces the legacy admin-ajax bcc_vlb_load_more handler.
 *
 * Route: GET /bcc/v1/validators/top
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorLeaderboardEndpoint
{
    /**
     * Register REST routes.
     */
    public static function register(): void
    {
        register_rest_route('bcc/v1', '/validators/top', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'page' => [
                    'type'    => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
                'per_page' => [
                    'type'    => 'integer',
                    'default' => 25,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'chain_id' => [
                    'type'    => 'integer',
                    'default' => 0,
                ],
                'sort' => [
                    'type'              => 'string',
                    'default'           => 'voting_power_rank',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'dir' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'time_window' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    /**
     * Handle GET /bcc/v1/validators/top
     */
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('vlb_rest', 30, 60)) {
            return new WP_REST_Response(
                ['code' => 'rate_limited', 'message' => 'Too many requests.'],
                429
            );
        }

        $page        = (int) $request->get_param('page');
        $per_page    = (int) $request->get_param('per_page');
        $chain_id    = (int) $request->get_param('chain_id') ?: null;
        $sort        = $request->get_param('sort');
        $dir         = $request->get_param('dir') ?: null;
        $time_window = $request->get_param('time_window') ?: null;

        $allowed_sorts = ['total_stake', 'self_stake', 'commission_rate', 'uptime_30d', 'voting_power_rank'];
        if (!in_array($sort, $allowed_sorts, true)) {
            $sort = 'voting_power_rank';
        }
        if ($dir !== null && $dir !== 'asc' && $dir !== 'desc') {
            $dir = null;
        }

        // Fail-loud: Onchain is a hard in-plugin dependency. A missing class here is a
        // deployment bug, not a fallback case — do not reintroduce class_exists guards.
        if (!class_exists(\BCC\Trust\Onchain\Repositories\ValidatorRepository::class)) {
            throw new \RuntimeException('Onchain domain classes not autoloaded');
        }

        $data = \BCC\Trust\Onchain\Repositories\ValidatorRepository::getTopValidators(
            $page, $per_page, $sort, $chain_id, $time_window, $dir
        );

        $validators = $data['items'] ?? [];

        // Batch-load claims. Only store entity_ids that actually have a first claim;
        // skipping empty lists lets later `isset($claims_map[$vid])` imply a real object.
        $claims_map = [];
        if (!empty($validators)) {
            $vids = array_map(fn($v) => (int) $v->id, $validators);
            $all_claims = \BCC\Trust\Onchain\Repositories\ClaimRepository::getForEntityBatch('validator', $vids);
            foreach ($all_claims as $vid => $claims) {
                if (!empty($claims)) {
                    $claims_map[$vid] = $claims[0];
                }
            }
        }

        // Site logo for unclaimed.
        $site_logo_url = '';
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $site_logo_url = wp_get_attachment_image_url($custom_logo_id, 'thumbnail');
        }
        if (!$site_logo_url) {
            $site_logo_url = get_site_icon_url(64);
        }

        $offset = ($page - 1) * $per_page;
        $items  = [];

        foreach ($validators as $rank => $v) {
            $vid         = (int) $v->id;
            $claimer     = $claims_map[$vid] ?? null;
            $claimed     = $claimer !== null;
            $claimer_uid = $claimer !== null ? (int) $claimer->user_id : 0;

            $avatar_url = $claimer_uid
                ? get_avatar_url($claimer_uid, ['size' => 64])
                : $site_logo_url;

            $items[] = [
                'id'                     => $vid,
                'moniker'                => $v->moniker ?: 'Unknown',
                'operator_address'       => $v->operator_address ?? '',
                'chain_name'             => $v->chain_name ?? '',
                'chain_slug'             => $v->chain_slug ?? '',
                'native_token'           => $v->native_token ?? '',
                'voting_power_rank'      => (int) ($v->voting_power_rank ?: ($offset + $rank + 1)),
                'total_stake'            => (float) ($v->total_stake ?? 0),
                'self_stake'             => (float) ($v->self_stake ?? 0),
                'commission_rate'        => $v->commission_rate !== null ? (float) $v->commission_rate : null,
                'uptime_30d'             => $v->uptime_30d !== null ? round((float) $v->uptime_30d, 1) : null,
                'delegator_count'        => (int) ($v->delegator_count ?? 0),
                'jailed_count'           => (int) ($v->jailed_count ?? 0),
                'explorer_url'           => $v->explorer_url ?? '',
                'avatar_url'             => $avatar_url ? esc_url($avatar_url) : '',
                'claimed'                => $claimed,
                'claimer_name'           => $claimer !== null ? ($claimer->claimer_name ?? '') : '',
            ];
        }

        return rest_ensure_response([
            'items'    => $items,
            'total'    => (int) ($data['total'] ?? 0),
            'pages'    => (int) ($data['pages'] ?? 0),
            'page'     => $page,
            'has_more' => $page < (int) ($data['pages'] ?? 0),
        ]);
    }
}
