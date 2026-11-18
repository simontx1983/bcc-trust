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
            return self::completeVerification($userId);
        }

        // ── OTP path ──────────────────────────────────────────────────
        // The account whose email it is. Failing that, the wallet sign-ups
        // holding it pending (F-294): one is answered as that account; with
        // several, the code is checked against each, since a match proves
        // the inbox for the account that code was sent for.
        $owner = get_user_by('email', $email);
        $accounts = $owner instanceof \WP_User ? [$owner] : self::pendingHolderAccounts($email);
        if ($accounts === []) {
            // Generic error — don't reveal whether the address is registered.
            return self::invalidOtp();
        }
        if (count($accounts) > 1) {
            return self::verifyCodeForHolders($accounts, $code);
        }

        $userId = (int) $accounts[0]->ID;
        $outcome = self::tryCode($userId, $code);
        if ($outcome === self::CODE_MATCHED) {
            return self::completeVerification($userId);
        }
        if ($outcome === self::CODE_VERIFIED) {
            return ApiResponse::error('bcc_already_verified', 'This email is already verified.', 409);
        }
        return $outcome === self::CODE_BURNED ? self::otpExhausted() : self::invalidOtp();
    }

    /** tryCode() outcomes. */
    private const CODE_MATCHED  = 'matched';
    private const CODE_WRONG    = 'wrong';
    private const CODE_BURNED   = 'burned';   // the attempt cap burned it; no new code yet
    private const CODE_VERIFIED = 'verified'; // the account's email is already verified

    /**
     * One code attempt against one account, with that account's own
     * attempt cap. Consumes the code on a match.
     *
     * @return self::CODE_*
     */
    private static function tryCode(int $userId, string $code): string
    {
        $emailVerified = (string) get_user_meta($userId, AuthSupport::META_EMAIL_VERIFIED, true);
        if ($emailVerified === '1') {
            return self::CODE_VERIFIED;
        }

        // F-294: the code was burned by too many wrong attempts and no new
        // one has been sent. Say so, instead of "invalid or expired", so the
        // user knows to request a new code rather than retype this one.
        if (AuthSupport::emailOtpIdentity($userId) === null && get_transient(self::OTP_EXHAUSTED_PREFIX . $userId) !== false) {
            return self::CODE_BURNED;
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
            self::exhaustOtp($userId);
            return self::CODE_BURNED;
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
                self::exhaustOtp($userId);
                return self::CODE_BURNED;
            }
            return self::CODE_WRONG;
        }

        return self::CODE_MATCHED;
    }

    /**
     * F-294: a code for an address several wallet sign-ups hold pending.
     * Each holder's code is a separate code sent to that same inbox, so the
     * code is tried against each (oldest first), each under its own attempt
     * cap: a guess counts once against every holder. The first match proves
     * the inbox for that account, and only that account is verified. No
     * match is the generic answer, or "request a new code" only when every
     * holder's code is burned.
     *
     * @param list<\WP_User> $holders
     */
    private static function verifyCodeForHolders(array $holders, string $code): WP_REST_Response
    {
        $tried  = 0;
        $burned = 0;
        foreach ($holders as $holder) {
            $holderId = (int) $holder->ID;
            $outcome  = self::tryCode($holderId, $code);
            if ($outcome === self::CODE_MATCHED) {
                return self::completeVerification($holderId);
            }
            if ($outcome === self::CODE_VERIFIED) {
                continue; // nothing pending to prove for this one
            }
            $tried++;
            if ($outcome === self::CODE_BURNED) {
                $burned++;
            }
        }
        return $tried > 0 && $burned === $tried ? self::otpExhausted() : self::invalidOtp();
    }

    /**
     * F-294: the wallet sign-ups holding $email as their pending address
     * (PendingEmailService::pendingSignupHolders(): oldest first, bounded).
     * A wallet sign-up keeps the placeholder in user_email until the inbox
     * is proven.
     *
     * @return list<\WP_User>
     */
    private static function pendingHolderAccounts(string $email): array
    {
        $accounts = [];
        foreach (\BCC\Trust\Core\Services\PendingEmailService::pendingSignupHolders($email) as $holderId) {
            $holder = get_userdata($holderId);
            if ($holder instanceof \WP_User) {
                $accounts[] = $holder;
            }
        }
        return $accounts;
    }

    /** On a proven inbox: apply a wallet sign-up's pending address, then sign in. */
    private static function completeVerification(int $userId): WP_REST_Response
    {
        $applied = self::applyPendingSignupEmail($userId);
        if ($applied !== null) {
            return $applied;
        }
        return AuthSupport::finalizeVerification($userId);
    }

    private static function invalidOtp(): WP_REST_Response
    {
        return ApiResponse::error('bcc_invalid_otp', 'Invalid or expired code.', 400);
    }

    /**
     * F-294: on a proven inbox, move a wallet sign-up's pending address into
     * user_email before the account is finalised. Null = carry on; otherwise
     * the response to return (the address was taken meanwhile, or the write
     * failed).
     */
    private static function applyPendingSignupEmail(int $userId): ?WP_REST_Response
    {
        $outcome = \BCC\Trust\Core\Services\PendingEmailService::applyPendingSignupEmail($userId);
        if ($outcome === \BCC\Trust\Core\Services\PendingEmailService::SIGNUP_EMAIL_CONFLICT) {
            return ApiResponse::error('bcc_conflict', 'An account with that email already exists.', 409);
        }
        if ($outcome === \BCC\Trust\Core\Services\PendingEmailService::SIGNUP_EMAIL_FAILED) {
            return ApiResponse::error('bcc_internal_error', 'Could not confirm your email. Please try again.', 500);
        }
        return null;
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

    private static function exhaustOtp(int $userId): void
    {
        AuthSupport::burnEmailOtp($userId);
        set_transient(self::OTP_EXHAUSTED_PREFIX . $userId, '1', AuthSupport::OTP_TTL);
        \BCC\Core\Log\Logger::audit('verify_email_attempts_exhausted', ['user_id' => $userId]);
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
        /** @var list<\WP_User> $recipients */
        $recipients = [];

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
            // The account whose email it is; failing that, every wallet
            // sign-up holding it pending (F-294; bounded, oldest first), each
            // with its own code, as each would have had at sign-up. The
            // per-address caps above count the request once, so a request
            // mails at most MAX_PENDING_SIGNUP_HOLDERS codes.
            $owner      = get_user_by('email', $email);
            $recipients = $owner instanceof \WP_User ? [$owner] : self::pendingHolderAccounts($email);
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
                AuthSupport::releaseThrottle('resend_verification', 3600);
                return ApiResponse::error('bcc_rate_limited', 'Too many requests. Try again later.', 429);
            }
            $ownerId = HandleService::currentOwner($handle);
            $user    = $ownerId !== null ? get_userdata($ownerId) : false;
            $email   = '';
            if ($user instanceof \WP_User) {
                $pending = \BCC\Trust\Core\Services\PendingEmailService::pendingSignupEmail((int) $user->ID);
                $email   = $pending !== '' ? $pending : (string) $user->user_email;
            }
            // The resolved inbox's own cap still binds, so alternating the
            // email and handle forms cannot double the mail to one address.
            // Over it, nothing is sent and the answer is the same ok (a 429
            // here would say the handle exists). The network slot is NOT
            // given back here: a give-back only for handles that exist would
            // let the network bucket's count say which ones do.
            if ($user instanceof \WP_User && AuthSupport::allowMailToAddress(
                'resend_verification',
                $email,
                AuthSupport::RESEND_VERIFICATION_EMAIL_RATE_LIMIT,
                AuthSupport::RESEND_VERIFICATION_EMAIL_GLOBAL_RATE_LIMIT
            )) {
                $recipients = [$user];
            }
        } else {
            return ApiResponse::error('bcc_invalid_request', 'Supply an email or a handle.', 422);
        }

        foreach ($recipients as $user) {
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
