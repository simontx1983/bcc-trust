<?php
/**
 * My Account Activity Endpoint — in-app half of the §J / Track-F
 * security trail.
 *
 * The user can verify the email side-channel (`AccountSecurityMailer`)
 * against an in-app timeline of credential-class events on their own
 * account: email change, password change, account deletion request,
 * wallet link/unlink, sessions-revoked-all.
 *
 * Routes:
 *   GET /bcc/v1/me/account-activity   — paginated security event timeline
 *
 * Self-only by construction: 401 anonymous, 403 suspended. There is no
 * admin override and no cross-user read; the controller filters on
 * `get_current_user_id()`, the repository's bounded query keys on
 * `user_id`. Cross-user data is structurally unreachable.
 *
 * Server-side action allowlist: even though `bcc_trust_activity` also
 * stores behavioural events (vote_cast, page_view, endorse_*, etc.),
 * this endpoint surfaces only the six user-facing security events
 * that correspond 1:1 to AccountSecurityMailer emails. Non-security
 * activity does NOT bleed into account settings.
 *
 * Sensitive fields:
 *   - ip_address (VARBINARY)  → masked at the boundary (last octet for
 *                                 IPv4, last 64 bits for IPv6) before
 *                                 emission. Security-through-obscurity
 *                                 posture for the case where the user's
 *                                 own audit log is later compromised.
 *   - user_agent / device fingerprint / session tokens — not stored in
 *                                 the bcc_trust_activity schema, so
 *                                 no risk of accidental exposure.
 *
 * Cache: no-store (per-user state).
 *
 * @package BCC\Trust\Core\REST
 * @since   Tier D (2026-05-16)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Security\Throttle;
use BCC\Trust\Core\Repositories\AuditLogRepository;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyAccountActivityEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /**
     * Server-side action allowlist — the six security events that
     * correspond 1:1 to AccountSecurityMailer emails. Even if
     * AuditLogger writes other action types (vote_*, endorse_*, etc.)
     * to the same table, this endpoint surfaces only these.
     *
     * @var list<string>
     */
    private const USER_FACING_ACTIONS = [
        'account_email_changed',
        'account_password_changed',
        'account_deleted',
        'wallet_linked',
        'wallet_unlinked',
        'sessions_revoked_all',
    ];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/account-activity',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'handle'],
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
                        'default'           => 20,
                        'minimum'           => 1,
                        'maximum'           => 50,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!is_user_logged_in()) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended()) {
            return ApiResponse::error('bcc_forbidden', 'Account suspended.', 403);
        }

        if (!Throttle::allow('me_account_activity', 30, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $userId  = get_current_user_id();
        $page    = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(50, (int) $request->get_param('per_page')));

        $repo  = new AuditLogRepository();
        $rows  = $repo->getByUserIdPaginated($userId, $page, $perPage, self::USER_FACING_ACTIONS);
        $total = $repo->countByUserId($userId, self::USER_FACING_ACTIONS);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'          => (int) $row->id,
                'action'      => (string) $row->action,
                'target_type' => (string) $row->target_type,
                'target_id'   => (int) $row->target_id,
                'ip_masked'   => AuditLogRepository::maskIpBinary($row->ip_address ?? null),
                'created_at'  => (string) $row->created_at,
            ];
        }

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

        $response = ApiResponse::ok([
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
