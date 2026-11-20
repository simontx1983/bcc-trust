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
 * ⚠ REST REQUESTS ONLY (security review, 2026-11-19). The bearer is the
 * app's credential for `/wp-json/…`, and nothing else. Before this it
 * signed a member in on EVERY request type: cms HTML (a 404 page printed
 * a peepso_nonce minted for them), admin-ajax and PeepSo's /peepsoajax/
 * (which then answered for that member). {@see isRestRequestFor()}
 * decides from the request alone, because REST_REQUEST is not defined
 * yet when core first resolves the user (WP::init(), before
 * parse_request). Anything it cannot place as a REST request through the
 * front controller fails closed: the token is ignored, the request is
 * signed out.
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
    /** presentedTokenFailure() values. */
    public const TOKEN_EXPIRED = 'expired';
    public const TOKEN_INVALID = 'invalid';
    /**
     * F-462: the token's revocation state could not be read, so whether it
     * still counts is unknown. The request is NOT authenticated, and is not
     * served as anonymous either: refuseUnknownTokenState() answers 503.
     * A 401 with a token code would make the app sign the member out
     * (#673), so one database blip would sign everyone out.
     */
    public const TOKEN_UNAVAILABLE = 'unavailable';

    /** First: a request whose token state is unknown reaches nothing. */
    public const AUTH_ERRORS_PRIORITY = 1;

    /**
     * Why the bearer token PRESENTED with this request failed, if it did
     * (F-288): self::TOKEN_EXPIRED / TOKEN_INVALID, or null (no token, or it
     * worked). Request-scoped; read by Envelope to turn a 401
     * `bcc_unauthorized` into `bcc_token_expired` / `bcc_token_invalid`.
     */
    private static ?string $presentedTokenFailure = null;

    public static function presentedTokenFailure(): ?string
    {
        return self::$presentedTokenFailure;
    }

    /**
     * The user THIS request's bearer token authenticated, or 0 when the
     * bearer did not decide: no token, a bad one, or an earlier login (a
     * WordPress cookie, an application password) had already won.
     * Request-scoped; read by WordPressSignInGate, which lets a member onto
     * a bcc route only when the bearer is what signed them in.
     */
    private static int $authenticatedUserId = 0;

    public static function authenticatedUserId(): int
    {
        return self::$authenticatedUserId;
    }

    public static function register(): void
    {
        add_filter('determine_current_user', [self::class, 'authenticateBearer'], 30);
        add_filter('rest_authentication_errors', [self::class, 'refuseUnknownTokenState'], self::AUTH_ERRORS_PRIORITY);
        add_filter('rest_send_nocache_headers', [self::class, 'noCacheWhenTokenStateUnknown']);
    }

    /**
     * REST permission_callback for admin-only routes.
     *
     * Safe to use as a permission_callback because authenticateBearer()
     * (priority 30 on determine_current_user) has already resolved the
     * bearer user by the time the REST server dispatches permission
     * checks — so current_user_can() sees the correct user. This is the
     * single shared helper for admin gating; routes keep their in-handler
     * adminGate() as defense-in-depth (it also emits the canonical
     * envelope that a bare permission_callback rejection does not).
     */
    public static function requireAdmin(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @param  int|false|\WP_Error $userId existing-user-id (false = unauthenticated, int = already authed)
     * @return int|false
     */
    public static function authenticateBearer($userId)
    {
        // Each decision stands alone: only a token verified below marks the
        // request as bearer-authenticated.
        self::$authenticatedUserId = 0;

        // If a previous filter (cookie auth, application password, etc.)
        // already authenticated the request, leave it alone — Bearer is
        // a secondary path, not an override. (On a bcc route that is an
        // admin only: WordPressSignInGate refuses a member signed in this way.)
        if (is_int($userId) && $userId > 0) {
            return $userId;
        }

        // Not a REST request: the bearer does not count here, whatever it
        // carries. (See the class note.)
        if (!self::isRestRequest()) {
            return is_int($userId) ? $userId : false;
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
            // F-288: only a plain expiry is refreshable; anything else
            // (malformed, bad signature, revoked, signed out, superseded by
            // a refresh) is a dead token.
            self::$presentedTokenFailure = match ($result['error']) {
                JwtToken::ERR_EXPIRED           => self::TOKEN_EXPIRED,
                JwtToken::ERR_STATE_UNAVAILABLE => self::TOKEN_UNAVAILABLE,
                default                         => self::TOKEN_INVALID,
            };
            return false;
        }

        $payload     = $result['payload'];
        $tokenUserId = (int) ($payload['user_id'] ?? 0);
        if ($tokenUserId <= 0) {
            self::$presentedTokenFailure = self::TOKEN_INVALID;
            return false;
        }

        // Refuse a JWT for a user that no longer exists. The token
        // could be from before the user was deleted; the cryptographic
        // signature is still valid, but the principal isn't.
        if (get_userdata($tokenUserId) === false) {
            Logger::audit('jwt_user_missing', ['user_id' => $tokenUserId]);
            self::$presentedTokenFailure = self::TOKEN_INVALID;
            return false;
        }

        self::$presentedTokenFailure = null;
        self::$authenticatedUserId   = $tokenUserId;
        return $tokenUserId;
    }

    /**
     * `rest_authentication_errors`, first (F-462): a request whose bearer
     * token could not be checked, because its revocation state could not be
     * read, gets a 503 `bcc_unavailable` (try again). It is never served as
     * signed out, and never told its token is invalid. An earlier filter's
     * error stands; an earlier login did not involve the bearer.
     *
     * @param mixed $result
     * @return mixed
     */
    public static function refuseUnknownTokenState($result)
    {
        if (self::$presentedTokenFailure !== self::TOKEN_UNAVAILABLE || is_wp_error($result)) {
            return $result;
        }

        \BCC\Trust\Infrastructure\EdgeCache::noCache();

        return new \WP_Error(
            'bcc_unavailable',
            'Your session could not be checked just now. Please try again.',
            ['status' => 503]
        );
    }

    /**
     * `rest_send_nocache_headers` (F-462): that 503 is private and never
     * stored. Core sends no-cache headers only for a signed-in user, and
     * this request is not one.
     *
     * @param mixed $send
     * @return mixed
     */
    public static function noCacheWhenTokenStateUnknown($send)
    {
        return self::$presentedTokenFailure === self::TOKEN_UNAVAILABLE ? true : $send;
    }

    /**
     * Is THIS request a REST request? Read from the request and WordPress's
     * configuration; see {@see isRestRequestFor()}.
     */
    public static function isRestRequest(): bool
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $homePath   = wp_parse_url((string) home_url(), PHP_URL_PATH);

        return self::isRestRequestFor(
            is_string($scriptName) ? $scriptName : '',
            is_string($requestUri) ? $requestUri : '',
            is_string($homePath) ? $homePath : '',
            (string) rest_get_url_prefix(),
            is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
        );
    }

    /**
     * The REST-request rule, a pure function of its inputs. True only when
     * ALL of these hold:
     *
     *   - the request ran the FRONT CONTROLLER: the script is `…/index.php`
     *     and it is not an admin, ajax, cron or XML-RPC request. Only
     *     index.php runs parse_request, so only there does a path become a
     *     REST dispatch. admin-ajax.php, admin-post.php, wp-login.php,
     *     wp-cron.php and xmlrpc.php never qualify, whatever they carry;
     *   - the request path, as WP::parse_request reads it (before `?`,
     *     slashes trimmed, the home path taken off the front when it is
     *     there, as core does), is the REST
     *     prefix itself or starts with the prefix and a `/`. Those are
     *     core's two REST rewrite rules (`^{prefix}/?$`, `^{prefix}/(.*)?`).
     *
     * Everything else is false, and that is the safe direction, because
     * false only means the bearer is ignored:
     *
     *   - `?rest_route=` is not honoured (nothing BCC sends uses it);
     *   - nor is `/index.php/{prefix}/…` (PATH_INFO);
     *   - nor is a percent-encoded or differently-cased prefix.
     *
     * The app and its NextAuth server call `{BCC_API_URL}/wp-json/…` only.
     *
     * @param string $scriptName  SCRIPT_NAME (else PHP_SELF)
     * @param string $requestUri  REQUEST_URI, raw
     * @param string $homePath    home_url()'s path, '' at the root
     * @param string $restPrefix  rest_get_url_prefix()
     * @param bool   $otherEntry  an admin, ajax, cron or XML-RPC request
     */
    public static function isRestRequestFor(string $scriptName, string $requestUri, string $homePath, string $restPrefix, bool $otherEntry): bool
    {
        if ($otherEntry || !str_ends_with($scriptName, '/index.php')) {
            return false;
        }

        $prefix = trim($restPrefix, '/');
        if ($prefix === '' || $requestUri === '') {
            return false;
        }

        $path = trim(explode('?', $requestUri, 2)[0], '/');

        $home = trim($homePath, '/');
        if ($home !== '') {
            if (strcasecmp($path, $home) === 0) {
                $path = '';
            } elseif (stripos($path, $home . '/') === 0) {
                $path = trim(substr($path, strlen($home) + 1), '/');
            }
        }

        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    /**
     * Extract the JWT from the Authorization header, or null when
     * absent / malformed. Case-insensitive on the "Bearer " prefix.
     *
     * Public so an endpoint that must validate the PRESENTED token itself
     * (GET /auth/session) reads it through the same LiteSpeed/HTTP-2-safe
     * header path as authentication does — never a second reader that
     * could disagree with this one.
     */
    public static function readBearerToken(): ?string
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
     * Does this request carry an authentication credential at all?
     *
     * Exposed for the edge-cache exclusion
     * ({@see \BCC\Trust\Infrastructure\EdgeCache::isCredentialed}), which
     * must never let a credentialed response be stored by a cache that
     * does not key on the credential.
     *
     * Deliberately answered by the SAME header reader that decides
     * authentication rather than by a second, independent check. If the
     * cache's notion of "credentialed" could disagree with the auth
     * layer's, the gap between them is precisely a personalized response
     * cached as anonymous. Tying both to one reader makes that class of
     * drift impossible — if this returns false, BearerAuth cannot have
     * authenticated anyone either.
     *
     * Intentionally does NOT validate the token: a rejected or expired
     * credential still produces a user-specific response (a 401 is not a
     * cacheable public answer), and validating here would duplicate
     * decode work on every request.
     */
    public static function hasCredential(): bool
    {
        return self::readAuthorizationHeader() !== '';
    }

    /**
     * Read the Authorization header from whichever source the current
     * server config populates. See the file-level docblock for the
     * forwarding requirement.
     */
    private static function readAuthorizationHeader(): string
    {
        // LiteSpeed/HTTP-2 quirk: $_SERVER['HTTP_AUTHORIZATION'] can be
        // *set but empty* when the upstream rewrite captures an empty
        // header on HTTP/2 even though the actual request DID carry
        // Authorization. Using !empty() here forces fall-through to
        // REDIRECT_HTTP_AUTHORIZATION / apache_request_headers() which
        // do carry the real value. Plain isset() short-circuits at the
        // empty string and BearerAuth ends up rejecting valid tokens.
        $httpAuth     = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        $redirectAuth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        $apacheAuth   = null;
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $key => $value) {
                if (strcasecmp((string) $key, 'Authorization') === 0) {
                    $apacheAuth = (string) $value;
                    break;
                }
            }
        }

        // Try each source in order; pick the first that looks like a
        // real Bearer token. This is more defensive than the previous
        // "first set" logic because some PHP/HTTP-server combos populate
        // HTTP_AUTHORIZATION with a non-Bearer value while the real
        // token only lives in REDIRECT_HTTP_AUTHORIZATION.
        foreach ([$httpAuth, $redirectAuth, $apacheAuth] as $candidate) {
            if (is_string($candidate) && stripos($candidate, 'Bearer ') === 0) {
                return $candidate;
            }
        }

        // Fall back to first non-empty even if it doesn't look like Bearer
        // (so any malformed input still reaches readBearerToken's parser
        // for consistent error handling).
        foreach ([$httpAuth, $redirectAuth, $apacheAuth] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
