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
 * The app's /auth routes carry BCC's sign-in protections (the email-code
 * second factor, the throttles, the captcha step-up). WordPress's own
 * sign-in, ajax and admin doors have none of them, so non-admins are kept
 * off those doors entirely. Rationale: security reviews 2026-11-18/19
 * (H2, PR B, F-496; private tracker).
 *
 * Four doors close here. The first three are for anyone without
 * `manage_options`; the fourth is for every role:
 *
 * 1. `authenticate`, as the LAST filter: wp-login.php, XML-RPC and any other
 *    wp_signon() caller (PeepSo's own login form included) refuse a member
 *    account, including the cookie re-login (`wp_authenticate_cookie`,
 *    priority 30). BCC's own sign-ins never call wp_authenticate(): they
 *    check the password themselves. Every failed sign-in, admin or member
 *    alike, gets the same answer as a refused one. Every other answer (an
 *    empty field, Wordfence's "enter your 2FA code" for an admin) passes
 *    through untouched.
 * 2. `init` (first) and `admin_init`: WordPress's and PeepSo's own ajax
 *    doors, plus wp-admin.
 *    - admin-ajax.php: a signed-in non-admin gets a bare 403 unless the
 *      action is in MEMBER_AJAX_ACTIONS (empty: the app needs none). A
 *      signed-out caller gets the same for a denied action
 *      (deniesSignedOutAjax()): PeepSo's, and a few others' that no
 *      signed-out caller needs. Every other signed-out action stays open:
 *      admin sign-in (2FA, passkeys), loopbacks and schedulers run there,
 *      and a miss would lock admins out.
 *    - PeepSo's ajax router: every non-admin, signed out included, gets a
 *      bare 403. It is matched the way PeepSo itself routes (see
 *      isPeepSoAjax()); that matcher must stay a superset of PeepSo's.
 *    - wp-admin and admin-post.php (`admin_init`): a signed-in member is
 *      sent to the app.
 *    This runs at `init`, before PeepSo's router (`wp_loaded`, -1). A
 *    refusal is bare, never a rendered page. Nothing BCC or the app uses
 *    goes through these doors.
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
 *    "reset your password at the app" instead, with the same answer for an
 *    account that does not exist (`lostpassword_errors`). Admins reset at
 *    the app too; ops' wp-cli is the fallback.
 *
 * Also: RESERVED_PATH_WORDS can't become a group's slug (`wp_unique_post_slug`)
 * and HandleService reserves them as handles, so no app URL that names a
 * member or a group is caught by the router match in door 2.
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

    /** First on `init`, before PeepSo's /peepsoajax/ router (`wp_loaded`, -1). */
    public const AJAX_PRIORITY = PHP_INT_MIN;

    /**
     * The admin-ajax actions a signed-in member may still call. Empty on
     * purpose: the app needs none. An entry needs a reason and a test of
     * its own.
     *
     * @var list<string>
     */
    public const MEMBER_AJAX_ACTIONS = [];

    /**
     * Signed-out admin-ajax actions starting with this are refused (F-496):
     * PeepSo names its actions so, and the app needs none of them. Matched
     * case-sensitively, as WordPress matches hook names.
     */
    public const SIGNED_OUT_DENIED_PREFIX = 'peepso';

    /**
     * Other signed-out admin-ajax actions refused by exact name (F-496): bulk
     * write and migration actions, anonymous image processing, and a local
     * debug plugin's logger. A DENY-list on purpose: an allowlist would break
     * sign-in and background work whenever a plugin update adds an action.
     * An entry needs a reason and a test of its own.
     *
     * @var list<string>
     */
    public const SIGNED_OUT_DENIED_ACTIONS = [
        'wp_convert_aioseo_blocks',
        'wp_convert_yoast_blocks',
        'wp_bulk_edit_seo_meta',
        'wp_bulk_image_alt',
        'rank_math_overlay_thumb',
        'log_js_errors',
    ];

    /**
     * Path words no member handle or group slug may take (F-496). Door 2
     * refuses any request with a `peepsoajax` path segment, so an app URL
     * naming a member or group with it would be refused too.
     *
     * @var list<string>
     */
    public const RESERVED_PATH_WORDS = ['peepsoajax', 'peepso'];

    /** PeepSo's group post type (halls are groups too). */
    private const GROUP_POST_TYPE = 'peepso-group';

    /** True only while the app's forgot-password flow mints its key. */
    private static bool $mintingAppResetKey = false;

    public static function register(): void
    {
        add_filter('authenticate', [self::class, 'refuseMemberSignIn'], self::AUTHENTICATE_PRIORITY, 3);
        add_action('init', [self::class, 'refuseWordPressAjax'], self::AJAX_PRIORITY);
        add_action('admin_init', [self::class, 'keepMembersOutOfAdmin'], 1);
        add_filter('rest_pre_dispatch', [self::class, 'filterRest'], self::REST_PRIORITY, 3);
        add_filter('allow_password_reset', [self::class, 'allowPasswordReset'], self::RESET_PRIORITY, 2);
        add_filter('lostpassword_errors', [self::class, 'sameLostPasswordAnswer'], self::RESET_PRIORITY, 2);
        add_filter('wp_unique_post_slug', [self::class, 'keepPathWordsOffGroups'], 10, 6);
    }

    /**
     * `wp_unique_post_slug`: a group (or hall) never gets one of
     * RESERVED_PATH_WORDS as its slug. It gets the `-group` form instead,
     * itself made unique the usual way. Every other post type is untouched.
     *
     * @param mixed $slug
     * @param mixed $postId
     * @param mixed $postStatus
     * @param mixed $postType
     * @param mixed $postParent
     * @param mixed $originalSlug
     * @return mixed
     */
    public static function keepPathWordsOffGroups($slug, $postId = 0, $postStatus = '', $postType = '', $postParent = 0, $originalSlug = '')
    {
        unset($originalSlug);
        if ($postType !== self::GROUP_POST_TYPE
            || !is_string($slug)
            || !in_array(strtolower($slug), self::RESERVED_PATH_WORDS, true)
        ) {
            return $slug;
        }

        return wp_unique_post_slug(
            strtolower($slug) . '-group',
            (int) $postId,
            is_string($postStatus) ? $postStatus : '',
            self::GROUP_POST_TYPE,
            (int) $postParent
        );
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
        if ($userId <= 0) {
            // Signed out: refuseWordPressAjax() decided admin-ajax at `init`;
            // asked again here in case that hook was taken away. Signed-out
            // wp-admin visits are WordPress's own business (its login page).
            if (wp_doing_ajax()) {
                self::refuseSignedOutAjaxIfDenied();
            }
            return;
        }
        if (user_can($userId, self::CAPABILITY)) {
            return;
        }

        // refuseWordPressAjax() has already decided this at `init`; asking
        // again here costs nothing and holds if that hook was taken away.
        if (wp_doing_ajax()) {
            self::refuseAjaxUnlessListed(self::MEMBER_AJAX_ACTIONS);
            return;
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
     * `init`, first: WordPress's and PeepSo's ajax doors (see the class
     * note, door 2). Admins are untouched.
     *   - PeepSo's router: refused to every non-admin, signed out included;
     *   - admin-ajax.php: refused to a signed-in non-admin unless the
     *     action is in MEMBER_AJAX_ACTIONS, and to a signed-out caller for a
     *     denied action (deniesSignedOutAjax(), F-496).
     */
    public static function refuseWordPressAjax(): void
    {
        $userId = get_current_user_id();
        if ($userId > 0 && user_can($userId, self::CAPABILITY)) {
            return;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (is_string($uri) && self::isPeepSoAjax($uri)) {
            self::refuseBare();
        }

        if (wp_doing_ajax()) {
            if ($userId > 0) {
                self::refuseAjaxUnlessListed(self::MEMBER_AJAX_ACTIONS);
            } else {
                self::refuseSignedOutAjaxIfDenied();
            }
        }
    }

    /**
     * Would PeepSo route this request to its ajax handler? Answered as a
     * SUPERSET of PeepSo::check_ajax_query() (peepso.php, 9.0.5.5), which
     * takes trim(parse_url(REQUEST_URI, PHP_URL_PATH), '/'), splits it on
     * '/', and routes when the second-to-last segment is 'peepsoajax'.
     * Here, case-blind:
     *   - the raw URI contains '/peepsoajax/' anywhere; or
     *   - any segment of the path, read the same way with PHP's own
     *     parse_url() (not wp_parse_url()), is 'peepsoajax'.
     * Prefixed and suffixed forms match, with or without a trailing slash
     * or a query. A false positive only refuses a non-admin a URL that
     * serves them nothing (RESERVED_PATH_WORDS keeps app URLs out of it).
     */
    public static function isPeepSoAjax(string $requestUri): bool
    {
        if (stripos($requestUri, '/peepsoajax/') !== false) {
            return true;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);
        foreach (explode('/', trim(is_string($path) ? $path : '', '/')) as $segment) {
            if (strcasecmp($segment, 'peepsoajax') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * admin-ajax.php for a non-admin: a bare 403, unless the action is in
     * $allowed.
     *
     * @param list<string> $allowed
     */
    private static function refuseAjaxUnlessListed(array $allowed): void
    {
        $action = $_REQUEST['action'] ?? null;
        if (is_string($action) && self::allowsMemberAjax($action, $allowed)) {
            return;
        }

        self::refuseBare();
    }

    /** admin-ajax.php signed out: a bare 403 for a denied action, else through. */
    private static function refuseSignedOutAjaxIfDenied(): void
    {
        $action = $_REQUEST['action'] ?? null;
        if (is_string($action) && self::deniesSignedOutAjax($action)) {
            self::refuseBare();
        }
    }

    /**
     * Is this admin-ajax action refused to a signed-out caller? It starts
     * with SIGNED_OUT_DENIED_PREFIX, or is exactly one of
     * SIGNED_OUT_DENIED_ACTIONS. Case-sensitive, as hook names are.
     */
    public static function deniesSignedOutAjax(string $action): bool
    {
        return str_starts_with($action, self::SIGNED_OUT_DENIED_PREFIX)
            || in_array($action, self::SIGNED_OUT_DENIED_ACTIONS, true);
    }

    /**
     * Is this admin-ajax action in the list? Only an exact entry: no prefix,
     * no case folding.
     *
     * @param list<string> $allowed MEMBER_AJAX_ACTIONS; a test passes its own
     */
    public static function allowsMemberAjax(string $action, array $allowed = self::MEMBER_AJAX_ACTIONS): bool
    {
        return in_array($action, $allowed, true);
    }

    /**
     * A bare 403 and nothing else: no page, no theme, so no nonce minted
     * for the caller, and no handler after it.
     */
    private static function refuseBare(): never
    {
        nocache_headers();
        status_header(403);
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
