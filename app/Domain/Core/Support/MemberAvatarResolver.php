<?php
/**
 * MemberAvatarResolver — single cached seam for a member's avatar URL.
 *
 * Both {@see \BCC\Trust\Core\Services\CardViewService::resolveMemberAvatarUrl}
 * and {@see \BCC\Trust\Core\Services\UserViewService::resolveAvatar} carried a
 * byte-identical resolver: prefer PeepSo's `get_avatar('full')` (PeepSo stores
 * custom uploads under its own image dir; WP's `get_avatar_url()` only sees
 * them when PeepSo is filtering the native pipeline, which is a plugin option),
 * falling back to `get_avatar_url()` when PeepSo is absent. This collapses both
 * into one place (§11) and adds a cache layer.
 *
 * WHY CACHE (and why it's safe — unlike membership, see
 * PeepSoGroupRepository::getUserMemberGroupIds): resolving via
 * `PeepSoUser::get_instance($id)->get_avatar('full')` constructs a PeepSoUser
 * (a raw `SELECT * FROM peepso_users` PeepSo runs directly, consulting no WP
 * cache) and then does `get_user_meta()` + a `file_exists()` stat per call —
 * a per-card cost across every member list. Caching the resolved URL removes
 * that on warm cache. Staleness here is COSMETIC: a stale URL either 404s (the
 * frontend Avatar component then renders the initials monogram) or briefly
 * shows the prior avatar. There is no authorization or content-visibility
 * decision riding on this value, so a missed bust cannot leak anything.
 *
 * Invalidation (wired in bcc-trust.php): PeepSo writes `peepso_avatar_hash`
 * via `update_user_meta` (peepso/classes/user.php) on avatar change, and
 * `peepso_use_gravatar` toggles the gravatar branch — so added/updated/deleted
 * user-meta on those keys busts that one user's entry. A short TTL backstops
 * any avatar-change path that doesn't route through user-meta.
 *
 * Degrades cleanly without a persistent object cache: with no Redis drop-in
 * `wp_cache_*` is request-scoped, so this still collapses repeat resolutions
 * within a request and simply doesn't persist across them.
 *
 * @package BCC\Trust\Core\Support
 * @since 2026-06-18 (perf audit P1-B)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberAvatarResolver
{
    private const CACHE_GROUP = 'bcc_trust:avatars';
    private const KEY_PREFIX  = 'member_avatar:';
    /** Backstop for any avatar-change path that bypasses the user-meta bust. */
    private const CACHE_TTL   = 3600; // 1h

    /** User-meta keys whose change alters the resolved avatar URL. */
    private const BUST_META_KEYS = ['peepso_avatar_hash', 'peepso_use_gravatar'];

    /**
     * Resolved avatar URL for $userId, or '' when none resolves (the
     * frontend renders its initials monogram on empty).
     */
    public static function resolve(int $userId): string
    {
        if ($userId <= 0) {
            // Anon/invalid — no stable identity to key on; resolve uncached.
            $url = get_avatar_url($userId);
            return is_string($url) ? $url : '';
        }

        $key    = self::KEY_PREFIX . $userId;
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        // '' is a valid cached value ("no custom avatar"); only `false` (miss)
        // falls through to recompute.
        if (is_string($cached)) {
            return $cached;
        }

        $url = self::compute($userId);
        wp_cache_set($key, $url, self::CACHE_GROUP, self::CACHE_TTL);
        return $url;
    }

    /**
     * Drop one user's cached avatar URL. Public so the user-meta bust
     * closures in bcc-trust.php can call it; safe to call on a cold cache.
     */
    public static function bust(int $userId): void
    {
        if ($userId > 0) {
            wp_cache_delete(self::KEY_PREFIX . $userId, self::CACHE_GROUP);
        }
    }

    /**
     * Whether $metaKey is one whose change should bust the avatar cache.
     * Keeps the meta-key list owned by this class rather than the wiring.
     */
    public static function isBustMetaKey(string $metaKey): bool
    {
        return in_array($metaKey, self::BUST_META_KEYS, true);
    }

    /**
     * The actual resolution — PeepSo first, WP native fallback. Mirrors
     * the previous CardViewService / UserViewService implementations.
     */
    private static function compute(int $userId): string
    {
        if (class_exists('\\PeepSoUser')) {
            $peepso = \PeepSoUser::get_instance($userId);
            $url    = $peepso->get_avatar('full');
            if ($url !== '') {
                return $url;
            }
        }
        $url = get_avatar_url($userId);
        return is_string($url) ? $url : '';
    }
}
