<?php
/**
 * ViewerWatchResolver — "is the viewer watching this card, and by which
 * follow?" for any number of cards, without the watchlist (F-255).
 *
 * Before, every watch button derived its state from the first page of
 * GET /me/watching (page_size 50). A member watching more than 50 things
 * saw "Watch" on everything past the 50th, the click answered
 * `already_watching`, the button never flipped, and they could not
 * unwatch. There is NO cap on how many things a member may watch (user
 * decision 2026-11-12); 50 is only a page size. So the answer now ships
 * on the card itself, resolved here per card page, in two bounded IN()
 * reads however long the viewer's watchlist is.
 *
 * The resolution is the SAME one WatchingService::watch() writes with —
 * {@see pageFollowee()} is shared by both, so the flag can never disagree
 * with what a click would do:
 *   - member card  → the viewer's active PeepSo follow of that user;
 *   - page card    → a published peepso-page with an author: the viewer's
 *                    follow of the AUTHOR (watching a claimed page IS
 *                    following its owner); with no author (a pre-claim
 *                    placeholder): the viewer's bcc_page_follows row.
 *
 * The handle is what `DELETE /me/watching/:follow_id[?source=page]` takes,
 * so the client can unwatch any card it can see.
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\PageFollowRepository;
use BCC\Trust\Core\Repositories\WatchingRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ViewerWatch array{follow_id: int, source: 'peepso'|'page'}
 */
final class ViewerWatchResolver
{
    public function __construct(
        private readonly WatchingRepository $watching,
        private readonly PageFollowRepository $pageFollows
    ) {
    }

    /**
     * Who watching page `$pageId` follows: the author's user id, 0 for a
     * placeholder page (watched through bcc_page_follows), or null when
     * the id is not a published peepso-page (nothing to watch).
     * WatchingService::watch() resolves its followee through this.
     */
    public static function pageFollowee(int $pageId): ?int
    {
        if ($pageId <= 0) {
            return null;
        }
        $post = get_post($pageId);
        if (!$post instanceof \WP_Post
            || $post->post_type !== 'peepso-page'
            || $post->post_status !== 'publish'
        ) {
            return null;
        }

        return (int) $post->post_author;
    }

    /**
     * @param list<int> $userIds
     * @return array<int, ViewerWatch> user id => handle; absent = not watching
     */
    public function forMembers(int $viewerId, array $userIds): array
    {
        if ($viewerId <= 0) {
            return [];
        }

        $out = [];
        foreach ($this->watching->findActiveFollowIdsForFollowees($viewerId, $userIds) as $userId => $followId) {
            $out[$userId] = ['follow_id' => $followId, 'source' => 'peepso'];
        }

        return $out;
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, ViewerWatch> page id => handle; absent = not watching
     */
    public function forPages(int $viewerId, array $pageIds): array
    {
        if ($viewerId <= 0 || $pageIds === []) {
            return [];
        }

        $authorOf     = [];
        $placeholders = [];
        foreach ($pageIds as $pageId) {
            $pageId   = (int) $pageId;
            $followee = self::pageFollowee($pageId);
            if ($followee === null) {
                continue;
            }
            if ($followee > 0) {
                $authorOf[$pageId] = $followee;
            } else {
                $placeholders[] = $pageId;
            }
        }

        $byAuthor = $authorOf === []
            ? []
            : $this->watching->findActiveFollowIdsForFollowees($viewerId, array_values(array_unique($authorOf)));
        $byPage = $placeholders === []
            ? []
            : $this->pageFollows->findIdsForUserPages($viewerId, $placeholders);

        $out = [];
        foreach ($authorOf as $pageId => $authorId) {
            if (isset($byAuthor[$authorId])) {
                $out[$pageId] = ['follow_id' => $byAuthor[$authorId], 'source' => 'peepso'];
            }
        }
        foreach ($byPage as $pageId => $followId) {
            $out[$pageId] = ['follow_id' => $followId, 'source' => 'page'];
        }

        return $out;
    }

    /**
     * The two card/profile fields, from one resolved handle (or null).
     *
     * @param ViewerWatch|null $watch
     * @return array{viewer_is_watching: bool, viewer_watch: ViewerWatch|null}
     */
    public static function fields(?array $watch): array
    {
        return [
            'viewer_is_watching' => $watch !== null,
            'viewer_watch'       => $watch,
        ];
    }
}
