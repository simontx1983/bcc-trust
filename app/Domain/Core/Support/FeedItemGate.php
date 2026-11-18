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
            if ($module !== null && self::isFeedModule($module)) {
                return null;
            }
        }

        return ApiResponse::error('bcc_not_found', 'Post not found.', 404);
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
