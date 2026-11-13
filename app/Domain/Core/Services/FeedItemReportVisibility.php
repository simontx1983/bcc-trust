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

use BCC\Core\Feed\GroupSyndicationGate;
use BCC\Core\Repositories\PeepSoGroupRepository;
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


    /**
     * @param \BCC\Trust\Core\Repositories\VoteRepository|null $votes For the F-345
     *        review-reply arm only (is the reply's review still showable?).
     *        Optional and last so every existing two-argument construction
     *        keeps working; null resolves the plugin's repository lazily.
     */
    public function __construct(
        private readonly GroupsService $groups,
        private readonly HiddenActivityRepository $hidden,
        private ?\BCC\Trust\Core\Repositories\VoteRepository $votes = null
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
        // F-345: a review reply is reportable although no feed shows it
        // (module 206 is deliberately off the feed allowlist below). It is
        // visible exactly where it renders: nested under its review on a
        // Reviews tab. So: the reply itself is live (published, not hidden)
        // AND its parent review is still active and not hidden. Reviews are
        // never group-tagged, so the group rule does not apply.
        if ((int) ($activity->act_module_id ?? 0)
            === \BCC\Trust\Core\Repositories\PeepSoActivityWriter::getNumericId(\BCC\Trust\Core\Repositories\ReviewReplyRepository::MODULE_NAME)
        ) {
            return $this->isLive($activity) && $this->replyParentIsVisible($postId);
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

        return $this->groupRuleAllows($viewerId, $postId);
    }

    /**
     * F-345: is the review a reply (backing post $postId) answers still
     * showable — active and not moderation-hidden? Fails CLOSED: a failed
     * read is "not visible", so a reply is never reportable on a guess.
     */
    private function replyParentIsVisible(int $postId): bool
    {
        $voteId = (int) get_post_meta($postId, '_bcc_activity_sidecar_id', true);
        if ($voteId <= 0) {
            return false;
        }
        $this->votes ??= \BCC\Trust\Core\Plugin::instance()->voteRepository();
        try {
            return $this->votes->findReviewForReply($voteId) !== null;
        } catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure $e) {
            return false;
        }
    }

    /**
     * The same GROUP rule, for a caller that already holds a HYDRATED feed
     * item and has already had the cheap gates applied by bcc-core.
     *
     * Added for the group-permalink alignment: when
     * `ActivityFeedService::getActivityById()` is called with
     * `deferGroupVisibilityToCaller: true`, bcc-core stops deciding the
     * group-tagged case and THIS is the rule the caller owes it. Without
     * this call that flag would turn closed-group posts into public
     * permalinks.
     *
     * ⚠ WHAT IT DELIBERATELY DOES NOT RE-CHECK, and why that is safe
     * rather than sloppy: the module allowlist, `act_status = publish` and
     * the hidden-activity list are all applied INSIDE the same
     * `getActivityById()` call that produced `$item` — they are not
     * deferred by that flag, only the group branch is. Repeating them here
     * would cost a second `HiddenActivityRepository` read per permalink to
     * re-derive an answer bcc-core already gave.
     *
     * ⚠ So this is NOT a general-purpose entry point. Use
     * {@see canSee()} for a raw row from anywhere else; this one is only
     * correct for an item that came back from a bcc-core feed read.
     *
     * @param array<string, mixed> $item A hydrated feed item (`external_id`
     *        is the BACKING wp_post, never the act_id).
     */
    public function canSeeHydratedItem(int $viewerId, array $item): bool
    {
        $postId = is_int($item['external_id'] ?? null) ? $item['external_id'] : 0;
        if ($postId <= 0) {
            return false;
        }

        return $this->groupRuleAllows($viewerId, $postId);
    }

    /**
     * "May this viewer see a post in the group it is tagged to?" — the
     * group half of the rule, shared by both entry points above so they
     * cannot drift.
     *
     * A post that is NOT group-tagged, or is tagged but marked
     * `public_all`, is visible in the global feed and returns true here
     * (mirrors bcc-core `ActivityFeedService::isVisibleGlobally()`, which
     * is private — keep in lockstep). Otherwise
     * {@see GroupsService::gateGroupFeed()} decides exactly as the group
     * feed does.
     *
     * ⚠ A non-group post returning TRUE is why a permalink caller must
     * ALSO check that the post is group-tagged before letting this rescue
     * an item §F-02 refused — this method answers a group question and
     * says nothing about profile visibility.
     */
    private function groupRuleAllows(int $viewerId, int $postId): bool
    {
        $groupRaw   = get_post_meta($postId, 'peepso_group_id', true);
        $visibility = (string) get_post_meta($postId, '_bcc_post_visibility', true);

        // Not group-tagged at all: an ordinary global-feed post.
        if ($groupRaw === '' || $groupRaw === false) {
            return true;
        }

        // ⚠⚠ `public_all` IS NOT A FREE PASS, and this is the one place
        // that now has to say so. It used to be: this method short-circuited
        // on `public_all` because bcc-core's `isVisibleGlobally()` had
        // ALREADY applied the syndication gate upstream, so by the time a
        // row got here the question was settled.
        //
        // That stopped being true when the permalink started passing
        // `deferGroupVisibilityToCaller: true` (bcc-core #111): the defer
        // skips the whole syndication branch, `public_all` marker included.
        // Keeping the shortcut would mean the gate ran NOWHERE — a post
        // made `public_all` while its group was OPEN would stay reachable
        // by permalink to anonymous visitors after the group went SECRET.
        //
        // So syndication is checked HERE, with the same logic and the same
        // fail-closed posture as `isVisibleGlobally()`. A refusal is not
        // final: it falls through to the group's own gate below, so a
        // MEMBER of the now-secret group still reads their own post.
        if ($visibility === self::GLOBAL_VISIBILITY && self::syndicationAllows($postId)) {
            return true;
        }

        $gate = $this->groups->gateGroupFeed($viewerId, (int) $groupRaw);
        if (($gate['kind'] ?? '') !== 'allowed') {
            return false; // missing group, or secret + non-member
        }
        if (!($gate['public_only'] ?? true)) {
            return true; // member — sees every post in the group
        }
        return in_array($visibility, self::TEASER_VISIBILITIES, true);
    }

    /**
     * May a `public_all` group post be served OUTSIDE its group right now?
     *
     * ⚠ MIRROR of the syndication tail of bcc-core
     * `ActivityFeedService::isVisibleGlobally()`, which is private — keep
     * the two in lockstep. The rule: a post deliberately made `public_all`
     * while its group was ALREADY non-open stays public whatever the group
     * does later; otherwise the group must still be open now.
     *
     * ⚠ FAIL-CLOSED. `getNonOpenGroupIdsStrict()` answers `null` when it
     * cannot prove a complete list (query error, or over its cap), and
     * {@see GroupSyndicationGate::allows()} then admits only the posts
     * recorded as made while non-open. That is deliberately the strict
     * direction: an unreadable deny-list must not widen what is public.
     *
     * Reads the MULTI-VALUE form of `peepso_group_id`, as bcc-core does —
     * a post can carry more than one group id, and the single-value read
     * used for the gate below would silently consider only the first.
     */
    private static function syndicationAllows(int $postId): bool
    {
        $groupIds = array_values(array_filter(
            array_map('intval', (array) get_post_meta($postId, 'peepso_group_id', false)),
            static fn(int $id): bool => $id > 0
        ));
        if ($groupIds === []) {
            return true;
        }

        $privacyAtPost = get_post_meta($postId, GroupSyndicationGate::META_PRIVACY_AT_POST, true);
        if (GroupSyndicationGate::madeWhileNonOpen($privacyAtPost)) {
            // A deliberate non-open syndication passes whatever the
            // group's state is now.
            //
            // This branch is an OPTIMIZATION, not a rule: allows() checks
            // madeWhileNonOpen() itself, so removing it changes no answer
            // - only whether getNonOpenGroupIdsStrict() is read. Kept
            // because that read is a cache/DB round trip on a permalink
            // path, and because isVisibleGlobally() has the same early
            // return and the two are maintained in lockstep.
            //
            // Consequence worth knowing: a mutation that deletes this is
            // EQUIVALENT and survives by construction. That is not a
            // coverage gap.
            return true;
        }

        return GroupSyndicationGate::allows(
            $groupIds,
            $privacyAtPost,
            PeepSoGroupRepository::getNonOpenGroupIdsStrict()
        );
    }
}
