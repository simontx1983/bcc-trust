<?php
/**
 * PageAccess — may this viewer see this PeepSo page on its own?
 *
 * The one gate for every surface that shows a single page, or reads by
 * one page id: its card (`/cards/{type}/{id}`), its reviews, its vouches and
 * its watchers (`/entities/{kind}_card/{id}/reviews|attestations|watchers`).
 *
 * Checked cheapest first:
 *   1. The page's author. Some owners have no `member_owner` row yet
 *      (claims made before the F-299 owner backfill runs), and the
 *      membership rule below only knows rows.
 *   2. Site admins, through the one exemption every BCC privacy gate shares
 *      (ProfileAccess::viewerIsExemptFromVisibility), so moderators can open
 *      a secret page.
 *   3. Everyone else: bcc-core's shared rule (PeepSoPagePrivacy::isVisibleTo).
 *      A SECRET page is seen by its members and invitees only, and a failed
 *      read hides it.
 *
 * A caller that gets false must answer exactly as it answers for a page that
 * does not exist, so the answer never reveals that a secret page is there.
 *
 * A list that shows pages to ONE viewer (their watchlist, a feed page's page
 * labels) uses visibleAmong(): the same verdict for each page, with one
 * batched read instead of one per page.
 *
 * Discovery lists do not use this: they apply PeepSoPagePrivacy::notSecretSql()
 * and keep secret pages out for everyone, admins included.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\PeepSoPagePrivacy;
use BCC\Trust\Core\Repositories\PageDiscoveryRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class PageAccess
{
    /**
     * The most secret pages one member list checks for a viewer (F-528).
     * Each one may cost a member check, so a list with more fails closed
     * rather than run them all: its read throws and the route answers 503.
     */
    public const HIDDEN_LIST_CAP = 100;

    /**
     * Refuse a member list that covers more than HIDDEN_LIST_CAP secret
     * pages (F-528): log it, count it, and throw, so the route answers 503.
     *
     * ⚠ Not a passing failure. The member's tab answers 503 to every other
     * viewer for as long as they stay over the cap, so any count on
     * `page_privacy` / `member_list_over_cap` means real members cannot be
     * read; the log line names the member and the list.
     *
     * @param string $repository the reading repository's class
     * @param string $method     its method, usually `__FUNCTION__`
     * @throws \BCC\Trust\Onchain\Repositories\RepositoryReadFailure always
     */
    public static function refuseOverCap(string $repository, string $method, int $memberId): never
    {
        \BCC\Core\Log\Logger::error('[bcc-trust] member list over the secret-page cap: refused rather than checked page by page', [
            'repository' => $repository,
            'method'     => $method,
            'member_id'  => $memberId,
            'cap'        => self::HIDDEN_LIST_CAP,
        ]);
        \BCC\Core\Observability\DegradationMetrics::record('page_privacy', 'member_list_over_cap');

        throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure($repository, $method, 'more secret pages than the list can check');
    }

    /** For a caller that already holds the page's post. */
    public static function viewerMaySee(\WP_Post $page, int $viewerId): bool
    {
        return self::decide((int) $page->ID, (int) $page->post_author, $viewerId);
    }

    /**
     * For a caller that reads by page id. An id that names no post has no
     * author, so only the admin exemption and the core rule apply; with no
     * privacy rows the rule says visible, and the caller goes on to give its
     * usual missing-page answer.
     *
     * @param bool|null $viewerIsExempt the VIEWER's admin exemption; null =
     *        the request's user's, which is right only when the viewer IS the
     *        request's user (see ProfileAccess::postVisibilityArgs()).
     */
    public static function viewerMaySeeId(int $pageId, int $viewerId, ?bool $viewerIsExempt = null): bool
    {
        if ($pageId <= 0) {
            return false;
        }
        $page = get_post($pageId);

        return self::decide($pageId, $page instanceof \WP_Post ? (int) $page->post_author : 0, $viewerId, $viewerIsExempt);
    }

    /**
     * Of $pageIds, the ones $viewerId may see: in input order, each id once.
     * The verdict for each is exactly viewerMaySeeId()'s.
     *
     * Batched for lists. One read finds which of the pages are SECRET, or
     * unreadable (PageDiscoveryRepository::findSecretOrUnknownIds); only
     * those go through the single-page check, and every other page is seen
     * by everyone. Admins skip the read.
     *
     * @param list<int> $pageIds
     * @param bool|null $viewerIsExempt as {@see viewerMaySeeId()}
     * @return list<int>
     */
    public static function visibleAmong(array $pageIds, int $viewerId, ?bool $viewerIsExempt = null): array
    {
        $pageIds = array_values(array_unique(array_filter(
            $pageIds,
            static fn (int $id): bool => $id > 0
        )));
        if ($pageIds === [] || ($viewerIsExempt ?? ProfileAccess::viewerIsExemptFromVisibility())) {
            return $pageIds;
        }

        $secret = array_flip(PageDiscoveryRepository::findSecretOrUnknownIds($pageIds));
        if ($secret === []) {
            return $pageIds;
        }

        return array_values(array_filter(
            $pageIds,
            static fn (int $id): bool => !isset($secret[$id]) || self::viewerMaySeeId($id, $viewerId, $viewerIsExempt)
        ));
    }

    /**
     * Of $pageIds, the ones $viewerId may NOT see: exactly the ids
     * visibleAmong() leaves out, in input order, each id once.
     *
     * For a list read in SQL, which leaves these pages out of both its rows
     * and its total (`page_id NOT IN (…)`), so the pages and the count agree.
     *
     * @param list<int> $pageIds
     * @param bool|null $viewerIsExempt as {@see viewerMaySeeId()}
     * @return list<int>
     */
    public static function hiddenAmong(array $pageIds, int $viewerId, ?bool $viewerIsExempt = null): array
    {
        $pageIds = array_values(array_unique(array_filter(
            $pageIds,
            static fn (int $id): bool => $id > 0
        )));
        $visible = array_flip(self::visibleAmong($pageIds, $viewerId, $viewerIsExempt));

        return array_values(array_filter(
            $pageIds,
            static fn (int $id): bool => !isset($visible[$id])
        ));
    }

    private static function decide(int $pageId, int $authorId, int $viewerId, ?bool $viewerIsExempt = null): bool
    {
        if ($viewerId > 0 && $authorId === $viewerId) {
            return true;
        }
        if ($viewerIsExempt ?? ProfileAccess::viewerIsExemptFromVisibility()) {
            return true;
        }
        return PeepSoPagePrivacy::isVisibleTo($pageId, $viewerId);
    }
}
