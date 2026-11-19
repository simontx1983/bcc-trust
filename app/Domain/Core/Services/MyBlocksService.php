<?php
/**
 * My Blocks Service — composes the §K1 /me/blocks paginated list.
 *
 * Hydrates each block row with the blocked user's handle + display
 * name + avatar so the settings page renders without a follow-up
 * fetch per row. The handle preference order matches UserViewService::
 * resolveHandle — bcc_handle first, user_login fallback.
 *
 * Privacy note: this surface is owner-only. Blocks aren't part of the
 * public user view-model — there's no "people Alice blocks" tab.
 *
 * ⚠ A row shows WHO holds the blocked id only when there is a member AND
 * the blocker may view that member's profile (ProfileAccess::canViewProfile,
 * which never counts the block itself). Every other row is the unknown row:
 * the user_id with "" for handle, display name, avatar and profile link.
 * Since v1.179 anyone can block any positive id, so naming the holder here
 * would tell a member which ids exist, and who holds them, private
 * profiles included (F-404).
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §K1 Phase A blocks)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Support\ProfileAccess;

if (!defined('ABSPATH')) {
    exit;
}

final class MyBlocksService
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 50;

    /**
     * @return array{
     *   items: list<array{
     *     user_id: int,
     *     handle: string,
     *     display_name: string,
     *     avatar_url: string,
     *     profile_url: string
     *   }>,
     *   pagination: array{
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     *   }
     * }
     */
    public function getMyBlocks(int $blockerId, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        if ($blockerId <= 0) {
            return self::emptyPage($page, $perPage);
        }

        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset  = ($page - 1) * $perPage;

        $total = PeepSoBlockRepository::countByBlocker($blockerId);
        if ($total === 0) {
            return self::emptyPage($page, $perPage);
        }

        $rows = PeepSoBlockRepository::listForBlocker($blockerId, $perPage, $offset);

        // canViewProfile() answers for the CURRENT viewer only. Asked on
        // behalf of anyone else it would judge the wrong person, so then
        // no identity is shown at all.
        $viewerIsBlocker = get_current_user_id() === $blockerId;

        $shown = [];
        foreach ($rows as $row) {
            $uid = (int) $row->blk_blocked_id;
            if ($uid > 0 && $viewerIsBlocker && self::mayShowIdentity($uid, $row)) {
                $shown[$uid] = true;
            }
        }
        // One cached bulk avatar resolve, for the members shown only.
        $avatars = $shown === []
            ? []
            : \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrlBulk(array_keys($shown));

        $items = [];
        foreach ($rows as $row) {
            $userId = (int) $row->blk_blocked_id;
            if ($userId <= 0) {
                continue;
            }
            $items[] = isset($shown[$userId])
                ? self::shapeRow($userId, $row, $avatars[$userId] ?? '')
                : self::unknownRow($userId);
        }

        return [
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Is there a member behind this id, and may the blocker view their
     * profile? `user_login` is NULL when the LEFT JOIN found no WP user: an
     * id nobody holds. That is decided first, so such a row costs no
     * profile read.
     *
     * @param object{user_login: string|null} $row
     */
    private static function mayShowIdentity(int $userId, object $row): bool
    {
        if ($row->user_login === null) {
            return false;
        }

        return ProfileAccess::canViewProfile($userId);
    }

    /**
     * The row for an id nobody holds, or a member the blocker may not view:
     * the same for both, so the list never says which it is.
     *
     * @return array{
     *   user_id: int,
     *   handle: string,
     *   display_name: string,
     *   avatar_url: string,
     *   profile_url: string
     * }
     */
    private static function unknownRow(int $userId): array
    {
        return [
            'user_id'      => $userId,
            'handle'       => '',
            'display_name' => '',
            'avatar_url'   => '',
            'profile_url'  => '',
        ];
    }

    /**
     * @param object{
     *   blk_blocked_id: int|numeric-string,
     *   user_login: string|null,
     *   display_name: string|null,
     *   blk_id: int|numeric-string
     * } $row
     * @return array{
     *   user_id: int,
     *   handle: string,
     *   display_name: string,
     *   avatar_url: string,
     *   profile_url: string
     * }
     */
    private static function shapeRow(int $userId, object $row, string $avatarUrl): array
    {
        // Prefer bcc_handle; PublicIdentity never falls back to user_login.
        $handle      = \BCC\Trust\Core\Support\PublicIdentity::handle($userId, get_user_meta($userId, 'bcc_handle', true));
        $displayName = \BCC\Trust\Core\Support\PublicIdentity::visibleName($userId, $row->display_name, $row->user_login, $handle);

        return [
            'user_id'      => $userId,
            'handle'       => $handle,
            'display_name' => $displayName,
            'avatar_url'   => $avatarUrl,
            'profile_url'  => $handle !== '' ? '/u/' . $handle : '',
        ];
    }

    /**
     * @return array{
     *   items: list<array{
     *     user_id: int,
     *     handle: string,
     *     display_name: string,
     *     avatar_url: string,
     *     profile_url: string
     *   }>,
     *   pagination: array{
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     total_pages: int
     *   }
     * }
     */
    private static function emptyPage(int $page, int $perPage): array
    {
        return [
            'items'      => [],
            'pagination' => [
                'page'        => max(1, $page),
                'per_page'    => max(1, $perPage),
                'total'       => 0,
                'total_pages' => 0,
            ],
        ];
    }
}
