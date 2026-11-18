<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Http\SafeHttpClient;
use BCC\Core\Log\Logger;
use BCC\Core\Observability\DegradationMetrics;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Refuse passwords known to have leaked (Have I Been Pwned's Pwned
 * Passwords, k-anonymity range API).
 *
 * Only the first 5 hex characters of the password's SHA-1 leave the
 * server; the rest is matched locally against the range HIBP returns. The
 * `Add-Padding` header makes every response a similar size, so the size of
 * the answer says nothing about the prefix either. Padding rows carry a
 * count of 0 and never match.
 *
 * FAILS OPEN: a timeout, a transport error, a non-200 or an unreadable
 * answer allows the password (a sign-up, reset or change is never blocked
 * on a third-party outage) and records DegradationMetrics
 * `password_breach_check` / `unavailable`. The password and its hash are
 * never logged.
 *
 * Used by POST /auth/signup, POST /auth/reset-password and
 * PATCH /me/account/password. The 8-character minimum is separate and
 * unchanged.
 */
final class BreachedPasswordCheck
{
    public const RANGE_URL       = 'https://api.pwnedpasswords.com/range/';
    public const TIMEOUT_SECONDS = 2;

    public const ERROR_CODE    = 'bcc_password_breached';
    public const ERROR_MESSAGE = 'This password has appeared in a data breach; please choose a different one.';

    public const METRIC_SUBSYSTEM = 'password_breach_check';
    public const METRIC_EVENT     = 'unavailable';

    /**
     * True only when HIBP answered and lists this password. Every failure
     * to get a usable answer is false (fail open).
     */
    public static function isBreached(string $password): bool
    {
        $sha1   = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $response = SafeHttpClient::get(self::RANGE_URL . $prefix, [
                'timeout'     => self::TIMEOUT_SECONDS,
                'redirection' => 0,
                'headers'     => [
                    'Add-Padding' => 'true',
                    // A neutral agent: WordPress's default one names the site.
                    'User-Agent'  => 'password-range-check',
                ],
            ]);
        } catch (\Throwable $e) {
            return self::unavailable('exception');
        }

        if (is_wp_error($response)) {
            return self::unavailable('transport');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return self::unavailable('http_' . $status);
        }
        $body = (string) wp_remote_retrieve_body($response);
        if (trim($body) === '') {
            return self::unavailable('empty');
        }

        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            $parts = explode(':', trim($line), 2);
            if (count($parts) !== 2) {
                continue;
            }
            if (hash_equals($suffix, strtoupper($parts[0])) && (int) $parts[1] > 0) {
                return true;
            }
        }
        return false;
    }

    /** The 422 every password-setting route answers for a breached password. */
    public static function rejection(): WP_REST_Response
    {
        return ApiResponse::error(self::ERROR_CODE, self::ERROR_MESSAGE, 422);
    }

    /** Record the outage, say nothing about the password, and allow it. */
    private static function unavailable(string $reason): bool
    {
        DegradationMetrics::record(self::METRIC_SUBSYSTEM, self::METRIC_EVENT);
        Logger::warning('[bcc-trust] breached-password check unavailable; password allowed', ['reason' => $reason]);
        return false;
    }
}
