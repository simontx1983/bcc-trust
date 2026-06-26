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

use BCC\Core\Crypto\WalletVerifier;
use BCC\Core\Security\Throttle;
use BCC\Core\Wallet\WalletIdentityService;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\AccountSecurityMailer;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class RecoveryEmailEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Pending-email transient prefix + TTL (15 min; mirrors AuthEndpoint::OTP_TTL). */
    private const PENDING_PREFIX = 'bcc_recovery_email_';
    private const PENDING_TTL    = 900;

    /** Email-verified usermeta key. Mirrors AuthEndpoint::META_EMAIL_VERIFIED (private there). */
    private const META_EMAIL_VERIFIED = '_bcc_email_verified';

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

        $walletAddress = wp_strip_all_tags(wp_unslash((string) $request->get_param('wallet_address')));
        $chainSlug     = strtolower(trim((string) $request->get_param('chain_slug')));
        $signature     = wp_strip_all_tags(wp_unslash((string) $request->get_param('signature')));
        $extraParam    = $request->get_param('extra');
        $extra         = is_array($extraParam) ? $extraParam : [];
        $emailRaw      = trim((string) $request->get_param('email'));

        if ($walletAddress === '' || $chainSlug === '' || $signature === '') {
            return ApiResponse::error('bcc_invalid_request', 'wallet_address, chain_slug and signature are required.', 400);
        }

        $chain = \BCC\Core\ServiceLocator::resolveChainRead()->getBySlug($chainSlug);
        if ($chain === null) {
            return ApiResponse::error('bcc_invalid_request', 'Unknown chain.', 400);
        }
        $chainId = (int) $chain->id;

        // Ownership + verified: the proving wallet must be the caller's OWN,
        // verified wallet. An unverified or foreign wallet cannot authorize
        // recovery.
        $linkId = WalletRepository::findIdByUserChainAddress($userId, $chainId, $walletAddress);
        if ($linkId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'That wallet is not linked to your account.', 400);
        }
        $link = WalletRepository::getById($linkId);
        if ($link === null || empty($link->verified_at)) {
            return ApiResponse::error('bcc_invalid_request', 'That wallet is not verified.', 400);
        }

        // Validate the new email BEFORE consuming the nonce so a cheap reject
        // doesn't burn the user's challenge.
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

        // Point of no return for the nonce: atomic consume, then verify the
        // signature against the SERVER-stored challenge message (never the
        // caller's input).
        $challenge = WalletIdentityService::consumeChallenge($userId, $walletAddress);
        if ($challenge === null) {
            return ApiResponse::error('bcc_invalid_request', 'Challenge not found or expired. Please request a new nonce.', 400);
        }
        $message = (string) ($challenge['message'] ?? '');
        if ($message === '') {
            return ApiResponse::error('bcc_internal_error', 'Challenge malformed.', 500);
        }

        if (!WalletVerifier::verify((string) $chain->chain_type, $message, $signature, $walletAddress, $extra)) {
            return ApiResponse::error('bcc_signature_invalid', 'Signature verification failed.', 401);
        }

        // Stage the pending email + OTP hash in a single transient. user_email
        // is NOT touched until the OTP is confirmed (verify-before-promote).
        $otp     = sprintf('%06d', random_int(0, 999999));
        $otpHash = hash_hmac('sha256', $otp, (string) wp_salt('auth'));
        set_transient(
            self::PENDING_PREFIX . $userId,
            ['email' => $email, 'otp_hash' => $otpHash],
            self::PENDING_TTL
        );

        AuthMailer::sendRecoveryEmailOtp($email, $otp);
        AuditLogger::log('recovery_email_requested', $userId, ['chain' => (string) $chain->slug], 'user', $userId);

        $resp = ApiResponse::ok([
            'ok'           => true,
            'email_masked' => self::maskEmail($email),
            'expires_in'   => self::PENDING_TTL,
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

        $pending = get_transient(self::PENDING_PREFIX . $userId);
        if (!is_array($pending) || !isset($pending['email'], $pending['otp_hash'])) {
            return ApiResponse::error('bcc_invalid_request', 'No pending recovery email. Please start again.', 400);
        }
        $pendingEmail = (string) $pending['email'];
        $storedHash   = (string) $pending['otp_hash'];

        // Timing-safe compare. On mismatch the transient is LEFT in place so
        // the user can retry within the TTL + verify throttle (a 6-digit OTP
        // under 10/hour + 15-min TTL is not brute-forceable).
        $expected = hash_hmac('sha256', $code, (string) wp_salt('auth'));
        if (!hash_equals($storedHash, $expected)) {
            return ApiResponse::error('bcc_invalid_request', 'Incorrect or expired code.', 422);
        }

        // Re-check the conflict that was clear at request time (race window).
        $existingId = email_exists($pendingEmail);
        if ($existingId !== false && (int) $existingId !== $userId) {
            delete_transient(self::PENDING_PREFIX . $userId);
            return ApiResponse::error('bcc_conflict', 'That email is already in use.', 409);
        }

        $user     = get_userdata($userId);
        $oldEmail = $user instanceof \WP_User ? (string) $user->user_email : '';

        $result = wp_update_user(['ID' => $userId, 'user_email' => $pendingEmail]);
        if (is_wp_error($result)) {
            return ApiResponse::error('bcc_internal_error', 'Could not save recovery email. Please try again.', 500);
        }

        // The address is now proven, so the email is genuinely verified.
        update_user_meta($userId, self::META_EMAIL_VERIFIED, '1');
        delete_transient(self::PENDING_PREFIX . $userId);

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

    /**
     * Mask an email for a safe echo back to the client: first char + stars
     * + domain (e.g. `a****@example.com`). Not security-critical — the
     * caller already supplied the address — just avoids echoing it verbatim.
     */
    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at < 1) {
            return '***';
        }
        return substr($email, 0, 1) . str_repeat('*', max(1, $at - 1)) . substr($email, $at);
    }
}
