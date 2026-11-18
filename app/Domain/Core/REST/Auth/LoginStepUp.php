<?php

declare(strict_types=1);

namespace BCC\Trust\Core\REST\Auth;

use BCC\Trust\Core\Security\TurnstileVerifier;

/**
 * Captcha step-up for POST /auth/login.
 *
 * App sign-ins go through wp_check_password, not wp-login.php, so they bypass
 * Wordfence. The login buckets stop one source, but a distributed attacker
 * still gets ~100 guesses per 15 min per account (the per-identifier ceiling),
 * and credential stuffing is capped only per network. The step-up asks for a
 * Turnstile token — only when something looks wrong:
 *   - more than IDENTIFIER_FAILURES wrong passwords for one typed identifier
 *     in IDENTIFIER_WINDOW from networks the account has NOT recently signed
 *     in from; or
 *   - more than SITE_FAILURES failed sign-ins site-wide in SITE_WINDOW (a
 *     spike), which turns it on for everyone.
 * Either stays on for HOLD seconds after the latest failure over its
 * threshold. A network the account recently completed a sign-in from (the
 * F-281 trusted networks) never sees the captcha. An identifier with no
 * account has no trusted networks, so it takes exactly the path an untrusted
 * network does: existence-blind. Normal sign-ins never see it.
 *
 * Everything here is a no-op while Turnstile is not configured.
 */
final class LoginStepUp
{
    public const IDENTIFIER_FAILURES = 10;
    public const IDENTIFIER_WINDOW   = 900;

    /** ~10 failures a minute, sustained for 5 minutes — an attack, not mistyping. */
    public const SITE_FAILURES = 50;
    public const SITE_WINDOW   = 300;

    public const HOLD = 900;

    private const FLAG_IDENTIFIER = 'bcc_login_stepup_id_';
    private const FLAG_SITE       = 'bcc_login_stepup_site';

    /** Must this sign-in pass a captcha before its password is checked? */
    public static function required(string $typedKey, bool $trustedNetwork): bool
    {
        if ($trustedNetwork || !TurnstileVerifier::isConfigured()) {
            return false;
        }
        return get_transient(self::FLAG_SITE) !== false
            || get_transient(self::FLAG_IDENTIFIER . self::hash($typedKey)) !== false;
    }

    /** Record a failed sign-in (wrong password or unknown identifier). */
    public static function recordFailure(string $typedKey, bool $trustedNetwork): void
    {
        if (!TurnstileVerifier::isConfigured()) {
            return;
        }

        if (!\BCC\Core\Security\Throttle::allow('login_fail_site', self::SITE_FAILURES, self::SITE_WINDOW, 'bcc_login_fail_site')) {
            if (get_transient(self::FLAG_SITE) === false
                && class_exists(\BCC\Core\Observability\DegradationMetrics::class)
            ) {
                // Once per activation, so the metric counts spikes, not failures.
                \BCC\Core\Observability\DegradationMetrics::record('auth_throttle', 'login_failure_spike');
            }
            set_transient(self::FLAG_SITE, 1, self::HOLD);
        }

        if ($trustedNetwork) {
            return;
        }
        $hash = self::hash($typedKey);
        if (!\BCC\Core\Security\Throttle::allow('login_fail_id', self::IDENTIFIER_FAILURES, self::IDENTIFIER_WINDOW, 'bcc_login_fail_id_' . $hash)) {
            set_transient(self::FLAG_IDENTIFIER . $hash, 1, self::HOLD);
        }
    }

    /**
     * The response body's error.data for a bcc_captcha_required answer.
     *
     * @return array{captcha: array{provider: string, site_key: string}}
     */
    public static function challengeData(): array
    {
        return ['captcha' => ['provider' => 'turnstile', 'site_key' => TurnstileVerifier::siteKey()]];
    }

    private static function hash(string $typedKey): string
    {
        return substr(hash('sha256', $typedKey), 0, 32);
    }
}
