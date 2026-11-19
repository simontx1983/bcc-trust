<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security;

use BCC\Core\Http\SafeHttpClient;
use BCC\Core\Log\Logger;
use BCC\Trust\Core\Support\FrontendOrigin;

/**
 * Cloudflare Turnstile, checked server-side — the captcha behind the
 * /auth/login step-up (LoginStepUp).
 *
 * OFF unless BOTH keys are configured: the wp-config constants
 * BCC_TURNSTILE_SITE_KEY / BCC_TURNSTILE_SECRET_KEY, or else the options
 * bcc_turnstile_site_key / bcc_turnstile_secret_key (a constant wins over its
 * option). With either missing, isConfigured() is false and the login route
 * behaves exactly as it did before the step-up existed.
 *
 * verify() never throws and answers one of three verdicts:
 *   - PASS        — Turnstile accepted the token, and it was solved on our
 *                   frontend's /auth/login widget (action `login`, hostname
 *                   ours; see solvedHere());
 *   - FAIL        — the token is missing, malformed, invalid, expired or
 *                   already used (and anything Turnstile rejects for a reason
 *                   we can't attribute to ourselves: unknown error codes fail
 *                   CLOSED, so a crafted token can never buy a bypass);
 *   - UNAVAILABLE — Turnstile could not answer (unreachable, non-200, a
 *                   malformed reply, internal-error) or rejected OUR secret.
 *                   The caller fails OPEN on this — an outage must not lock
 *                   everyone out — and it is logged and counted
 *                   (DegradationMetrics auth_throttle:turnstile_unavailable).
 * The secret never leaves this class.
 */
final class TurnstileVerifier
{
    public const PASS        = 'pass';
    public const FAIL        = 'fail';
    public const UNAVAILABLE = 'unavailable';

    /**
     * The `action` the /auth/login widget must render with
     * (data-action="login"). A token solved for any other action is refused.
     */
    public const ACTION = 'login';

    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Seconds. A slow Turnstile is an unavailable one. */
    private const TIMEOUT = 5;

    /** Turnstile response tokens are at most 2048 characters. */
    private const MAX_TOKEN_LENGTH = 2048;

    /** Error codes that are OUR fault or Turnstile's, never the token's. */
    private const UNAVAILABLE_ERRORS = ['missing-input-secret', 'invalid-input-secret', 'internal-error'];

    public static function isConfigured(): bool
    {
        return self::siteKey() !== '' && self::secret() !== '';
    }

    /** The public site key the client renders the widget with ('' when not configured). */
    public static function siteKey(): string
    {
        return self::config('BCC_TURNSTILE_SITE_KEY', 'bcc_turnstile_site_key');
    }

    private static function secret(): string
    {
        return self::config('BCC_TURNSTILE_SECRET_KEY', 'bcc_turnstile_secret_key');
    }

    private static function config(string $constant, string $option): string
    {
        if (defined($constant)) {
            $value = constant($constant);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        $value = get_option($option, '');
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param string $remoteIp the caller's IP, passed to Turnstile when valid
     * @return self::PASS|self::FAIL|self::UNAVAILABLE
     */
    public static function verify(string $token, string $remoteIp): string
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return self::FAIL;
        }
        $secret = self::secret();
        if ($secret === '') {
            return self::unavailable('no secret configured');
        }

        $body = ['secret' => $secret, 'response' => $token];
        if (filter_var($remoteIp, FILTER_VALIDATE_IP) !== false) {
            $body['remoteip'] = $remoteIp;
        }

        $response = SafeHttpClient::post(self::SITEVERIFY_URL, [
            'timeout' => self::TIMEOUT,
            'body'    => $body,
        ]);
        if (is_wp_error($response)) {
            return self::unavailable('unreachable (' . $response->get_error_code() . ')');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return self::unavailable('HTTP ' . $status);
        }
        $reply = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($reply) || !array_key_exists('success', $reply)) {
            return self::unavailable('malformed reply');
        }
        if ($reply['success'] === true) {
            return self::solvedHere($reply) ? self::PASS : self::FAIL;
        }

        $codes = is_array($reply['error-codes'] ?? null)
            ? array_values(array_filter($reply['error-codes'], 'is_string'))
            : [];
        $ours = array_values(array_intersect($codes, self::UNAVAILABLE_ERRORS));
        if ($ours !== []) {
            return self::unavailable(implode(',', $ours));
        }
        return self::FAIL;
    }

    /**
     * A valid token only counts if OUR /auth/login widget produced it.
     * Turnstile reports where it was solved (`hostname`) and for which widget
     * (`action`, the widget's data-action). This is defence in depth behind
     * the Cloudflare dashboard's hostname list (security review of #715):
     *   - a token solved on another site that renders our public site key
     *     (a key can be allowed on several domains) is refused;
     *   - so is a token from one of our other widgets, should we add any.
     *
     * Our sites: the hosts of BCC_FRONTEND_ORIGIN's exact entries (any
     * scheme or port, so local dev works), plus the one Vercel preview shape
     * FrontendOrigin accepts. A refusal is a FAIL, never UNAVAILABLE: a token
     * from elsewhere must not fail open. It is logged at warning, since it
     * means someone is shopping tokens or the widget is misconfigured.
     *
     * @param array<mixed> $reply
     */
    private static function solvedHere(array $reply): bool
    {
        $action   = $reply['action'] ?? null;
        $hostname = $reply['hostname'] ?? null;
        $hostname = is_string($hostname) ? strtolower(trim($hostname)) : '';

        if ($action === self::ACTION && $hostname !== '' && self::isOurHost($hostname)) {
            return true;
        }
        Logger::warning('[bcc-trust] Turnstile token not from our /auth/login widget; refused', [
            'action'   => is_string($action) ? substr($action, 0, 64) : gettype($action),
            'hostname' => substr($hostname, 0, 255),
        ]);
        return false;
    }

    private static function isOurHost(string $hostname): bool
    {
        foreach (FrontendOrigin::exactOrigins() as $origin) {
            $host = parse_url($origin, PHP_URL_HOST);
            if (is_string($host) && strtolower($host) === $hostname) {
                return true;
            }
        }
        // A Vercel preview: FrontendOrigin's one accepted regex shape.
        return FrontendOrigin::match('https://' . $hostname) !== null;
    }

    /** @return self::UNAVAILABLE */
    private static function unavailable(string $cause): string
    {
        Logger::error('[bcc-trust] Turnstile unavailable; /auth/login falls back to its throttles', ['cause' => $cause]);
        if (class_exists(\BCC\Core\Observability\DegradationMetrics::class)) {
            \BCC\Core\Observability\DegradationMetrics::record('auth_throttle', 'turnstile_unavailable');
        }
        return self::UNAVAILABLE;
    }
}
