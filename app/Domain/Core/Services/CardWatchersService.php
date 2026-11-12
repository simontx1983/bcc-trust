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
 * Privacy (F-26): watchers are the OWNER's PeepSo followers, so the list
 * answers to the owner's PROFILE privacy, exactly like
 * `/users/:handle/followers`. When ProfileAccess::canViewProfile($owner)
 * says no (a private/members/friends profile the viewer can't read, or a
 * suspended owner per F-182), the list is refused with the same
 * ProfileAccess::forbidden() 403. The owner's `watching_hidden` flag still
 * does NOT propagate (watching an entity is a public trust signal); profile
 * privacy is a separate, stronger choice that does.
 *
 * ⚠ The target must be a PUBLISHED `peepso-page` of the requested card kind.
 * This used to accept ANY post id and list its author's followers, so a
 * member's status-post or draft id listed that member's followers through
 * the profile gate's back door. A non-matching target gets the same empty
 * page a missing one does.
 *
 * @package BCC\Trust\Core\Services
 * @since 2026-05-14 (Phase 2 entity tab parity)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\MemberCardPrefetcher;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Core\Support\PeepSoFriendGate;
use BCC\Trust\Core\Support\ProfileAccess;

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
     * }|null  null = the owner's profile is not viewable (F-26) → 403
     */
    public function listWatchers(
        int $pageId,
        int $viewerId,
        int $offset = 0,
        int $limit = self::DEFAULT_LIMIT,
        string $cardKind = ''
    ): ?array {
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
        // F-26: only a published entity page of the requested kind. Any
        // other post id (a status post, a draft, another kind's page)
        // answers like a missing one.
        if ($post->post_type !== 'peepso-page'
            || $post->post_status !== 'publish'
            || ($cardKind !== ''
                && PageTypeMap::kindForStoredPageType((string) get_post_meta($pageId, '_bcc_page_type', true)) !== $cardKind)
        ) {
            return self::emptyPage($offset, $limit);
        }
        $ownerUserId = (int) $post->post_author;
        if ($ownerUserId <= 0) {
            return self::emptyPage($offset, $limit);
        }
        // F-26: the owner's followers answer to the owner's profile
        // privacy and suspension (F-02 + F-182), exactly as
        // /users/:handle/followers does. null → the caller's 403.
        if (!ProfileAccess::canViewProfile($ownerUserId)) {
            return null;
        }

        // §F-02 — a member whose profile is private must not be listed in
        // someone else's followers or following. bcc-core owns the rule;
        // this decides whether to apply it.
        //
        // ⚠⚠ THE COUNT AND THE LIST GET THE SAME ARGUMENTS. They are two
        // queries, so gating one and not the other renders "128
        // following" above seven rows — and the `$total === 0`
        // short-circuit below would return an empty page while the count
        // insisted otherwise. Any change here must change both.
        //
        // ⚠ Admins pass `false`, which is how bcc-core expects an admin
        // read to be expressed: the repository deliberately holds no
        // capability check. Same narrower `manage_options` notion the
        // profile gate uses, so a row and its profile always agree.
        $enforce   = !ProfileAccess::viewerIsExemptFromVisibility();
        $friendIds = PeepSoFriendGate::viewerFriendIdList($viewerId);

        $counts = PeepSoFollowerRepository::getCounts($ownerUserId, $viewerId, $friendIds, $enforce);
        $total  = $counts['followers'];
        if ($total === 0) {
            return [
                'items'      => [],
                'pagination' => self::pagination($offset, $limit, 0, false),
            ];
        }

        $userIds = PeepSoFollowerRepository::getFollowers($ownerUserId, $limit, $offset, $viewerId, $friendIds, $enforce);
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
