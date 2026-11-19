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
 * Four doors close here. The first three are for anyone without
 * `manage_options`; the fourth is for every role:
 *
 * 1. `authenticate`, as the LAST filter: wp-login.php, XML-RPC and any other
 *    wp_signon() caller (PeepSo's own login form included) refuse a member
 *    account, including the cookie re-login (`wp_authenticate_cookie`,
 *    priority 30). BCC's own sign-ins never call wp_authenticate(): they
 *    check the password themselves. The answer gives nothing away: a
 *    member's sign-in and every failed one (a wrong password, an unknown
 *    name or email, admin or member alike) all get the same "that sign-in
 *    didn't work" answer, so the form tests neither passwords nor which
 *    logins are admins. Every other answer (an empty field, Wordfence's
 *    "enter your 2FA code" for an admin) passes through untouched.
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
 * 4. `allow_password_reset`: only the app's own forgot-password flow
 *    (POST /auth/forgot-password, with its throttles) mints a password
 *    reset key, for every role. wp-login.php's lost-password form, PeepSo's
 *    recover-password page and anything else that asks for a key get
 *    "reset your password at the app" instead. For every role, because a
 *    form that emails admins and turns members away would tell anyone
 *    which logins are admins. Admins reset at the app too; ops' wp-cli is
 *    the fallback. wp-login.php's form gives that same answer for an
 *    account that does not exist (`lostpassword_errors`), so it does not
 *    tell which accounts exist either.
 *
 * Admins keep the first three: wp-login.php, wp-admin, and cookie-plus-nonce
 * calls to bcc routes from wp-admin tooling.
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

    /** Early, beside RestRouteAllowlist: a refused request reaches no handler. */
    public const REST_PRIORITY = 1;

    /** The last word on whether a reset key may be minted. */
    public const RESET_PRIORITY = PHP_INT_MAX;

    public const ERROR_CODE = 'bcc_sign_in_at_app';

    public const RESET_ERROR_CODE = 'bcc_reset_at_app';

    public const REST_MESSAGE = 'Sign in at the app.';

    /**
     * Core's answers to a failed sign-in, each naming its own cause. Next
     * to the refusal a member gets, they would tell an admin login from a
     * member one, so they get the refusal's wording instead.
     */
    public const FAILED_SIGN_IN_CODES = ['incorrect_password', 'invalid_username', 'invalid_email'];

    /** True only while the app's forgot-password flow mints its key. */
    private static bool $mintingAppResetKey = false;

    public static function register(): void
    {
        add_filter('authenticate', [self::class, 'refuseMemberSignIn'], self::AUTHENTICATE_PRIORITY, 3);
        add_action('admin_init', [self::class, 'keepMembersOutOfAdmin'], 1);
        add_filter('rest_pre_dispatch', [self::class, 'filterRest'], self::REST_PRIORITY, 3);
        add_filter('allow_password_reset', [self::class, 'allowPasswordReset'], self::RESET_PRIORITY, 2);
        add_filter('lostpassword_errors', [self::class, 'sameLostPasswordAnswer'], self::RESET_PRIORITY, 2);
    }

    /**
     * `authenticate` filter: a member account never signs in here, and no
     * failed sign-in says why it failed.
     *
     *   - signed in so far (a WP_User): an admin passes; a member gets the
     *     refusal;
     *   - failed with one of FAILED_SIGN_IN_CODES: the refusal, whatever
     *     the role, so a member's right password, a member's wrong one and
     *     an admin's wrong one all read the same;
     *   - anything else (nothing yet, an empty field, another plugin's
     *     answer such as Wordfence's 2FA prompt): untouched.
     *
     * @param mixed $user     WP_User, WP_Error or null from earlier filters
     * @param mixed $username
     * @param mixed $password
     * @return mixed
     */
    public static function refuseMemberSignIn($user, $username = '', $password = '')
    {
        unset($username, $password);
        if ($user instanceof \WP_User) {
            return user_can($user, self::CAPABILITY) ? $user : self::signInRefusal();
        }
        if ($user instanceof \WP_Error && in_array($user->get_error_code(), self::FAILED_SIGN_IN_CODES, true)) {
            return self::signInRefusal();
        }

        return $user;
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

        // ⚠ wp_redirect, NOT wp_safe_redirect. The target is built here from
        // BCC_FRONTEND_ORIGIN, never from the request. wp_safe_redirect only
        // trusts the frontend's host once FrontendRedirect's
        // `allowed_redirect_hosts` filter is in place, which happens on
        // rest_api_init, never on an admin request. Until then it swaps
        // the target for admin_url(), which lands back here: an endless
        // redirect wherever the frontend's host is not WordPress's home
        // (staging, local).
        wp_redirect(FrontendRedirect::defaultReturn('/'));
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

    /**
     * The reset key for the app's forgot-password flow: the only caller
     * allowPasswordReset() lets through.
     *
     * @return string|\WP_Error what get_password_reset_key() returns
     */
    public static function appResetKey(\WP_User $user)
    {
        self::$mintingAppResetKey = true;
        try {
            return get_password_reset_key($user);
        } finally {
            self::$mintingAppResetKey = false;
        }
    }

    /**
     * `allow_password_reset`: no reset key except the app's (see
     * appResetKey()), for every role. Inside the app's flow, whatever the
     * earlier filters decided (PeepSo's own checks) stands.
     *
     * @param mixed $allow
     * @param mixed $userId
     * @return mixed
     */
    public static function allowPasswordReset($allow, $userId = 0)
    {
        unset($userId);
        if (self::$mintingAppResetKey) {
            return $allow;
        }

        return self::resetRefusal();
    }

    /**
     * `lostpassword_errors`, as the last filter: wp-login.php's
     * lost-password form gives every named account the same answer.
     *
     * Core answers "There is no account with that username or email
     * address" for an unknown account: `invalid_email` before this filter,
     * `invalidcombo` just after it. A known account gets the reset refusal
     * from allowPasswordReset(). Side by side, those told which accounts
     * exist. So the unknown-email error is dropped, and a request left with
     * no other error gets the reset refusal, account or not (a known
     * account would get it a step later anyway). Errors that read the same
     * for every account (an empty field, another plugin's captcha) stand.
     *
     * @param mixed $errors
     * @param mixed $userData
     * @return mixed
     */
    public static function sameLostPasswordAnswer($errors, $userData = null)
    {
        unset($userData);
        if (!$errors instanceof \WP_Error) {
            return $errors;
        }

        $errors->remove('invalid_email');
        if ($errors->has_errors()) {
            return $errors;
        }

        return self::resetRefusal();
    }

    private static function resetRefusal(): \WP_Error
    {
        return new \WP_Error(self::RESET_ERROR_CODE, sprintf(
            'Reset your password at <a href="%s">the app</a>.',
            esc_url(FrontendRedirect::defaultReturn('/forgot-password'))
        ));
    }

    private static function signInRefusal(): \WP_Error
    {
        return new \WP_Error(self::ERROR_CODE, sprintf(
            'That sign-in didn\'t work. Members sign in at <a href="%s">the app</a>.',
            esc_url(FrontendRedirect::defaultReturn('/login'))
        ));
    }
}
