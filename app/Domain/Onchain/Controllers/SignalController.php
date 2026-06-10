<?php

namespace BCC\Trust\Onchain\Controllers;

use BCC\Trust\Onchain\Services\SignalRefreshService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API controller for on-chain signal data.
 *
 * Routes:
 *   POST /bcc/v1/onchain/{page_id}/refresh  — admin force re-fetch
 */
final class SignalController
{
    /**
     * Register REST routes.
     */
    public static function registerRoutes(): void
    {
        register_rest_route('bcc/v1', '/onchain/(?P<page_id>\d+)/refresh', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'refresh'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
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
