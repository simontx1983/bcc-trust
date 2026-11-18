<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Core\Support\FrontendRedirect;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Members sign in at the app, never through WordPress's own doors.
 *
 * ⚠⚠ WHY (security review 2026-11-18, H2): wp-login.php signs any member in
 * with the password alone. BCC's email-code 2FA, its sign-in throttles and
 * the captcha step-up all live in the app's /auth routes; BCC hooks
 * `wp_login` only to record rank and IP. The cookie wp-login.php sets, plus
 * a `wp_rest` nonce (admin-ajax's `rest-nonce` action hands one out), then
 * authenticated every bcc REST route, because BearerAuth defers to an
 * earlier login. So a stuffed password skipped every sign-in defence BCC
 * has.
 *
 * Three doors close here for anyone without `manage_options`:
 *
 * 1. `authenticate`, as the LAST filter: wp-login.php, XML-RPC and any other
 *    wp_signon() caller (PeepSo's own login form included) refuse a member
 *    account with "sign in at the app". The answer is the same whether or
 *    not the password was right, so this door cannot be used to test
 *    passwords either. It also refuses the cookie re-login
 *    (`wp_authenticate_cookie`, priority 30). BCC's own sign-ins never call
 *    wp_authenticate(): they check the password themselves.
 * 2. `admin_init`: a signed-in member is sent from wp-admin and
 *    admin-post.php to the app, and refused at admin-ajax.php (403). No
 *    admin-ajax action is needed by a member. The app talks to BCC's REST
 *    routes only, and the logged-in actions registered on this site are
 *    wp-admin tooling, PeepSo's own UI (whose pages send members to the
 *    app) and core's `rest-nonce`, the nonce this attack needed.
 * 3. `rest_pre_dispatch` on every bcc namespace: a member must be
 *    authenticated by a BCC bearer token. A request whose user came from
 *    anything else (a cookie plus nonce, an application password) is 401
 *    `bcc_unauthorized`. The frontend never sends cookies
 *    (`credentials: "omit"`), so no client loses anything. Batch
 *    sub-requests and internal dispatches pass through this filter too.
 *
 * Admins keep all three: wp-login.php, wp-admin, and cookie-plus-nonce calls
 * to bcc routes from wp-admin tooling.
 */
final class WordPressSignInGate
{
    public const CAPABILITY = 'manage_options';

    /**
     * The last word: after core's password checks (20), its cookie re-login
     * (30) and every plugin's own filter, so nothing later turns a refused
     * member back into a signed-in one.
     */
    public const AUTHENTICATE_PRIORITY = PHP_INT_MAX;

    /** Early, beside CoreUsersRouteGuard: a refused request reaches no handler. */
    public const REST_PRIORITY = 1;

    public const ERROR_CODE = 'bcc_sign_in_at_app';

    public const REST_MESSAGE = 'Sign in at the app.';

    public static function register(): void
    {
        add_filter('authenticate', [self::class, 'refuseMemberSignIn'], self::AUTHENTICATE_PRIORITY, 3);
        add_action('admin_init', [self::class, 'keepMembersOutOfAdmin'], 1);
        add_filter('rest_pre_dispatch', [self::class, 'filterRest'], self::REST_PRIORITY, 3);
    }

    /**
     * `authenticate` filter: a member account never signs in here.
     *
     * The account is the one an earlier filter signed in or, when the
     * sign-in failed, the one the username (or email) names. Either way a
     * member gets the same refusal, so a wrong password and a right one look
     * alike. An admin, an unknown name and an empty form pass through
     * untouched.
     *
     * @param mixed $user     WP_User, WP_Error or null from earlier filters
     * @param mixed $username already sanitize_user()'d by wp_authenticate()
     * @param mixed $password
     * @return mixed
     */
    public static function refuseMemberSignIn($user, $username = '', $password = '')
    {
        unset($password);
        $account = $user instanceof \WP_User
            ? $user
            : self::accountNamed(is_string($username) ? $username : '');
        if ($account === null || user_can($account, self::CAPABILITY)) {
            return $user;
        }

        return new \WP_Error(self::ERROR_CODE, self::signInMessage());
    }

    /**
     * `admin_init`: a signed-in member does not use wp-admin. admin-ajax.php
     * answers 403; wp-admin pages and admin-post.php send them to the app.
     */
    public static function keepMembersOutOfAdmin(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0 || user_can($userId, self::CAPABILITY)) {
            return;
        }

        if (wp_doing_ajax()) {
            wp_die('-1', 403);
        }

        wp_safe_redirect(FrontendRedirect::defaultReturn('/'));
        exit;
    }

    /**
     * `rest_pre_dispatch`. A refusal wins over whatever an earlier filter
     * answered: no member request authenticated this way may reach a
     * handler.
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function filterRest($result, $server, $request)
    {
        unset($server);
        if (!$request instanceof \WP_REST_Request) {
            return $result;
        }

        return self::decideRest(
            (string) $request->get_route(),
            get_current_user_id(),
            BearerAuth::authenticatedUserId()
        ) ?? $result;
    }

    /**
     * A 401 for a member on a bcc route who was not authenticated by the
     * bearer token, or null to let the request through.
     */
    public static function decideRest(string $route, int $userId, int $bearerUserId): ?\WP_REST_Response
    {
        if ($userId <= 0 || $userId === $bearerUserId || !self::isBccRoute($route)) {
            return null;
        }
        if (user_can($userId, self::CAPABILITY)) {
            return null;
        }

        return ApiResponse::error('bcc_unauthorized', self::REST_MESSAGE, 401);
    }

    /**
     * Any bcc namespace (`bcc/v1`, `bcc-trust/v1`, and any later `bcc-*`),
     * however the route is spelled: lowercased (core matches routes
     * case-insensitively) and with its leading slashes, none or several,
     * made exactly one (`?rest_route=bcc/v1/…`, `//bcc/v1/…`). Only the
     * first segment decides, so slashes further in do not matter.
     */
    public static function isBccRoute(string $route): bool
    {
        $normalised = '/' . ltrim(strtolower($route), '/');

        return preg_match('#^/bcc(?:-[a-z0-9-]+)?/#', $normalised) === 1;
    }

    /** The member a username or email names, or null. */
    private static function accountNamed(string $username): ?\WP_User
    {
        $name = trim($username);
        if ($name === '') {
            return null;
        }

        $user = get_user_by('login', $name);
        if (!$user instanceof \WP_User && is_email($name) !== false) {
            $user = get_user_by('email', $name);
        }

        return $user instanceof \WP_User ? $user : null;
    }

    private static function signInMessage(): string
    {
        return sprintf(
            'Members sign in at <a href="%s">the app</a>.',
            esc_url(FrontendRedirect::defaultReturn('/login'))
        );
    }
}
