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
 *   - Signed-out callers: the same two namespaces, plus the exact
 *     method-and-route pairs in EXEMPT_ROUTES (none today). Every other
 *     route answers 401, any method, reads included.
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
 * ⚠⚠ Signed-out READS are refused too (review of this allowlist,
 * 2026-11-18). Outside BCC's namespaces nothing applies BCC's privacy
 * rules, so an anonymous GET is a read boundary of its own:
 * `/wp/v2/media` listed every member's blog-cover upload (unattached, so
 * public) with its author id, and the comments, search and users routes
 * and the index answered anyone too. No client of BCC reads them; the
 * deploy health probes read BCC routes (`/wp-json/bcc/v1/ranks`).
 *
 * Matching:
 *   - Both checks are the allow direction, so they stay fail-closed: the
 *     route is only lowercased (core matches routes case-insensitively,
 *     `'@^…$@i'`). Core's route patterns are anchored and begin with their
 *     namespace, so a route that starts with `/bcc/v1/` can only reach a
 *     BCC handler. Any other spelling (`//bcc/v1/…`, no leading slash)
 *     reaches no BCC handler either, and is refused like any other route.
 *     An exemption is one exact route, compared the same way.
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
 * `rest_forbidden` shape: 401 signed out, 403 signed in. It is a
 * WP_REST_Response, never a bare WP_Error: core's /batch/v1 loop reads
 * get_status() off whatever this filter answers, so a WP_Error there is a
 * fatal and the whole batch a 500.
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

    /**
     * What a signed-out caller may reach outside BCC's namespaces: exact
     * `"METHOD /route"` pairs, the method in capitals and the route in
     * lower case as core receives it (one leading slash, no trailing
     * slash). GET does not bring HEAD with it: list each method.
     *
     * Empty on purpose. An entry is for a server-to-server callback that is
     * genuinely in use (a cache or security service calling back with its
     * own signature), never for a browser read, and it needs a test of its
     * own.
     *
     * @var list<string>
     */
    public const EXEMPT_ROUTES = [];

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

        return self::refusal($route, (string) $request->get_method(), get_current_user_id() > 0) ?? $result;
    }

    /**
     * The refusal for a non-admin outside BCC's namespaces, or null when the
     * caller is signed out and the method and route are exempt. A member
     * gets no exemption.
     *
     * @param list<string> $exemptions EXEMPT_ROUTES; a test passes its own
     */
    public static function refusal(string $route, string $method, bool $signedIn, array $exemptions = self::EXEMPT_ROUTES): ?\WP_REST_Response
    {
        if (!$signedIn && in_array(strtoupper($method) . ' ' . strtolower($route), $exemptions, true)) {
            return null;
        }

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
}
