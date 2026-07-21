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
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §K1 Phase A blocks)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoBlockRepository;

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

        $blockedIds = [];
        foreach ($rows as $row) {
            $uid = (int) $row->blk_blocked_id;
            if ($uid > 0) {
                $blockedIds[] = $uid;
            }
        }
        // One cached bulk avatar resolve for the page.
        $avatars = \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrlBulk($blockedIds);

        $items = [];
        foreach ($rows as $row) {
            $userId = (int) $row->blk_blocked_id;
            if ($userId <= 0) {
                continue;
            }
            $items[] = self::shapeRow($userId, $row, $avatars[$userId] ?? '');
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
        // Prefer bcc_handle, fall back to user_login. Same precedence
        // UserViewService uses everywhere else.
        $handleRaw = get_user_meta($userId, 'bcc_handle', true);
        $handle    = is_string($handleRaw) && $handleRaw !== ''
            ? $handleRaw
            : (is_string($row->user_login) ? $row->user_login : '');

        $displayName = is_string($row->display_name) && $row->display_name !== ''
            ? $row->display_name
            : (is_string($row->user_login) ? $row->user_login : '');

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
