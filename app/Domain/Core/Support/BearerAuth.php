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

    /** The message of every F-462 503 `bcc_unavailable`. */
    public const UNAVAILABLE_MESSAGE = 'Your session could not be checked just now. Please try again.';

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
        add_action('parse_request', [self::class, 'signOutBearerOutsideRest'], self::OUTSIDE_REST_PRIORITY);
    }

    /**
     * After core's `rest_api_loaded` (parse_request, 10), which serves a REST
     * request and exits. Anything that reaches 11 is not REST.
     */
    public const OUTSIDE_REST_PRIORITY = 11;

    /** Percent-decoding rounds a request path may need before it is stable. */
    private const MAX_DECODE_ROUNDS = 4;

    /**
     * `parse_request`, after core's REST dispatch: a backstop. A user the
     * bearer signed in is still here only if this is NOT a REST request, so
     * they are signed out for the rest of it. isRestRequestFor() is the
     * primary guard; this runs later (after `init` and `wp_loaded`), and
     * catches any request it let through that core did not route to REST.
     *
     * @param mixed $wp
     */
    public static function signOutBearerOutsideRest($wp = null): void
    {
        unset($wp);
        if (self::$authenticatedUserId <= 0 || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        Logger::warning('[bcc-trust] bearer sign-in dropped: not a REST request');
        self::$authenticatedUserId = 0;
        wp_set_current_user(0);
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
        return current_user_can(self::ADMIN_CAPABILITY);
    }

    /**
     * The capability the admin routes require. One constant, so the gate
     * (requireAdmin) and the display flag (userIsAdmin → the auth responses'
     * `can_moderate`) cannot drift apart.
     */
    public const ADMIN_CAPABILITY = 'manage_options';

    /**
     * requireAdmin()'s check, for a given user rather than the current one:
     * the auth responses compute `can_moderate` with it, for the user they
     * are about. DISPLAY ONLY there; the admin routes still enforce with
     * requireAdmin() (and their in-handler adminGate()).
     */
    public static function userIsAdmin(int $userId): bool
    {
        return $userId > 0 && user_can($userId, self::ADMIN_CAPABILITY);
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

        return new \WP_Error('bcc_unavailable', self::UNAVAILABLE_MESSAGE, ['status' => 503]);
    }

    /**
     * The same 503 as a handler's response (F-462), for a route that finds
     * the token state unknown after the door, or could not record a
     * revocation: private, never stored, and varying by token.
     */
    public static function unavailableResponse(string $message = self::UNAVAILABLE_MESSAGE): \WP_REST_Response
    {
        $response = ApiResponse::error('bcc_unavailable', $message, 503);
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'Authorization');

        return $response;
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
        $pathInfo   = $_SERVER['PATH_INFO'] ?? '';
        if (!is_string($pathInfo) || $pathInfo === '') {
            $pathInfo = $_SERVER['ORIG_PATH_INFO'] ?? '';
        }
        $homePath   = wp_parse_url((string) home_url(), PHP_URL_PATH);

        return self::isRestRequestFor(
            is_string($scriptName) ? $scriptName : '',
            is_string($requestUri) ? $requestUri : '',
            is_string($homePath) ? $homePath : '',
            (string) rest_get_url_prefix(),
            is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST),
            is_string($pathInfo) ? $pathInfo : 'set'
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
     *   - there is no PATH_INFO (or ORIG_PATH_INFO);
     *   - the request path is CANONICAL (canonicalPath()): no `.` or `..`
     *     segment, no empty segment, no backslash, no control character, no
     *     malformed escape, and no `/` that only appears once decoded, in the
     *     raw path or after any of up to MAX_DECODE_ROUNDS percent-decodings.
     *     Percent-encoded text otherwise stays allowed: some card slugs are
     *     stored percent-encoded;
     *   - the path, as WP::parse_request reads it (before `?`, slashes
     *     trimmed, the home path taken off the front when it is there, as
     *     core does), is the REST prefix itself or starts with the prefix and
     *     a `/`, both raw and fully decoded. Those are core's two REST rewrite
     *     rules (`^{prefix}/?$`, `^{prefix}/(.*)?`).
     *
     * Everything else is false, and that is the safe direction, because
     * false only means the bearer is ignored:
     *
     *   - `?rest_route=` is not honoured (nothing BCC sends uses it);
     *   - nor is `/index.php/{prefix}/…`, or any request with PATH_INFO;
     *   - nor is a percent-encoded or differently-cased prefix.
     *
     * A literal `%` in a path (sent as `%25`) is never kept as itself: the
     * next decoding round reads it as an escape. Followed by two hex digits
     * it decodes on (`%2541` becomes `A`). Otherwise (`100%25-off`, or a
     * trailing `%25`) it is a malformed escape, and the bearer is ignored.
     *
     * The app and its NextAuth server call `{BCC_API_URL}/wp-json/…` only.
     *
     * @param string $scriptName  SCRIPT_NAME (else PHP_SELF)
     * @param string $requestUri  REQUEST_URI, raw
     * @param string $homePath    home_url()'s path, '' at the root
     * @param string $restPrefix  rest_get_url_prefix()
     * @param bool   $otherEntry  an admin, ajax, cron or XML-RPC request
     * @param string $pathInfo    PATH_INFO (else ORIG_PATH_INFO), '' when unset
     */
    public static function isRestRequestFor(string $scriptName, string $requestUri, string $homePath, string $restPrefix, bool $otherEntry, string $pathInfo): bool
    {
        if ($otherEntry || $pathInfo !== '' || !str_ends_with($scriptName, '/index.php')) {
            return false;
        }

        $prefix = trim($restPrefix, '/');
        if ($prefix === '' || $requestUri === '') {
            return false;
        }

        $rawPath = explode('?', $requestUri, 2)[0];
        $decoded = self::canonicalPath($rawPath);
        if ($decoded === null) {
            return false;
        }

        return self::startsWithRestPrefix($rawPath, $homePath, $prefix)
            && self::startsWithRestPrefix($decoded, $homePath, $prefix);
    }

    /**
     * The path fully percent-decoded, or null when it is not canonical: the
     * raw path and every decoding round must be clean (isCleanPath()), a
     * decoding may not add a `/`, and the path must stop changing within
     * MAX_DECODE_ROUNDS decodings.
     */
    private static function canonicalPath(string $rawPath): ?string
    {
        $current = $rawPath;
        for ($round = 0; ; $round++) {
            if (!self::isCleanPath($current)) {
                return null;
            }
            $next = rawurldecode($current);
            if ($next === $current) {
                return $current;
            }
            if ($round >= self::MAX_DECODE_ROUNDS) {
                return null; // still changing after MAX_DECODE_ROUNDS decodings
            }
            if (substr_count($next, '/') !== substr_count($current, '/')) {
                return null;
            }
            $current = $next;
        }
    }

    /**
     * No control character, no backslash, no malformed escape, and no `.`,
     * `..` or empty segment (outer slashes aside, as core trims them).
     */
    private static function isCleanPath(string $path): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }
        if (str_contains($path, '\\')) {
            return false;
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1) {
            return false;
        }

        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return true;
        }
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Is $path (before `?`) the REST prefix, or under it, once the home path
     * is taken off the front, as WP::parse_request reads it?
     */
    private static function startsWithRestPrefix(string $path, string $homePath, string $prefix): bool
    {
        $path = trim($path, '/');

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
