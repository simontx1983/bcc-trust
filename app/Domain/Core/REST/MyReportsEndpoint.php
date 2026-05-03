<?php
/**
 * MyReportsEndpoint — handles /bcc/v1/me/reports routes (§K1 Phase B).
 *
 * Routes:
 *   - POST /me/reports — body {target_kind, target_id, reason_code, comment?}
 *
 * Auth: required. Self-only (no admin variant in Phase B; admin queue
 * lives in Phase C).
 *
 * Cache: `no-store`.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §K1 Phase B)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\ContentReportService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyReportsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/reports',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'create'],
                'permission_callback' => '__return_true',
                'args' => [
                    'target_kind' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => ContentReportService::TARGET_KINDS,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'target_id' => [
                        'required' => true,
                        'type'     => 'integer',
                        'minimum'  => 1,
                    ],
                    'reason_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => ContentReportService::REASON_CODES,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'comment' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                ],
            ]
        );
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $targetKind = (string) $request->get_param('target_kind');
        $targetId   = (int) $request->get_param('target_id');
        $reasonCode = (string) $request->get_param('reason_code');
        $comment    = (string) ($request->get_param('comment') ?? '');

        $result = Plugin::instance()->contentReportService()->fileReport(
            $viewerId,
            $targetKind,
            $targetId,
            $reasonCode,
            $comment
        );

        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = self::statusForCode($code);
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), $status);
        }

        $resp = ApiResponse::ok($result);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    private static function statusForCode(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'    => 401,
            'bcc_forbidden'       => 403,
            'bcc_invalid_request' => 400,
            'bcc_rate_limited'    => 429,
            'bcc_unavailable'     => 503,
            default               => 400,
        };
    }
}
