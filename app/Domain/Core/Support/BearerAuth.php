<?php
/**
 * BearerAuth — JWT bearer-token auth bridge for the BCC REST API.
 *
 * Hooks WP's `determine_current_user` filter at priority 30 (after
 * WP's built-in cookie/Application-Password handlers, which run at 10
 * and 20). When a request arrives with `Authorization: Bearer <jwt>`
 * AND no other auth has already established a user, we verify the
 * token via JwtToken::decode and authenticate as the `user_id` claim.
 *
 * Order matters: WP cookie auth wins when both a cookie AND a Bearer
 * token are present (same-origin admin tooling). For headless Next.js
 * traffic there's no cookie, so the Bearer path always handles auth.
 *
 * ────────────────────────────────────────────────────────────────────
 *  HEADER FORWARDING — PRODUCTION REQUIREMENT (read this)
 * ────────────────────────────────────────────────────────────────────
 *
 * PHP-FPM and many Apache configs strip the Authorization header
 * before it reaches PHP. Without forwarding, every Bearer-authed
 * request fails silently — works on dev, breaks in prod.
 *
 *   Apache (.htaccess or vhost):
 *     RewriteEngine On
 *     RewriteCond %{HTTP:Authorization} ^(.*)
 *     RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
 *
 *   Nginx (server block, location ~ \.php$):
 *     fastcgi_param HTTP_AUTHORIZATION $http_authorization;
 *
 * `readAuthorizationHeader()` falls back through three sources so
 * common configs work without intervention, but the rewrite above
 * is the canonical fix:
 *
 *   1. $_SERVER['HTTP_AUTHORIZATION']
 *   2. $_SERVER['REDIRECT_HTTP_AUTHORIZATION']      (Apache + suEXEC)
 *   3. apache_request_headers() / getallheaders()   (mod_php only)
 *
 * If none of these populate, the rewrite isn't configured. There is
 * no further fallback — the header is genuinely gone.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, Phase 2 hardening)
 */

namespace BCC\Trust\Core\Support;

use BCC\Core\Log\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class BearerAuth
{
    public static function register(): void
    {
        add_filter('determine_current_user', [self::class, 'authenticateBearer'], 30);
    }

    /**
     * @param  int|false|\WP_Error $userId existing-user-id (false = unauthenticated, int = already authed)
     * @return int|false
     */
    public static function authenticateBearer($userId)
    {
        // If a previous filter (cookie auth, application password, etc.)
        // already authenticated the request, leave it alone — Bearer is
        // a secondary path, not an override.
        if (is_int($userId) && $userId > 0) {
            return $userId;
        }

        $token = self::readBearerToken();
        if ($token === null) {
            // No bearer token — pass through whatever upstream filters decided.
            return is_int($userId) ? $userId : false;
        }

        $result = JwtToken::decode($token);
        if (!$result['ok']) {
            // Audit-level (not error) — bad tokens are common during
            // session expiry / clock skew. Logging at error would
            // drown the channel without revealing actual abuse.
            Logger::audit('jwt_verify_failed', ['error' => $result['error']]);
            return false;
        }

        $payload     = $result['payload'];
        $tokenUserId = (int) ($payload['user_id'] ?? 0);
        if ($tokenUserId <= 0) {
            return false;
        }

        // Refuse a JWT for a user that no longer exists. The token
        // could be from before the user was deleted; the cryptographic
        // signature is still valid, but the principal isn't.
        if (get_userdata($tokenUserId) === false) {
            Logger::audit('jwt_user_missing', ['user_id' => $tokenUserId]);
            return false;
        }

        return $tokenUserId;
    }

    /**
     * Extract the JWT from the Authorization header, or null when
     * absent / malformed. Case-insensitive on the "Bearer " prefix.
     */
    private static function readBearerToken(): ?string
    {
        $header = self::readAuthorizationHeader();
        if ($header === '') {
            return null;
        }

        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));
        return $token === '' ? null : $token;
    }

    /**
     * Read the Authorization header from whichever source the current
     * server config populates. See the file-level docblock for the
     * forwarding requirement.
     */
    private static function readAuthorizationHeader(): string
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $key => $value) {
                if (strcasecmp((string) $key, 'Authorization') === 0) {
                    return (string) $value;
                }
            }
        }
        return '';
    }
}
