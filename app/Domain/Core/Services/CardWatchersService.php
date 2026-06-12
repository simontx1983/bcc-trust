<?php
/**
 * Card Watchers Service — composes the §V1.5
 * /entities/{kind}/{id}/watchers paginated list for the entity-profile
 * Watchers tab.
 *
 * "Watchers of card X" routes through the PeepSo follower graph: the
 * card maps to a wp_post, the post has an owner (post_author), and
 * the owner's followers are by definition the people watching the
 * card. Unclaimed cards have no resolvable owner (post_author = 0 or
 * a system user) — for those we return an empty page; the
 * frontend's Watchers tab renders "no watchers yet" copy that points
 * at claim as the way to anchor the graph.
 *
 * Same item shape + pagination as `/users/:handle/followers`
 * (offset-based MemberSummary list). Hydration goes through the same
 * shared {@see MemberCardPrefetcher}.
 *
 * Privacy: entity watchers aren't privacy-gated. There's no entity-
 * level `watching_hidden` flag (only user profiles carry one) — the
 * underlying owner's flag DOES NOT propagate to their entity pages,
 * because watching an entity is a public trust signal even if the
 * owner has hidden their personal watcher list.
 *
 * @package BCC\Trust\Core\Services
 * @since 2026-05-14 (Phase 2 entity tab parity)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\MemberCardPrefetcher;

if (!defined('ABSPATH')) {
    exit;
}

final class CardWatchersService
{
    public const DEFAULT_LIMIT = 24;
    public const MAX_LIMIT     = 100;

    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   pagination: array{
     *     offset: int,
     *     limit: int,
     *     total: int,
     *     has_more: bool
     *   }
     * }
     */
    public function listWatchers(
        int $pageId,
        int $viewerId,
        int $offset = 0,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $offset = max(0, $offset);
        $limit  = max(1, min(self::MAX_LIMIT, $limit));

        if ($pageId <= 0) {
            return self::emptyPage($offset, $limit);
        }

        // Resolve page → owner user_id. Unclaimed pages have no
        // post_author anchor; return an empty page so the tab renders
        // its copy-specific empty state without a SQL fan-out.
        $post = get_post($pageId);
        if ($post === null) {
            return self::emptyPage($offset, $limit);
        }
        $ownerUserId = (int) $post->post_author;
        if ($ownerUserId <= 0) {
            return self::emptyPage($offset, $limit);
        }

        $counts = PeepSoFollowerRepository::getCounts($ownerUserId);
        $total  = $counts['followers'];
        if ($total === 0) {
            return [
                'items'      => [],
                'pagination' => self::pagination($offset, $limit, 0, false),
            ];
        }

        $userIds = PeepSoFollowerRepository::getFollowers($ownerUserId, $limit, $offset);
        if ($userIds === []) {
            return [
                'items'      => [],
                'pagination' => self::pagination($offset, $limit, $total, false),
            ];
        }

        $prefetched = MemberCardPrefetcher::primeFor($userIds, $viewerId);

        $cardView = Plugin::instance()->cardViewService();
        $items    = [];
        foreach ($userIds as $userId) {
            $card = $cardView->getMemberCardForList($userId, $viewerId, $prefetched);
            if ($card === null) {
                continue;
            }
            $items[] = $card;
        }

        $hasMore = ($offset + count($userIds)) < $total;

        return [
            'items'      => $items,
            'pagination' => self::pagination($offset, $limit, $total, $hasMore),
        ];
    }

    /**
     * @return array{offset: int, limit: int, total: int, has_more: bool}
     */
    private static function pagination(int $offset, int $limit, int $total, bool $hasMore): array
    {
        return [
            'offset'   => $offset,
            'limit'    => $limit,
            'total'    => $total,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   pagination: array{
     *     offset: int,
     *     limit: int,
     *     total: int,
     *     has_more: bool
     *   }
     * }
     */
    private static function emptyPage(int $offset, int $limit): array
    {
        return [
            'items'      => [],
            'pagination' => self::pagination($offset, $limit, 0, false),
        ];
    }
}
