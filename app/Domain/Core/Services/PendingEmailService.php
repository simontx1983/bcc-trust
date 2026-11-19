<?php
/**
 * PendingEmailService — verify-before-promote for a NEW account email.
 *
 * A new address is never written to `user_email` on request. It is held
 * PENDING (with the HMAC of a 6-digit OTP) and the OTP is mailed to the NEW
 * inbox; only a correct code promotes it to `user_email` and sets
 * `_bcc_email_verified = '1'`. Until then the account keeps its old address
 * and its old verification state, and nothing that matches by `user_email`
 * (OAuth link-by-email, password login by email, password reset) can see the
 * pending one.
 *
 * Shared by:
 *   - RecoveryEmailEndpoint (purpose 'recovery') — wallet-only accounts
 *     replacing the placeholder with a real address;
 *   - MyAccountEndpoint (purpose 'change') — PATCH /me/account/email. Before
 *     this, a password holder could re-point a VERIFIED account at an
 *     address they don't own, and the '1' stayed — OAuth would then link the
 *     real owner of that address into their account (pre-hijack).
 *
 * One pending address per account: a new request (either purpose)
 * supersedes the previous one. A confirm only accepts its own purpose, so a
 * code from one flow can never complete the other.
 *
 * The transient key keeps the recovery flow's original prefix, so recovery
 * requests already in flight at deploy (TTL 15 min) still confirm; their
 * value has no `purpose` and is read as 'recovery'.
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class PendingEmailService
{
    public const PURPOSE_RECOVERY = 'recovery';
    public const PURPOSE_CHANGE   = 'change';

    /** Pending-email transient prefix + TTL (15 min; mirrors AuthSupport::OTP_TTL). */
    public const PENDING_PREFIX = 'bcc_recovery_email_';
    public const PENDING_TTL    = 900;

    /** Email-verified usermeta key (AuthSupport::META_EMAIL_VERIFIED). */
    private const META_EMAIL_VERIFIED = '_bcc_email_verified';

    /**
     * F-294: the address a WALLET sign-up typed, held pending until it is
     * verified (user_email keeps the wallet placeholder meanwhile). An
     * unverified address used to go straight into user_email, where it
     * reserved the address against its real owner (user_email is UNIQUE)
     * and nothing could reclaim it. Verified through the ordinary sign-up
     * code and link (POST /auth/verify-email), which apply it with
     * applyPendingSignupEmail().
     */
    public const META_PENDING_SIGNUP_EMAIL = 'bcc_pending_signup_email';

    /** applyPendingSignupEmail() outcomes. */
    public const SIGNUP_EMAIL_NONE     = 'none';     // nothing pending, or user_email is already real
    public const SIGNUP_EMAIL_APPLIED  = 'applied';
    public const SIGNUP_EMAIL_CONFLICT = 'conflict'; // someone else holds it now; the pending one is dropped
    public const SIGNUP_EMAIL_FAILED   = 'failed';   // the write failed; pending kept for a retry

    /** At most this many pending holders of one address are looked at. */
    public const MAX_PENDING_SIGNUP_HOLDERS = 5;

    /**
     * The accounts whose pending sign-up address is $email, oldest first, at
     * most MAX_PENDING_SIGNUP_HOLDERS. More than one can hold it: two wallet
     * sign-ups can type the same address (one of them perhaps not its
     * owner), and none owns it until one proves the inbox. A holder past the
     * bound can still verify with its emailed link, which names it.
     *
     * @return list<int>
     */
    public static function pendingSignupHolders(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $ids = get_users([
            'meta_key'   => self::META_PENDING_SIGNUP_EMAIL,
            'meta_value' => $email,
            'fields'     => 'ID',
            'orderby'    => 'ID',
            'order'      => 'ASC',
            'number'     => self::MAX_PENDING_SIGNUP_HOLDERS,
        ]);
        $holders = [];
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $holders[] = (int) $id;
            }
        }
        return $holders;
    }

    /** The pending sign-up address for $userId, or '' when there is none. */
    public static function pendingSignupEmail(int $userId): string
    {
        return $userId > 0 ? (string) get_user_meta($userId, self::META_PENDING_SIGNUP_EMAIL, true) : '';
    }

    /**
     * applyPendingSignupEmail() outcome (H1): a pending address was held but
     * user_email is no longer the wallet placeholder, because it was changed
     * outside BCC meanwhile. The code or link proved the PENDING inbox, not
     * the address now in user_email, so the caller must not finalise. The
     * pending one is dropped.
     */
    public const SIGNUP_EMAIL_REFUSED = 'refused';

    /**
     * On a proven inbox (a correct sign-up code, or the link): move the
     * pending sign-up address into user_email. Only while user_email is
     * still the wallet placeholder; the address is re-checked first, and if
     * another account holds it now the pending one is dropped. Does NOT set
     * the verified flag: the caller's finalizeVerification() does.
     */
    public static function applyPendingSignupEmail(int $userId): string
    {
        $pending = self::pendingSignupEmail($userId);
        if ($pending === '') {
            return self::SIGNUP_EMAIL_NONE;
        }
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            delete_user_meta($userId, self::META_PENDING_SIGNUP_EMAIL);
            return self::SIGNUP_EMAIL_NONE;
        }
        if (!AccountRecoveryService::isPlaceholderEmail((string) $user->user_email)) {
            delete_user_meta($userId, self::META_PENDING_SIGNUP_EMAIL);
            return self::SIGNUP_EMAIL_REFUSED;
        }
        $holder = email_exists($pending);
        if ($holder !== false && (int) $holder !== $userId) {
            delete_user_meta($userId, self::META_PENDING_SIGNUP_EMAIL);
            return self::SIGNUP_EMAIL_CONFLICT;
        }
        // The old address is the placeholder: WordPress core's "Email
        // Changed" mail would go to a dead inbox, so it is off for this write.
        $result = self::writeEmail($userId, $pending);
        if (is_wp_error($result)) {
            return self::SIGNUP_EMAIL_FAILED;
        }
        delete_user_meta($userId, self::META_PENDING_SIGNUP_EMAIL);
        return self::SIGNUP_EMAIL_APPLIED;
    }

    /** confirm() outcomes other than success. */
    public const ERR_NONE         = 'none';          // nothing pending for this purpose (or expired)
    public const ERR_BAD_CODE     = 'bad_code';      // wrong code — pending kept for a retry
    public const ERR_CONFLICT     = 'conflict';      // address taken meanwhile — pending dropped
    public const ERR_WRITE_FAILED = 'write_failed';  // wp_update_user failed — pending kept

    /**
     * Stage $email as the account's pending address. Returns the plain OTP
     * for the caller to mail to $email. Supersedes any previous pending one.
     */
    public static function stage(int $userId, string $email, string $purpose): string
    {
        $otp = sprintf('%06d', random_int(0, 999999));
        set_transient(
            self::PENDING_PREFIX . $userId,
            [
                'email'    => $email,
                'otp_hash' => self::hash($otp),
                'purpose'  => $purpose,
            ],
            self::PENDING_TTL
        );
        return $otp;
    }

    /** The pending address for $purpose, or null. Never matched by user_email lookups. */
    public static function pendingEmail(int $userId, string $purpose): ?string
    {
        $pending = self::read($userId, $purpose);
        return $pending === null ? null : $pending['email'];
    }

    /**
     * Confirm the OTP and promote the pending address.
     *
     * On success: user_email = the pending address, META_EMAIL_VERIFIED =
     * '1' (the address is now proven), pending cleared. Returns
     * ['ok' => true, 'email' => new, 'old_email' => previous user_email].
     *
     * @return array{ok: true, email: string, old_email: string}|array{ok: false, error: string}
     */
    public static function confirm(int $userId, string $code, string $purpose): array
    {
        $pending = self::read($userId, $purpose);
        if ($pending === null) {
            return ['ok' => false, 'error' => self::ERR_NONE];
        }

        // Timing-safe compare. On mismatch the pending entry is LEFT in place
        // so the user can retry within the TTL + the caller's throttle.
        if (!hash_equals($pending['otp_hash'], self::hash($code))) {
            return ['ok' => false, 'error' => self::ERR_BAD_CODE];
        }

        // Re-check the conflict that was clear at request time (race window).
        $existingId = email_exists($pending['email']);
        if ($existingId !== false && (int) $existingId !== $userId) {
            delete_transient(self::PENDING_PREFIX . $userId);
            return ['ok' => false, 'error' => self::ERR_CONFLICT];
        }

        $user     = get_userdata($userId);
        $oldEmail = $user instanceof \WP_User ? (string) $user->user_email : '';

        // The old address gets BCC's own notice (the caller's job). WordPress
        // core would send a second "Email Changed" mail from the same update
        // (F-294), so its notice is switched off for THIS write only.
        $result = self::writeEmail($userId, $pending['email']);
        if (is_wp_error($result)) {
            return ['ok' => false, 'error' => self::ERR_WRITE_FAILED];
        }

        update_user_meta($userId, self::META_EMAIL_VERIFIED, '1');
        // F-294: a real address is in place now; a wallet sign-up's
        // still-pending one is moot.
        delete_user_meta($userId, self::META_PENDING_SIGNUP_EMAIL);
        delete_transient(self::PENDING_PREFIX . $userId);

        return ['ok' => true, 'email' => $pending['email'], 'old_email' => $oldEmail];
    }

    /**
     * Mask an email for a safe echo back to the client: first char + stars
     * + domain (e.g. `a****@example.com`). Not security-critical — the
     * caller already supplied the address — just avoids echoing it verbatim.
     */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        return substr($email, 0, 1) . str_repeat('*', max(1, $at - 1)) . substr($email, $at);
    }

    /**
     * H1: the address this service is writing to user_email right now, per
     * user. Set only for the duration of writeEmail()'s wp_update_user().
     *
     * @var array<int, string>
     */
    private static array $writing = [];

    /**
     * H1: is this email change PendingEmailService's own write of $email for
     * $userId? UserLifecycleService asks on `profile_update`: every other
     * user_email change is an address nobody proved, and revokes the
     * account's email verification. True only inside writeEmail(), for that
     * user and that address.
     */
    public static function isOwnWrite(int $userId, string $email): bool
    {
        return isset(self::$writing[$userId])
            && strtolower(trim(self::$writing[$userId])) === strtolower(trim($email));
    }

    /**
     * Write a PROVEN address to user_email, with WordPress core's "Email
     * Changed" notice off for this write (both callers send their own, or
     * none). Marked as this service's own write while wp_update_user() runs,
     * so the `profile_update` it fires does not revoke the verification
     * (isOwnWrite).
     *
     * @return int|\WP_Error
     */
    private static function writeEmail(int $userId, string $email)
    {
        self::$writing[$userId] = $email;
        add_filter('send_email_change_email', '__return_false', PHP_INT_MAX);
        try {
            return wp_update_user(['ID' => $userId, 'user_email' => $email]);
        } finally {
            remove_filter('send_email_change_email', '__return_false', PHP_INT_MAX);
            unset(self::$writing[$userId]);
        }
    }

    /** @return array{email: string, otp_hash: string}|null */
    private static function read(int $userId, string $purpose): ?array
    {
        $pending = get_transient(self::PENDING_PREFIX . $userId);
        if (!is_array($pending) || !isset($pending['email'], $pending['otp_hash'])) {
            return null;
        }
        // Pre-existing recovery entries carry no purpose.
        $storedPurpose = isset($pending['purpose']) ? (string) $pending['purpose'] : self::PURPOSE_RECOVERY;
        if ($storedPurpose !== $purpose) {
            return null;
        }
        return ['email' => (string) $pending['email'], 'otp_hash' => (string) $pending['otp_hash']];
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) wp_salt('auth'));
    }
}
