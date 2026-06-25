<?php
/**
 * Feed Ranking Service — the §F3 single brain.
 *
 * Per §F3: ALL feed surfaces route through ONE ranking service. Endpoints
 * differ only in their input scope and viewer context. The trust-aware
 * de-prioritization rules (§O4.1: caution/risky excluded everywhere a
 * viewer sees the system) live here exactly once.
 *
 * V1 contract: this service is the entrypoint for /feed/hot and /feed.
 * It wraps bcc-core's ActivityFeedService (the read-side feed orchestrator)
 * and applies the bcc-trust-owned shadow-limit list before the underlying
 * SQL runs.
 *
 * V1 ranking is recency + author tier exclusion. F1's full priority chain
 * (followed-content boost, engagement, trending) is deferred to the
 * post-launch ranking ramp — adding them is a function-body change here,
 * not a new endpoint or a new service.
 *
 * Post-query enrichment responsibility (Phase 11 extraction): the
 * IDENTICAL 8-step hydration chain every feed surface ran inline —
 * bodies, reactions, author badges/ranks, social-proof reactors, viewer
 * permissions, group contexts, comment counts — now lives in the sibling
 * FeedHydrationPipeline. This service keeps the source query + the
 * trust-aware visibility-exclusion logic (§O4.1 caution/risky, §K1
 * blocks, moderation hide, §4.7.x group-leak gate) and delegates all
 * enrichment via the single `FeedHydrationPipeline::hydrate()` call.
 *
 * @package BCC\Trust\Core\Services\Feed
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services\Feed;

use BCC\Core\Feed\ActivityFeedService;
use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\GroupContextResolver;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedRankingService
{
    public function __construct(
        private readonly ActivityFeedService $activityFeed,
        private readonly ReputationRepository $reputationRepo,
        private readonly HiddenActivityRepository $hiddenRepo,
        private readonly GroupContextResolver $groupContextResolver,
        private readonly FeedHydrationPipeline $hydrationPipeline
    ) {
    }

    /**
     * Hot feed (§F2) — global trending, anonymous-OK, zero-follow fallback.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getHotFeed(?string $cursor = null, int $limit = 20): array
    {
        $excluded       = $this->reputationRepo->getCautionAndRiskyUserIds();
        $hidden         = $this->hiddenRepo->getAllHiddenIds();
        // Anon viewer (id=0) → exclude every non-open group. Restricted-
        // group resolution happens BEFORE the underlying SQL builds the
        // exclude IN clause, so empty list short-circuits the LEFT JOIN.
        $excludedGroups = self::resolveRestrictedGroupIds(0);

        $payload = $this->activityFeed->getFeed(
            0,
            ActivityFeedService::SCOPE_FOR_YOU,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            null,
            $excludedGroups === [] ? null : $excludedGroups
        );

        // viewerId=0 — anonymous; reaction hydration fills counts but
        // skips viewer_reaction (always null for anon).
        $payload['items'] = $this->hydrationPipeline->hydrate($payload['items'], 0);
        return $payload;
    }

    /**
     * Tag feed (`GET /bcc/v1/feed/tag`) — the global hot feed, narrowed to
     * posts carrying a single hashtag.
     *
     * SECURITY INVARIANT (load-bearing): the tag feed must NEVER surface a
     * post the public hot feed would not. This method is therefore a literal
     * mirror of `getHotFeed()` for everything that governs VISIBILITY:
     *
     *   - same `$excluded` set: $this->reputationRepo->getCautionAndRiskyUserIds()
     *     (§O4.1 caution/risky shadow-limit) — IDENTICAL call, no merge.
     *   - same `$hidden` set: $this->hiddenRepo->getAllHiddenIds()
     *     (§K1-C moderation hide) — IDENTICAL call.
     *   - same restricted-group exclusion computed with `self::resolveRestrictedGroupIds(0)`
     *     — deliberately the ANONYMOUS posture (viewer id 0), exactly as
     *     getHotFeed does. We do NOT subtract the real viewer's memberships
     *     here: doing so would WIDEN the candidate set to include the viewer's
     *     own gated-group posts, which the public hot feed never shows. The
     *     tag feed's visible set is thus a strict subset of the hot feed's.
     *   - same global-feed visibility gate: `onlyForGroupId = null` →
     *     PeepSoActivityRepository applies the `public_all` LEFT JOIN gate
     *     (group posts surface only when `_bcc_post_visibility = 'public_all'`;
     *     non-group posts pass through).
     *
     * The ONLY difference from getHotFeed is the trailing `$hashtag` argument
     * forwarded to `activityFeed->getFeed(...)`, which is a pure NARROWING
     * predicate (`post_content LIKE '%#tag%'`). A narrowing predicate can only
     * remove rows from the already-gated candidate set — it can never add one
     * — so the visibility parity with the hot feed holds by construction.
     *
     * Hydration is IDENTICAL to getHotFeed (bodies, reactions, author badges,
     * author ranks, social-proof reactors, viewer permissions, group
     * contexts, comment counts). The real `$viewerId` is passed ONLY to the
     * hydration steps that personalize NON-visibility chrome (viewer_reaction,
     * can_report, gated comment-count zeroing). Personalization tightens or
     * annotates per-viewer state; it never reveals a post the gate dropped.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getTagFeed(int $viewerId, string $hashtag, ?string $cursor = null, int $limit = 20): array
    {
        $hashtag = ltrim(trim($hashtag), '#');
        if ($hashtag === '') {
            return ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
        }

        // Visibility gates — computed IDENTICALLY to getHotFeed (see docblock).
        $excluded       = $this->reputationRepo->getCautionAndRiskyUserIds();
        $hidden         = $this->hiddenRepo->getAllHiddenIds();
        $excludedGroups = self::resolveRestrictedGroupIds(0);

        $payload = $this->activityFeed->getFeed(
            $viewerId,
            ActivityFeedService::SCOPE_FOR_YOU,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            null,
            $excludedGroups === [] ? null : $excludedGroups,
            null,
            $hashtag
        );

        $payload['items'] = $this->hydrationPipeline->hydrate($payload['items'], $viewerId);
        return $payload;
    }

    /**
     * Personalized feed (§N6) — auth-required, three scopes.
     *
     * @param 'for_you'|'following'|'signals' $scope
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getFeed(int $viewerId, string $scope, ?string $cursor = null, int $limit = 20): array
    {
        $excluded = self::mergeExclusions(
            $this->reputationRepo->getCautionAndRiskyUserIds(),
            // §K1 mutual-invisibility: hide both directions of any
            // block this viewer is part of. Users this viewer blocks
            // disappear from the feed; users who blocked this viewer
            // also disappear (so they can't quietly haunt the For You
            // tab via shared-follow paths).
            $viewerId > 0 ? PeepSoBlockRepository::getBlockedIds($viewerId) : [],
            $viewerId > 0 ? PeepSoBlockRepository::getBlockerIds($viewerId) : []
        );

        // §K1 Phase C — moderation hide. Cached + bounded; merged at the
        // act_id level (not author level) so a single bad post is hidden
        // without suppressing the author's other content.
        $hidden = $this->hiddenRepo->getAllHiddenIds();

        // §4.7.x main-feed leak gate — drop posts in closed/secret/
        // NFT-gated groups the viewer is not a member of. Subtractive:
        // (non-open groups) - (viewer's memberships). Empty list
        // short-circuits the SQL exclude branch entirely.
        $excludedGroups = self::resolveRestrictedGroupIds($viewerId);

        $payload = $this->activityFeed->getFeed(
            $viewerId,
            $scope,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            null,
            $excludedGroups === [] ? null : $excludedGroups
        );

        $payload['items'] = $this->hydrationPipeline->hydrate($payload['items'], $viewerId);
        return $payload;
    }

    /**
     * Per-author wall (§3.1 Activity tab on /u/:handle).
     *
     * Same hydration chain as `getFeed()` so the per-user wall and the
     * global feed render identically — wall is just a one-author slice
     * of the same stream.
     *
     * Exclusions: §O4.1 caution/risky still applies even on a wall (a
     * caution-tier author's wall reads as empty for everyone). §K1
     * mutual-block invisibility also applies — the viewer's block list
     * suppresses the wall when the wall owner is on it. §K1-C per-row
     * moderation hide is honored too.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getActivityForAuthor(int $authorId, int $viewerId, ?string $cursor = null, int $limit = 20): array
    {
        $excluded = self::mergeExclusions(
            $this->reputationRepo->getCautionAndRiskyUserIds(),
            $viewerId > 0 ? PeepSoBlockRepository::getBlockedIds($viewerId) : [],
            $viewerId > 0 ? PeepSoBlockRepository::getBlockerIds($viewerId) : []
        );

        $hidden = $this->hiddenRepo->getAllHiddenIds();

        // §4.7.x author-wall leak gate — same shape as the main feed.
        // A wall is a one-author slice of the same activity stream, so
        // posts the wall owner made inside a closed/secret/NFT-gated
        // group the viewer can't see must be filtered out here too.
        $excludedGroups = self::resolveRestrictedGroupIds($viewerId);

        $payload = $this->activityFeed->getActivityForAuthor(
            $authorId,
            $viewerId,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            $excludedGroups === [] ? null : $excludedGroups
        );

        $payload['items'] = $this->hydrationPipeline->hydrate($payload['items'], $viewerId);
        return $payload;
    }

    /**
     * Group-scoped feed — chronological stream of activity inside a
     * single PeepSo group. Backs `GET /bcc/v1/groups/{id}/feed`.
     *
     * Same single-brain composition as `getFeed()`: shadow-limit
     * exclusions (§O4.1), mutual-block invisibility (§K1), per-row
     * moderation hide (§K1 Phase C), then the standard hydration
     * chain (bodies, reactions, author badges, viewer permissions,
     * group context, comment counts).
     *
     * Authorization is the caller's job. The endpoint
     * (GroupsDetailEndpoint::feed) enforces:
     *   - secret + non-member → 404
     *   - everyone else (member OR non-member of nft/closed/open) → allowed
     * before this is invoked. The SQL filter then restricts the
     * candidate set to posts carrying `peepso_group_id` post-meta.
     *
     * Phase 2 (per-post visibility teaser): `$publicOnly` is TRUE for
     * non-members (set from the gate's `public_only` flag). When true we
     * restrict the candidate set to `public_group` / `public_all` posts
     * via the visibility INNER JOIN in
     * PeepSoActivityRepository::getActivities — `members_only` and
     * absent-meta posts are EXCLUDED for non-members (the security
     * invariant). When false (a member is reading) no visibility filter
     * is applied, so the member sees every post including members_only.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getGroupFeed(int $viewerId, int $groupId, ?string $cursor = null, int $limit = 20, bool $publicOnly = false): array
    {
        if ($groupId <= 0) {
            return ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
        }

        // Block + blocker exclusions apply in EVERY feed surface — a
        // personal block relationship must hold even inside a shared group.
        $blockExclusions = $viewerId > 0
            ? self::mergeExclusions(
                PeepSoBlockRepository::getBlockedIds($viewerId),
                PeepSoBlockRepository::getBlockerIds($viewerId)
            )
            : [];

        // §O4.1 reputation shadow-limit (caution/risky authors) is a
        // PUBLIC-FLOOR mechanism. Inside an NFT holder group the on-chain
        // gate + membership IS the moderation boundary: a verified holder
        // who was admitted must not be filtered out of the room they
        // qualified for, nor hidden from fellow holders (a low-trust holder
        // would otherwise see their own posts vanish into "empty room").
        // So we drop the reputation exclusion for nft-type groups only —
        // Local / plain groups keep it (their entry bar is lower). Block +
        // the moderation-hidden list still apply everywhere. forGroup() is
        // request-cached (the feed gate already resolved this group), so the
        // lookup is free.
        $ctx        = $this->groupContextResolver->forGroup($groupId);
        $isNftGroup = $ctx !== null && $ctx->isHolderGroup();

        $excluded = $isNftGroup
            ? $blockExclusions
            : self::mergeExclusions(
                $this->reputationRepo->getCautionAndRiskyUserIds(),
                $blockExclusions
            );

        $hidden = $this->hiddenRepo->getAllHiddenIds();

        // Per-post visibility scope. Non-members ($publicOnly === true) may
        // only read posts marked `public_group` / `public_all`; null means
        // "no visibility filter" (member read → all posts incl members_only).
        // The downstream INNER JOIN excludes absent-meta posts for the
        // non-member case, which is the security invariant (absent ⇒
        // members_only ⇒ hidden).
        $visibilityIn = $publicOnly ? ['public_group', 'public_all'] : null;

        $payload = $this->activityFeed->getFeed(
            $viewerId,
            ActivityFeedService::SCOPE_GROUP,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            $groupId,
            null,
            $visibilityIn
        );

        $payload['items'] = $this->hydrationPipeline->hydrate($payload['items'], $viewerId);
        return $payload;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * §4.7.x main-feed group-leak gate — compute the list of group IDs
     * the viewer must NOT see posts from. Subtractive composition:
     *
     *   (every non-open group on the install) - (viewer's memberships)
     *
     * NB: this canonical seam is only invoked from the §F3 single brain
     * here; bcc-core stays unaware of WHY group IDs are excluded —
     * same coupling-avoidance pattern as the existing `excludedAuthorIds`
     * (caution/risky tier) and `excludedActIds` (moderation hide) channels.
     *
     * Anon viewers (id<=0) get the full non-open list back — they
     * cannot be a member of anything, so every closed/secret/NFT-gated
     * group is restricted. Authed viewers get their membership list
     * subtracted out so their own gated-group posts surface in the
     * main feed normally.
     *
     * Both inputs are bounded:
     *  - `getNonOpenGroupIds()` caps at 500 (V1 scale; ~hundreds of
     *    groups across the install)
     *  - `getUserMemberGroupIds()` caps at 1000 here (looser than the
     *    default 200; we want to subtract every membership the viewer
     *    has, not just their first 200, otherwise gated groups they
     *    belong to but happen to fall outside the cap re-leak).
     *
     * Caller passes `null` to bcc-core when this returns `[]` — empty
     * lists short-circuit the SQL exclude branch entirely.
     *
     * @return list<int>
     */
    private static function resolveRestrictedGroupIds(int $viewerId): array
    {
        $nonOpen = PeepSoGroupRepository::getNonOpenGroupIds();
        if ($nonOpen === []) {
            return [];
        }
        if ($viewerId <= 0) {
            return $nonOpen;
        }
        $myGroups = PeepSoGroupRepository::getUserMemberGroupIds($viewerId, 1000);
        if ($myGroups === []) {
            return $nonOpen;
        }
        return array_values(array_diff($nonOpen, $myGroups));
    }

    /**
     * Merge multiple exclusion lists into a deduped, positive-int-only
     * list. Used to layer §O4.1 reputation shadow-limits with §K1 user
     * blocks under the same `excludedAuthorIds` interface.
     *
     * @param list<int> ...$lists
     * @return list<int>
     */
    private static function mergeExclusions(array ...$lists): array
    {
        $set = [];
        foreach ($lists as $list) {
            foreach ($list as $id) {
                $intId = (int) $id;
                if ($intId > 0) {
                    $set[$intId] = true;
                }
            }
        }
        return array_keys($set);
    }
}
