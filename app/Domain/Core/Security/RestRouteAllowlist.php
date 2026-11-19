<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Only BCC's own REST namespaces are open to non-admins.
 *
 *   - Admins (`manage_options`): untouched, everywhere.
 *   - Signed-in non-admins (members): `/bcc/v1` and `/bcc-trust/v1` only.
 *     Every other route answers 403, any method: core's `/wp/v2/*`, the
 *     index, `/batch/v1`, other plugins' namespaces.
 *   - Signed-out callers: BCC's namespaces, plus GET/HEAD elsewhere, except
 *     `/wp/v2/users*`, which is refused outright. Any other method outside
 *     BCC's namespaces answers 401.
 *
 * And application passwords are available to admins only.
 *
 * ⚠⚠ WHY: BearerAuth authenticates EVERY REST request, so a member's bearer
 * token reaches core's routes and every other plugin's, none of which run
 * BCC's gates (step-up, suspension, privacy, rate limits). The first hole
 * found this way was `PUT /wp/v2/users/me` with a `password` or `email`, a
 * takeover around every BCC step-up. Its `application-passwords` routes
 * minted a credential that outlives "sign out everywhere". The security
 * review (2026-11-18) confirmed it and asked for an allowlist rather than
 * one route at a time. The frontend and its NextAuth server call no REST
 * route outside BCC's namespaces, so members lose nothing.
 *
 * Matching:
 *   - The BCC check is the allow direction, so it stays fail-closed: the
 *     route is only lowercased (core matches routes case-insensitively,
 *     `'@^…$@i'`). Core's route patterns are anchored and begin with their
 *     namespace, so a route that starts with `/bcc/v1/` can only reach a
 *     BCC handler. Any other spelling (`//bcc/v1/…`, no leading slash) reaches
 *     no BCC handler either, and is refused like any other route.
 *   - The `/wp/v2/users` check is the deny direction, so it normalises the
 *     way core might: lowercased, repeated slashes collapsed, and a leading
 *     slash supplied.
 *   - `rest_pre_dispatch` also runs for each request inside a `/batch/v1`
 *     call (an admin's, since the batch route itself is refused to everyone
 *     else) and for internal `rest_do_request()` dispatches. BCC's own
 *     internal dispatches all target `/bcc/v1`.
 *   - The method is the one core settled on, after `_method` or
 *     `X-HTTP-Method-Override`.
 *
 * This is a security boundary, so it does not share a predicate with
 * `EdgeCache::appliesTo()` or the namespace lists in `Envelope` or
 * `SuspendedWriteGate` (see `CorsHandler::ownsRoute()`: a cache policy must
 * never decide a security boundary).
 *
 * A refusal wins over whatever an earlier filter answered. It is the core
 * `rest_forbidden` shape: 401 signed out, 403 signed in.
 *
 * A refusal is returned as a RESPONSE (`rest_convert_error_to_response()`),
 * never a bare `WP_Error`. At the top level core converts either one to the
 * same answer. Inside a `/batch/v1` call it does not: core passes the
 * filter's result through `rest_ensure_response()`, which returns a
 * `WP_Error` unchanged, into `envelope_response()`, which calls
 * `get_status()` on it. That is a fatal, so the whole batch answers 500
 * instead of 207 with the refused sub-request's own status. (Found by ops
 * while installing the production mitigation.)
 *
 * Production had an mu-plugin as the immediate mitigation for the users
 * routes; this is the permanent version, widened.
 */
final class RestRouteAllowlist
{
    public const CAPABILITY = 'manage_options';

    /** Before SuspendedWriteGate (20) and anything else that might answer. */
    public const PRIORITY = 1;

    /** BCC's REST namespaces, the only ones open to signed-in non-admins. */
    public const NAMESPACES = ['/bcc/v1', '/bcc-trust/v1'];

    /** What a signed-out caller may do outside BCC's namespaces. */
    private const ANONYMOUS_METHODS = ['GET', 'HEAD'];

    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'filter'], self::PRIORITY, 3);
        add_filter('wp_is_application_passwords_available_for_user', [self::class, 'applicationPasswordsAvailable'], 10, 2);
    }

    /**
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function filter($result, $server, $request)
    {
        if (!$request instanceof \WP_REST_Request) {
            return $result;
        }
        $route = (string) $request->get_route();
        if (self::isBccRoute($route) || current_user_can(self::CAPABILITY)) {
            return $result;
        }

        $signedIn = get_current_user_id() > 0;
        if (!$signedIn && !self::isCoreUsersRoute($route)
            && in_array(strtoupper((string) $request->get_method()), self::ANONYMOUS_METHODS, true)
        ) {
            return $result;
        }

        // A response, not a bare WP_Error: see the class note on /batch/v1.
        return rest_convert_error_to_response(new \WP_Error(
            'rest_forbidden',
            'Sorry, you are not allowed to do that.',
            ['status' => $signedIn ? 403 : 401]
        ));
    }

    /**
     * `wp_is_application_passwords_available_for_user`: admins only. A
     * member's application password would be a credential that never
     * expires and that "sign out everywhere" can't revoke. Core consults
     * this when one is used, too, so an existing one stops working.
     *
     * @param mixed $available
     * @param mixed $user
     */
    public static function applicationPasswordsAvailable($available, $user): bool
    {
        return (bool) $available && $user instanceof \WP_User && user_can($user, self::CAPABILITY);
    }

    /** `/bcc/v1` or `/bcc-trust/v1`, or anything under them, in any letter case. */
    public static function isBccRoute(string $route): bool
    {
        $route = strtolower($route);
        foreach (self::NAMESPACES as $namespace) {
            if ($route === $namespace || str_starts_with($route, $namespace . '/')) {
                return true;
            }
        }
        return false;
    }

    /** `/wp/v2/users` itself or anything under it, however it is spelled. */
    public static function isCoreUsersRoute(string $route): bool
    {
        $collapsed  = (string) preg_replace('#/+#', '/', strtolower($route));
        $normalised = '/' . ltrim($collapsed, '/');

        return preg_match('#^/wp/v2/users(?:/|$)#', $normalised) === 1;
    }
}
