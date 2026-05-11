<?php
/**
 * MyBlocksEndpoint — handles /bcc/v1/me/blocks routes (§K1 Phase A).
 *
 * Routes:
 *   - GET    /me/blocks           — paginated list of blocked users
 *   - POST   /me/blocks           — body {user_id} blocks the target
 *   - DELETE /me/blocks/:user_id  — unblock the target
 *
 * Auth: required. Self-only — no admin endpoint to see-or-edit
 * another user's blocks (the audit trail for that lives on the
 * `peepso_user_blocked` / `bcc_user_blocked` action subscribers).
 *
 * Self-block protection is double-layered:
 *   - the writer rejects blocker_id == blocked_id
 *   - the endpoint rejects too, with a clearer error code
 *
 * Cache: `no-store`. Mutations + per-viewer scope.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §K1 Phase A blocks)
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\PeepSo\PeepSoBlockWriter;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyBlocksEndpoint
{
    private const ROUTE_NAMESPACE   = 'bcc/v1';
    private const DEFAULT_PER_PAGE  = 20;
    private const MAX_PER_PAGE      = 50;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/blocks',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$instance, 'list'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'page' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'default'           => 1,
                            'minimum'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                        'per_page' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'default'           => self::DEFAULT_PER_PAGE,
                            'minimum'           => 1,
                            'maximum'           => self::MAX_PER_PAGE,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$instance, 'create'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'user_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'minimum'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/blocks/(?P<user_id>\d+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'remove'],
                'permission_callback' => '__return_true',
                'args' => [
                    'user_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $page    = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');
        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $payload = Plugin::instance()->myBlocksService()->getMyBlocks($viewerId, $page, $perPage);

        $resp = ApiResponse::ok($payload);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $targetId = (int) $request->get_param('user_id');
        if ($targetId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Target user is required.', 400);
        }
        if ($targetId === $viewerId) {
            return ApiResponse::error('bcc_invalid_request', "You can't block yourself.", 400);
        }

        // Confirm the target exists so a malformed POST returns a 404
        // rather than silently writing a row pointing at a phantom user.
        $target = get_userdata($targetId);
        if ($target === false) {
            return ApiResponse::error('bcc_not_found', 'User not found.', 404);
        }

        $result = PeepSoBlockWriter::block($viewerId, $targetId);
        if ($result === 'error') {
            return ApiResponse::error('bcc_unavailable', 'Could not record the block.', 503);
        }
        if ($result === 'invalid') {
            // Defense in depth — endpoint already rejected self-blocks.
            return ApiResponse::error('bcc_invalid_request', 'Invalid block target.', 400);
        }

        // Audit only the state transition (the first successful block), not
        // the idempotent re-block — the latter is a no-op in storage and a
        // repeated log entry would inflate the trail without new information.
        if ($result === 'created') {
            AuditLogger::log('user_blocked', $targetId, [], 'user', $viewerId);
        }

        $resp = ApiResponse::ok([
            'ok'      => true,
            'user_id' => $targetId,
            // 'created' on first block, 'existing' on idempotent retry —
            // frontend can vary the toast copy ("Blocked" vs "Already blocked").
            'state'   => $result,
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public function remove(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $targetId = (int) $request->get_param('user_id');
        if ($targetId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Target user is required.', 400);
        }

        $deleted = PeepSoBlockWriter::unblock($viewerId, $targetId);

        // Audit only the state transition (an actual row deletion); a
        // double-tap unblock against a no-longer-existing row yields
        // $deleted = false and gets no log line.
        if ($deleted) {
            AuditLogger::log('user_unblocked', $targetId, [], 'user', $viewerId);
        }

        // Idempotent: returning ok=true even when no row existed lets a
        // double-tap unblock succeed without confusing the UI.
        $resp = ApiResponse::ok([
            'ok'      => true,
            'user_id' => $targetId,
            'removed' => $deleted,
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

}
