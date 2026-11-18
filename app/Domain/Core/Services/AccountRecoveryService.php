<?php
/**
 * AccountRecoveryService — single source of truth for "can this account
 * be recovered without the wallet it signed up with?"
 *
 * Wallet-signup accounts are minted with a random, never-shown password
 * and (when the user supplies no email) a deterministic, undeliverable
 * placeholder address (see AuthEndpoint::placeholderEmailForWallet). For
 * such an account the wallet is the ONLY credential: forgot-password
 * mails the dead placeholder, and the email-change endpoint requires the
 * password the user never had. Losing the wallet = permanent lockout.
 *
 * This service centralises the "does a real recovery channel exist?"
 * question so the unlink guard, the (Phase 2) recovery-email endpoint,
 * and the (Phase 3) /me posture all agree on one definition rather than
 * re-deriving it. A recovery email is a validly-formatted address that
 * is NOT the placeholder domain AND whose inbox is not still pending proof
 * (F-332): an address typed at wallet sign-up starts unverified
 * (`_bcc_email_verified` = '0', a code mailed to it) and only becomes the
 * account's way back in once POST /auth/verify-email confirms it. Until
 * then nobody has shown they read that inbox, so it can't count as a
 * recovery channel — and the member can still replace a typo through the
 * recovery-email route. A legacy account with no flag at all ('') keeps
 * counting, as it always has.
 *
 * @package BCC\Trust\Core\Services
 * @since   2026-06-10 (wallet recovery, phase 1)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class AccountRecoveryService
{
    /**
     * Domain used for synthetic wallet-signup addresses. `.local` keeps
     * these firmly out of any real-MX collision class; `noreply` makes
     * the no-mail intent explicit. AuthEndpoint::placeholderEmailForWallet
     * builds addresses on this domain and is the only generator —
     * everything that needs to RECOGNISE a placeholder routes through
     * isPlaceholderEmail() here.
     */
    public const PLACEHOLDER_EMAIL_DOMAIN = 'noreply.bcc.local';

    /**
     * True when $email is a synthetic wallet-signup placeholder (an
     * address on PLACEHOLDER_EMAIL_DOMAIN) rather than a real inbox the
     * user can receive mail at. Case-insensitive on the domain.
     */
    public static function isPlaceholderEmail(string $email): bool
    {
        return str_ends_with(strtolower($email), '@' . self::PLACEHOLDER_EMAIL_DOMAIN);
    }

    /**
     * True when the user has a real (non-placeholder, well-formed) email
     * that is not pending verification — a channel /auth/forgot-password
     * can actually reach, owned by the member. This is the account's
     * email-based way back in if every linked wallet is lost.
     */
    public static function hasRecoveryEmail(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return false;
        }

        $email = (string) $user->user_email;

        if ($email === '' || is_email($email) === false || self::isPlaceholderEmail($email)) {
            return false;
        }

        // F-332: an address still awaiting its verification code ('0') is
        // not a recovery channel yet. '1' (proven) and '' (legacy) are.
        return (string) get_user_meta($userId, \BCC\Trust\Core\REST\Auth\AuthSupport::META_EMAIL_VERIFIED, true) !== '0';
    }

    /**
     * F-253: '1' when the member knows their password, '0' when the account
     * was minted with a random, never-shown one (wallet and OAuth signup).
     * Written at signup; flipped to '1' whenever a password is actually set
     * (reset, in-app change, wp-admin edit — UserLifecycleService).
     */
    public const META_HAS_PASSWORD = 'bcc_has_password';

    /**
     * Does the member have a password they know? Drives which confirmation
     * the settings forms show (password field, or "sign with your wallet").
     *
     *   true  — they set one (password signup, or any later set/reset)
     *   false — never: a wallet/OAuth signup that has not set one, or an
     *           older wallet signup still on its placeholder email (no
     *           reset could ever have reached it)
     *   null  — unknown: an account from before F-253 with a real email
     *           (a password signup, or an OAuth signup — can't be told
     *           apart). The client offers both confirmations.
     */
    public static function hasPassword(int $userId): ?bool
    {
        if ($userId <= 0) {
            return null;
        }

        $flag = (string) get_user_meta($userId, self::META_HAS_PASSWORD, true);
        if ($flag === '1') {
            return true;
        }
        if ($flag === '0') {
            return false;
        }

        $user = get_userdata($userId);
        if ($user instanceof \WP_User && self::isPlaceholderEmail((string) $user->user_email)) {
            return false;
        }

        return null;
    }

    /**
     * F-253: the wallet_links id of the wallet the account was CREATED with,
     * written by POST /auth/wallet-signup once that link commits — and
     * nowhere else. Its exact link row: a relink (a new row) is not it, and
     * an OAuth / password account never has one. Read by
     * AccountCredentialCheck's signup-wallet exception.
     */
    public const META_SIGNUP_WALLET_LINK = 'bcc_signup_wallet_link';

    public static function markHasPassword(int $userId): void
    {
        if ($userId > 0) {
            update_user_meta($userId, self::META_HAS_PASSWORD, '1');
        }
    }
}
