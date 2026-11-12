<?php
/**
 * AccountCredentialCheck — "prove it's you" for the sensitive account
 * changes: email change, password change, account delete (MyAccountEndpoint),
 * and the wallet half of the recovery-email flow (RecoveryEmailEndpoint).
 *
 * F-253 (user decision 2026-11-12): a member confirms with EITHER
 *   - their current password (`current_password`), or
 *   - a fresh signature from one of their own VERIFIED linked wallets
 *     (`wallet_address`, `chain_slug`, `signature`, optional `extra`), over a
 *     challenge from GET /auth/nonce issued for THAT change's purpose.
 * Wallet-only members never had a password (wallet/OAuth signup mints a
 * random one), so before this they could not change their email or
 * password, or delete their account, at all. Any account with a verified
 * wallet may use the wallet path: wallet sign-in already grants that holder
 * a full session, so it is no weaker than the password.
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
use BCC\Trust\Core\Support\ApiResponse;
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

        if (self::hasWalletProof($request)) {
            return self::verifyOwnWallet($request, $userId, $walletPurpose) ?? self::BY_WALLET;
        }

        return ApiResponse::error(
            'bcc_invalid_request',
            'Confirm with your current password or a signature from one of your verified wallets.',
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
     */
    public static function verifyOwnWallet(WP_REST_Request $request, int $userId, string $purpose): ?WP_REST_Response
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

        // Point of no return for the nonce: atomic consume (refused when it
        // was issued for another purpose), then verify the signature against
        // the SERVER-stored challenge message, never the caller's input.
        $challenge = WalletIdentityService::consumeChallenge($userId, $walletAddress, $purpose);
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
