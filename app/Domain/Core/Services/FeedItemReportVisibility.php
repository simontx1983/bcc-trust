<?php
/**
 * FeedItemReportVisibility — "you can report what you can actually see"
 * (F-73, product decision 2026-11-10).
 *
 * Before this, POST /me/reports accepted a feed_item report from any
 * signed-in, non-suspended member against ANY act_id, including posts in
 * closed and secret groups the reporter could never see, and its rows
 * counted toward auto-hide.
 *
 * The rule REUSES the feeds' own gates rather than restating privacy:
 *
 *   - the activity's module must be on bcc-core's public-feed allowlist
 *     (FeedItemNormalizer::publicFeedModuleIds(), the rule the feed and
 *     permalink paths use), and the backing post must be published and not
 *     moderation-hidden;
 *   - ⚠ FOLLOW-UP: per-post `act_access` is not consulted yet. Update this
 *     in lockstep when F-02's per-post act_access lands.
 *   - a post that is not group-tagged, or is group-tagged with visibility
 *     `public_all`, is visible in the GLOBAL feed (mirrors bcc-core
 *     ActivityFeedService::isVisibleGlobally(), which is private — keep in
 *     lockstep);
 *   - otherwise GroupsService::gateGroupFeed() decides, exactly as the
 *     group feed does:
 *       not_found            → not visible (missing, or secret + non-member)
 *       public_only = false  → member: visible
 *       public_only = true   → non-member teaser: visible only for the
 *                              per-post visibilities in TEASER_VISIBILITIES.
 *
 * Everything not visible is answered by the caller with the SAME
 * `bcc_not_found` envelope a missing id gets, so a refusal never confirms
 * that a closed/secret post exists.
 *
 * Deliberately NOT applied: viewer-specific presentation filters
 * (mutual-block invisibility, caution-tier shadow limiting). Those live in
 * FeedRankingService's exclusion lists, and a member who blocked an author
 * may still need to report what that author posted.
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\HiddenActivityRepository;

if (!defined('ABSPATH')) {
    exit;
}

class FeedItemReportVisibility
{
    /**
     * Per-post visibilities a NON-member sees in a group's teaser feed.
     *
     * ⚠ MIRROR of the literal in FeedRankingService::getGroupFeed()
     * (`$visibilityIn = $publicOnly ? ['public_group', 'public_all'] : null`).
     * Keep in lockstep; queued to become one shared constant once the
     * in-flight FeedRankingService PR merges.
     */
    public const TEASER_VISIBILITIES = ['public_group', 'public_all'];

    /** The visibility that syndicates a group post into the global feed. */
    public const GLOBAL_VISIBILITY = 'public_all';


    public function __construct(
        private readonly GroupsService $groups,
        private readonly HiddenActivityRepository $hidden
    ) {
    }

    /**
     * F-265: is this activity still LIVE, i.e. is its post published and
     * not moderation-hidden? A post failing this is gone from every feed
     * and from its permalink, so nothing may read its thread or act on it
     * either (comments, reactions, stoke, helpful-mark, the bells they
     * send). ONE predicate for all of them: {@see isLiveWith()}.
     *
     * @param object $activity A PeepSoActivityRepository::getById() row
     *        (act_id, act_status).
     */
    public function isLive(object $activity): bool
    {
        return self::isLiveWith($activity, $this->hidden);
    }

    /**
     * {@see isLive()} for the static call sites (GroupInteractionGate,
     * NotificationDispatcher) that hold no instance of this class.
     *
     * `act_status` is the backing wp_post's post_status (bcc-core
     * getById selects `p.post_status AS act_status`), so a post the author
     * deleted (`trash`) is not live. HiddenActivityRepository::exists()
     * fails closed (F-98): a failed read counts as hidden.
     */
    public static function isLiveWith(object $activity, HiddenActivityRepository $hidden): bool
    {
        $actId = (int) ($activity->act_id ?? 0);
        if ($actId <= 0 || (string) ($activity->act_status ?? '') !== 'publish') {
            return false;
        }

        return !$hidden->exists($actId);
    }

    /**
     * May `$viewerId` see — and therefore report — this activity?
     *
     * @param object $activity A PeepSoActivityRepository::getById() row
     *        (act_id, act_module_id, act_external_id, act_status).
     */
    public function canSee(int $viewerId, object $activity): bool
    {
        $actId  = (int) ($activity->act_id ?? 0);
        $postId = (int) ($activity->act_external_id ?? 0);
        if ($actId <= 0 || $postId <= 0) {
            return false;
        }
        // Only modules a feed or permalink can show at all: the SAME
        // allowlist bcc-core's feed and permalink paths enforce (§11 reuse).
        // An ALLOWLIST, not a denylist: it excludes private messages (6),
        // group-join notices (8), page walls (9), polls (30), post
        // backgrounds (111) and raw peepso-blog posts (6661), and fails
        // closed on any module added later. Blog posts reach the feed as
        // module 204 (blog_excerpt), which is on the list.
        if (!in_array(
            (int) ($activity->act_module_id ?? 0),
            \BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds(),
            true
        )) {
            return false;
        }
        if (!$this->isLive($activity)) {
            return false; // trashed/unpublished or moderation-hidden: no feed shows it
        }

        $groupRaw  = get_post_meta($postId, 'peepso_group_id', true);
        $visibility = (string) get_post_meta($postId, '_bcc_post_visibility', true);

        // Global feed (mirrors ActivityFeedService::isVisibleGlobally()).
        if ($groupRaw === '' || $groupRaw === false || $visibility === self::GLOBAL_VISIBILITY) {
            return true;
        }

        $gate = $this->groups->gateGroupFeed($viewerId, (int) $groupRaw);
        if (($gate['kind'] ?? '') !== 'allowed') {
            return false;
        }
        if (!($gate['public_only'] ?? true)) {
            return true; // member — sees every post in the group
        }
        return in_array($visibility, self::TEASER_VISIBILITIES, true);
    }
}
