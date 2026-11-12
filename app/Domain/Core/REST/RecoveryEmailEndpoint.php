<?php
/**
 * RecoveryEmailEndpoint — attach a real, verified recovery email to an
 * account whose only credential is a wallet.
 *
 * Wallet-only signups (AuthEndpoint::walletSignup) carry a random,
 * never-shown password and an undeliverable `@noreply.bcc.local`
 * placeholder email, so the wallet is the SOLE credential and the account
 * cannot use `/auth/forgot-password`. This endpoint breaks that trap: the
 * user proves control of one of their VERIFIED wallets with a fresh
 * signature (the same proof they signed up with — NOT the password they
 * never had), supplies a real email, and confirms it with an OTP sent to
 * that inbox.
 *
 * Verify-before-promote: the new address is held PENDING in a transient and
 * only written to `user_email` after the OTP is confirmed. A typo can never
 * silently replace the placeholder, so AccountRecoveryService::hasRecoveryEmail()
 * (a pure non-placeholder check) stays correct, and the Phase-1 unlink
 * self-lockout guard only relaxes once a real inbox is proven.
 *
 *   POST /bcc/v1/me/account/recovery-email         — prove wallet, stage email, mail OTP
 *   POST /bcc/v1/me/account/recovery-email/verify  — confirm OTP, promote to user_email
 *
 * The wallet challenge is issued by the existing GET /auth/nonce (authed)
 * and consumed here, so no new nonce surface is added. The signed message
 * is sourced from the consumed challenge, never the caller.
 *
 * @package BCC\Trust\Core\REST
 * @since   2026-06-10 (wallet recovery, phase 2)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Security\Throttle;
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

final class RecoveryEmailEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    // Pending-email staging, OTP hashing and promotion live in
    // PendingEmailService (shared with PATCH /me/account/email).

    /** Request is signature-gated (mirrors patchEmail's 5/60); verify mirrors verify_email (10/3600). */
    private const REQUEST_RATE_LIMIT = 5;
    private const VERIFY_RATE_LIMIT  = 10;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/account/recovery-email',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'requestRecoveryEmail'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/account/recovery-email/verify',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'verifyRecoveryEmail'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // A. Request — prove wallet, stage pending email, mail OTP
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function requestRecoveryEmail(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Throttle BEFORE the signature verify — never let a session-only
        // caller burn CPU on ECDSA/ed25519 verification unboundedly.
        if (!Throttle::allow('recovery_email_req:' . $userId, self::REQUEST_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        // F-253 (#16): this endpoint attaches a FIRST real email to an account
        // stuck on its wallet-signup placeholder. Once a real email exists,
        // changing it is PATCH /me/account/email: the audited change that
        // also signs out other sessions, and accepts the same wallet proof.
        if (AccountRecoveryService::hasRecoveryEmail($userId)) {
            return self::alreadyHasEmail();
        }

        // Validate the new email BEFORE the wallet proof consumes the nonce,
        // so a cheap reject doesn't burn the user's challenge.
        $emailRaw = trim((string) $request->get_param('email'));
        if ($emailRaw === '') {
            return ApiResponse::error('bcc_invalid_request', 'A recovery email is required.', 422);
        }
        $email = sanitize_email($emailRaw);
        if ($email === '' || !is_email($email)) {
            return ApiResponse::error('bcc_invalid_request', 'Email address is not valid.', 422);
        }
        if (AccountRecoveryService::isPlaceholderEmail($email)) {
            return ApiResponse::error('bcc_invalid_request', 'That is not a usable recovery email.', 422);
        }
        $existingId = email_exists($email);
        if ($existingId !== false && (int) $existingId !== $userId) {
            return ApiResponse::error('bcc_conflict', 'That email is already in use.', 409);
        }

        // Prove control of one of the caller's own verified wallets (shared
        // with the account-change confirmations, F-253). The recovery flow
        // signs the ordinary "verify your wallet" challenge from
        // GET /auth/nonce, as it always has.
        // This route only attaches a FIRST real email (guard above), so the
        // signup wallet may prove it right after sign-up (F-253 exception).
        $proofError = AccountCredentialCheck::verifyOwnWallet($request, $userId, AccountCredentialCheck::PURPOSE_VERIFY, true);
        if ($proofError !== null) {
            return $proofError;
        }
        $chainSlug = strtolower(trim((string) $request->get_param('chain_slug')));

        // Stage the pending email + OTP hash in a single transient. user_email
        // is NOT touched until the OTP is confirmed (verify-before-promote).
        $otp = PendingEmailService::stage($userId, $email, PendingEmailService::PURPOSE_RECOVERY);

        AuthMailer::sendRecoveryEmailOtp($email, $otp);
        AuditLogger::log('recovery_email_requested', $userId, ['chain' => $chainSlug], 'user', $userId);

        $resp = ApiResponse::ok([
            'ok'           => true,
            'email_masked' => PendingEmailService::maskEmail($email),
            'expires_in'   => PendingEmailService::PENDING_TTL,
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    // ──────────────────────────────────────────────────────────────────
    // B. Verify — confirm OTP, promote pending email to user_email
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function verifyRecoveryEmail(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        if (!Throttle::allow('recovery_email_verify:' . $userId, self::VERIFY_RATE_LIMIT, 3600)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many attempts. Please wait.', 429);
        }

        $code = trim((string) $request->get_param('code'));
        if ($code === '') {
            return ApiResponse::error('bcc_invalid_request', 'Verification code is required.', 422);
        }

        // F-253 (#16): a recovery staged while the account had only its
        // placeholder can't overwrite a real email set since.
        if (AccountRecoveryService::hasRecoveryEmail($userId)) {
            return self::alreadyHasEmail();
        }

        // Verify-before-promote (PendingEmailService): a wrong code leaves the
        // pending entry for a retry within the TTL + verify throttle (a 6-digit
        // OTP under 10/hour + 15-min TTL is not brute-forceable); a correct
        // one promotes the address and marks it verified.
        $confirmed = PendingEmailService::confirm($userId, $code, PendingEmailService::PURPOSE_RECOVERY);
        if (!$confirmed['ok']) {
            switch ($confirmed['error']) {
                case PendingEmailService::ERR_NONE:
                    return ApiResponse::error('bcc_invalid_request', 'No pending recovery email. Please start again.', 400);
                case PendingEmailService::ERR_BAD_CODE:
                    return ApiResponse::error('bcc_invalid_request', 'Incorrect or expired code.', 422);
                case PendingEmailService::ERR_CONFLICT:
                    return ApiResponse::error('bcc_conflict', 'That email is already in use.', 409);
                default:
                    return ApiResponse::error('bcc_internal_error', 'Could not save recovery email. Please try again.', 500);
            }
        }
        $pendingEmail = $confirmed['email'];
        $oldEmail     = $confirmed['old_email'];

        AuditLogger::log('recovery_email_confirmed', $userId, [], 'user', $userId);

        // Out-of-band notification. Replacing the synthetic placeholder →
        // nobody is at the old address, so notify the new inbox only.
        // Replacing a real email → send the both-addresses canary.
        if ($oldEmail === '' || AccountRecoveryService::isPlaceholderEmail($oldEmail)) {
            AccountSecurityMailer::recoveryEmailConfirmed($userId, $pendingEmail);
        } else {
            AccountSecurityMailer::emailChanged($userId, $oldEmail, $pendingEmail);
        }

        $resp = ApiResponse::ok(['ok' => true, 'email' => $pendingEmail]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    private static function alreadyHasEmail(): WP_REST_Response
    {
        return ApiResponse::error(
            'bcc_conflict',
            'Your account already has an email address. Change it in your account settings instead.',
            409
        );
    }
}
