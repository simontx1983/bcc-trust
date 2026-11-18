<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deny WordPress core's `/wp/v2/users` REST routes, and every path under
 * them, to anyone without `manage_options`. All methods.
 *
 * ⚠⚠ WHY: BearerAuth authenticates EVERY REST request, core's routes
 * included, and core lets any member edit themselves there (`edit_user` on
 * their own id). So a member's bearer token alone could
 * `PUT /wp/v2/users/me` with:
 *   - `password` — skipping PATCH /me/account/password's current-password
 *     step-up (AccountCredentialCheck), the 8-character minimum and the
 *     breached-password check;
 *   - `email` — skipping the pending-email verification; core only
 *     notifies the old address.
 * A stolen token became a takeover that outlives "sign out everywhere".
 * `/wp/v2/users/me/application-passwords` would mint a credential that
 * never expires. The frontend never calls `/wp/v2/*`, so the members'
 * surface for all of this is BCC's own `/me/*` routes. Admins keep core's
 * routes (wp-admin's block editor reads them).
 *
 * The route is normalised before it is matched, the way core's own
 * matching treats it: lowercased (core matches routes case-insensitively),
 * repeated slashes collapsed, and a leading slash supplied (a
 * `?rest_route=wp/v2/users` spelling). `rest_pre_dispatch` also runs for
 * each request inside a `/batch/v1` call and for internal dispatches such
 * as `_embed`.
 *
 * A matched route is refused whatever an earlier filter answered: no
 * non-admin request may reach these handlers. 401 for a signed-out caller,
 * 403 for a signed-in one.
 *
 * Security review, 2026-11-18; an mu-plugin was the immediate production
 * mitigation, and this is the permanent version.
 */
final class CoreUsersRouteGuard
{
    public const CAPABILITY = 'manage_options';

    /** Before SuspendedWriteGate (20) and anything else that might answer. */
    public const PRIORITY = 1;

    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'filter'], self::PRIORITY, 3);
    }

    /**
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function filter($result, $server, $request)
    {
        if (!$request instanceof \WP_REST_Request || !self::isCoreUsersRoute((string) $request->get_route())) {
            return $result;
        }
        if (current_user_can(self::CAPABILITY)) {
            return $result;
        }

        return new \WP_Error(
            'rest_forbidden',
            'Sorry, you are not allowed to do that.',
            ['status' => get_current_user_id() > 0 ? 403 : 401]
        );
    }

    /** `/wp/v2/users` itself or anything under it, however it is spelled. */
    public static function isCoreUsersRoute(string $route): bool
    {
        $collapsed  = (string) preg_replace('#/+#', '/', strtolower($route));
        $normalised = '/' . ltrim($collapsed, '/');

        return preg_match('#^/wp/v2/users(?:/|$)#', $normalised) === 1;
    }
}
