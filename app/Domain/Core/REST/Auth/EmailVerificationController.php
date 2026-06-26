<?php
/**
 * EmailVerificationController — /auth email-verification routes.
 *
 * Routes:
 *   - POST /auth/verify-email        — consume OTP or one-shot token → JWT
 *   - POST /auth/resend-verification — send a fresh OTP + verify link
 *
 * Split out of AuthEndpoint (Phase 11 architecture split #3 of 4). Route
 * blocks + handler bodies are VERBATIM; shared helpers + constants moved
 * to AuthSupport. No OTP, token, or key string changed.
 *
 * @package BCC\Trust\Core\REST\Auth
 * @since V1 (Phase 11 split — 2026-06)
 */

namespace BCC\Trust\Core\REST\Auth;

use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\FrontendRedirect;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class EmailVerificationController
{
    public static function register(): void
    {
        $instance = new self();

        // POST /auth/verify-email — consume an OTP code or a one-shot
        // token to confirm email ownership and complete signup. On
        // success, marks the account verified and returns a JWT so the
        // user is immediately signed in without a separate login step.
        // Accepts either:
        //   { email, code }  — OTP typed by the user (15-min TTL)
        //   { token }        — one-shot link token from the email (24-h TTL)
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
            '/auth/verify-email',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'verifyEmail'],
                'permission_callback' => '__return_true',
                'args' => [
                    'email' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'code' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'token' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // POST /auth/resend-verification — send a fresh OTP + verify
        // link to the given email address. Always returns ok=true
        // (anti-enumeration). Rate-limited per IP to prevent email-bomb
        // abuse against any one inbox.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
            '/auth/resend-verification',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'resendVerification'],
                'permission_callback' => '__return_true',
                'args' => [
                    'email' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                ],
            ]
        );
    }

    /**
     * POST /auth/verify-email — confirm email ownership and complete signup.
     *
     * Accepts two verification paths in the same endpoint:
     *
     *   OTP path   { email, code }  — user types the 6-digit code from the
     *                                  email into the /verify-email page.
     *                                  TTL: 15 minutes. Rate-limited.
     *
     *   Token path { token }        — user clicks the link/button in the
     *                                  email. Self-identifying (token encodes
     *                                  the userId). TTL: 24 hours. Single-use.
     *
     * On success via either path: sets _bcc_email_verified = '1', deletes
     * the OTP transient, sets the WP auth cookie, and mints a JWT so the
     * user is signed in without a separate /auth/login roundtrip.
     *
     * Already-verified accounts return bcc_already_verified (409) on the
     * OTP path; the token path silently succeeds (idempotent re-verify is
     * harmless and avoids a confusing error when a user double-clicks).
     */
    public function verifyEmail(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('verify_email', AuthSupport::VERIFY_EMAIL_RATE_LIMIT, 3600)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many attempts. Try again later.', 429);
        }

        $email = sanitize_email((string) $request->get_param('email'));
        $code  = (string) $request->get_param('code');
        $token = (string) $request->get_param('token');

        if ($token === '' && ($email === '' || $code === '')) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Supply a verification token or an email and code.',
                422
            );
        }

        // ── Token path ────────────────────────────────────────────────
        if ($token !== '') {
            $userId = AuthSupport::consumeVerifyToken($token);
            if ($userId === null) {
                return ApiResponse::error(
                    'bcc_invalid_verify_token',
                    'This link has expired or has already been used.',
                    400
                );
            }
            return AuthSupport::finalizeVerification($userId);
        }

        // ── OTP path ──────────────────────────────────────────────────
        $user = get_user_by('email', $email);
        if (!($user instanceof \WP_User)) {
            // Generic error — don't reveal whether the address is registered.
            return ApiResponse::error('bcc_invalid_otp', 'Invalid or expired code.', 400);
        }

        $userId = (int) $user->ID;

        $emailVerified = (string) get_user_meta($userId, AuthSupport::META_EMAIL_VERIFIED, true);
        if ($emailVerified === '1') {
            return ApiResponse::error('bcc_already_verified', 'This email is already verified.', 409);
        }

        if (!AuthSupport::consumeOtpForUser($userId, $code)) {
            return ApiResponse::error('bcc_invalid_otp', 'Invalid or expired code.', 400);
        }

        return AuthSupport::finalizeVerification($userId);
    }

    /**
     * POST /auth/resend-verification — send a fresh OTP + verify link.
     *
     * Always returns ok=true regardless of whether the email matches a
     * registered unverified account (anti-enumeration; mirrors forgotPassword).
     * Side effects fire only when a real account with _bcc_email_verified = '0'
     * is found.
     *
     * On match: invalidates the existing OTP transient (overwrite), generates
     * a fresh OTP + token, stores both, and dispatches a new email. Old
     * verify-token transients are left to expire naturally (they're keyed by
     * the token string, not by userId, so they can't be bulk-deleted without
     * a full scan; their 24-hour TTL is short enough that this is safe).
     */
    public function resendVerification(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('resend_verification', AuthSupport::RESEND_VERIFICATION_RATE_LIMIT, 3600)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Try again later.', 429);
        }

        $email = sanitize_email((string) $request->get_param('email'));
        if ($email === '' || !is_email($email)) {
            return ApiResponse::error('bcc_invalid_request', 'A valid email is required.', 422);
        }

        $user = get_user_by('email', $email);

        if ($user instanceof \WP_User) {
            $userId        = (int) $user->ID;
            $emailVerified = (string) get_user_meta($userId, AuthSupport::META_EMAIL_VERIFIED, true);

            // Only resend for accounts that are explicitly pending
            // verification. Already-verified and legacy accounts are
            // silently skipped — the anti-enumeration response hides which.
            if ($emailVerified === '0') {
                // Overwrite the existing OTP (if any) so the previous code
                // immediately stops working — only the new email is valid.
                $otpCode     = AuthSupport::generateOtp();
                $verifyToken = AuthSupport::generateVerifyToken();
                AuthSupport::storeOtpForUser($userId, $otpCode);
                AuthSupport::storeVerifyToken($verifyToken, $userId);

                $verifyPath = '/verify-email?email=' . rawurlencode($email) . '&token=' . rawurlencode($verifyToken);
                $verifyUrl  = FrontendRedirect::defaultReturn($verifyPath);
                AuthMailer::sendVerificationEmail($userId, $email, $otpCode, $verifyUrl);
            }
        }

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
