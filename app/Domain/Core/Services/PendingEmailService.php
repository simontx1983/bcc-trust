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

        $result = wp_update_user(['ID' => $userId, 'user_email' => $pending['email']]);
        if (is_wp_error($result)) {
            return ['ok' => false, 'error' => self::ERR_WRITE_FAILED];
        }

        update_user_meta($userId, self::META_EMAIL_VERIFIED, '1');
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
