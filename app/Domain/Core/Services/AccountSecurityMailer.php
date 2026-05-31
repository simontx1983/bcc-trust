<?php
/**
 * AccountSecurityMailer — side-channel notifications for credential /
 * identity-bearing-artifact changes.
 *
 * When an attacker hijacks a session and rotates the password (or
 * adds a wallet, or initiates account deletion), the legitimate user
 * has no in-app signal that anything happened — by the time they
 * notice they can't log in, the attacker has had hours of head-start.
 *
 * This mailer closes that gap by emailing the user out-of-band on
 * every credential or identity-bearing-artifact change. In-app
 * notifications and push are deliberately NOT used for these events:
 * an attacker with a live session can mark in-app rows read and
 * dismiss push payloads, but the user's email inbox is a separate
 * trust anchor.
 *
 * Constraints (load-bearing — do not relax):
 *
 *   - **Never throws.** Every send is wrapped in try/catch. The audit
 *     log already captured the mutation; the email is a best-effort
 *     side channel. Per Constitution §VIII.30, audit/telemetry MUST
 *     NOT break the mutation path.
 *
 *   - **Never retries.** A failed wp_mail records a DegradationMetric
 *     and gives up. Account-security emails are time-sensitive (the
 *     point is "tell the user within seconds"); a delayed retry from
 *     a sweep cron is operationally useless and creates a window
 *     where the user might re-trigger the action manually before the
 *     stale email arrives. The audit log is the second line of
 *     defense — admins can reconstruct.
 *
 *   - **Plain text only.** HTML emails widen the surface (rendering
 *     differences, image-pixel tracking, CSS expression engines on
 *     legacy mail clients). Keep it terse and unmistakably from BCC.
 *
 *   - **No secrets in body.** No password, no full wallet address, no
 *     session token. Truncate wallet addresses to first-4 / last-4
 *     so a forwarded email can't be replayed as proof of address
 *     ownership. The IP + timestamp are the actionable context.
 *
 *   - **Email-change special case.** Send to BOTH the old AND new
 *     address. The old gets the canary signal (user-detected
 *     compromise); the new gets the confirmation. If a single send
 *     fails, the other still goes — they are independent.
 *
 * Observability: failures record
 * `DegradationMetrics::record('account_security_mail', $event)` where
 * $event is one of {email_changed_send_failed,
 * password_changed_send_failed, account_deleted_send_failed,
 * wallet_linked_send_failed, wallet_unlinked_send_failed,
 * sessions_revoked_all_send_failed}. The taxonomy is registered in
 * bcc-core/bcc-core.php so /system/health surfaces sustained failure
 * on a hot security surface.
 *
 * @package BCC\Trust\Core\Services
 * @since   2026-05-13 (operational-hardening track F)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Security\IpResolver;

if (!defined('ABSPATH')) {
    exit;
}

final class AccountSecurityMailer
{
    /** "From" header for security mails; site_name keeps it recognisable. */
    private static function fromHeader(): string
    {
        // Use the WP admin_email as the From address; the site name is the
        // display name. This matches WordPress's own password-reset mails so
        // the user sees consistent sender identity.
        $email = get_option('admin_email');
        if (!is_string($email) || $email === '') {
            return '';
        }
        $name = get_bloginfo('name') ?: 'BCC';

        return sprintf('From: %s <%s>', $name, $email);
    }

    /**
     * Email change — send to BOTH the old AND new addresses.
     *
     * Old address gets the canary "this account no longer uses you"
     * signal; new address gets the "this account now uses you"
     * confirmation. The two sends are independent — failure of one
     * does not affect the other.
     */
    public static function emailChanged(int $userId, string $oldEmail, string $newEmail): void
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return;
        }

        $siteName = get_bloginfo('name') ?: 'BCC';
        $ip       = self::clientIp();
        $when     = gmdate('Y-m-d H:i:s') . ' UTC';

        // To OLD address — the canary signal.
        $subjectOld = sprintf('[%s] Your account email was changed', $siteName);
        $bodyOld = sprintf(
            "Hello %s,\n\n"
            . "The email address on your %s account was just changed AWAY from this address.\n\n"
            . "When: %s\n"
            . "IP:   %s\n"
            . "New email: %s\n\n"
            . "If you made this change, no action is needed — this is the last email you'll receive at this address.\n\n"
            . "If you did NOT make this change, your account may be compromised. Reply to this email immediately or contact support; we can help you recover the account.\n\n"
            . "— The %s Team",
            $user->display_name,
            $siteName,
            $when,
            $ip,
            self::maskEmail($newEmail),
            $siteName
        );
        self::send($oldEmail, $subjectOld, $bodyOld, 'email_changed_send_failed', $userId);

        // To NEW address — the confirmation.
        $subjectNew = sprintf('[%s] Email change confirmed', $siteName);
        $bodyNew = sprintf(
            "Hello %s,\n\n"
            . "This email address is now associated with your %s account.\n\n"
            . "When: %s\n"
            . "IP:   %s\n\n"
            . "If you did not initiate this change, contact support immediately.\n\n"
            . "— The %s Team",
            $user->display_name,
            $siteName,
            $when,
            $ip,
            $siteName
        );
        self::send($newEmail, $subjectNew, $bodyNew, 'email_changed_send_failed', $userId);
    }

    /**
     * Password change — send to the user's CURRENT email (which equals
     * the email at the moment the password rotated; password change
     * doesn't change email).
     */
    public static function passwordChanged(int $userId): void
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !$user->user_email) {
            return;
        }

        $siteName = get_bloginfo('name') ?: 'BCC';
        $subject  = sprintf('[%s] Your password was changed', $siteName);
        $body     = sprintf(
            "Hello %s,\n\n"
            . "Your %s account password was just changed.\n\n"
            . "When: %s\n"
            . "IP:   %s\n\n"
            . "If you made this change, no action is needed.\n\n"
            . "If you did NOT make this change, your account may be compromised. "
            . "Sign in and visit your account settings to rotate the password again, "
            . "or reply to this email and we will help you secure the account.\n\n"
            . "— The %s Team",
            $user->display_name,
            $siteName,
            gmdate('Y-m-d H:i:s') . ' UTC',
            self::clientIp(),
            $siteName
        );

        self::send($user->user_email, $subject, $body, 'password_changed_send_failed', $userId);
    }

    /**
     * Account deletion — caller MUST pass the email explicitly because
     * by the time wp_delete_user() returns, the wp_users row is gone
     * and get_userdata($userId) returns false. Capture before calling
     * this method; pass the email string in.
     *
     * The display name is similarly captured by the caller — we use
     * "you" in the body to keep this simple (no $userId resolution).
     */
    public static function accountDeleted(string $email, ?string $displayName = null): void
    {
        if ($email === '') {
            return;
        }

        $siteName = get_bloginfo('name') ?: 'BCC';
        $subject  = sprintf('[%s] Your account was deleted', $siteName);
        $body     = sprintf(
            "Hello %s,\n\n"
            . "Your %s account was just deleted.\n\n"
            . "When: %s\n"
            . "IP:   %s\n\n"
            . "If you initiated this, you're all set — your account, votes, "
            . "endorsements, and on-chain links have been removed.\n\n"
            . "If you did NOT initiate this, contact support immediately. "
            . "We retain audit records that can help reconstruct the timeline "
            . "even after account deletion.\n\n"
            . "— The %s Team",
            $displayName !== null && $displayName !== '' ? $displayName : 'there',
            $siteName,
            gmdate('Y-m-d H:i:s') . ' UTC',
            self::clientIp(),
            $siteName
        );

        // Pass userId = 0 because the user row is about to be (or already
        // has been) deleted; the metric still records under the bucket but
        // the per-user context is intentionally lost.
        self::send($email, $subject, $body, 'account_deleted_send_failed', 0);
    }

    /**
     * Wallet link — a new on-chain identity is now associated with
     * the account, which broadens the auth surface (the user can now
     * sign in via that wallet). Notify out-of-band.
     */
    public static function walletLinked(int $userId, string $chainSlug, string $walletAddress): void
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !$user->user_email) {
            return;
        }

        $siteName = get_bloginfo('name') ?: 'BCC';
        $subject  = sprintf('[%s] A wallet was linked to your account', $siteName);
        $body     = sprintf(
            "Hello %s,\n\n"
            . "A new wallet was just linked to your %s account.\n\n"
            . "Chain:   %s\n"
            . "Address: %s\n"
            . "When:    %s\n"
            . "IP:      %s\n\n"
            . "Linking a wallet means it can be used to sign in to this account. "
            . "If you initiated this, no action is needed.\n\n"
            . "If you did NOT link this wallet, sign in to your account immediately "
            . "and unlink it from the wallet settings. Contact support if you "
            . "need help.\n\n"
            . "— The %s Team",
            $user->display_name,
            $siteName,
            $chainSlug,
            self::truncateAddress($walletAddress),
            gmdate('Y-m-d H:i:s') . ' UTC',
            self::clientIp(),
            $siteName
        );

        self::send($user->user_email, $subject, $body, 'wallet_linked_send_failed', $userId);
    }

    /**
     * Wallet unlink — narrows the auth surface, but still notable.
     * Used by either user-initiated unlink OR admin removal; both
     * flow through the same email so the user knows.
     */
    public static function walletUnlinked(int $userId, string $chainSlug, string $walletAddress): void
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !$user->user_email) {
            return;
        }

        $siteName = get_bloginfo('name') ?: 'BCC';
        $subject  = sprintf('[%s] A wallet was unlinked from your account', $siteName);
        $body     = sprintf(
            "Hello %s,\n\n"
            . "A wallet was just unlinked from your %s account.\n\n"
            . "Chain:   %s\n"
            . "Address: %s\n"
            . "When:    %s\n"
            . "IP:      %s\n\n"
            . "If you initiated this, no action is needed.\n\n"
            . "If you did NOT unlink this wallet, sign in and re-link it from the "
            . "wallet settings. Contact support if you need help.\n\n"
            . "— The %s Team",
            $user->display_name,
            $siteName,
            $chainSlug,
            self::truncateAddress($walletAddress),
            gmdate('Y-m-d H:i:s') . ' UTC',
            self::clientIp(),
            $siteName
        );

        self::send($user->user_email, $subject, $body, 'wallet_unlinked_send_failed', $userId);
    }

    /**
     * Sessions revoked everywhere — the user (or an attacker) just
     * called `/auth/logout-everywhere`. Every outstanding token for
     * this account has been invalidated; the user will need to sign
     * back in on every device including this one.
     *
     * Track-F redundancy: an attacker who triggers this to lock the
     * legitimate user out still trips the email channel — the
     * out-of-band warning reaches the inbox the attacker can't
     * suppress.
     */
    public static function sessionsRevokedAll(int $userId): void
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !$user->user_email) {
            return;
        }

        $siteName = get_bloginfo('name') ?: 'BCC';
        $subject  = sprintf('[%s] All other devices signed out', $siteName);
        $body     = sprintf(
            "Hello %s,\n\n"
            . "Your %s account was just signed out of every device.\n\n"
            . "When: %s\n"
            . "IP:   %s\n\n"
            . "If you initiated this — for example, because you suspected a "
            . "stolen session — no action is needed. You'll be asked to sign "
            . "back in on every device the next time you use them.\n\n"
            . "If you did NOT initiate this, your account may have been "
            . "compromised. Change your password immediately, then sign back "
            . "in. Reply to this email if you need help.\n\n"
            . "— The %s Team",
            $user->display_name,
            $siteName,
            gmdate('Y-m-d H:i:s') . ' UTC',
            self::clientIp(),
            $siteName
        );

        self::send($user->user_email, $subject, $body, 'sessions_revoked_all_send_failed', $userId);
    }

    /**
     * Password-reset link requested — the user (or someone claiming to
     * be them) hit /auth/forgot-password. The endpoint always responds
     * "ok" regardless of whether a matching user exists (anti-
     * enumeration); this mail is only sent when a real user was found.
     *
     * The reset URL is itself the secret (one-shot, 24-hour TTL via
     * WP's user_activation_key). Emitting it in plain text is the
     * standard WP behavior — the body around it carries the canary
     * signal so a user who didn't request the reset learns about an
     * unauthorized attempt against their account.
     */
    public static function passwordResetRequested(int $userId, string $resetUrl): void
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !$user->user_email) {
            return;
        }

        $siteName = get_bloginfo('name') ?: 'BCC';
        $subject  = sprintf('[%s] Reset your password', $siteName);
        $body     = sprintf(
            "Hello %s,\n\n"
            . "Someone requested a password reset for your %s account.\n\n"
            . "When: %s\n"
            . "IP:   %s\n\n"
            . "If this was you, click the link below to set a new password. "
            . "The link is single-use and expires in 24 hours:\n\n"
            . "%s\n\n"
            . "If you did NOT request this, ignore this email — your password "
            . "stays the same. Repeated unexpected reset emails could mean "
            . "someone is targeting your account; reply if you'd like help "
            . "investigating.\n\n"
            . "— The %s Team",
            $user->display_name,
            $siteName,
            gmdate('Y-m-d H:i:s') . ' UTC',
            self::clientIp(),
            $resetUrl,
            $siteName
        );

        self::send($user->user_email, $subject, $body, 'password_reset_requested_send_failed', $userId);
    }

    // ── internals ─────────────────────────────────────────────────────

    /**
     * Send wrapper. Never throws; logs + records DegradationMetric on
     * failure. Caller's mutation has already committed.
     */
    private static function send(string $to, string $subject, string $body, string $failureEvent, int $userId): void
    {
        if ($to === '' || !is_email($to)) {
            return;
        }

        $headers = [];
        $from = self::fromHeader();
        if ($from !== '') {
            $headers[] = $from;
        }

        $sent = false;
        try {
            $sent = (bool) wp_mail($to, $subject, $body, $headers);
        } catch (\Throwable $e) {
            // wp_mail itself shouldn't throw, but PHPMailer can if a
            // filter mis-configures it. Treat any throwable as failure.
            $sent = false;
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] AccountSecurityMailer wp_mail threw', [
                    'event'   => $failureEvent,
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if (!$sent) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] AccountSecurityMailer wp_mail failed', [
                    'event'   => $failureEvent,
                    'user_id' => $userId,
                ]);
            }
            // Per /system/health surface — sustained activation here =
            // mail subsystem unhealthy on a security-critical path.
            \BCC\Core\Observability\DegradationMetrics::record('account_security_mail', $failureEvent);
        }
    }

    /**
     * Resolve the client IP via IpResolver (spoof-proof, Cloudflare-aware).
     * Falls back to "unknown" when the resolver returns its safe default.
     */
    private static function clientIp(): string
    {
        $ip = IpResolver::getClientIp();
        return ($ip && $ip !== '0.0.0.0') ? $ip : 'unknown';
    }

    /**
     * Truncate a wallet address to first-6 / last-4. Full addresses in
     * outbound email = PII leak if the inbox is shared or quoted. Six
     * leading chars are enough to identify which wallet (chain prefix +
     * a few hex chars) without exposing the whole address.
     */
    private static function truncateAddress(string $address): string
    {
        $len = strlen($address);
        if ($len <= 12) {
            return $address;
        }
        return substr($address, 0, 6) . '…' . substr($address, $len - 4);
    }

    /**
     * Mask an email for use INSIDE another email's body. Keeps the
     * domain visible (so the user can confirm) and the first char of
     * the local part. "alice@example.com" → "a***@example.com".
     */
    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return $email;
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at);
        $first  = substr($local, 0, 1);
        return $first . str_repeat('*', max(1, strlen($local) - 1)) . $domain;
    }
}
