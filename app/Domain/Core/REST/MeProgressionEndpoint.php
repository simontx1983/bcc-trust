<?php
/**
 * GET /bcc/v1/me/progression — the canonical member-facing Rank
 * progression surface (Rank Phase 5; approved plan §22.1, contract §4).
 *
 * Authenticated members only; each member sees ONLY their own
 * progression. The block is built entirely backend-side by
 * RankStateService::progressionFor — the frontend derives no
 * thresholds, labels, or permissions (§22 / plan invariant 33). The
 * §22.2 do-not-expose list holds by construction: no per-action point
 * formulas, no fraud/IP/device/cluster signals, no other member's
 * weights.
 *
 * @package BCC\Trust\Core\REST
 * @since Rank redesign Phase 5 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MeProgressionEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/me/progression';

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_PATH, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [new self(), 'handle'],
            // Auth in-handler (MeAttestationsEndpoint pattern) — bearer
            // resolution happens upstream; anonymous gets a clean 401.
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in to view your progression.', 401);
        }

        $block = \BCC\Trust\Core\Plugin::instance()->rankStateService()->progressionFor($userId);

        $response = ApiResponse::ok($block);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
