<?php
/**
 * UserSlugResolver — canonical slug → wp_users.ID lookup.
 *
 * Resolves a URL slug (the value rendered in `/u/{slug}`,
 * `/users/{slug}/...`) to a wp_users.ID. Primary lookup is
 * `wp_usermeta.bcc_handle` (the §B6 canonical handle); falls back to
 * `wp_users.user_login` for legacy accounts created before the §B6
 * handle picker landed. Without the fallback those accounts would 404
 * on their own profile pages even though they exist and can log in.
 * WordPress enforces unique `user_login`, so the fallback is
 * collision-safe.
 *
 * Normalization: input is `strtolower(trim($slug))` before either
 * lookup. The §B6 contract stores `bcc_handle` lowercase + WP's
 * `user_login` lookup is case-insensitive on default collations, so
 * normalization makes case-mismatched URLs (`@Phillip` vs `@phillip`)
 * resolve identically.
 *
 * Returns 0 when nothing matches.
 *
 * Replaces 5 inline copies that lived in:
 *   - UsersEndpoint::resolveHandle (the original — had the normalize step)
 *   - UserGroupsEndpoint::resolveSlugToUserId
 *   - UserAlbumsEndpoint::resolveSlugToUserId
 *   - UserAlbumPhotosEndpoint::resolveSlugToUserId
 *   - UserFollowsEndpoint::resolveSlugToUserId
 *
 * The 4 endpoint-local copies silently dropped the normalization
 * step. Migrating them to this helper closes a latent case-sensitivity
 * bug as a side-effect.
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
     * Resolve a URL slug to a wp_users.ID. Returns 0 when neither the
     * bcc_handle meta lookup nor the user_login fallback finds a match.
     */
    public static function resolve(string $slug): int
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return 0;
        }

        // get_users uses $wpdb under the hood but is the WP-canonical
        // user-lookup API — the §1 repo-only-DB rule applies to raw
        // $wpdb in our code, not WP core helpers. Bounded to a single
        // match by 'number' => 1.
        $userIds = get_users([
            'meta_key'   => 'bcc_handle',
            'meta_value' => $slug,
            'number'     => 1,
            'fields'     => 'ID',
        ]);
        if (!empty($userIds)) {
            return (int) $userIds[0];
        }

        // Fallback: try wp_users.user_login. WordPress is case-
        // insensitive on most collations; combined with the
        // strtolower above this matches the §B6 lowercase-only
        // handle rule cleanly.
        $userByLogin = get_user_by('login', $slug);
        if ($userByLogin instanceof \WP_User) {
            return (int) $userByLogin->ID;
        }

        return 0;
    }
}
