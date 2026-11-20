<?php
/**
 * A SECRET page's announcement, wherever its feed activity is read.
 *
 * §4.32.8 projects a published announcement as ONE activity (module
 * `announcement`, 205), written as the OPERATOR's own post with the
 * announcement's wp_post as its `external_id`. Nothing on that row names the
 * page. So the secret-page rule (#731, #758, #768: a page with
 * `peepso_page_privacy = 2` is seen by its author, site admins, and its
 * members and invitees; everyone else gets what a missing page gets) did not
 * reach it, and a secret page's notice was readable on every feed, the
 * operator's wall, `/feed/hot`, the permalink and its shortcode alias.
 *
 * This class answers one question for those readers: may this viewer see this
 * announcement activity? The page is the announcement's `post_parent`, read
 * server-side ({@see AnnouncementRepository::pageIdsFor()}); the verdict is
 * {@see PageAccess}'s, so it cannot drift from the page itself.
 *
 *   - {@see keepVisible()}: a page of feed items. One read for the pages, one
 *     batched privacy check ({@see PageAccess::visibleAmong()}). A page with
 *     no announcement on it costs nothing.
 *   - {@see mayViewerSeeRow()}: one activity row by id
 *     (FeedItemReportVisibility::canSee(), the gate behind the delete,
 *     comment and report refusals).
 *
 * A feed item is an announcement by its `post_kind`; an activity ROW by its
 * module, the one {@see ActivityStreamWriter::handleAnnouncementPublished()}
 * writes ({@see isAnnouncementModule()}, through PeepSoActivityWriter's map,
 * as FeedItemReportVisibility already identifies review replies).
 *
 * ⚠ FAILS CLOSED, BUT ONLY FOR NOTICES. An unreadable page lookup drops the
 * announcements on the page, and every other item stays: a notice is the
 * only thing this rule is about, so a fault here must not empty a feed of
 * ordinary posts. An announcement whose page cannot be found is dropped too;
 * "no page" is never read as "an open page".
 *
 * ⚠ PER VIEWER, AND NEVER INSIDE A SHARED CACHE. Callers apply this after the
 * anonymous /feed/hot payload is read from its shared cache, on the request's
 * own copy, so a page made secret is hidden on the next request rather than
 * after the cache's TTL, and a member's view never reaches anyone else.
 *
 * @package BCC\Trust\Core\Announcements
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements;

use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\AnnouncementRepository;
use BCC\Trust\Core\Repositories\PeepSoActivityWriter;
use BCC\Trust\Core\Support\PageAccess;
use BCC\Trust\Core\Support\ProfileAccess;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementPageAccess
{
    /** The `post_kind` bcc-core gives a module-205 row (FeedItemNormalizer::MODULE_TO_KIND). */
    public const POST_KIND = 'announcement';

    /** The activity module name ActivityStreamWriter writes a projection under. */
    private const MODULE_NAME = 'announcement';

    /**
     * $items without the announcements $viewerId may not see; every other
     * item, and the order, unchanged.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function keepVisible(array $items, int $viewerId): array
    {
        $announcementIds = [];
        foreach ($items as $item) {
            $id = self::announcementIdOf($item);
            if ($id !== null) {
                $announcementIds[$id] = true;
            }
        }
        if ($announcementIds === []) {
            return $items;
        }

        $visible = self::visibleAnnouncementIds(array_keys($announcementIds), $viewerId);

        $kept = [];
        foreach ($items as $item) {
            $id = self::announcementIdOf($item);
            if ($id === null || isset($visible[$id])) {
                $kept[] = $item;
            }
        }

        return $kept;
    }

    /**
     * One activity row (a PeepSoActivityRepository::getById() row:
     * act_module_id, act_external_id). TRUE for every row that is not an
     * announcement; for an announcement, whether $viewerId may see its page.
     */
    public static function mayViewerSeeRow(object $activity, int $viewerId): bool
    {
        if (!self::isAnnouncementModule($activity->act_module_id ?? null)) {
            return true;
        }

        $announcementId = (int) ($activity->act_external_id ?? 0);

        return $announcementId > 0
            && isset(self::visibleAnnouncementIds([$announcementId], $viewerId)[$announcementId]);
    }

    /**
     * Is this activity module the announcement projection's? A DB row carries
     * it as a numeric string, a hydrated row as an int; both count.
     */
    public static function isAnnouncementModule(mixed $module): bool
    {
        $module = is_int($module) || (is_string($module) && ctype_digit($module)) ? (int) $module : 0;

        return $module > 0 && $module === PeepSoActivityWriter::getNumericId(self::MODULE_NAME);
    }

    /**
     * @param list<int> $announcementIds
     * @return array<int, true> the ones $viewerId may see
     */
    private static function visibleAnnouncementIds(array $announcementIds, int $viewerId): array
    {
        try {
            $pageOf = (new AnnouncementRepository())->pageIdsFor($announcementIds);
        } catch (RepositoryException) {
            return [];
        }

        // ⚠ The viewer is not always the request's user: canView() asks about
        // a MENTIONEE during the author's request (#767). The admin exemption
        // is then the viewer's own, never the current user's, or an admin
        // author would exempt a stranger from a secret page.
        $exempt = $viewerId === get_current_user_id()
            ? null
            : ProfileAccess::userIsExemptFromVisibility($viewerId);

        $visiblePages = array_flip(PageAccess::visibleAmong(array_values($pageOf), $viewerId, $exempt));

        $out = [];
        foreach ($pageOf as $announcementId => $pageId) {
            if (isset($visiblePages[$pageId])) {
                $out[$announcementId] = true;
            }
        }

        return $out;
    }

    /**
     * The announcement id of a feed item, or null when it is not an
     * announcement. An announcement with no usable id is 0, which matches
     * no page and is dropped.
     *
     * ⚠ A numeric STRING counts. /feed/hot items come back through the
     * object cache, and a backend that round-trips through JSON or a text
     * column returns ids as strings (the same reason as
     * FeedRankingService::authorIdOf()). An int-only test would read every
     * cached notice as id 0 and hide them all; worse, treating "not an int"
     * as "not an announcement" would show them all.
     *
     * @param array<string, mixed> $item
     */
    private static function announcementIdOf(array $item): ?int
    {
        if (($item['post_kind'] ?? null) !== self::POST_KIND) {
            return null;
        }
        $id = $item['external_id'] ?? null;
        if (is_int($id)) {
            return max(0, $id);
        }

        return is_string($id) && $id !== '' && ctype_digit($id) ? (int) $id : 0;
    }
}
