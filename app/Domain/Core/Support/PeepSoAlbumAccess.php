<?php
/**
 * PeepSoAlbumAccess — viewer-aware album-visibility gate.
 *
 * Mirrors `PeepSoPhotosAlbumModel::get_user_photos_album` privacy
 * grammar:
 *
 *   ACCESS_PUBLIC  (10) — visible to everyone
 *   ACCESS_MEMBERS (20) — visible to logged-in viewers
 *   ACCESS_FRIENDS (30) — visible to friends (+ owner). Honored only
 *                         when the Friends plugin is loaded; otherwise
 *                         degrades to PRIVATE (owner-only).
 *   ACCESS_PRIVATE (40) — visible to owner only
 *
 * The owner short-circuit happens before the access check so a viewer
 * looking at their own album always passes regardless of stored level.
 *
 * Replaces 2 inline copies that lived in:
 *   - UserAlbumsEndpoint::canView
 *   - UserAlbumPhotosEndpoint::canView
 *
 * `$isFriend` is passed in (not computed here) so callers can resolve
 * the friend-state once per request via {@see PeepSoFriendGate} and
 * pass the cached boolean into multiple `canView` calls (e.g. when
 * filtering a page of albums).
 *
 * @package BCC\Trust\Core\Support
 * @since 2026-05-15 (cleanup pass)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoAlbumAccess
{
    public const ACCESS_PUBLIC  = 10;
    public const ACCESS_MEMBERS = 20;
    public const ACCESS_FRIENDS = 30;
    public const ACCESS_PRIVATE = 40;

    /**
     * Can the viewer see the album given its access level + their
     * relationship to the owner?
     *
     * @param int  $albumAccess Album's `pho_album_acc` enum (see ACCESS_* constants).
     * @param int  $viewerId    Current viewer's user_id (0 for anonymous).
     * @param bool $isSelf      Viewer === album owner.
     * @param bool $isFriend    Viewer is a PeepSo friend of the album owner.
     *                          Caller should pass the cached
     *                          {@see PeepSoFriendGate::areFriends} result;
     *                          when the Friends plugin is absent this
     *                          should always be false.
     */
    public static function canView(
        int $albumAccess,
        int $viewerId,
        bool $isSelf,
        bool $isFriend
    ): bool {
        if ($isSelf) {
            return true;
        }
        if ($albumAccess === self::ACCESS_PUBLIC) {
            return true;
        }
        if ($albumAccess === self::ACCESS_MEMBERS) {
            return $viewerId > 0;
        }
        if ($albumAccess === self::ACCESS_FRIENDS) {
            return $isFriend;
        }
        // ACCESS_PRIVATE and any unknown enum default to owner-only.
        return false;
    }
}
