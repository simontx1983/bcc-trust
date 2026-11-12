<?php
/**
 * UserSlugResolver — canonical slug → wp_users.ID lookup.
 *
 * Resolves a URL slug (the value rendered in `/u/{slug}`,
 * `/users/{slug}/...`, `/cards/member/{handle}`) to a wp_users.ID.
 * The ONE resolver for every per-user route: CardViewService delegates
 * here, so a privacy gate and the payload it protects always resolve the
 * same user.
 *
 * Matches, in order, and nothing else:
 *   1. `wp_usermeta.bcc_handle`, exact (the §B6 canonical handle).
 *   2. A RETIRED handle, exact (HandleService::retiredOwner): handles the
 *      login-handle rotation retired keep resolving so old links work;
 *      the response carries the current handle and the client redirects.
 *   3. `member-{id}` for member {id} ONLY when that member has no stored
 *      handle: the PublicIdentity fallback the server itself emits, so
 *      links rendered for a handle-less member resolve. User ids are
 *      already public, so this reveals nothing.
 *
 * ⚠ It deliberately does NOT fall back to `user_login` or
 * `user_nicename` (WordPress's slug of the login). Both made every
 * per-user route a login-existence and login→handle oracle: /users/<x>
 * answering 200 vs 404 confirmed a login. Every member now has a
 * bcc_handle (assigned at creation and by the canonical-handle
 * backfill), so those fallbacks served nobody legitimate.
 *
 * Normalization: input is `strtolower(trim($slug))`; handles are stored
 * lowercase, so case-mismatched URLs resolve identically.
 *
 * Returns 0 when nothing matches.
 *
 * @package BCC\Trust\Core\Support
 * @since 2026-05-15 (Phase 2 cleanup pass)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class UserSlugResolver
{
    /**
     * Resolve a URL slug to a wp_users.ID, or 0 when nothing matches.
     */
    public static function resolve(string $slug): int
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return 0;
        }

        // get_users uses $wpdb under the hood but is the WP-canonical
        // user-lookup API — the §1 repo-only-DB rule applies to raw
        // $wpdb in our code, not WP core helpers. Bounded to one match.
        $userIds = get_users([
            'meta_key'   => \BCC\Trust\Core\Services\HandleService::META_HANDLE,
            'meta_value' => $slug,
            'number'     => 1,
            'fields'     => 'ID',
        ]);
        if (!empty($userIds)) {
            return (int) $userIds[0];
        }

        $retiredOwner = \BCC\Trust\Core\Services\HandleService::retiredOwner($slug);
        if ($retiredOwner !== null) {
            return $retiredOwner;
        }

        return self::resolveFallbackHandle($slug);
    }

    /**
     * 'member-{id}' → {id}, only for an existing member with no stored
     * handle (the form PublicIdentity::handle() emits for them). A member
     * who HAS a handle is reached by that handle, never by member-{id}.
     */
    private static function resolveFallbackHandle(string $slug): int
    {
        if (preg_match('/^' . preg_quote(PublicIdentity::HANDLE_PREFIX, '/') . '([1-9][0-9]*)$/', $slug, $m) !== 1) {
            return 0;
        }
        $id = (int) $m[1];
        if ($id <= 0 || get_userdata($id) === false) {
            return 0;
        }
        $stored = get_user_meta($id, \BCC\Trust\Core\Services\HandleService::META_HANDLE, true);
        if (is_string($stored) && trim($stored) !== '') {
            return 0;
        }
        return $id;
    }
}
