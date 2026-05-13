<?php
/**
 * MyAccountEndpoint — handles /bcc/v1/me/account.
 *
 * Three routes for the Account sub-tab on /settings/profile (V2 Phase 2.5):
 *
 *   PATCH  /me/account/email     — change email (requires current password)
 *   PATCH  /me/account/password  — change password (requires current password)
 *   DELETE /me/account           — delete account (requires current password)
 *
 * Every route requires the user's current password as a write-amplification
 * gate. We do NOT rely on a session-elevation flag — every sensitive
 * mutation re-verifies. This matches PeepSo's profile-delete pattern at
 * `peepso/classes/profile.php::delete_profile`.
 *
 * Storage:
 *   - Email     → `wp_users.user_email` via `wp_update_user`
 *   - Password  → `wp_users.user_pass` via `wp_set_password`
 *   - Delete    → `wp_delete_user` (PeepSo's hooks fire on `delete_user`,
 *                  which fans out to its activity / friends / messages
 *                  cleanup), then `wp_logout` so the user's session ends
 *                  before the response returns.
 *
 * Account deletion is gated on the PeepSo `site_registration_allowdelete`
 * option to mirror PeepSo's UX-level toggle. When the admin has disabled
 * self-delete, DELETE returns 403.
 *
 * Auth: required. Self-only — `get_current_user_id()`. There is no admin
 * override here; admin-side user editing belongs in wp-admin.
 *
 * Cache: `no-store` on every response.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 Phase 2.5 (Account sub-tab)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyAccountEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Minimum new-password length. WordPress core has no hard floor; this is ours. */
    private const PASSWORD_MIN_LENGTH = 10;

    /** Confirmation token the client must echo back on DELETE. */
    private const DELETE_CONFIRMATION_TOKEN = 'DELETE';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/account/email',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patchEmail'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/account/password',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patchPassword'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/account',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'deleteAccount'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Email
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function patchEmail(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Rate-limit BEFORE verifyCurrentPassword. The endpoint is gated on
        // the current_password check — without a throttle, an attacker with
        // a session token but not the password could brute-force the gate
        // unboundedly. 5/60s per user mirrors the wallet_verify ceiling
        // (AuthEndpoint::VERIFY_RATE_LIMIT).
        if (!\BCC\Core\Security\Throttle::allow('account_email:' . $userId, 5, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $passwordCheck = self::verifyCurrentPassword($request, $userId);
        if ($passwordCheck instanceof WP_REST_Response) {
            return $passwordCheck;
        }

        $emailRaw = $request->get_param('email');
        if (!is_string($emailRaw)) {
            return ApiResponse::error('bcc_invalid_request', 'Field `email` is required.', 422);
        }
        $email = sanitize_email($emailRaw);
        if ($email === '' || !is_email($email)) {
            return ApiResponse::error('bcc_invalid_request', 'Email address is not valid.', 422);
        }

        // Ensure no other user owns this email (WP enforces uniqueness too,
        // but error message is generic — return a clearer 409 here).
        $existingId = email_exists($email);
        if ($existingId !== false && (int) $existingId !== $userId) {
            return ApiResponse::error(
                'bcc_conflict',
                'That email is already in use.',
                409
            );
        }

        $result = wp_update_user([
            'ID'         => $userId,
            'user_email' => $email,
        ]);
        if (is_wp_error($result)) {
            return ApiResponse::error(
                'bcc_internal_error',
                'Could not update email. Please try again.',
                500
            );
        }

        // Account-state mutation — log after the write commits. We do NOT
        // record the new email in meta to avoid duplicating PII in two
        // tables; the wp_users row is the source of truth.
        AuditLogger::log('account_email_changed', $userId, [], 'user', $userId);

        $resp = ApiResponse::ok(['email' => $email]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    // ──────────────────────────────────────────────────────────────────
    // Password
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function patchPassword(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Credential-rotation surface — must rate-limit BEFORE the
        // verifyCurrentPassword brute-force surface. Without this, a
        // session-hijacked attacker could brute-force the current_password
        // gate at machine speed.
        if (!\BCC\Core\Security\Throttle::allow('account_password:' . $userId, 5, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $passwordCheck = self::verifyCurrentPassword($request, $userId);
        if ($passwordCheck instanceof WP_REST_Response) {
            return $passwordCheck;
        }

        $newPassword = $request->get_param('password');
        if (!is_string($newPassword) || $newPassword === '') {
            return ApiResponse::error('bcc_invalid_request', 'Field `password` is required.', 422);
        }
        if (strlen($newPassword) < self::PASSWORD_MIN_LENGTH) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf('Password must be at least %d characters.', self::PASSWORD_MIN_LENGTH),
                422
            );
        }
        // wp_set_password rotates the hash AND clears the user's session
        // tokens, which logs them out everywhere except the current
        // session (we re-establish that below).
        wp_set_password($newPassword, $userId);

        // wp_set_password destroys all session tokens. Re-issue ours so
        // the user isn't kicked back to the login screen mid-flow.
        $user = get_user_by('id', $userId);
        if ($user instanceof \WP_User) {
            wp_clear_auth_cookie();
            wp_set_current_user($userId);
            wp_set_auth_cookie($userId, false);
        }

        // Credential rotation — log without the password value (obviously).
        AuditLogger::log('account_password_changed', $userId, [], 'user', $userId);

        $resp = ApiResponse::ok(['ok' => true]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    // ──────────────────────────────────────────────────────────────────
    // Delete
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteAccount(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Account deletion is irreversible — tightest bucket of the three
        // account routes. 3/60s per user is more than enough for legit
        // typos on the confirmation flow but blunt for any brute-force.
        // BEFORE verifyCurrentPassword so the credential gate is also
        // brute-force protected.
        if (!\BCC\Core\Security\Throttle::allow('account_delete:' . $userId, 3, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        if (!self::accountDeletionAllowed()) {
            return ApiResponse::error(
                'bcc_forbidden',
                'Account self-deletion is currently disabled. Contact an administrator.',
                403
            );
        }

        $passwordCheck = self::verifyCurrentPassword($request, $userId);
        if ($passwordCheck instanceof WP_REST_Response) {
            return $passwordCheck;
        }

        $confirm = $request->get_param('confirm');
        if (!is_string($confirm) || $confirm !== self::DELETE_CONFIRMATION_TOKEN) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf('Send `confirm: "%s"` to confirm account deletion.', self::DELETE_CONFIRMATION_TOKEN),
                422
            );
        }

        // wp_delete_user lives in wp-admin/includes/user.php — REST
        // requests don't have it auto-loaded.
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $deleted = wp_delete_user($userId);
        if ($deleted !== true) {
            return ApiResponse::error(
                'bcc_internal_error',
                'Could not delete account. Please try again.',
                500
            );
        }

        // Audit log the successful account deletion. Logged BEFORE wp_logout()
        // so get_current_user_id() is still meaningful, with explicit $userId
        // passed because the wp_users row is already gone — AuditLogger writes
        // the integer id directly to its own audit_log table, which persists
        // independently of the now-deleted user record.
        AuditLogger::log('account_deleted', $userId, [], 'user', $userId);

        // Kill the session before responding so the auth cookie no longer
        // resolves to a valid user.
        wp_logout();

        $resp = ApiResponse::ok([
            'deleted'    => true,
            'logout_url' => self::logoutRedirectUrl(),
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Verify the request's `current_password` field against the user's
     * stored hash. Returns null on success, an error envelope on failure.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    private static function verifyCurrentPassword(WP_REST_Request $request, int $userId): ?WP_REST_Response
    {
        $current = $request->get_param('current_password');
        if (!is_string($current) || $current === '') {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Field `current_password` is required.',
                422
            );
        }

        $user = get_user_by('id', $userId);
        if (!($user instanceof \WP_User)) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!wp_check_password($current, $user->user_pass, $user->ID)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Current password is incorrect.',
                422
            );
        }

        return null;
    }

    /**
     * Mirror PeepSo's `site_registration_allowdelete` setting — when the
     * admin disables it on the PeepSo settings screen, the headless
     * surface honors it too.
     */
    private static function accountDeletionAllowed(): bool
    {
        if (!class_exists('\\PeepSo')) {
            // PeepSo not loaded — be conservative and disallow.
            return false;
        }
        $optionValue = \PeepSo::get_option('site_registration_allowdelete', 0);
        return (int) $optionValue === 1;
    }

    /**
     * Where the frontend should redirect after a successful delete.
     * PeepSo exposes a `logout_redirect` page; fall back to home_url.
     */
    private static function logoutRedirectUrl(): string
    {
        if (class_exists('\\PeepSo')) {
            $url = \PeepSo::get_page('logout_redirect');
            if ($url !== '') {
                return $url;
            }
        }
        return home_url('/');
    }
}
