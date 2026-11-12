<?php
/**
 * AccountCredentialCheck — "prove it's you" for the sensitive account
 * changes: email change, password change, account delete (MyAccountEndpoint),
 * and the wallet half of the recovery-email flow (RecoveryEmailEndpoint).
 *
 * F-253 (user decisions 2026-11-12): a member confirms with
 *   - their current password (`current_password`) — REQUIRED for any account
 *     that has one, or might (has_password true or unknown), or
 *   - only when the account has NO password (has_password === false: a
 *     wallet/OAuth signup that never set one), a fresh signature from one of
 *     their own VERIFIED linked wallets (`wallet_address`, `chain_slug`,
 *     `signature`, optional `extra`), over a challenge from GET /auth/nonce
 *     issued for THAT change's purpose.
 *
 * Why the wallet path is so narrow: whoever holds a stolen session can link
 * THEIR OWN wallet (linking needs no password). If that wallet could then
 * confirm a password change or a delete, a stolen token would be a stolen
 * account. So the proving wallet must also be ESTABLISHED: verified at least
 * WALLET_MIN_AGE_SECONDS ago AND before the current session began
 * (JwtToken::sessionStartedAt — the sign-in time, which a refresh carries
 * forward). `verified_at` is stamped once and never moved (WalletRepository::
 * verify); unlinking and relinking creates a new row with a new stamp, so
 * the clock can't be reset in the attacker's favour. The same rule guards
 * the recovery-email route (RecoveryEmailEndpoint).
 *
 * The signed message is the SERVER-stored challenge, never the caller's;
 * the challenge is single-use and bound to its purpose (bcc-core
 * WalletIdentityService::consumeChallenge), so a signature approving one
 * change can't be spent on another, or on wallet linking.
 *
 * Callers run their cheap validation BEFORE this check, so a typo doesn't
 * burn the member's signed challenge.
 *
 * @package BCC\Trust\Core\REST
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Crypto\WalletChallenge;
use BCC\Core\Crypto\WalletVerifier;
use BCC\Core\Wallet\WalletIdentityService;
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Core\Support\JwtToken;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class AccountCredentialCheck
{
    /** Which credential confirmed the change — for the audit row. */
    public const BY_PASSWORD = 'password';
    public const BY_WALLET   = 'wallet';

    /**
     * Challenge purposes: bcc-core's WalletChallenge::PURPOSE_* values,
     * spelled out here so this plugin still loads (and the password path
     * still works) against a bcc-core from before F-253, where those
     * constants don't exist. The wallet path feature-detects core below.
     */
    public const PURPOSE_VERIFY          = 'verify';
    public const PURPOSE_EMAIL_CHANGE    = 'confirm_email_change';
    public const PURPOSE_PASSWORD_CHANGE = 'confirm_password_change';
    public const PURPOSE_ACCOUNT_DELETE  = 'confirm_account_delete';

    /** A proving wallet must have been verified at least this long ago. */
    public const WALLET_MIN_AGE_SECONDS = 48 * 3600;

    /** The signup-wallet exception (recovery email only) lasts this long after sign-up. */
    public const SIGNUP_WALLET_WINDOW_SECONDS = 48 * 3600;

    /**
     * Confirm the caller with their password or a wallet signature.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     * @param string $walletPurpose the WalletChallenge purpose this change's
     *                              challenge must have been issued for
     * @return WP_REST_Response|string an error envelope, or BY_PASSWORD /
     *                                 BY_WALLET on success
     */
    public static function confirm(WP_REST_Request $request, int $userId, string $walletPurpose): WP_REST_Response|string
    {
        $current = $request->get_param('current_password');
        if (is_string($current) && $current !== '') {
            return self::checkPassword($current, $userId) ?? self::BY_PASSWORD;
        }

        // Only an account KNOWN to have no password may confirm with a
        // wallet. Unknown (null: an older account with a real email) is
        // treated as password-protected; it can set a known password through
        // forgot-password.
        $walletOnly = AccountRecoveryService::hasPassword($userId) === false;

        if (self::hasWalletProof($request)) {
            if (!$walletOnly) {
                return ApiResponse::error(
                    'bcc_forbidden',
                    'Your account has a password. Confirm with your current password.',
                    403
                );
            }
            return self::verifyOwnWallet($request, $userId, $walletPurpose) ?? self::BY_WALLET;
        }

        return ApiResponse::error(
            'bcc_invalid_request',
            $walletOnly
                ? 'Confirm with a signature from one of your verified wallets.'
                : 'Confirm with your current password.',
            422
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    private static function hasWalletProof(WP_REST_Request $request): bool
    {
        foreach (['wallet_address', 'signature'] as $field) {
            $value = $request->get_param($field);
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function checkPassword(string $current, int $userId): ?WP_REST_Response
    {
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
     * The caller proves control of one of their OWN, VERIFIED wallets by
     * signing a challenge issued for $purpose. Null on success.
     *
     * Consumes the challenge (point of no return) only after the cheap
     * checks pass.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     * @param bool $allowSignupWallet ONLY RecoveryEmailEndpoint, attaching the
     *                                account's first real email: waive the
     *                                established-wallet rule for the wallet
     *                                the account was created with, for
     *                                SIGNUP_WALLET_WINDOW_SECONDS after sign-up
     */
    public static function verifyOwnWallet(WP_REST_Request $request, int $userId, string $purpose, bool $allowSignupWallet = false): ?WP_REST_Response
    {
        // Purpose-bound challenges need bcc-core with F-253. An older core
        // would ignore the purpose and accept a plain "verify your wallet"
        // signature as approval of an account change: refuse instead.
        if ($purpose !== self::PURPOSE_VERIFY && !self::coreHasPurposes()) {
            return ApiResponse::error(
                'bcc_unavailable',
                'Confirming with a wallet is not available yet. Please try again later.',
                503
            );
        }

        $walletAddress = wp_strip_all_tags(wp_unslash((string) $request->get_param('wallet_address')));
        $chainSlug     = strtolower(trim((string) $request->get_param('chain_slug')));
        $signature     = wp_strip_all_tags(wp_unslash((string) $request->get_param('signature')));
        $extraParam    = $request->get_param('extra');
        $extra         = is_array($extraParam) ? $extraParam : [];

        if ($walletAddress === '' || $chainSlug === '' || $signature === '') {
            return ApiResponse::error('bcc_invalid_request', 'wallet_address, chain_slug and signature are required.', 400);
        }

        $chain = \BCC\Core\ServiceLocator::resolveChainRead()->getBySlug($chainSlug);
        if ($chain === null) {
            return ApiResponse::error('bcc_invalid_request', 'Unknown chain.', 400);
        }

        // Ownership + verified: the proving wallet must be the caller's OWN,
        // verified wallet. An unverified or foreign wallet proves nothing.
        $linkId = WalletRepository::findIdByUserChainAddress($userId, (int) $chain->id, $walletAddress);
        if ($linkId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'That wallet is not linked to your account.', 400);
        }
        $link = WalletRepository::getById($linkId);
        if ($link === null || empty($link->verified_at)) {
            return ApiResponse::error('bcc_invalid_request', 'That wallet is not verified.', 400);
        }

        // Established wallets only (see the class docblock). Checked before
        // the challenge is spent: the member can't fix it by re-signing.
        $signupWallet = $allowSignupWallet && self::isSignupWallet($userId, $linkId, (string) $link->verified_at);
        $establishedError = $signupWallet ? null : self::establishedWalletError((string) $link->verified_at);
        if ($establishedError !== null) {
            return $establishedError;
        }

        // Point of no return for the nonce: atomic consume (refused when it
        // was issued for another purpose), then verify the signature against
        // the SERVER-stored challenge message, never the caller's input.
        $challenge = WalletIdentityService::consumeChallenge($userId, $walletAddress, $purpose);
        if ($challenge === null) {
            return ApiResponse::error('bcc_invalid_request', 'Challenge not found or expired. Please request a new nonce.', 400);
        }
        // The challenge is keyed by (user, address), not chain, and an EVM
        // address is the same on every EVM chain: a challenge issued for one
        // chain must not be spent claiming another (the signed message names
        // its chain, so the member never approved this one).
        if (strtolower((string) ($challenge['chain_slug'] ?? '')) !== $chainSlug) {
            return ApiResponse::error('bcc_invalid_request', 'Challenge was issued for another chain. Please request a new nonce.', 400);
        }
        $message = (string) ($challenge['message'] ?? '');
        if ($message === '') {
            return ApiResponse::error('bcc_internal_error', 'Challenge malformed.', 500);
        }

        if (!WalletVerifier::verify((string) $chain->chain_type, $message, $signature, $walletAddress, $extra)) {
            return ApiResponse::error('bcc_signature_invalid', 'Signature verification failed.', 401);
        }

        return null;
    }

    /**
     * Is this link the wallet the account was CREATED with, and is the
     * account still inside SIGNUP_WALLET_WINDOW_SECONDS of its creation?
     * (F-253 signup exception: a new wallet-only member adds a recovery
     * email right after signing up.)
     *
     * The signup wallet is EXACTLY the link row POST /auth/wallet-signup
     * created (AccountRecoveryService::META_SIGNUP_WALLET_LINK), never
     * inferred from timing — an OAuth or password account linking a wallet
     * minutes after signing up has no such row. It must also still be the
     * account's earliest verified wallet. A relink is a new row, so it is
     * not it. No session-time condition: proving still needs a fresh
     * signature from THIS wallet, which someone holding only a stolen
     * session can't produce. Times are GMT.
     */
    private static function isSignupWallet(int $userId, int $linkId, string $verifiedAt): bool
    {
        $marked = (int) get_user_meta($userId, AccountRecoveryService::META_SIGNUP_WALLET_LINK, true);
        if ($marked <= 0 || $marked !== $linkId) {
            return false;
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || trim((string) $user->user_registered) === '') {
            return false;
        }
        $registeredTs = strtotime((string) $user->user_registered . ' UTC');
        $verifiedTs   = strtotime($verifiedAt . ' UTC');
        if ($registeredTs === false || $verifiedTs === false) {
            return false;
        }
        if (time() > $registeredTs + self::SIGNUP_WALLET_WINDOW_SECONDS) {
            return false;
        }

        $oldest = WalletRepository::oldestVerifiedAt($userId);
        return $oldest !== null && strtotime($oldest . ' UTC') === $verifiedTs;
    }

    /**
     * Null when the proving wallet is ESTABLISHED: verified at least
     * WALLET_MIN_AGE_SECONDS ago and before the current session began.
     * Otherwise a 403 that says when (or how) it will be usable.
     *
     * `verified_at` is UTC (WalletRepository::verify stamps it with
     * current_time('mysql', true)). The session start comes from the
     * PRESENTED bearer token; a request with no valid bearer token (no
     * session to date) is refused.
     */
    private static function establishedWalletError(string $verifiedAt): ?WP_REST_Response
    {
        $verifiedTs = strtotime($verifiedAt . ' UTC');
        if ($verifiedTs === false) {
            return ApiResponse::error('bcc_invalid_request', 'That wallet is not verified.', 400);
        }

        $usableAt = $verifiedTs + self::WALLET_MIN_AGE_SECONDS;
        if (time() < $usableAt) {
            return ApiResponse::error(
                'bcc_forbidden',
                'This wallet was verified too recently to confirm account changes. It can be used 48 hours after it was verified.',
                403,
                ['usable_at' => gmdate('Y-m-d\TH:i:s\Z', $usableAt)]
            );
        }

        $token   = BearerAuth::readBearerToken();
        $decoded = $token !== null ? JwtToken::decode($token) : null;
        if ($decoded === null || $decoded['ok'] !== true) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in again to confirm with a wallet.', 401);
        }
        if ($verifiedTs >= JwtToken::sessionStartedAt($decoded['payload'])) {
            return ApiResponse::error(
                'bcc_forbidden',
                'This wallet was verified after you signed in. Sign out, sign back in, and try again.',
                403
            );
        }

        return null;
    }

    /**
     * Does the loaded bcc-core issue and check purpose-bound challenges?
     * An older one ignores the purpose argument, so a plain "verify your
     * wallet" signature would pass as approval of an account change.
     */
    public static function coreHasPurposes(): bool
    {
        return defined(WalletChallenge::class . '::AUTHED_PURPOSES');
    }

    /** True for a purpose a signed-in member may request a challenge for. */
    public static function isAuthedPurpose(string $purpose): bool
    {
        return in_array($purpose, [
            self::PURPOSE_VERIFY,
            self::PURPOSE_EMAIL_CHANGE,
            self::PURPOSE_PASSWORD_CHANGE,
            self::PURPOSE_ACCOUNT_DELETE,
        ], true);
    }
}
