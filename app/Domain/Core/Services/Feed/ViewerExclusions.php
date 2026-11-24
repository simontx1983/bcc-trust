<?php
/**
 * ViewerExclusions — what one viewer's feeds and permalinks exclude BEFORE
 * any post is read: the §O4.1 shadow-limit (caution/risky authors, never the
 * viewer), §K1 blocks in both directions, and the §K1-C moderation-hidden
 * posts.
 *
 * Built only by {@see FeedRankingService::viewerExclusions()}, which throws
 * ActivityReadFailedException when one of those lists could not be read
 * (F-438 / F-455 / F-454), so holding one means every list was really read:
 *   - the block lists, read in full;
 *   - the moderation-hidden list, read, or its last-good copy;
 *   - the caution/risky list (F-454), read in full, or truncated most severe
 *     first at ReputationRepository::CAUTION_LIST_CEILING (recorded and
 *     alerted), or its last-good copy no older than 24 h.
 * A caller
 * that has to answer "unavailable" before it looks anything up (the shortcode
 * permalink, a repost's root check) probes with that method first, then hands
 * the result to {@see FeedRankingService::getActivityById()}, so nothing is
 * read twice.
 *
 * ⚠ ONE VIEWER, ONE REQUEST. It carries the viewer it was built for, and
 * getActivityById() ignores one built for anyone else. Never cache it across
 * requests: a block or a hide made since would not apply.
 *
 * The parts stay separate because the permalink uses them separately: the
 * F-451 third look re-reads with the blocks and WITHOUT the caution list
 * (a room that lifts the shadow-limit).
 *
 * @package BCC\Trust\Core\Services\Feed
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Feed;

if (!defined('ABSPATH')) {
    exit;
}

final class ViewerExclusions
{
    /**
     * @param list<int> $cautionedAuthorIds the §O4.1 caution/risky list, without the viewer (F-269)
     * @param list<int> $blockedAuthorIds   users the viewer blocks, and users who block the viewer
     * @param list<int> $hiddenActivityIds  the §K1-C moderation-hidden act ids
     */
    public function __construct(
        private readonly int $viewerId,
        private readonly array $cautionedAuthorIds,
        private readonly array $blockedAuthorIds,
        private readonly array $hiddenActivityIds
    ) {
    }

    /** The viewer these lists were read for. */
    public function viewerId(): int
    {
        return $this->viewerId;
    }

    /**
     * The §O4.1 shadow-limited authors, never the viewer. Most severe first;
     * at the ceiling the least severe are cut (see the class docblock).
     *
     * @return list<int>
     */
    public function cautionedAuthorIds(): array
    {
        return $this->cautionedAuthorIds;
    }

    /**
     * §K1 mutual invisibility: both directions of every block the viewer is
     * part of. A cheap pre-filter, capped by bcc-core; the exact check runs
     * per page after the read (F-54).
     *
     * @return list<int>
     */
    public function blockedAuthorIds(): array
    {
        return $this->blockedAuthorIds;
    }

    /**
     * Every author the viewer's reads exclude: the caution list and the
     * blocks, deduped.
     *
     * @return list<int>
     */
    public function excludedAuthorIds(): array
    {
        $set = [];
        foreach ([$this->cautionedAuthorIds, $this->blockedAuthorIds] as $list) {
            foreach ($list as $id) {
                if ($id > 0) {
                    $set[$id] = true;
                }
            }
        }

        return array_keys($set);
    }

    /**
     * The moderation-hidden act ids.
     *
     * @return list<int>
     */
    public function hiddenActivityIds(): array
    {
        return $this->hiddenActivityIds;
    }
}
