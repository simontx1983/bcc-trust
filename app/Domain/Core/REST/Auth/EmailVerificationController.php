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

use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\ApiResponse;
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
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    // F-294: an email OR a handle, so an unverified sign-in
                    // by handle can offer "resend" (it knows no email).
                    'identifier' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
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
        // F-290: refused calls aren't recorded; the per-code cap below is
        // the brute-force guard.
        if (!\BCC\Core\Security\Throttle::allow('verify_email', AuthSupport::VERIFY_EMAIL_RATE_LIMIT, 3600, null, false)) {
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

        // F-294: the code was burned by too many wrong attempts and no new
        // one has been sent. Say so, instead of "invalid or expired", so the
        // user knows to request a new code rather than retype this one.
        if (AuthSupport::emailOtpIdentity($userId) === null && get_transient(self::OTP_EXHAUSTED_PREFIX . $userId) !== false) {
            return self::otpExhausted();
        }

        // Count EVERY attempt BEFORE comparing (L2) — see TwoFactorController:
        // caps comparisons per (user, current code) at VERIFY_EMAIL_MAX_FAILURES
        // even under concurrent guesses; an attempt beyond it burns the code
        // without comparing. Same generic answer. Only while a code is pending.
        $otpId = AuthSupport::emailOtpIdentity($userId);
        if ($otpId !== null && !\BCC\Core\Security\Throttle::allow(
            'verify_email_attempt',
            AuthSupport::VERIFY_EMAIL_MAX_FAILURES,
            AuthSupport::OTP_TTL,
            'verify_email_attempt:' . $userId . ':' . $otpId
        )) {
            return self::exhaustOtp($userId);
        }

        if (!AuthSupport::consumeOtpForUser($userId, $code)) {
            // Attempt cap (F-48), per (user, current code): a correct code
            // signs the caller in, so guesses spread across IPs must be
            // bounded per account. A Throttle bucket with an EXPLICIT key
            // (never split by client IP) counts wrong codes over the code's
            // lifetime; the MAX-th burns the code. Keyed on the code's
            // identity, so a resend starts a fresh count. The emailed link
            // (a separate, unguessable token) and resend keep working, and
            // the answer stays the generic one — no new enumeration signal.
            // A degraded limiter fails closed (burns).
            if ($otpId !== null && !\BCC\Core\Security\Throttle::allow(
                'verify_email_fail',
                AuthSupport::VERIFY_EMAIL_MAX_FAILURES - 1,
                AuthSupport::OTP_TTL,
                'verify_email_fail:' . $userId . ':' . $otpId
            )) {
                return self::exhaustOtp($userId);
            }
            return ApiResponse::error('bcc_invalid_otp', 'Invalid or expired code.', 400);
        }

        return AuthSupport::finalizeVerification($userId);
    }

    /**
     * F-294: a code burned by the attempt cap (F-48) used to answer the
     * generic "Invalid or expired code.", so a user who then typed the right
     * code was told it was wrong, with no hint to ask for a new one. The
     * burning attempt, and every attempt after it until a resend, now
     * answers bcc_otp_exhausted (user-approved). It is only reachable after
     * the cap on an account with a pending code; sign-up already says
     * whether an email has an account, so this adds nothing an attacker
     * lacks.
     */
    private const OTP_EXHAUSTED_PREFIX = 'bcc_otp_exhausted_';

    private static function exhaustOtp(int $userId): WP_REST_Response
    {
        AuthSupport::burnEmailOtp($userId);
        set_transient(self::OTP_EXHAUSTED_PREFIX . $userId, '1', AuthSupport::OTP_TTL);
        \BCC\Core\Log\Logger::audit('verify_email_attempts_exhausted', ['user_id' => $userId]);
        return self::otpExhausted();
    }

    private static function otpExhausted(): WP_REST_Response
    {
        return ApiResponse::error('bcc_otp_exhausted', 'Too many attempts. Request a new code.', 400);
    }

    /**
     * POST /auth/resend-verification — send a fresh OTP + verify link.
     *
     * Always returns ok=true regardless of whether the email matches a
     * registered unverified account (anti-enumeration; mirrors forgotPassword).
     * Side effects fire only when a real account with _bcc_email_verified = '0'
     * (pending) or '' (legacy, never verified) is found, and its address is
     * not a wallet placeholder.
     *
     * On match: invalidates the existing OTP transient (overwrite), generates
     * a fresh OTP + token, stores both, and dispatches a new email. Old
     * verify-token transients are left to expire naturally (they're keyed by
     * the token string, not by userId, so they can't be bulk-deleted without
     * a full scan; their 24-hour TTL is short enough that this is safe).
     */
    public function resendVerification(WP_REST_Request $request): WP_REST_Response
    {
        // F-290: as forgot-password — the network bucket doesn't record
        // refused calls; the per-address mail caps count every request.
        if (!\BCC\Core\Security\Throttle::allow('resend_verification', AuthSupport::RESEND_VERIFICATION_RATE_LIMIT, 3600, null, false)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Try again later.', 429);
        }

        // F-294: `email`, or `identifier` (an email OR a handle). A handle
        // resolves through the account's CURRENT handle, and the mail goes
        // to the address on file; the response never says which, or whether.
        $email      = sanitize_email((string) $request->get_param('email'));
        $identifier = trim((string) $request->get_param('identifier'));
        $user       = false;

        if ($email === '' && $identifier !== '' && is_email($identifier)) {
            $email = sanitize_email($identifier);
        }
        if ($email !== '') {
            if (!is_email($email)) {
                return ApiResponse::error('bcc_invalid_request', 'A valid email is required.', 422);
            }
            // F-290: the per-network bucket above is office scale; this caps mail
            // to any one inbox, before the lookup and whether or not it exists.
            if (!AuthSupport::allowMailToAddress(
                'resend_verification',
                $email,
                AuthSupport::RESEND_VERIFICATION_EMAIL_RATE_LIMIT,
                AuthSupport::RESEND_VERIFICATION_EMAIL_GLOBAL_RATE_LIMIT
            )) {
                AuthSupport::releaseThrottle('resend_verification', 3600);
                return ApiResponse::error('bcc_rate_limited', 'Too many requests. Try again later.', 429);
            }
            $user = get_user_by('email', $email);
        } elseif ($identifier !== '') {
            $handle = ltrim(strtolower($identifier), '@');
            // F-290 for the handle form: the same caps, keyed on the handle and
            // checked BEFORE the lookup, so a 429 says nothing about whether
            // the handle exists.
            if (!AuthSupport::allowMailToAddress(
                'resend_verification',
                'handle:' . $handle,
                AuthSupport::RESEND_VERIFICATION_EMAIL_RATE_LIMIT,
                AuthSupport::RESEND_VERIFICATION_EMAIL_GLOBAL_RATE_LIMIT
            )) {
                return ApiResponse::error('bcc_rate_limited', 'Too many requests. Try again later.', 429);
            }
            $ownerId = HandleService::currentOwner($handle);
            $user    = $ownerId !== null ? get_userdata($ownerId) : false;
            $email   = $user instanceof \WP_User ? (string) $user->user_email : '';
            // The resolved inbox's own cap still binds, so alternating the
            // email and handle forms cannot double the mail to one address.
            // Over it, nothing is sent and the answer is the same ok (a 429
            // here would say the handle exists).
            if ($user instanceof \WP_User && !AuthSupport::allowMailToAddress(
                'resend_verification',
                $email,
                AuthSupport::RESEND_VERIFICATION_EMAIL_RATE_LIMIT,
                AuthSupport::RESEND_VERIFICATION_EMAIL_GLOBAL_RATE_LIMIT
            )) {
                $user = false;
            }
        } else {
            return ApiResponse::error('bcc_invalid_request', 'Supply an email or a handle.', 422);
        }

        if ($user instanceof \WP_User) {
            $userId        = (int) $user->ID;
            $emailVerified = (string) get_user_meta($userId, AuthSupport::META_EMAIL_VERIFIED, true);

            // Resend for accounts whose email is NOT yet proven: pending ('0')
            // and legacy no-marker ('') accounts. A legacy account needs this
            // path because OAuth sign-in only links by email into a '1'
            // account — verifying here is how it gets there. Already-verified
            // accounts and wallet placeholder addresses (an internal
            // noreply.bcc.local alias, no inbox) are silently skipped; the
            // anti-enumeration response hides which.
            if (
                ($emailVerified === '0' || $emailVerified === '')
                && !AccountRecoveryService::isPlaceholderEmail($email)
            ) {
                // Overwrites the existing OTP (if any) so the previous code
                // immediately stops working — only the new email is valid.
                // A new code ends an "attempts exhausted" state (F-294).
                delete_transient(self::OTP_EXHAUSTED_PREFIX . $userId);
                AuthSupport::beginEmailVerification($userId, $email);
            }
        }

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
