<?php
/**
 * Flag Endpoint
 *
 * REST API for the public page flagging system.
 * Signal only — no score or fraud impact.
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\Permissions\Permissions;
use BCC\Trust\Core\Security\RateLimiter;
use BCC\Trust\Core\Services\FlagService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

class FlagEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/flag', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [new self(), 'handle'],
            'permission_callback' => function () {
                return is_user_logged_in() && Permissions::is_not_suspended();
            },
            'args' => [
                'page_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'action' => [
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => ['flag', 'unflag'],
                ],
                'reason' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'maxLength'         => 255,
                    'default'           => '',
                ],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!RateLimiter::allow('flag')) {
            return new WP_REST_Response(
                ['code' => 'bcc_rate_limited', 'message' => 'Too many requests. Please wait.'],
                429
            );
        }

        $pageId = (int) $request->get_param('page_id');
        $action = $request->get_param('action');
        $reason = $request->get_param('reason') ?: null;
        $userId = get_current_user_id();

        // Verify page exists
        if (!get_post($pageId)) {
            return new WP_REST_Response(
                ['code' => 'bcc_not_found', 'message' => 'Page not found.'],
                404
            );
        }

        if ($action === 'flag') {
            $result = FlagService::flagPage($pageId, $userId, $reason);
        } else {
            $result = FlagService::unflagPage($pageId, $userId);
        }

        $httpStatus = $result['success'] ? 200 : 400;

        return new WP_REST_Response([
            'success'      => $result['success'],
            'message'      => $result['message'],
            'flag_count'   => $result['flag_count'],
            'user_flagged' => FlagService::hasUserFlagged($pageId, $userId),
        ], $httpStatus);
    }
}
