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
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ViewerWatch array{follow_id: int, source: 'peepso'|'page'}
 * @phpstan-type WatchState ViewerWatch|'unknown'
 */
final class ViewerWatchResolver
{
    /**
     * A map value meaning "the read that would answer this failed" (as
     * opposed to an absent key: not watching). Surfaces as
     * `viewer_is_watching: null`, so the client shows a neutral state
     * instead of a wrong "Watch" button. The card page never fails for it.
     */
    public const UNKNOWN = 'unknown';
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
     * @return array<int, WatchState> user id => handle or UNKNOWN; absent = not watching
     */
    public function forMembers(int $viewerId, array $userIds): array
    {
        if ($viewerId <= 0) {
            return [];
        }

        try {
            $followIds = $this->watching->findActiveFollowIdsForFollowees($viewerId, $userIds);
        } catch (RepositoryReadFailure $e) {
            self::logUnknown('members', $viewerId, $e);
            return self::allUnknown($userIds, $viewerId);
        }

        $out = [];
        foreach ($followIds as $userId => $followId) {
            $out[$userId] = ['follow_id' => $followId, 'source' => 'peepso'];
        }

        return $out;
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, WatchState> page id => handle or UNKNOWN; absent = not watching
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

        $out = [];

        // Each source fails on its own: a failed follow read makes the
        // owned pages UNKNOWN, a failed page-follow read the placeholders.
        if ($authorOf !== []) {
            try {
                $byAuthor = $this->watching->findActiveFollowIdsForFollowees($viewerId, array_values(array_unique($authorOf)));
                foreach ($authorOf as $pageId => $authorId) {
                    if (isset($byAuthor[$authorId])) {
                        $out[$pageId] = ['follow_id' => $byAuthor[$authorId], 'source' => 'peepso'];
                    }
                }
            } catch (RepositoryReadFailure $e) {
                self::logUnknown('owned pages', $viewerId, $e);
                foreach (array_keys($authorOf) as $pageId) {
                    $out[$pageId] = self::UNKNOWN;
                }
            }
        }

        if ($placeholders !== []) {
            try {
                foreach ($this->pageFollows->findIdsForUserPages($viewerId, $placeholders) as $pageId => $followId) {
                    $out[$pageId] = ['follow_id' => $followId, 'source' => 'page'];
                }
            } catch (RepositoryReadFailure $e) {
                self::logUnknown('placeholder pages', $viewerId, $e);
                foreach ($placeholders as $pageId) {
                    $out[$pageId] = self::UNKNOWN;
                }
            }
        }

        return $out;
    }

    /**
     * The two card/profile fields for `$id`, from a forPages()/forMembers()
     * map:
     *   - absent        → not watching:  false, null
     *   - a handle      → watching:      true,  handle
     *   - UNKNOWN       → the read failed: null, null
     *
     * @param array<int, mixed> $map
     * @return array{viewer_is_watching: bool|null, viewer_watch: ViewerWatch|null}
     */
    public static function fieldsFor(array $map, int $id): array
    {
        if (!array_key_exists($id, $map)) {
            return ['viewer_is_watching' => false, 'viewer_watch' => null];
        }
        $state = $map[$id];
        if (is_array($state) && isset($state['follow_id'], $state['source'])) {
            /** @var ViewerWatch $state */
            return ['viewer_is_watching' => true, 'viewer_watch' => $state];
        }

        return ['viewer_is_watching' => null, 'viewer_watch' => null];
    }

    /**
     * @param list<int> $ids
     * @return array<int, 'unknown'>
     */
    private static function allUnknown(array $ids, int $viewerId): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $viewerId) {
                $out[$id] = self::UNKNOWN;
            }
        }

        return $out;
    }

    private static function logUnknown(string $what, int $viewerId, RepositoryReadFailure $e): void
    {
        \BCC\Core\Log\Logger::warning('[bcc-trust] viewer watch state unknown: read failed', [
            'for'       => $what,
            'viewer_id' => $viewerId,
            'error'     => $e->getMessage(),
        ]);
    }
}
