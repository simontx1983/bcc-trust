<?php

namespace BCC\Trust\Onchain\Controllers;

use BCC\Trust\Onchain\Repositories\SignalRepository;
use BCC\Trust\Onchain\Services\SignalRefreshService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API controller for on-chain signal data.
 *
 * Routes:
 *   GET  /bcc/v1/onchain/{page_id}         — fetch stored signals
 *   POST /bcc/v1/onchain/{page_id}/refresh  — admin force re-fetch
 */
final class SignalController
{
    /**
     * Register REST routes.
     */
    public static function registerRoutes(): void
    {
        register_rest_route('bcc/v1', '/onchain/(?P<page_id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'get'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        register_rest_route('bcc/v1', '/onchain/(?P<page_id>\d+)/refresh', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'refresh'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
    }

    /**
     * GET /bcc/v1/onchain/{page_id}
     */
    public static function get(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('onchain_read', 30, 60)) {
            return new \WP_REST_Response(
                ['code' => 'rate_limited', 'message' => 'Too many requests.'],
                429
            );
        }

        $pageId = (int) $req->get_param('page_id');
        $userId = get_current_user_id();

        // Only page owner and admins can see full signal data.
        $isOwner = \BCC\Core\Permissions\Permissions::owns_page($pageId, $userId);
        $isAdmin = current_user_can('manage_options');

        $data = SignalRepository::get_for_page($pageId);

        // Whitelist: only expose fields the UI actually needs.
        // Non-owners see only aggregated scores — no identifying data
        // (wallet_address, first_tx_at, granular counts) that could
        // enable wallet deanonymization via public blockchain explorers.
        $safe = array_map(function (array $row) use ($isOwner, $isAdmin): array {
            $entry = [
                'id'                 => (int) ($row['id'] ?? 0),
                'chain'              => $row['chain'] ?? '',
                'score_contribution' => (float) ($row['score_contribution'] ?? 0),
                'fetched_at'         => $row['fetched_at'] ?? null,
            ];

            if ($isOwner || $isAdmin) {
                $entry['wallet_address']  = $row['wallet_address'] ?? '';
                $entry['wallet_age_days'] = (int) ($row['wallet_age_days'] ?? 0);
                $entry['first_tx_at']     = $row['first_tx_at'] ?? null;
                $entry['tx_count']        = (int) ($row['tx_count'] ?? 0);
                $entry['contract_count']  = (int) ($row['contract_count'] ?? 0);
            }

            return $entry;
        }, $data);

        return rest_ensure_response($safe);
    }

    /**
     * POST /bcc/v1/onchain/{page_id}/refresh
     */
    public static function refresh(\WP_REST_Request $req): \WP_REST_Response
    {
        $pageId  = (int) $req->get_param('page_id');
        $results = SignalRefreshService::fetchAndStoreForPage($pageId, true);
        return rest_ensure_response(['refreshed' => count($results), 'signals' => $results]);
    }
}
