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
    private const VERIFY_EMAIL_FAILURE_EVENT = 'verify_email_send_failed';

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

        $siteName = get_bloginfo('name') ?: 'BCC';
        $subject  = sprintf('[%s] Verify your email address', $siteName);

        BccMailer::send(
            $to,
            $subject,
            self::buildHtml($siteName, $otpCode, $verifyUrl),
            self::VERIFY_EMAIL_FAILURE_EVENT
        );
    }

    // ── HTML builder ──────────────────────────────────────────────

    /**
     * Build the HTML body for the verification email.
     *
     * Table-based layout for maximum email-client compatibility.
     * All user-supplied values are htmlspecialchars()-escaped.
     * No external resources — self-contained, no tracking pixels.
     */
    private static function buildHtml(string $siteName, string $otpCode, string $verifyUrl): string
    {
        $safeSiteName  = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $safeOtp       = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');
        $safeVerifyUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>Verify your email &mdash; {$safeSiteName}</title>
</head>
<body style="margin:0;padding:0;background:#0d1117;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0d1117;">
  <tr>
    <td align="center" style="padding:48px 16px 64px;">

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;margin:0 auto;">

        <!-- wordmark -->
        <tr>
          <td align="center" style="padding:0 0 24px;">
            <span style="display:inline-block;font-size:13px;font-weight:700;letter-spacing:4px;text-transform:uppercase;color:#6e7681;">BLUE COLLAR CRYPTO</span>
          </td>
        </tr>

        <!-- card -->
        <tr>
          <td style="background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden;">

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

              <!-- card body -->
              <tr>
                <td style="padding:40px 40px 32px;">

                  <h1 style="margin:0 0 12px;font-size:22px;font-weight:600;color:#f0f6fc;line-height:1.3;">
                    Verify your email address
                  </h1>
                  <p style="margin:0 0 32px;font-size:15px;line-height:1.65;color:#8b949e;">
                    Enter the code below in the app to confirm your email and finish setting up your account. The code is valid for 15&nbsp;minutes.
                  </p>

                  <!-- OTP box -->
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                    <tr>
                      <td align="center" style="padding:28px 24px;background:#0d1117;border:1px solid #30363d;border-radius:6px;">
                        <span style="display:block;font-family:'Courier New',Courier,monospace;font-size:48px;font-weight:700;letter-spacing:16px;color:#f0f6fc;line-height:1;">{$safeOtp}</span>
                        <span style="display:block;margin-top:10px;font-size:13px;color:#6e7681;">Expires in 15 minutes</span>
                      </td>
                    </tr>
                  </table>

                  <!-- or divider -->
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                    <tr>
                      <td style="border-top:1px solid #30363d;"></td>
                      <td align="center" style="font-size:13px;color:#6e7681;white-space:nowrap;padding:0 14px;">or</td>
                      <td style="border-top:1px solid #30363d;"></td>
                    </tr>
                  </table>

                  <!-- verify button -->
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 32px;">
                    <tr>
                      <td align="center">
                        <a href="{$safeVerifyUrl}"
                           style="display:inline-block;padding:13px 36px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-size:15px;font-weight:600;line-height:1.4;letter-spacing:0.2px;">
                          Verify my email &rarr;
                        </a>
                      </td>
                    </tr>
                  </table>

                  <!-- footnote -->
                  <p style="margin:0;font-size:13px;line-height:1.6;color:#6e7681;">
                    The button link expires in 24&nbsp;hours. If you didn&rsquo;t create a {$safeSiteName} account, you can safely ignore this email &mdash; your address won&rsquo;t be used.
                  </p>

                </td>
              </tr>

              <!-- card footer -->
              <tr>
                <td style="padding:18px 40px;border-top:1px solid #21262d;text-align:center;">
                  <p style="margin:0;font-size:12px;color:#6e7681;">
                    &copy; {$safeSiteName} &bull;
                    <a href="https://bluecollarcrypto.io" style="color:#6e7681;text-decoration:none;">bluecollarcrypto.io</a>
                  </p>
                </td>
              </tr>

            </table>
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
