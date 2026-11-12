<?php

namespace BCC\Trust\Core\Security;

use BCC\Trust\Core\Support\ApiResponse;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-305: ONE gate for every BCC write by a suspended member.
 *
 * User decision 2026-11-12: a suspended member stays signed in READ-ONLY.
 * Before, refresh refused them, so a suspended session simply ended; now it
 * continues, and every write route must refuse on its own. Many do
 * (Permissions::is_not_suspended at the handler), but several never checked
 * (cover upload, collection stances, connect flows, recovery email, …). So
 * instead of auditing every handler, this rest_pre_dispatch filter refuses
 * any non-GET request to the BCC namespaces from a KNOWN-suspended member,
 * except an explicit allow-list of things a suspended member must still be
 * able to do. Per-route checks stay as a second line.
 *
 * Allowed while suspended (classify() → ALLOW):
 *   - the /auth/ routes in AUTH_ALLOWED  sign in, sign up, refresh, session,
 *                                      log out, nonces, 2FA, password reset,
 *                                      email verification (an EXPLICIT list:
 *                                      an /auth/ route added later is gated
 *                                      until it is listed here)
 *   - POST /me/findings/:id/appeal     appeal the finding
 *   - /me/notification-prefs, /me/privacy
 *   - DELETE /me/push-subscriptions/:id   unsubscribe (subscribing is a write)
 *   - /me/blocks, /me/blocks/:id       block / unblock (safety)
 *   - /me/account/password             password change (user decision)
 *
 * Account identity (classify() → ACCOUNT): email change and its verify
 * step, the recovery email and its verify step, linking a wallet
 * (POST /auth/wallet-link adds a sign-in identity), and DELETE /me/account. Suspended → 403 account_suspended; UNKNOWN → 503
 * (house rule F-109: an identity write denies on unknown).
 *
 * Everything else (classify() → GATE): suspended → 403 account_suspended.
 * UNKNOWN (the suspension could not be read) falls through to the route's
 * own checks, so a DB blip never fails the whole write API.
 *
 * Anonymous requests pass (the route answers 401). GET/HEAD/OPTIONS pass.
 */
final class SuspendedWriteGate
{
    public const ALLOW   = 'allow';
    public const ACCOUNT = 'account';
    public const GATE    = 'gate';
    public const NONE    = 'none';

    /** Namespaces this gate covers (route prefixes, with the slash). */
    private const NAMESPACES = ['/bcc/v1/', '/bcc-trust/v1/'];

    public const MESSAGE = 'Your account is suspended.';

    /**
     * /auth/ routes a suspended member may still call: everything needed to
     * sign in (read-only), stay signed in, and sign out. NOT wallet-link,
     * which changes the account (ACCOUNT below).
     */
    private const AUTH_ALLOWED = [
        '/auth/login',
        '/auth/logout',
        '/auth/logout-everywhere',
        '/auth/refresh',
        '/auth/session',
        '/auth/nonce',
        '/auth/wallet-nonce',
        '/auth/wallet-login',
        '/auth/wallet-signup',
        '/auth/signup',
        '/auth/oauth',
        '/auth/oauth-complete',
        '/auth/2fa/verify',
        '/auth/2fa/resend',
        '/auth/forgot-password',
        '/auth/reset-password',
        '/auth/verify-email',
        '/auth/resend-verification',
    ];

    /** Account-identity writes: suspended → 403, unknown → 503 (F-109). */
    private const ACCOUNT_PATHS = [
        '/me/account/email',
        '/me/account/email/verify',
        '/me/account/recovery-email',
        '/me/account/recovery-email/verify',
        '/auth/wallet-link',
    ];

    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'filter'], 20, 3);
    }

    /**
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function filter($result, $server, $request)
    {
        if ($result !== null || !$request instanceof \WP_REST_Request) {
            return $result; // something earlier already answered
        }
        $verdict = self::decide(
            (string) $request->get_method(),
            (string) $request->get_route(),
            (int) get_current_user_id()
        );
        return $verdict ?? $result;
    }

    /**
     * The gate's answer for one request: a refusal, or null to let the
     * route run.
     */
    public static function decide(string $method, string $route, int $userId): ?\WP_REST_Response
    {
        if ($userId <= 0) {
            return null;
        }
        $class = self::classify($method, $route);
        if ($class === self::NONE || $class === self::ALLOW) {
            return null;
        }

        // Admin bypass OFF, as at every write gate (F-109).
        $state = \BCC\Core\Permissions\Permissions::suspensionState($userId, false);

        if ($state === true) {
            return ApiResponse::error('bcc_forbidden', self::MESSAGE, 403, ['reason_code' => 'account_suspended']);
        }
        if ($state === null && $class === self::ACCOUNT) {
            return ApiResponse::error(
                'bcc_unavailable',
                'We could not confirm your account status. Please try again in a moment.',
                503
            );
        }
        return null;
    }

    /**
     * Which rule applies to a request, from its method and route alone.
     *
     * @return self::ALLOW|self::ACCOUNT|self::GATE|self::NONE
     */
    public static function classify(string $method, string $route): string
    {
        $method = strtoupper($method);
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return self::NONE;
        }

        // WordPress matches routes case-INSENSITIVELY (WP_REST_Server::
        // match_request_to_handler, '@^…$@i'), so POST /BCC/v1/posts reaches
        // the bcc/v1 handler. Compare lowercased, or a capital letter skips
        // the gate. Batch sub-requests pass through rest_pre_dispatch too.
        $route = strtolower($route);

        $path = null;
        foreach (self::NAMESPACES as $ns) {
            if (str_starts_with($route, $ns)) {
                $path = '/' . substr($route, strlen($ns));
                break;
            }
        }
        if ($path === null) {
            return self::NONE; // not a BCC route
        }
        $path = rtrim($path, '/');

        if (in_array($path, self::AUTH_ALLOWED, true)) {
            return self::ALLOW;
        }
        if ($method === 'POST' && preg_match('#^/me/findings/\d+/appeal$#', $path) === 1) {
            return self::ALLOW;
        }
        if ($path === '/me/notification-prefs' || $path === '/me/privacy') {
            return self::ALLOW;
        }
        if ($method === 'DELETE' && preg_match('#^/me/push-subscriptions/\d+$#', $path) === 1) {
            return self::ALLOW;
        }
        if ($path === '/me/blocks' || preg_match('#^/me/blocks/\d+$#', $path) === 1) {
            return self::ALLOW;
        }
        if ($path === '/me/account/password') {
            return self::ALLOW;
        }

        if (in_array($path, self::ACCOUNT_PATHS, true)
            || ($method === 'DELETE' && $path === '/me/account')
        ) {
            return self::ACCOUNT;
        }

        return self::GATE;
    }
}
