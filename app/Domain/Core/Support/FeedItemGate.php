<?php
/**
 * Feed-Item Gate — the generic feed engagement and post routes act only on a
 * FEED ITEM: an activity whose module is on bcc-core's public-feed allowlist
 * ({@see \BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds()}, derived
 * from MODULE_TO_KIND). That is the same allowlist the feed candidate query,
 * the permalink and the report check already enforce, so a route that takes a
 * `feed_<act_id>` cannot act on something no feed would ever show.
 *
 * Why it exists (F-345 review): a review reply is its own activity, module
 * 206, deliberately OFF that allowlist ("never a standalone feed item", "one
 * reply, no thread"). Its `feed_id` is public: the reply carries it as its
 * report target. Without this gate, anyone signed in could open a hidden
 * comment thread under a reply, react, stoke or mark it helpful (belling the
 * replier), delete it through the generic post route (bypassing the reply's
 * own rules), and record views or read analytics for it.
 *
 * ONE rule for every caller, two shapes:
 *   - {@see check()}: the REST shape, same `?WP_REST_Response` contract and
 *     placement as {@see NoReactionsGate} and {@see GroupInteractionGate}
 *     (after the act id is validated, before any write).
 *   - {@see isFeedModule()}: the predicate, for services that already hold
 *     the activity row.
 *
 * The refusal is the SAME 404 "Post not found." an unknown act id gets: an
 * off-feed activity is not a post on these routes, and the answer says
 * nothing more. Reports and moderation hides are deliberately NOT gated —
 * they are how a reply is reported and hidden.
 *
 * @package BCC\Trust\Core\Support
 */

namespace BCC\Trust\Core\Support;

use BCC\Core\Feed\FeedItemNormalizer;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\CommentRepository;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedItemGate
{
    /**
     * Returns the 404 to short-circuit on, or null when the activity is a
     * feed item.
     *
     * FAILS CLOSED: an act id whose module cannot be read (unknown act, or
     * a failed lookup) is refused too. Every caller treats an unknown act
     * as not-found anyway, and a write must never proceed on an unread
     * module.
     */
    public static function check(int $actId): ?WP_REST_Response
    {
        if ($actId > 0) {
            $module = PeepSoActivityRepository::getModuleIdByActId($actId);
            if ($module !== null && self::isFeedModule($module) && !self::isCommentAct($actId)) {
                return null;
            }
        }

        return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
    }

    /**
     * Is this act a COMMENT rather than a top-level post?
     *
     * ⚠⚠ THE MODULE CANNOT ANSWER THIS, which is why the check above is not
     * redundant. PeepSo writes an activities row for EVERY comment and
     * stores `act_module_id = PeepSoActivity::MODULE_ID` (1) on it — the
     * SAME module a status post carries ("comments always belong to the
     * activity module"). So a comment act id passes
     * {@see isFeedModule()} and, before this, passed {@see check()}.
     *
     * The feed's candidate query has always excluded these rows with its own
     * `act_comment_object_id = 0` predicate, because they otherwise "INNER
     * JOIN a published wp_post and leak into the feed as if they were
     * top-level posts". This gate claims to enforce the same rule as that
     * query, and until now it enforced only half of it.
     *
     * ⛔ Why it matters beyond tidiness: a comment's own wp_post carries NO
     * `peepso_group_id` — PeepSo's groups add-on walks UP to the parent to
     * find the group — so any rule keyed on the resolved post reads "not
     * group-tagged" for a comment and admits it.
     *
     * Comments are NOT second-class here: they have their own routes, which
     * gate on the PARENT post ({@see GroupInteractionGate::checkComment()}).
     * Per-comment reaction rails do not exist in v1 (contract §3.3, "V2"),
     * and comment stoke has its own `/comments/:id/stoke` pair. So no caller
     * of {@see check()} legitimately receives a comment act id.
     *
     * ⚠ SCOPE, stated honestly: `getCommentMeta()` answers only for a
     * PUBLISHED comment, so an unpublished one is not detected here. That is
     * already covered downstream and needs no second read — every caller of
     * this gate then passes through
     * {@see GroupInteractionGate::resolveLiveTarget()}, which refuses an act
     * whose post is a `peepso-comment` and whose parent it cannot resolve.
     */
    public static function isCommentAct(int $actId, ?CommentRepository $repo = null): bool
    {
        if ($actId <= 0) {
            return false;
        }

        // ⚠ $repo exists so a caller that ALREADY HOLDS the repository passes
        // it instead of reaching back through the container for a second
        // handle on the same thing. CommentService does. Omitting it falls
        // back to the container, which is what the REST gates need, since
        // they are static and hold no dependencies.
        $repo ??= Plugin::instance()->commentRepository();

        return $repo->getCommentMeta($actId) !== null;
    }

    /**
     * Is this `act_module_id` a feed item's module?
     *
     * @param int|string|null $moduleId the activity row's act_module_id
     */
    public static function isFeedModule(int|string|null $moduleId): bool
    {
        if ($moduleId === null || $moduleId === '' || !is_numeric($moduleId)) {
            return false;
        }

        return in_array((int) $moduleId, FeedItemNormalizer::publicFeedModuleIds(), true);
    }
}
