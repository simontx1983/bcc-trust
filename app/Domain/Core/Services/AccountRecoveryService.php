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
 * re-deriving it. A "real" recovery email is any validly-formatted
 * address that is NOT the placeholder domain — that is enough for the
 * standard /auth/forgot-password → /auth/reset-password flow to reach
 * the user. (The email-verified flag is deliberately NOT part of the
 * test: wallet-signup marks every address verified, placeholder
 * included, so it carries no recovery signal.)
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
     * True when the user has a real (non-placeholder, well-formed)
     * recovery email — i.e. a channel /auth/forgot-password can actually
     * reach. This is the account's email-based way back in if every
     * linked wallet is lost.
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

        return $email !== ''
            && is_email($email) !== false
            && !self::isPlaceholderEmail($email);
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

    public static function markHasPassword(int $userId): void
    {
        if ($userId > 0) {
            update_user_meta($userId, self::META_HAS_PASSWORD, '1');
        }
    }
}
