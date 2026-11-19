<?php
/**
 * PageAccess — may this viewer see this PeepSo page on its own?
 *
 * The one gate for every surface that shows a single page, or reads by
 * one page id: its card (`/cards/{type}/{id}`), its reviews and its vouches
 * (`/entities/{kind}_card/{id}/reviews|attestations`).
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
 * Discovery lists do not use this: they apply PeepSoPagePrivacy::notSecretSql()
 * and keep secret pages out for everyone, admins included.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\PeepSoPagePrivacy;

if (!defined('ABSPATH')) {
    exit;
}

final class PageAccess
{
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
     */
    public static function viewerMaySeeId(int $pageId, int $viewerId): bool
    {
        if ($pageId <= 0) {
            return false;
        }
        $page = get_post($pageId);

        return self::decide($pageId, $page instanceof \WP_Post ? (int) $page->post_author : 0, $viewerId);
    }

    private static function decide(int $pageId, int $authorId, int $viewerId): bool
    {
        if ($viewerId > 0 && $authorId === $viewerId) {
            return true;
        }
        if (ProfileAccess::viewerIsExemptFromVisibility()) {
            return true;
        }
        return PeepSoPagePrivacy::isVisibleTo($pageId, $viewerId);
    }
}
