<?php
/**
 * MyAccountEndpoint — handles /bcc/v1/me/account.
 *
 * Three routes for the Account sub-tab on /settings/profile (V2 Phase 2.5):
 *
 *   PATCH  /me/account/email     — request an email change (requires current
 *                                   password); the new address is held PENDING
 *                                   and an OTP is mailed to it
 *   POST   /me/account/email/verify — confirm the OTP; only then does the new
 *                                   address replace user_email (verified), and
 *                                   earlier Bearer tokens are revoked
 *   PATCH  /me/account/password  — change password (requires current password)
 *   DELETE /me/account           — delete account (requires current password)
 *
 * Every route requires the user's current password as a write-amplification
 * gate, or (F-253) a fresh signature from one of their verified wallets over
 * a purpose-bound challenge (AccountCredentialCheck) - wallet-only members
 * never had a password. We do NOT rely on a session-elevation flag — every sensitive
 * mutation re-verifies. This matches PeepSo's profile-delete pattern at
 * `peepso/classes/profile.php::delete_profile`.
 *
 * Storage:
 *   - Email     → `wp_users.user_email` via `wp_update_user`, only after the
 *                  OTP confirms the new address (PendingEmailService)
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
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\AccountSecurityMailer;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Services\PendingEmailService;
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
            '/me/account/email/verify',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'verifyEmailChange'],
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

        // Rate-limit BEFORE the credential check. The endpoint is gated on
        // the current_password check — without a throttle, an attacker with
        // a session token but not the password could brute-force the gate
        // unboundedly. 5/60s per user mirrors the wallet_verify ceiling
        // (AuthEndpoint::VERIFY_RATE_LIMIT).
        if (!\BCC\Core\Security\Throttle::allow('account_email:' . $userId, 5, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        // Format check BEFORE the credential check (F-253): it reveals
        // nothing, and a typo must not burn a signed wallet challenge. The
        // "already in use" check stays AFTER it — it says whether an address
        // is registered, which a session alone must not learn.
        $emailRaw = $request->get_param('email');
        if (!is_string($emailRaw)) {
            return ApiResponse::error('bcc_invalid_request', 'Field `email` is required.', 422);
        }
        $email = sanitize_email($emailRaw);
        if ($email === '' || !is_email($email)) {
            return ApiResponse::error('bcc_invalid_request', 'Email address is not valid.', 422);
        }

        $confirmedBy = AccountCredentialCheck::confirm(
            $request,
            $userId,
            AccountCredentialCheck::PURPOSE_EMAIL_CHANGE
        );
        if ($confirmedBy instanceof WP_REST_Response) {
            return $confirmedBy;
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

        $existingUser = get_userdata($userId);
        $currentEmail = $existingUser instanceof \WP_User ? (string) $existingUser->user_email : '';
        if (strtolower($currentEmail) === strtolower($email)) {
            // Nothing to change — and nothing to prove.
            $resp = ApiResponse::ok(['ok' => true, 'pending' => false]);
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }

        // Verify-before-promote. The new address is NOT written to user_email
        // here: it is held pending and an OTP goes to the NEW inbox; only
        // POST /me/account/email/verify with that code promotes it (and marks
        // it verified). Until then the account keeps its current address and
        // verification state. Writing it straight away let a password holder
        // re-point a VERIFIED account at an address they don't own, with the
        // '1' carried over — so the real owner's later Google sign-in linked
        // into their account (pre-hijack). A new request supersedes any
        // earlier pending one.
        $otp = PendingEmailService::stage($userId, $email, PendingEmailService::PURPOSE_CHANGE);
        AuthMailer::sendEmailChangeOtp($email, $otp);

        // We do NOT record the new email in meta/audit to avoid duplicating
        // PII; the canary mails go out when the change is confirmed.
        AuditLogger::log('account_email_change_requested', $userId, ['confirmed_by' => $confirmedBy], 'user', $userId);

        $resp = ApiResponse::ok([
            'ok'           => true,
            'pending'      => true,
            'email_masked' => PendingEmailService::maskEmail($email),
            'expires_in'   => PendingEmailService::PENDING_TTL,
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * POST /me/account/email/verify — confirm the pending email change.
     *
     * Body: { code }. A correct code promotes the pending address to
     * user_email and marks it verified (PendingEmailService::confirm). The
     * account's credentials just changed, so — exactly like patchPassword —
     * every earlier Bearer token is revoked and a fresh one is returned for
     * the caller (`token`, `expires_in`, `token_type`); the client must store
     * it. Both addresses get the change canary.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function verifyEmailChange(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        // Mirrors the recovery-email verify ceiling: a 6-digit OTP under
        // 10/hour per user + a 15-minute TTL is not brute-forceable.
        if (!\BCC\Core\Security\Throttle::allow('account_email_verify:' . $userId, 10, 3600)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many attempts. Please wait.', 429);
        }

        $code = trim((string) $request->get_param('code'));
        if ($code === '') {
            return ApiResponse::error('bcc_invalid_request', 'Verification code is required.', 422);
        }

        $confirmed = PendingEmailService::confirm($userId, $code, PendingEmailService::PURPOSE_CHANGE);
        if (!$confirmed['ok']) {
            switch ($confirmed['error']) {
                case PendingEmailService::ERR_NONE:
                    return ApiResponse::error('bcc_invalid_request', 'No pending email change. Please start again.', 400);
                case PendingEmailService::ERR_BAD_CODE:
                    // Distinct code (same one /auth/verify-email uses for a
                    // wrong code) so a client tells "retry the code" from
                    // "start again" (400 above) without relying on status alone.
                    return ApiResponse::error('bcc_invalid_otp', 'Incorrect code. Check the latest email and try again.', 422);
                case PendingEmailService::ERR_CONFLICT:
                    return ApiResponse::error('bcc_conflict', 'That email is already in use.', 409);
                default:
                    return ApiResponse::error('bcc_internal_error', 'Could not update email. Please try again.', 500);
            }
        }
        $newEmail = $confirmed['email'];
        $oldEmail = $confirmed['old_email'];

        // Revoke FIRST, then mint — the fresh token carries the bumped
        // version; every session minted before the change now fails
        // ERR_REVOKED. (Same order and reason as patchPassword.) The fresh
        // token CONTINUES this session (F-253): read when it began before
        // the revoke invalidates the presented token.
        $sessionStart = \BCC\Trust\Core\Support\JwtToken::presentedSessionStartedAt($userId);
        \BCC\Trust\Core\Support\JwtToken::revokeAllForUser($userId);
        $handle     = (string) get_user_meta($userId, \BCC\Trust\Core\Services\HandleService::META_HANDLE, true);
        $freshToken = \BCC\Trust\Core\Support\JwtToken::encodeContinuation($userId, $handle, $sessionStart);

        // Account-state mutation — log after the write commits.
        AuditLogger::log('account_email_changed', $userId, [], 'user', $userId);

        // Side-channel notification: tell BOTH addresses out-of-band so a
        // compromised-session attacker can't silently rotate the email and
        // lock the legitimate user out. A placeholder has no inbox — notify
        // the new address only. Best-effort; never throws.
        if ($oldEmail === '' || AccountRecoveryService::isPlaceholderEmail($oldEmail)) {
            AccountSecurityMailer::recoveryEmailConfirmed($userId, $newEmail);
        } elseif (strtolower($oldEmail) !== strtolower($newEmail)) {
            AccountSecurityMailer::emailChanged($userId, $oldEmail, $newEmail);
        }

        $resp = ApiResponse::ok([
            'ok'         => true,
            'email'      => $newEmail,
            'token'      => $freshToken,
            'expires_in' => \BCC\Trust\Core\Support\JwtToken::TTL_SECONDS,
            'token_type' => 'Bearer',
        ]);
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
        // credential-check brute-force surface. Without this, a
        // session-hijacked attacker could brute-force the current_password
        // gate at machine speed.
        if (!\BCC\Core\Security\Throttle::allow('account_password:' . $userId, 5, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        // New-password checks BEFORE the credential check (F-253): they
        // reveal nothing, and a too-short password must not burn a signed
        // wallet challenge.
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

        // A wallet-only member SETS their first password here, confirming
        // with a wallet signature (they have no current one).
        $confirmedBy = AccountCredentialCheck::confirm(
            $request,
            $userId,
            AccountCredentialCheck::PURPOSE_PASSWORD_CHANGE
        );
        if ($confirmedBy instanceof WP_REST_Response) {
            return $confirmedBy;
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

        // Revoke every outstanding bearer JWT (bump the token-version counter)
        // so a token stolen before this rotation can't outlive the password
        // change — the bearer analogue of wp_set_password clearing WP sessions.
        // Order matters: revoke FIRST, then mint, so the fresh token carries
        // the bumped version and survives; every OTHER token now fails
        // ERR_REVOKED. The new token is returned below so the caller's own
        // session stays alive. [audit H-2] It CONTINUES this session (F-253):
        // read when it began before the revoke.
        $sessionStart = \BCC\Trust\Core\Support\JwtToken::presentedSessionStartedAt($userId);
        \BCC\Trust\Core\Support\JwtToken::revokeAllForUser($userId);
        $handle    = (string) get_user_meta($userId, \BCC\Trust\Core\Services\HandleService::META_HANDLE, true);
        $freshToken = \BCC\Trust\Core\Support\JwtToken::encodeContinuation($userId, $handle, $sessionStart);

        // A rotation cuts off every other token, so it must also cut off
        // every device's push subscription — including the caller's own,
        // which its client re-registers. [F-76]
        (new \BCC\Trust\Core\Repositories\PushSubscriptionRepository())->deleteAllForUser($userId);

        // Credential rotation — log without the password value (obviously).
        AuditLogger::log('account_password_changed', $userId, ['confirmed_by' => $confirmedBy], 'user', $userId);

        // Side-channel notification: tell the user via email out-of-band
        // so a session-hijack-then-rotate attack is detectable.
        AccountSecurityMailer::passwordChanged($userId);

        $resp = ApiResponse::ok([
            'ok'         => true,
            'token'      => $freshToken,
            'expires_in' => \BCC\Trust\Core\Support\JwtToken::TTL_SECONDS,
            'token_type' => 'Bearer',
        ]);
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
        // BEFORE the credential check so the credential gate is also
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

        // The typed confirmation BEFORE the credential check (F-253): it
        // reveals nothing, and a missed word must not burn a signed wallet
        // challenge.
        $confirm = $request->get_param('confirm');
        if (!is_string($confirm) || $confirm !== self::DELETE_CONFIRMATION_TOKEN) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf('Send `confirm: "%s"` to confirm account deletion.', self::DELETE_CONFIRMATION_TOKEN),
                422
            );
        }

        $confirmedBy = AccountCredentialCheck::confirm(
            $request,
            $userId,
            AccountCredentialCheck::PURPOSE_ACCOUNT_DELETE
        );
        if ($confirmedBy instanceof WP_REST_Response) {
            return $confirmedBy;
        }

        // wp_delete_user lives in wp-admin/includes/user.php — REST
        // requests don't have it auto-loaded.
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        // Capture the email + display name BEFORE wp_delete_user so the
        // side-channel security email can still be sent — after deletion
        // the wp_users row is gone and get_userdata() returns false.
        $deletingUser = get_userdata($userId);
        $deletingEmail = $deletingUser instanceof \WP_User ? (string) $deletingUser->user_email : '';
        $deletingName  = $deletingUser instanceof \WP_User ? (string) $deletingUser->display_name : '';

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
        AuditLogger::log('account_deleted', $userId, ['confirmed_by' => $confirmedBy], 'user', $userId);

        // Side-channel notification using the email captured before deletion.
        // The user record is gone but the audit_log row + this email are the
        // two reconstructable signals. Best-effort; never throws.
        if ($deletingEmail !== '') {
            AccountSecurityMailer::accountDeleted($deletingEmail, $deletingName);
        }

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
