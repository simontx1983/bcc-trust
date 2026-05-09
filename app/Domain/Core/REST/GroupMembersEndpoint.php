<?php
/**
 * Group Members Endpoint — handles
 *   GET /bcc/v1/groups/{id}/members  (§4.7.7 paginated roster)
 *
 * Auth-OPTIONAL: anonymous viewers may read open-group rosters; closed
 * and secret group rosters are gated server-side per the §S
 * defense-in-depth model. The privacy gate runs BEFORE any SQL fetch
 * so timing-based existence leaks aren't possible.
 *
 * Three privacy outcomes (single source of truth — {@see
 * GroupsService::resolveGroupAccess} + the members-specific 403 layer
 * in {@see GroupMembersService::listMembers}):
 *
 *   secret + non-member  → 404 bcc_not_found        (don't leak existence)
 *   secret + member      → 200 with roster
 *   closed + non-member  → 403 bcc_permission_denied (group exists, list withheld)
 *   closed + member      → 200 with roster
 *   open   + anyone      → 200 with roster
 *
 * Cache:
 *   - 200 + authed       → private, no-store          (per-viewer summary signals)
 *   - 200 + anon (open)  → public, max-age=300        (no per-viewer state in payload)
 *   - 404 / 403          → private, no-store          (don't poison shared caches with denials)
 *
 * Pagination: offset envelope (offset / limit / total / has_more) per
 * §4.7.7. Cursor pagination would be wrong here — the roster is
 * stable-ordered by (role_rank, joined_at DESC) so offset paginates
 * correctly without timestamp drift.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 (group-detail page; PR 2; 2026-05-08)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\GroupMembersService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupMembersEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/groups/(?P<id>\d+)/members',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => 0,
                        'minimum'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'limit' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => GroupMembersService::DEFAULT_LIMIT,
                        'minimum'           => 1,
                        'maximum'           => GroupMembersService::MAX_LIMIT,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $groupId = (int) $request->get_param('id');
        if ($groupId <= 0) {
            $response = ApiResponse::error('bcc_invalid_request', 'Invalid group id.', 400);
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        }

        $offset = (int) $request->get_param('offset');
        if ($offset < 0) {
            $offset = 0;
        }

        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = GroupMembersService::DEFAULT_LIMIT;
        }
        if ($limit > GroupMembersService::MAX_LIMIT) {
            $limit = GroupMembersService::MAX_LIMIT;
        }

        $viewerId = get_current_user_id();
        $result   = Plugin::instance()->groupMembersService()->listMembers(
            $viewerId,
            $groupId,
            $offset,
            $limit
        );

        // null → 404 (group missing OR secret + non-member; per §S we
        // never distinguish these two on the wire).
        if ($result === null) {
            $response = ApiResponse::error('bcc_not_found', 'Group not found.', 404);
            // Don't poison shared caches with permission-denied responses.
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        }

        // Sentinel → 403. Service-level error envelope (matches
        // LocalsService's join/leave convention).
        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $msg    = isset($result['message']) ? (string) $result['message'] : '';
            $response = ApiResponse::error($code, $msg, 403);
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        }

        // 200 OK.
        $response = ApiResponse::ok($result);
        // Anon + open-group: payload carries no per-viewer state (the
        // UserSummary shape is identical for any viewer per §3.1
        // — wallet.address is gated on $isSelf and never appears here
        // because we hydrate per-row, not per-self). 5-min shared
        // cache is safe and matches the §4.7.7 contract.
        // Authed viewer: UserSummary still doesn't carry per-viewer
        // gating today, but `private, no-store` matches the §4.7.5
        // detail endpoint posture so a future per-viewer field
        // addition (e.g. blocked_by_viewer) doesn't silently leak
        // through a stale shared cache.
        $cacheCtl = $viewerId > 0 ? 'private, no-store' : 'public, max-age=300';
        $response->header('Cache-Control', $cacheCtl);
        if ($viewerId > 0) {
            $response->header('Vary', 'Authorization, Cookie');
        }

        return $response;
    }
}
