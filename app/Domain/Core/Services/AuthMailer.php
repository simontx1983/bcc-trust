<?php
/**
 * AuthMailer — auth-flow transactional emails.
 *
 * Owns the "what and when" for auth-specific email sends. Generates
 * the HTML body and delegates delivery to BccMailer (in bcc-core),
 * which owns the "how": headers, wp_mail(), and DegradationMetrics.
 *
 * Current sends:
 *   - Email-address verification (OTP code + one-shot link)
 *   - Signup welcome (sent after email is confirmed)
 *
 * Constraints (inherited from AccountSecurityMailer discipline):
 *   - Never throws. All sends are best-effort side channels.
 *   - Callers must not let a failed send block a mutation or signup.
 *   - HTML content: all user-supplied values are htmlspecialchars()
 *     escaped before interpolation. No raw user data in the body.
 *
 * @package BCC\Trust\Core\Services
 * @since   2026-06-02 (email-verification track)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Mail\BccMailer;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthMailer
{
    private const VERIFY_EMAIL_FAILURE_EVENT      = 'verify_email_send_failed';
    private const WELCOME_FAILURE_EVENT            = 'welcome_email_send_failed';
    private const PASSWORD_RESET_FAILURE_EVENT     = 'password_reset_email_send_failed';

    private const LOGO_URL = 'https://bluecollarcrypto.io/wp-content/uploads/2026/05/Blue-Collar-Crypto-Logo.png';
    private const SITE_URL = 'https://bluecollarcrypto.io';

    /**
     * Send the email-address verification email.
     *
     * The email contains two paths to verification:
     *   1. A 6-digit OTP the user types into the /verify-email page.
     *   2. A one-shot button/link that auto-verifies on click.
     *
     * Both paths have independent TTLs (OTP: 15 min, link: 24 h).
     * The merged design lets users on locked-down email clients (no
     * clickable links) still verify, while giving normal users the
     * lower-friction button path.
     *
     * Never throws. Failure is logged + recorded via BccMailer's
     * DegradationMetrics integration under BccMailer::SUBSYSTEM /
     * 'verify_email_send_failed'. Callers MUST NOT block the signup
     * flow on the return value — the user was already created.
     *
     * @param int    $userId    WP user ID of the newly created account.
     * @param string $to        Recipient address (= the address being verified).
     * @param string $otpCode   6-digit plain-text OTP (not the hash — the raw
     *                          code the user will type in).
     * @param string $verifyUrl One-shot token URL built by AuthEndpoint.
     */
    public static function sendVerificationEmail(
        int $userId,
        string $to,
        string $otpCode,
        string $verifyUrl
    ): void {
        if ($to === '' || !is_email($to)) {
            return;
        }

        $user = get_userdata($userId);
        if (!($user instanceof \WP_User)) {
            return;
        }

        $siteName    = get_bloginfo('name') ?: 'BCC';
        $displayName = $user->display_name ?: $user->user_login;
        $subject     = sprintf('[%s] Verify your email address', $siteName);

        BccMailer::send(
            $to,
            $subject,
            self::buildVerifyHtml($siteName, $displayName, $otpCode, $verifyUrl),
            self::VERIFY_EMAIL_FAILURE_EVENT
        );
    }

    /**
     * Send the post-verification welcome email.
     *
     * Dispatched after the user completes email verification — account is
     * now active. Confirms their handle and surfaces a CTA to the floor.
     *
     * Never throws. Failure is logged + recorded under BccMailer::SUBSYSTEM /
     * 'welcome_email_send_failed'. Callers MUST NOT block the verification
     * response on the return value.
     *
     * @param string $to       Recipient address (the verified email).
     * @param string $handle   The user's BCC handle (e.g. "tialuxe").
     * @param string $loginUrl Frontend URL to sign in / go to the floor.
     */
    public static function sendWelcomeEmail(
        string $to,
        string $handle,
        string $loginUrl
    ): void {
        if ($to === '' || !is_email($to)) {
            return;
        }

        $siteName = get_bloginfo('name') ?: 'BCC';
        $subject  = sprintf("Welcome to %s — you're on the floor", $siteName);

        BccMailer::send(
            $to,
            $subject,
            self::buildWelcomeHtml($siteName, $handle, $to, $loginUrl),
            self::WELCOME_FAILURE_EVENT
        );
    }

    /**
     * Send the password-reset request email.
     *
     * Dispatched when a user submits /auth/forgot-password. Contains a
     * single-use reset link (24h TTL) and request metadata (timestamp +
     * IP) so the user can recognise and investigate unexpected requests.
     *
     * Never throws. Failure is logged + recorded under BccMailer::SUBSYSTEM /
     * 'password_reset_email_send_failed'. Callers MUST NOT block the
     * forgot-password response on the return value.
     *
     * @param int    $userId     WP user ID.
     * @param string $to         Recipient address.
     * @param string $resetUrl   Single-use reset URL (24h TTL).
     * @param string $requestIp  Requester IP (shown in email; empty = omit).
     */
    public static function sendPasswordResetEmail(
        int $userId,
        string $to,
        string $resetUrl,
        string $requestIp = ''
    ): void {
        if ($to === '' || !is_email($to)) {
            return;
        }

        $user = get_userdata($userId);
        if (!($user instanceof \WP_User)) {
            return;
        }

        $siteName    = get_bloginfo('name') ?: 'BCC';
        $displayName = $user->display_name ?: $user->user_login;
        $requestedAt = gmdate('Y-m-d H:i:s') . ' UTC';
        $subject     = sprintf('[%s] Reset your password', $siteName);

        BccMailer::send(
            $to,
            $subject,
            self::buildPasswordResetHtml($siteName, $displayName, $resetUrl, $requestIp, $requestedAt),
            self::PASSWORD_RESET_FAILURE_EVENT
        );
    }

    // ── HTML builders ─────────────────────────────────────────────

    /**
     * Verification email HTML.
     *
     * Dark-themed, table-based for maximum email-client compatibility.
     * Top accent stripe: BCC blue (#16b5e6).
     * OTP box: dark bg with blue border and blue digits.
     * Two paths: enter code in app OR click the button (24h link).
     */
    private static function buildVerifyHtml(
        string $siteName,
        string $displayName,
        string $otpCode,
        string $verifyUrl
    ): string {
        $safeSiteName    = htmlspecialchars($siteName,    ENT_QUOTES, 'UTF-8');
        $safeDisplayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $safeOtp         = htmlspecialchars($otpCode,     ENT_QUOTES, 'UTF-8');
        $safeVerifyUrl   = htmlspecialchars($verifyUrl,   ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<title>Verify your email &mdash; {$safeSiteName}</title>
</head>
<body style="margin:0;padding:0;background-color:#0d1117;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0d1117">
  <tr>
    <td align="center" style="padding:40px 16px 56px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

        <tr>
          <td align="center" style="padding:0 0 24px;">
            <img src="https://bluecollarcrypto.io/wp-content/uploads/2026/05/Blue-Collar-Crypto-Logo.png"
                 alt="{$safeSiteName}" width="130" height="auto"
                 style="display:block;max-width:130px;height:auto;border:0;outline:none;text-decoration:none;">
          </td>
        </tr>

        <tr>
          <td bgcolor="#161b22" style="background-color:#161b22;border:1px solid #30363d;border-radius:16px;overflow:hidden;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

              <tr>
                <td bgcolor="#16b5e6" style="background-color:#16b5e6;height:3px;font-size:3px;line-height:3px;">&nbsp;</td>
              </tr>

              <tr>
                <td style="padding:36px 36px 28px;">

                  <h1 style="margin:0 0 20px;font-size:22px;font-weight:600;color:#f0f6fc;line-height:1.3;">
                    Verify your email address
                  </h1>
                  <p style="margin:0 0 14px;font-size:16px;font-weight:500;color:#c9d1d9;line-height:1.5;">
                    Hello, {$safeDisplayName}
                  </p>
                  <p style="margin:0 0 28px;font-size:15px;line-height:1.65;color:#8b949e;">
                    Enter this code in the app to confirm your email and finish setting up your account.
                    The code is valid for&nbsp;15&nbsp;minutes.
                  </p>

                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                    <tr>
                      <td align="center" bgcolor="#0d1117"
                          style="background-color:#0d1117;border:1px solid #16b5e6;border-radius:12px;padding:28px 24px;">
                        <span style="display:block;font-family:'Courier New',Courier,monospace;font-size:44px;font-weight:700;letter-spacing:14px;color:#16b5e6;line-height:1;padding-left:14px;">{$safeOtp}</span>
                        <span style="display:block;margin-top:10px;font-size:12px;letter-spacing:0.5px;text-transform:uppercase;color:#484f58;">Expires in 15 minutes &middot; one-time use</span>
                      </td>
                    </tr>
                  </table>

                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                    <tr>
                      <td style="border-top:1px solid #21262d;font-size:1px;line-height:1px;">&nbsp;</td>
                      <td align="center" style="white-space:nowrap;padding:0 14px;font-size:13px;color:#484f58;">
                        or verify by clicking below
                      </td>
                      <td style="border-top:1px solid #21262d;font-size:1px;line-height:1px;">&nbsp;</td>
                    </tr>
                  </table>

                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 32px;">
                    <tr>
                      <td align="center" bgcolor="#16b5e6" style="background-color:#16b5e6;border-radius:8px;">
                        <a href="{$safeVerifyUrl}"
                           style="display:inline-block;padding:13px 32px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;line-height:1.4;white-space:nowrap;">
                          Verify my email &rarr;
                        </a>
                      </td>
                    </tr>
                  </table>

                  <p style="margin:0;font-size:13px;line-height:1.6;color:#6e7681;">
                    The button link expires in 24&nbsp;hours. If you didn&rsquo;t create a {$safeSiteName} account,
                    you can safely ignore this email &mdash; your address will not be used.
                  </p>

                </td>
              </tr>

              <tr>
                <td style="padding:16px 36px;border-top:1px solid #21262d;text-align:center;">
                  <p style="margin:0;font-size:12px;color:#484f58;line-height:1.5;">
                    &copy; {$safeSiteName} &bull;
                    <a href="https://bluecollarcrypto.io" style="color:#484f58;text-decoration:none;">bluecollarcrypto.io</a>
                  </p>
                </td>
              </tr>

            </table>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:24px 16px 0;">
            <p style="margin:0;font-size:11px;color:#484f58;line-height:1.6;">
              You&rsquo;re receiving this because you registered a {$safeSiteName} account with this address.<br>
              For your security, please do not forward this email.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * Welcome email HTML.
     *
     * Dark-themed, same shell as the verify email.
     * Top accent stripe: BCC orange (#f98a1c) — visually distinguishes
     * it from the blue verify email in a crowded inbox.
     * Shows @handle + email in an account info block, with a CTA to the floor.
     * No password shown — account security improvement over the old WP default.
     */
    private static function buildWelcomeHtml(
        string $siteName,
        string $handle,
        string $email,
        string $loginUrl
    ): string {
        $safeSiteName = htmlspecialchars($siteName,  ENT_QUOTES, 'UTF-8');
        $safeHandle   = htmlspecialchars($handle,    ENT_QUOTES, 'UTF-8');
        $safeEmail    = htmlspecialchars($email,     ENT_QUOTES, 'UTF-8');
        $safeLoginUrl = htmlspecialchars($loginUrl,  ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<title>Welcome to {$safeSiteName}</title>
</head>
<body style="margin:0;padding:0;background-color:#0d1117;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0d1117">
  <tr>
    <td align="center" style="padding:40px 16px 56px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

        <tr>
          <td align="center" style="padding:0 0 24px;">
            <img src="https://bluecollarcrypto.io/wp-content/uploads/2026/05/Blue-Collar-Crypto-Logo.png"
                 alt="{$safeSiteName}" width="130" height="auto"
                 style="display:block;max-width:130px;height:auto;border:0;outline:none;text-decoration:none;">
          </td>
        </tr>

        <tr>
          <td bgcolor="#161b22" style="background-color:#161b22;border:1px solid #30363d;border-radius:16px;overflow:hidden;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

              <tr>
                <td bgcolor="#f98a1c" style="background-color:#f98a1c;height:3px;font-size:3px;line-height:3px;">&nbsp;</td>
              </tr>

              <tr>
                <td style="padding:36px 36px 28px;">

                  <h1 style="margin:0 0 12px;font-size:22px;font-weight:600;color:#f0f6fc;line-height:1.3;">
                    You&rsquo;re on the floor, {$safeHandle}.
                  </h1>
                  <p style="margin:0 0 28px;font-size:15px;line-height:1.65;color:#8b949e;">
                    Your email is confirmed and your account is ready. Welcome to a community of
                    validators, builders, creators, and contributors.
                  </p>

                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                         style="margin:0 0 28px;background-color:#0d1117;border:1px solid #30363d;border-radius:12px;">
                    <tr>
                      <td style="padding:20px 24px;">
                        <p style="margin:0 0 10px;font-size:12px;font-weight:600;color:#484f58;text-transform:uppercase;letter-spacing:0.8px;">
                          Your account
                        </p>
                        <p style="margin:0 0 5px;font-size:15px;color:#f0f6fc;">@{$safeHandle}</p>
                        <p style="margin:0;font-size:14px;color:#8b949e;">{$safeEmail}</p>
                      </td>
                    </tr>
                  </table>

                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 32px;">
                    <tr>
                      <td align="center" bgcolor="#16b5e6" style="background-color:#16b5e6;border-radius:8px;">
                        <a href="{$safeLoginUrl}"
                           style="display:inline-block;padding:13px 32px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;line-height:1.4;white-space:nowrap;">
                          Head to the floor &rarr;
                        </a>
                      </td>
                    </tr>
                  </table>

                  <p style="margin:0;font-size:14px;line-height:1.7;color:#8b949e;">
                    See you on the floor,<br>
                    <span style="color:#f0f6fc;font-weight:500;">The BCC Team</span>
                  </p>

                </td>
              </tr>

              <tr>
                <td style="padding:16px 36px;border-top:1px solid #21262d;text-align:center;">
                  <p style="margin:0;font-size:12px;color:#484f58;line-height:1.5;">
                    &copy; {$safeSiteName} &bull;
                    <a href="https://bluecollarcrypto.io" style="color:#484f58;text-decoration:none;">bluecollarcrypto.io</a>
                  </p>
                </td>
              </tr>

            </table>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:24px 16px 0;">
            <p style="margin:0;font-size:11px;color:#484f58;line-height:1.6;">
              You&rsquo;re receiving this because you created a {$safeSiteName} account.<br>
              Blue Collar Crypto &bull; blockchain-powered community for validators, builders, creators, and contributors.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * Password reset request email HTML.
     *
     * Dark-themed, same shell as verify/welcome emails.
     * Top accent stripe: red (#e5534b) — security-action colour.
     * Shows request timestamp + IP so the user can identify unexpected
     * requests. CTA uses BCC blue — consistent with all other emails.
     */
    private static function buildPasswordResetHtml(
        string $siteName,
        string $displayName,
        string $resetUrl,
        string $requestIp,
        string $requestedAt
    ): string {
        $safeSiteName    = htmlspecialchars($siteName,    ENT_QUOTES, 'UTF-8');
        $safeDisplayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $safeResetUrl    = htmlspecialchars($resetUrl,    ENT_QUOTES, 'UTF-8');
        $safeRequestedAt = htmlspecialchars($requestedAt, ENT_QUOTES, 'UTF-8');
        $ipLine = $requestIp !== ''
            ? '<p style="margin:0;font-size:14px;color:#8b949e;"><span style="color:#6e7681;">IP:&nbsp;&nbsp;&nbsp;</span>'
              . htmlspecialchars($requestIp, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<title>Reset your password &mdash; {$safeSiteName}</title>
</head>
<body style="margin:0;padding:0;background-color:#0d1117;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0d1117">
  <tr>
    <td align="center" style="padding:40px 16px 56px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

        <tr>
          <td align="center" style="padding:0 0 24px;">
            <img src="https://bluecollarcrypto.io/wp-content/uploads/2026/05/Blue-Collar-Crypto-Logo.png"
                 alt="{$safeSiteName}" width="130" height="auto"
                 style="display:block;max-width:130px;height:auto;border:0;outline:none;text-decoration:none;">
          </td>
        </tr>

        <tr>
          <td bgcolor="#161b22" style="background-color:#161b22;border:1px solid #30363d;border-radius:16px;overflow:hidden;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

              <tr>
                <td bgcolor="#e5534b" style="background-color:#e5534b;height:3px;font-size:3px;line-height:3px;">&nbsp;</td>
              </tr>

              <tr>
                <td style="padding:36px 36px 28px;">

                  <h1 style="margin:0 0 20px;font-size:22px;font-weight:600;color:#f0f6fc;line-height:1.3;">
                    Reset your password
                  </h1>
                  <p style="margin:0 0 14px;font-size:16px;font-weight:500;color:#c9d1d9;line-height:1.5;">
                    Hello, {$safeDisplayName}
                  </p>
                  <p style="margin:0 0 24px;font-size:15px;line-height:1.65;color:#8b949e;">
                    Someone requested a password reset for your {$safeSiteName} account.
                    Click the button below to set a new password.
                  </p>

                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                         style="margin:0 0 28px;background-color:#0d1117;border:1px solid #30363d;border-radius:12px;">
                    <tr>
                      <td style="padding:20px 24px;">
                        <p style="margin:0 0 8px;font-size:12px;font-weight:600;color:#484f58;text-transform:uppercase;letter-spacing:0.8px;">
                          Request details
                        </p>
                        <p style="margin:0 0 4px;font-size:14px;color:#8b949e;">
                          <span style="color:#6e7681;">When:&nbsp;</span>{$safeRequestedAt}
                        </p>
                        {$ipLine}
                      </td>
                    </tr>
                  </table>

                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 28px;">
                    <tr>
                      <td align="center" bgcolor="#16b5e6" style="background-color:#16b5e6;border-radius:8px;">
                        <a href="{$safeResetUrl}"
                           style="display:inline-block;padding:13px 32px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;line-height:1.4;white-space:nowrap;">
                          Reset my password &rarr;
                        </a>
                      </td>
                    </tr>
                  </table>

                  <p style="margin:0 0 20px;font-size:13px;line-height:1.6;color:#6e7681;">
                    This link is single-use and expires in&nbsp;24&nbsp;hours.
                  </p>

                  <p style="margin:0;font-size:13px;line-height:1.65;color:#6e7681;">
                    If you did <strong style="color:#8b949e;">not</strong> request this, ignore this email &mdash;
                    your password stays the same. Repeated unexpected reset emails could mean someone
                    is targeting your account; reply if you&rsquo;d like help investigating.
                  </p>

                </td>
              </tr>

              <tr>
                <td style="padding:16px 36px;border-top:1px solid #21262d;text-align:center;">
                  <p style="margin:0;font-size:12px;color:#484f58;line-height:1.5;">
                    &copy; {$safeSiteName} &bull;
                    <a href="https://bluecollarcrypto.io" style="color:#484f58;text-decoration:none;">bluecollarcrypto.io</a>
                  </p>
                </td>
              </tr>

            </table>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding:24px 16px 0;">
            <p style="margin:0;font-size:11px;color:#484f58;line-height:1.6;">
              You&rsquo;re receiving this because a password reset was requested for your {$safeSiteName} account.<br>
              For your security, please do not forward this email.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
    }
}
