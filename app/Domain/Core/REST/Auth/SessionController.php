<?php
/**
 * SessionController — /auth session-lifecycle routes.
 *
 * Routes:
 *   - POST /auth/logout-everywhere — revoke every outstanding token
 *   - POST /auth/refresh           — silent-refresh an expired-OK JWT
 *
 * Split out of AuthEndpoint (Phase 11 architecture split #3 of 4). Route
 * blocks + handler bodies are VERBATIM; shared helpers + constants moved
 * to AuthSupport. No token-version, revocation, or JWT logic changed.
 *
 * @package BCC\Trust\Core\REST\Auth
 * @since V1 (Phase 11 split — 2026-06)
 */

namespace BCC\Trust\Core\REST\Auth;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AccountSecurityMailer;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\JwtToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class SessionController
{
    public static function register(): void
    {
        $instance = new self();

        // POST /auth/logout-everywhere — destructive: invalidate every
        // outstanding token for the current user (including the one
        // making this request). Closes the §J / Track-F stolen-session
        // threat-model loop: the user can respond to a suspected
        // hijack by nuking all sessions, then sign back in fresh.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
            '/auth/logout-everywhere',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'logoutEverywhere'],
                'permission_callback' => '__return_true',
            ]
        );

        // POST /auth/refresh — exchange a (recently-expired-OK) JWT for
        // a fresh one. Survivability seam for mobile clients whose
        // backgrounded app exits the 7-day TTL window. Auth is checked
        // inside the handler via JwtToken::decodeForRefresh on the
        // Bearer token; no session cookie required.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
            '/auth/refresh',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'refresh'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * POST /auth/logout-everywhere — destructive credential-class
     * mutation. Bumps the user's token-version counter so every
     * outstanding JWT (including the request's own bearer) fails the
     * version check on its next use. Writes a `sessions_revoked_all`
     * audit row + fires the AccountSecurityMailer confirmation email.
     *
     * The current token IS revoked; the response is the last
     * authenticated reply this session will get. The client MUST call
     * NextAuth `signOut()` immediately after a 200 so the local
     * session state matches the server.
     *
     * Throttle: 5/60/user (`logout_everywhere` bucket). Low cardinality —
     * a user has no reason to fire this more than a few times per
     * minute, and the destructive blast-radius justifies a tight cap.
     */
    public function logoutEverywhere(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Throttle BEFORE the destructive mutation — per
        // project_destructive_mutation_hardening Tier 1 doctrine:
        // Throttle::allow runs BEFORE credential gates.
        if (!\BCC\Core\Security\Throttle::allow('logout_everywhere', 5, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        // Audit row FIRST so the in-app timeline records the action
        // even if the mailer fails. AuditLogger never throws.
        AuditLogger::log('sessions_revoked_all', $userId, [], 'user', $userId);

        // Out-of-band email — Track-F redundancy. Never throws; failure
        // records a DegradationMetric and is silently swallowed.
        AccountSecurityMailer::sessionsRevokedAll($userId);

        // Mutation: bump the token-version meta. Every outstanding JWT
        // for this user fails ERR_REVOKED on next use, including the
        // bearer that authenticated THIS request.
        JwtToken::revokeAllForUser($userId);

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * POST /auth/refresh — silent-refresh path for clients whose
     * Bearer JWT has expired within the REFRESH_GRACE_SECONDS window.
     * Mobile-survivability seam.
     *
     * Contract:
     *   Input:  Authorization: Bearer <expired-or-near-expiry JWT>
     *   Output: { token, expires_in, token_type }      — canonical envelope
     *   Errors: bcc_unauthorized (missing/malformed bearer, JWT decode failed,
     *                             grace exceeded, user suspended)
     *           bcc_rate_limited (Throttle)
     *           bcc_invalid_state (handle missing — mirrors token())
     *
     * Security posture:
     *   - Every JWT check OTHER than `exp` is identical to the canonical
     *     decode path: signature, issuer, audience, version, revocation
     *     (tv claim) all enforced. A token whose signing-key rotated, or
     *     whose user was revoked via revokeAllForUser, can NEVER refresh.
     *   - Suspended users cannot refresh (Permissions::is_not_suspended).
     *     A user account flagged compromised mid-session loses refresh
     *     capability on the next attempt.
     *   - Per-user Throttle limits replay attempts on the grace window.
     *   - Grace window is bounded (REFRESH_GRACE_SECONDS = 86400). After
     *     that the path 401s and the user re-authenticates fully.
     *
     * Not in scope:
     *   - Rotating refresh tokens (single-use, separate from access JWT).
     *     V1 design accepts a stolen JWT being refreshable within grace;
     *     revokeAllForUser is the kill switch for compromised accounts.
     */
    public function refresh(WP_REST_Request $request): WP_REST_Response
    {
        // IP-keyed throttle (the caller is not yet authenticated by the
        // standard means — we're INSIDE the auth-recovery path).
        if (!\BCC\Core\Security\Throttle::allow('auth_refresh', AuthSupport::REFRESH_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many refresh attempts.', 429);
        }

        $authHeader = (string) ($request->get_header('Authorization') ?? '');
        if (stripos($authHeader, 'Bearer ') !== 0) {
            return ApiResponse::error('bcc_unauthorized', 'Bearer token required.', 401);
        }
        $token = trim(substr($authHeader, 7));
        if ($token === '') {
            return ApiResponse::error('bcc_unauthorized', 'Bearer token required.', 401);
        }

        $decoded = JwtToken::decodeForRefresh($token);
        if ($decoded['ok'] !== true) {
            // Don't leak the specific JWT failure code — every failure
            // is bcc_unauthorized to the client. The PHP-side audit log
            // is the place to inspect the underlying error if needed.
            return ApiResponse::error('bcc_unauthorized', 'Token cannot be refreshed.', 401);
        }

        $payload = $decoded['payload'];
        $userId  = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Token cannot be refreshed.', 401);
        }

        // Mid-session compromise mitigation: a user flagged suspended
        // can no longer refresh, forcing them through the canonical
        // signOut → re-login path where the suspension is surfaced.
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId)) {
            return ApiResponse::error('bcc_forbidden', 'Account is not in good standing.', 403);
        }

        // Re-read the handle from authoritative storage rather than
        // trusting the JWT payload — a handle change since the original
        // mint should be reflected in the fresh token.
        $handle = (string) get_user_meta($userId, HandleService::META_HANDLE, true);
        if ($handle === '') {
            return ApiResponse::error(
                'bcc_invalid_state',
                'Account is missing a handle — re-login required.',
                409
            );
        }

        $fresh = JwtToken::encode($userId, $handle);

        Logger::audit('token_refreshed', [
            'user_id' => $userId,
        ]);

        $response = ApiResponse::ok([
            'token'      => $fresh,
            'expires_in' => AuthSupport::JWT_TTL_SECONDS,
            'token_type' => 'Bearer',
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
