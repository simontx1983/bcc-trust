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
use BCC\Trust\Core\Support\PeepSoFriendGate;
use BCC\Trust\Core\Support\ProfileAccess;
use BCC\Trust\Core\Support\RestrictedGroups;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedRankingService
{
    /**
     * Anon hot-feed payload cache (first page only). The key folds in
     * the HiddenActivityRepository generation counter (§5), so a §K1-C
     * moderation hide/unhide invalidates instantly; everything else —
     * including RANKING ORDER shifting as votes/scores change — is
     * intentionally bounded by the TTL + the minutely
     * `bcc_trust_feed_hot_warm` cron refresh, matching the endpoint's
     * `Cache-Control: max-age=60`. This is deliberate (audit-confirmed
     * 2026-07-10): a trending feed is windowed, not real-time; folding a
     * score/vote generation into the key would bust this cache on every
     * vote and defeat the warm cron. TTL 300s is the upper bound if the
     * warm cron stalls.
     */
    private const HOT_CACHE_GROUP = 'bcc_trust:feed';
    private const HOT_CACHE_TTL   = 300; // 5 min

    /**
     * The one hot-feed page size the warm cron primes. The cache key folds in
     * the limit, so every reader that wants the warmed entry MUST request this
     * exact size (and slice down if it needs fewer). Cold-start previously
     * asked for 2, missing the warmed `:20` entry and rebuilding inline every
     * request. Keep the cron, getHotFeed default, warmHotFeed default, and
     * cold-start all pinned to this constant. [audit L-B6]
     */
    public const HOT_WARM_LIMIT = 20;

    /**
     * Candidate-batch size for the exact mutual-block probe.
     *
     * Well under `getMutuallyBlockedSetOrUnknown()`'s own candidate cap, so
     * that cap can never silently truncate this check — see
     * {@see dropMutuallyBlockedAuthors()}.
     */
    private const BLOCK_PROBE_CHUNK = 100;

    /**
     * F-297: how many FURTHER pages a multi-author feed reads when the exact
     * block filter empties the page it fetched — see
     * {@see fetchPastBlockedPages()}. Bounded, so a viewer who blocks a
     * prolific author costs at most this many extra reads per request.
     */
    private const BLOCK_REFILL_PAGES = 3;

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
     * Payload cache (first page only): the hot feed is ALWAYS built with
     * the anonymous posture (viewer 0), so the fully hydrated
     * `{items, pagination}` payload is viewer-independent and safe to
     * share. `$cursor === null` hits are served from the object cache;
     * cursor pages stay live (rare + cheap once per-item caches are warm).
     *
     * Freshness: a NEW post appears on the cached anon first page within
     * TTL/cron bounds (≤~60s once the minutely `bcc_trust_feed_hot_warm`
     * cron ticks; 300s worst case if it stalls) — the endpoint already
     * declares equivalent staleness via `Cache-Control: public,
     * max-age=60, stale-while-revalidate=120`. Moderation hides are
     * INSTANT: the cache key folds in the HiddenActivityRepository
     * generation counter, so a §K1-C hide/unhide changes the key.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getHotFeed(
        ?string $cursor = null,
        int $limit = self::HOT_WARM_LIMIT,
        int $viewerId = 0
    ): array {
        $payload = $this->hotFeedPayload($cursor, $limit);

        // ⚠⚠ PER-VIEWER FILTER, APPLIED AFTER THE SHARED CACHE AND NEVER
        // WRITTEN BACK INTO IT. The payload above is built with the
        // ANONYMOUS posture (viewer 0) and cached for everyone, which is
        // what makes /feed/hot cheap and publicly cacheable. §K1 mutual
        // invisibility still has to hold for a signed-in reader, so the
        // block filter runs here, on this request's own copy.
        //
        // ⚠ The cache write lives inside hotFeedPayload(), so it is
        // STRUCTURALLY impossible for a filtered, viewer-specific payload to
        // reach the shared cache — rather than merely being careful about
        // the order of two statements. One viewer's filtered feed being
        // served to everyone else would be a far worse bug than the one
        // this fixes, and it is the reason the split exists.
        //
        // ⚠ `$viewerId` 0 (anonymous, and the cron warm path) short-circuits
        // inside the filter, so logged-out behaviour is byte-identical.
        $payload['items'] = self::dropMutuallyBlockedAuthors($payload['items'], $viewerId);

        return $payload;
    }

    /**
     * The shared, anonymous hot-feed payload — exactly the former
     * getHotFeed body, cache and all.
     *
     * ⚠ VIEWER-INDEPENDENT BY CONSTRUCTION, and it must stay that way: its
     * result is stored in a cache shared by every reader, including
     * logged-out ones, and is warmed by cron under
     * {@see warmHotFeed()}. Nothing viewer-specific may enter here.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    private function hotFeedPayload(?string $cursor, int $limit): array
    {
        if ($cursor !== null) {
            return $this->buildHotFeed($cursor, $limit);
        }

        $key    = self::hotFeedCacheKey($limit);
        $cached = wp_cache_get($key, self::HOT_CACHE_GROUP);
        if (is_array($cached) && isset($cached['items'], $cached['pagination'])) {
            /** @var array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}} $cached */
            return $cached;
        }

        $payload = $this->buildHotFeed(null, $limit);
        wp_cache_set($key, $payload, self::HOT_CACHE_GROUP, self::HOT_CACHE_TTL);

        return $payload;
    }

    /**
     * Cron-side warm of the anon hot-feed first page — rebuilds the
     * payload and stores it UNCONDITIONALLY (refresh-on-hit), so the
     * request path above stays a pure cache read between cron ticks.
     * Owned by CronService::warmHotFeed() on `bcc_trust_feed_hot_warm`.
     */
    public function warmHotFeed(int $limit = self::HOT_WARM_LIMIT): void
    {
        $payload = $this->buildHotFeed(null, $limit);
        wp_cache_set(self::hotFeedCacheKey($limit), $payload, self::HOT_CACHE_GROUP, self::HOT_CACHE_TTL);
    }

    /**
     * The pre-cache hot-feed build — exactly the former getHotFeed body.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    private function buildHotFeed(?string $cursor, int $limit): array
    {
        $excluded = $this->reputationRepo->getCautionAndRiskyUserIds();
        $hidden   = $this->hiddenRepo->getAllHiddenIds();

        // ⚠ NO RESTRICTED-GROUP LIST IS COMPUTED HERE, DELIBERATELY.
        //
        // This used to call `resolveRestrictedGroupIds(0)` and pass the
        // result as `$excludedGroupIds`. That parameter is a DOCUMENTED
        // NO-OP in bcc-core — an exclusion passed there filters NOTHING
        // (see the `@param $excludedGroupIds` entry on
        // PeepSoActivityRepository::getActivities, and the "live
        // counterpart" note on `$restrictedGroupIds`). So the call was
        // dead work on every hot-feed rebuild, and worse, it read as
        // though a group gate were being applied right here.
        //
        // The gate that ACTUALLY protects this read is the per-post
        // `_bcc_post_visibility = 'public_all'` requirement, which
        // getActivities applies on every global path where no
        // `$groupGateMode` is passed — this one. A group-tagged post
        // reaches the anonymous hot feed only when explicitly marked for
        // global syndication, which is STRICTER than the
        // "(non-open groups) - (viewer memberships)" subtraction the
        // removed comment described: a post in an OPEN group is withheld
        // too unless it carries `public_all`.
        //
        // Passing null makes that the single visible story. Nothing about
        // what this feed returns changes.
        // §F-02 per-post visibility, in its ANONYMOUS posture, and
        // UNCONDITIONALLY — no viewer id, no friend list, no admin
        // exemption.
        //
        // ⚠⚠ THIS IS THE ONE CALL SITE WHERE THE EXEMPTION MUST NOT BE
        // CONSULTED. This payload is written to a cache shared by every
        // reader (see hotFeedPayload()'s docblock) and warmed by cron. An
        // admin request that reached the exemption here would store an
        // UNGATED hot feed and serve it to everyone — strictly worse than
        // the hole F-02 closes, and invisible in testing unless an admin
        // happens to be the one to miss the cache. Hard-coded `true` is
        // what makes that impossible rather than merely unlikely.
        //
        // The cost is honest and accepted: a signed-in reader's own
        // non-public posts are ABSENT from /feed/hot, because the shared
        // payload knows no viewer. That is a property of the cache, not
        // of the gate — the same reason §K1 block filtering had to move
        // out of here and run per request.
        $payload = $this->activityFeed->getFeed(
            0,
            ActivityFeedService::SCOPE_FOR_YOU,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            null,
            null,
            viewerFriendIds: null,
            enforcePostVisibility: true
        );

        // viewerId=0 — anonymous; reaction hydration fills counts but
        // skips viewer_reaction (always null for anon).
        $payload['items'] = $this->hydrationPipeline->hydrate($payload['items'], 0);
        return $payload;
    }

    /**
     * Cache key for the anon first page: limit-scoped + §5 generation-
     * keyed on the moderation hidden-list (instant invalidation on
     * hide/unhide — stale generations age out via TTL).
     */
    private static function hotFeedCacheKey(int $limit): string
    {
        return 'hot:v1:' . $limit . ':g' . HiddenActivityRepository::getGeneration();
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
     *     (§O4.1 caution/risky shadow-limit) — IDENTICAL call, PLUS the
     *     viewer's mutual blocks (F-297). Extra exclusions only NARROW, so
     *     the subset relation holds; the hot feed has no viewer to block for.
     *   - same `$hidden` set: $this->hiddenRepo->getAllHiddenIds()
     *     (§K1-C moderation hide) — IDENTICAL call.
     *   - same restricted-group posture: NEITHER method computes one, because
     *     `$excludedGroupIds` is a documented NO-OP in bcc-core. Both used to
     *     call `self::resolveRestrictedGroupIds(0)` and pass the result; both
     *     now pass null. ⚠ The parity still holds and still matters — what
     *     holds it is the `public_all` gate on the next bullet, NOT an
     *     exclusion list. Neither method may start passing a REAL
     *     `$restrictedGroupIds`/`$groupGateMode` without the other, or the
     *     invariant breaks.
     *   - neither passes the real viewer's memberships, which would WIDEN the
     *     candidate set to the viewer's own gated-group posts — something the
     *     public hot feed never shows.
     *   - same §F-02 per-post visibility posture: BOTH enforce, and both
     *     enforce UNCONDITIONALLY — neither consults the admin exemption.
     *     The hot feed cannot (its payload is shared by every reader, so
     *     an exempt read would be cached for all), so the tag feed does
     *     not either; see both call sites.
     *     ⚠ This is the one place the subset relation is no longer
     *     literal. The tag feed passes the REAL `$viewerId` — the same
     *     argument the query already needed — so §F-02's own-post branch
     *     admits the VIEWER'S OWN restricted posts here, which the
     *     anonymous hot feed withholds, plus level-30 posts by authors
     *     who friended them (none in production: peepso-friends is not
     *     installed). What the invariant protects is unchanged: no
     *     STRANGER's restricted post can appear here and not there.
     *   - same global-feed visibility gate: `onlyForGroupId = null` →
     *     PeepSoActivityRepository applies the `public_all` LEFT JOIN gate
     *     (group posts surface only when `_bcc_post_visibility = 'public_all'`;
     *     non-group posts pass through).
     *
     * Beyond the §F-02 viewer id noted above, the only difference from
     * getHotFeed is the trailing `$hashtag` argument
     * forwarded to `activityFeed->getFeed(...)`, which is a pure NARROWING
     * predicate (PeepSo's whole-word `#tag` match, shared with the trending
     * recount since bcc-core F-268). A narrowing predicate can only
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

        // Visibility gates — computed IDENTICALLY to getHotFeed (see docblock),
        // which now includes NOT computing a restricted-group list: the
        // `$excludedGroupIds` parameter below is a no-op in bcc-core, so the
        // `public_all` gate is what holds the hot-feed parity. Changing one of
        // the two methods here without the other breaks the stated invariant.
        //
        // F-297: the viewer's mutual blocks are merged into the SQL exclusion
        // too, as getFeed() does. Excluding more authors can only NARROW the
        // gated set, so the hot-feed parity invariant still holds; what it
        // buys is that a tag dominated by an author the viewer blocked no
        // longer reads back as an empty page (the exact filter below used to
        // be the only block filter on this surface).
        $excluded = self::mergeExclusions(
            $this->reputationRepo->getCautionAndRiskyUserIds(),
            $viewerId > 0 ? PeepSoBlockRepository::getBlockedIds($viewerId) : [],
            $viewerId > 0 ? PeepSoBlockRepository::getBlockerIds($viewerId) : []
        );
        $hidden   = $this->hiddenRepo->getAllHiddenIds();

        // §F-02 per-post visibility, enforced UNCONDITIONALLY here too —
        // deliberately NOT through postVisibilityArgs(), so no admin exemption
        // applies on this surface.
        //
        // The reason is the hot-feed parity invariant in the docblock: an
        // exempt reader seeing a restricted post here that /feed/hot
        // withholds would break it, and the hot feed cannot grant the
        // same exemption (its payload is shared — see buildHotFeed()).
        // Of the two ways to make the surfaces agree, this is the one
        // that cannot leak.
        //
        // ⚠ The real `$viewerId` still reaches the gate, because it is
        // the same argument the query already takes. So this feed widens
        // by exactly two things relative to the anonymous hot feed: the
        // viewer's OWN posts, and level-30 posts by authors who friended
        // them (none in production — see ProfileAccess). Neither is a
        // stranger's restricted post, which is what the invariant
        // protects; the docblock above states this rather than leaving
        // the literal wording to carry it.
        $friendIds = $viewerId > 0
            ? (PeepSoFriendGate::viewerFriendIdList($viewerId) ?: null)
            : null;
        $payload = self::fetchPastBlockedPages(
            fn (?string $pageCursor): array => $this->activityFeed->getFeed(
                $viewerId,
                ActivityFeedService::SCOPE_FOR_YOU,
                $pageCursor,
                $limit,
                $excluded === [] ? null : $excluded,
                $hidden === []   ? null : $hidden,
                null,
                null,
                null,
                $hashtag,
                viewerFriendIds: $friendIds,
                enforcePostVisibility: true
            ),
            $cursor,
            $viewerId
        );

        // ⚠⚠ F-54: THIS SURFACE HAD NO BLOCK FILTERING AT ALL, which is a
        // §K1 gap rather than a cap problem. The docblock above says the
        // gates are "computed IDENTICALLY to getHotFeed" — and that is how
        // it happened: the hot feed is ANONYMOUS (`viewerId` 0, globally
        // cached), so it has no block list to apply, and the tag feed was
        // modelled on it while taking a real `$viewerId`.
        //
        // ⇒ That parity is deliberately broken here, because the two
        // methods do not have the same inputs. The reputation and hidden
        // gates stay identical; the viewer-specific one cannot, and could
        // never have been shared with a globally cached anonymous feed.
        // The exact filter runs inside fetchPastBlockedPages() above.

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

        // §F-02 per-post visibility — a post is readable only at the
        // STRICTER of its own act_access and its author's profile level,
        // so turning a profile to "friends" or "only me" narrows posts
        // already written under it. Admins read unenforced; see
        // ProfileAccess::postVisibilityArgs().
        [$friendIds, $enforceF02] = ProfileAccess::postVisibilityArgs($viewerId);

        // ⚠ F-54: EXACT mutual-block filter over each page's authors. The
        // `$excluded` list above is a cheap pre-filter and is NOT sufficient
        // — see dropMutuallyBlockedAuthors(). F-297: a page it empties is
        // read past, see fetchPastBlockedPages().
        $payload = self::fetchPastBlockedPages(
            fn (?string $pageCursor): array => $this->activityFeed->getFeed(
                $viewerId,
                $scope,
                $pageCursor,
                $limit,
                $excluded === [] ? null : $excluded,
                $hidden === []   ? null : $hidden,
                null,
                $excludedGroups === [] ? null : $excludedGroups,
                viewerFriendIds: $friendIds,
                enforcePostVisibility: $enforceF02
            ),
            $cursor,
            $viewerId
        );

        $payload['items'] = $this->hydrationPipeline->hydrate($payload['items'], $viewerId);
        return $payload;
    }

    /**
     * Single-activity permalink (post-detail). Same visibility gates as
     * `getFeed()` for the act-level/author-level channels (§O4.1
     * shadow-limit, §K1 mutual block, §K1 Phase C moderation hide) —
     * computed identically and passed into
     * `ActivityFeedService::getActivityById()` as a post-fetch check,
     * since a single-row-by-id lookup has no SQL `NOT IN` list to narrow.
     *
     * The group-leak gate (`resolveRestrictedGroupIds`) is deliberately
     * NOT recomputed here: on the non-group path it's already inert in
     * `PeepSoActivityRepository::getActivities()` (the per-post
     * `_bcc_post_visibility` gate supersedes it — see that method's
     * docblock), and bcc-core's `getActivityById()` enforces that same
     * gate directly. Mirroring it here would just reproduce dead weight.
     *
     * ⚠ §F-02 IS NOT THE DECIDER FOR A GROUP-TAGGED POST (user decision,
     * 2026-11-12). Inside a group a post follows the GROUP's rules, and
     * profile privacy hides posts on the main feed, the profile and the
     * blog only. So a post the room shows must not 404 by link merely
     * because its author's profile is restricted — see
     * {@see groupTaggedDespiteProfileLevel()} for the second look that
     * implements it, and `getGroupFeed()` for the matching room-side
     * carve-out. The two are deliberately consistent now; change both.
     *
     * @return array<string, mixed>|null
     */
    public function getActivityById(int $actId, int $viewerId): ?array
    {
        $excluded = self::mergeExclusions(
            $this->reputationRepo->getCautionAndRiskyUserIds(),
            $viewerId > 0 ? PeepSoBlockRepository::getBlockedIds($viewerId) : [],
            $viewerId > 0 ? PeepSoBlockRepository::getBlockerIds($viewerId) : []
        );
        $hidden = $this->hiddenRepo->getAllHiddenIds();

        // §F-02 — load-bearing HERE above all. A permalink that served
        // what the feed withholds is the whole reason bcc-core puts this
        // predicate in both readers; enforcing on the feed alone would
        // move the leak rather than close it.
        [$friendIds, $enforceF02] = ProfileAccess::postVisibilityArgs($viewerId);

        $item = $this->activityFeed->getActivityById(
            $actId,
            $viewerId,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            viewerFriendIds: $friendIds,
            enforcePostVisibility: $enforceF02
        );

        // §F-02 group carve-out: a refusal above may have been the
        // author's PROFILE level withholding a post whose room shows it.
        // Only reachable when the gate actually ran ($enforceF02), so an
        // exempt reader costs no second query, and only a GROUP-tagged
        // post can be recovered.
        if ($item === null && $enforceF02) {
            $item = $this->groupTaggedDespiteProfileLevel($actId, $viewerId, $excluded, $hidden);
        }

        if ($item === null) {
            return null;
        }

        // ⚠ F-54: the same exact check, on a single row. This method's
        // contract is already "404 rather than leak", so a blocked author
        // here becomes a 404 exactly as an excluded one does.
        if (self::dropMutuallyBlockedAuthors([$item], $viewerId) === []) {
            return null;
        }

        $hydrated = $this->hydrationPipeline->hydrate([$item], $viewerId);
        return $hydrated[0] ?? null;
    }

    /**
     * Re-ask for a permalink that the §F-02 profile gate refused, and
     * return it ONLY when it is a GROUP-tagged post.
     *
     * Per the 2026-11-12 user decision, a group post's audience is
     * decided by the group, not by its author's profile level. The room
     * (`getGroupFeed()`) does not apply §F-02, so the permalink must not
     * either — otherwise a post a fellow member is reading in the room
     * 404s the moment they share the link to it.
     *
     * ⚠ THIS IS NOT AN UNGATED READ, and the fail-closed property is
     * worth stating precisely. The re-ask drops exactly ONE predicate
     * (§F-02) and keeps every other gate bcc-core applies to a
     * permalink, because they all live inside the same call: the module
     * allowlist, `act_status = publish`, the excluded-author and
     * hidden-activity lists, and — the load-bearing one here —
     * `ActivityFeedService::isVisibleGlobally()`, which admits a
     * group-tagged post only when it is marked `public_all` and the
     * group-syndication rule allows it. A `members_only` or
     * `public_group` post therefore still 404s by link for everyone,
     * member or not, exactly as it did before this method existed. What
     * changes is only that the AUTHOR'S PROFILE LEVEL no longer
     * overrides the group's own answer.
     *
     * A post with no `peepso_group_id` is returned as null, so §F-02's
     * refusal stands untouched on every non-group surface.
     *
     * @param list<int> $excluded
     * @param list<int> $hidden
     * @return array<string, mixed>|null
     */
    private function groupTaggedDespiteProfileLevel(
        int $actId,
        int $viewerId,
        array $excluded,
        array $hidden
    ): ?array {
        $candidate = $this->activityFeed->getActivityById(
            $actId,
            $viewerId,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            // Explicitly inert: bcc-core reads the friend list only when
            // the gate below is true.
            viewerFriendIds: null,
            enforcePostVisibility: false
        );
        if ($candidate === null) {
            return null;
        }

        // The group marker lives on the BACKING wp_post, which is
        // `external_id` — never the act_id. Keying on act_id reads meta
        // off an unrelated low-numbered post (audit H-B1, the same trap
        // FeedHydrationPipeline::hydrateGroupContexts() documents).
        $postId = is_int($candidate['external_id'] ?? null) ? $candidate['external_id'] : 0;
        if ($postId <= 0) {
            return null;
        }

        return (int) get_post_meta($postId, 'peepso_group_id', true) > 0
            ? $candidate
            : null;
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

        // §F-02 per-post visibility. The wall is where the setting is
        // most likely to be TESTED by its owner — and note that this gate
        // and the profile gate are independent: a level-20 profile stays
        // openable by any signed-in viewer while its level-40 posts do
        // not appear on it.
        [$friendIds, $enforceF02] = ProfileAccess::postVisibilityArgs($viewerId);

        $payload = $this->activityFeed->getActivityForAuthor(
            $authorId,
            $viewerId,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            $excludedGroups === [] ? null : $excludedGroups,
            viewerFriendIds: $friendIds,
            enforcePostVisibility: $enforceF02
        );

        // ⚠ F-54: EXACT mutual-block filter over THIS PAGE's authors. The
        // `$excluded` list above is a cheap pre-filter and is NOT sufficient
        // — see dropMutuallyBlockedAuthors().
        $payload['items'] = self::dropMutuallyBlockedAuthors($payload['items'], $viewerId);

        $payload['items'] = $this->hydrationPipeline->hydrate($payload['items'], $viewerId);
        return $payload;
    }

    /**
     * Page stream — the posts written for one validator page. Backs
     * `GET /bcc/v1/pages/{id}/feed`.
     *
     * Shares the author wall's hydration chain and its two user-level
     * exclusion lists, but NOT its third: the author wall also passes
     * `resolveRestrictedGroupIds($viewerId)` (the §4.7.x leak gate) and this
     * path deliberately passes no group exclusion at all.
     *
     * ⚠ That is safe by construction, not by oversight, and the construction
     * is what carries it: `PostsService::createStatus()` refuses a
     * `target_page_id` together with a `group_id` (asserted by
     * `PagePostsIntegrationTest::testPageAndGroupTogetherAreRefusedEvenForTheOperator`),
     * and `stampPageTarget()` is the only writer of `PAGE_TARGET_META`. So a
     * page-marked post always has `group_id = 0` and can never sit inside a
     * closed, secret or NFT-gated group. If anything ever stamps that marker
     * on a group post, this route — `permission_callback => '__return_true'` —
     * has no second line of defence, so add the group exclusion before
     * widening who may write the marker.
     *
     * The candidate set is keyed on the post marker alone, so a post keeps its
     * place after its author stops being the page's operator. Confirming the id
     * is a published validator page is the endpoint's job, before this runs.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getActivityForPage(int $pageId, int $viewerId, ?string $cursor = null, int $limit = 20): array
    {
        $excluded = self::mergeExclusions(
            $this->reputationRepo->getCautionAndRiskyUserIds(),
            $viewerId > 0 ? PeepSoBlockRepository::getBlockedIds($viewerId) : [],
            $viewerId > 0 ? PeepSoBlockRepository::getBlockerIds($viewerId) : []
        );

        $hidden = $this->hiddenRepo->getAllHiddenIds();

        // §F-02 per-post visibility. A page stream is keyed on the post
        // marker, not on who owns the page, so it carries posts by
        // whoever was operator at the time — each one still answers to
        // its own author's profile level.
        [$friendIds, $enforceF02] = ProfileAccess::postVisibilityArgs($viewerId);

        $payload = $this->activityFeed->getActivityForPage(
            $pageId,
            $viewerId,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden,
            viewerFriendIds: $friendIds,
            enforcePostVisibility: $enforceF02
        );

        // ⚠ F-54: EXACT mutual-block filter over THIS PAGE's authors. The
        // `$excluded` list above is a cheap pre-filter and is NOT sufficient
        // — see dropMutuallyBlockedAuthors().
        $payload['items'] = self::dropMutuallyBlockedAuthors($payload['items'], $viewerId);

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
     * Rank Phase 7 (§21.3): `$hallFeed` selects the Hall feed channel.
     * 'ranked' narrows to posts stamped `_bcc_ranked_feed` (everyone
     * may read; POSTING into the channel is what's gated, at write
     * time); 'main' (default) EXCLUDES ranked posts so the two
     * channels never double-serve. Applied on every group-scoped read
     * — outside Halls no post carries the marker, so 'main' is a
     * no-op there and 'ranked' is honestly empty.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getGroupFeed(int $viewerId, int $groupId, ?string $cursor = null, int $limit = 20, bool $publicOnly = false, string $hallFeed = 'main'): array
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

        // ⚠⚠ §F-02 IS DELIBERATELY NOT ENFORCED ON THIS SURFACE. This was
        // the one judgment call in the F-02 posts wiring rather than a
        // mechanical application of it, and it is now a RATIFIED USER
        // DECISION (2026-11-12): inside a group, posts follow the GROUP's
        // own visibility rules, and profile privacy hides posts on the
        // main feed, the profile and the blog only.
        //
        // Inside a group the AUDIENCE IS THE ROOM, and the room already
        // has its own gate: membership (resolved by the endpoint — secret
        // + non-member is a 404) plus the per-post `public_group` /
        // `public_all` filter above for non-members. A profile level is a
        // statement about a PROFILE and its stream, not about a room its
        // owner deliberately joined and posted into.
        //
        // Enforcing here would also be loud rather than subtle, because
        // BCC's own writer stores `act_access = 0`: the effective level
        // of a BCC-written post is its author's PROFILE level alone. A
        // member whose profile reads "only me" would have every post they
        // ever made vanish from the room for everyone else — the "empty
        // room" failure the NFT-group reputation carve-out above already
        // argues against, arriving by a different door.
        //
        // ⚠ PeepSo offers no precedent to defer to: `usr_profile_acc`
        // appears in PeepSo's USER SEARCH only (`usersearch.php`), never
        // in its activity queries, so the stricter-of-the-two ladder is
        // BCC's own policy and this is BCC's own decision.
        //
        // ⚠ THE PERMALINK NOW AGREES, and that was not free — see
        // `groupTaggedDespiteProfileLevel()`. It used to enforce §F-02,
        // so a post a fellow member read in the room could 404 by link
        // when its author's profile was restricted. The permalink now
        // declines to let the author's profile level override the
        // group's answer, which is the same rule this surface applies.
        //
        // ⚠⚠ THE CARVE-OUT STOPS AT THESE TWO SURFACES (user
        // clarification, 2026-11-12). A `public_all` group post that is
        // SYNDICATED OUT of the room — onto /feed, /feed/hot or
        // /feed/tag — KEEPS its author's profile gate, because profile
        // privacy is exactly a statement about the main feed. Only this
        // room and that post's own permalink follow the group's rules.
        // Those three surfaces pass `enforcePostVisibility: true` and
        // are deliberately untouched by the permalink's second look.
        //
        // ⚠ What the permalink still refuses, because it is the GROUP's
        // own rule and not a profile one: a `members_only` /
        // `public_group` post 404s by link for everyone, member or not
        // (bcc-core's `isVisibleGlobally()` admits only `public_all`).
        // The room shows such a post to members; its link does not. That
        // residual gap is bcc-core's permalink-syndication policy, NOT
        // §F-02, and closing it would be a deliberate widening of what
        // the permalink serves.
        // ⚠ F-54: EXACT mutual-block filter over each page's authors. The
        // `$excluded` list above is a cheap pre-filter and is NOT sufficient
        // — see dropMutuallyBlockedAuthors(). F-297: a page it empties is
        // read past, see fetchPastBlockedPages().
        $payload = self::fetchPastBlockedPages(
            fn (?string $pageCursor): array => $this->activityFeed->getFeed(
                $viewerId,
                ActivityFeedService::SCOPE_GROUP,
                $pageCursor,
                $limit,
                $excluded === [] ? null : $excluded,
                $hidden === []   ? null : $hidden,
                $groupId,
                null,
                $visibilityIn,
                null,
                $hallFeed === 'ranked' ? 'ranked' : 'main'
            ),
            $cursor,
            $viewerId
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
     * Caching posture (2026-06-18 perf audit P1-A, closed 2026-07-16):
     *  - the non-open half is generation-cached in bcc-core
     *    (`PeepSoGroupRepository::getNonOpenGroupIds`, busted by the
     *    `peepso_group_privacy` post-meta hooks wired in bcc-core.php)
     *  - the membership half is INTENTIONALLY UNCACHED — see the leak
     *    invariant on `getUserMemberGroupIds`. Do not memoize this
     *    method across requests; a request-scoped memo would be dead
     *    code too (each request calls it at most once per viewer id —
     *    the authed cold-start double-call uses two DIFFERENT ids).
     *
     * @return list<int>
     */
    private static function resolveRestrictedGroupIds(int $viewerId): array
    {
        // Extracted to Support\RestrictedGroups so the profile Blog tab can
        // ask the same question (§11 — one definition of "restricted", not
        // two). This method stays as the feed's call-site name; the five
        // call sites above are untouched. forViewer() is the behaviour that
        // used to live here, byte for byte, including passing the
        // repository's default 500 bound explicitly.
        return RestrictedGroups::forViewer($viewerId);
    }

    /**
     * Drop items whose author is mutually blocked with the viewer — the
     * EXACT check, over this page's authors only.
     *
     * ⚠⚠ WHY THE PRE-FILTER IS NOT ENOUGH (F-54). Every caller above
     * merges `getBlockedIds()` and `getBlockerIds()` into an `$excluded`
     * list that the SQL narrows on. Both are `LIMIT 500` with NO ordering
     * and NO completeness signal, so a viewer who blocks more than 500
     * people simply does not get the 501st excluded, and sees their posts.
     * There is not even a way to tell "exactly 500" from "500 and more".
     *
     * ⚠ AND THEY FAIL OPEN ON A DB ERROR. `$wpdb->get_col()` never
     * returns null; a failed query returns `[]`, which reads as "blocks
     * nobody" — so an unreadable block table showed every blocked author.
     *
     * ⭐ THE CAP IS WHAT MOVES, AND THAT IS THE WHOLE FIX. Asking "which
     * of THESE authors is blocked" bounds the question by PAGE SIZE (at most
     * a few tens) instead of by how many people the viewer blocks
     * (unbounded, and 500+ is an ordinary number for a heavy blocker). The
     * pre-filter stays, as a cheap way to keep obvious noise out of the
     * query; correctness now comes from here.
     *
     * ⚠ FAIL CLOSED. `null` means the block read FAILED, not that nobody
     * is blocked, so the page is dropped rather than risk showing a blocked
     * author. Same rule and same reasoning as
     * {@see \BCC\Trust\Core\Services\MessagesService}'s inbox and thread
     * previews, which withhold every preview on an unreadable block table.
     * An empty feed on a DB fault is ugly, safe, and self-correcting on the
     * next request — and in practice the feed query itself is unlikely to
     * have survived whatever broke this one.
     *
     * ⚠ CHUNKED, deliberately. `getMutuallyBlockedSetOrUnknown()` caps its
     * own candidate list, and the defect being fixed here IS a silent cap,
     * so this must not depend on another one. A page cannot currently reach
     * one chunk (REST clamps `limit` to 50), so the loop runs once; what it
     * buys is that raising a page limit later cannot quietly re-open this.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function dropMutuallyBlockedAuthors(array $items, int $viewerId): array
    {
        return self::keepUnblockedOrNull($items, $viewerId) ?? [];
    }

    /**
     * {@see dropMutuallyBlockedAuthors()}, but a failed block read answers
     * NULL instead of an empty page, so {@see fetchPastBlockedPages()} can
     * tell "everything here was blocked" (read on) from "the block table is
     * unreadable" (fail closed, read nothing more).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>|null
     */
    private static function keepUnblockedOrNull(array $items, int $viewerId): ?array
    {
        if ($viewerId <= 0 || $items === []) {
            return $items;
        }

        $authorIds = self::authorIdsOf($items);
        if ($authorIds === []) {
            return $items;
        }

        $blocked = [];
        foreach (array_chunk($authorIds, self::BLOCK_PROBE_CHUNK) as $chunk) {
            $set = PeepSoBlockRepository::getMutuallyBlockedSetOrUnknown($viewerId, $chunk);
            if ($set === null) {
                return null;
            }
            foreach ($set as $id => $_) {
                $blocked[(int) $id] = true;
            }
        }

        if ($blocked === []) {
            return $items;
        }

        $kept = [];
        foreach ($items as $item) {
            $uid = self::authorIdOf($item);
            if ($uid > 0 && isset($blocked[$uid])) {
                continue;
            }
            $kept[] = $item;
        }

        return $kept;
    }

    /**
     * F-297: fetch a page, apply the exact block filter, and when that leaves
     * NOTHING while the feed says there is more, read on from the page's own
     * `next_cursor` — up to BLOCK_REFILL_PAGES more pages.
     *
     * Without this, a viewer who blocks a prolific author (or more than the
     * pre-filter's 500) could get `items: []` with `has_more: true`, which the
     * client renders as the empty state with no way to load more, although
     * older posts by other authors exist.
     *
     * Only an EMPTIED page is refilled; a short page is returned as is (the
     * client pages on). The cursor of the last page read is returned, so
     * nothing is skipped or repeated. A failed block read stays fail-closed:
     * an empty page, no further reads. The anonymous viewer (0) is never
     * filtered, so this is a single fetch for them.
     *
     * @param callable(?string): array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}} $fetch
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    private static function fetchPastBlockedPages(callable $fetch, ?string $cursor, int $viewerId): array
    {
        $payload = $fetch($cursor);
        for ($refills = 0; ; $refills++) {
            $fetched = $payload['items'];
            $kept    = self::keepUnblockedOrNull($fetched, $viewerId);
            if ($kept === null) {
                $payload['items'] = [];
                return $payload;
            }
            $payload['items'] = $kept;

            $next = $payload['pagination']['next_cursor'] ?? null;
            if ($kept !== []
                || $fetched === []
                || $refills >= self::BLOCK_REFILL_PAGES
                || !($payload['pagination']['has_more'] ?? false)
                || $next === null
                || $next === $cursor
            ) {
                return $payload;
            }
            $cursor  = $next;
            $payload = $fetch($cursor);
        }
    }

    /**
     * Distinct author ids on a page.
     *
     * Same extraction as {@see FeedHydrationPipeline}'s batch resolvers,
     * which is where the shape is established: the author block is
     * pre-hydrated by bcc-core's `ActivityFeedService` (`'id' => (int)
     * $u->ID`) and so is an int by the time it reaches either of us.
     *
     * @param list<array<string, mixed>> $items
     * @return list<int>
     */
    private static function authorIdsOf(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $uid = self::authorIdOf($item);
            if ($uid > 0) {
                $ids[$uid] = true;
            }
        }

        return array_keys($ids);
    }

    /** @param array<string, mixed> $item */
    private static function authorIdOf(array $item): int
    {
        $author = is_array($item['author'] ?? null) ? $item['author'] : [];
        $id     = $author['id'] ?? null;

        // ⚠⚠ A NUMERIC STRING COUNTS, AND THIS IS A FAIL-OPEN GUARD RATHER
        // THAN TOLERANCE FOR ITS OWN SAKE. An `is_int`-only test returns 0
        // for `"42"`, every author id becomes 0, and the block filter then
        // silently matches nobody — a viewer would see blocked authors with
        // no error anywhere.
        //
        // ⚠ The hot-feed path makes that reachable: those items come back
        // through `wp_cache_get()`, so their types survive whatever the
        // object-cache backend does to them on the way out. Serialising
        // backends preserve ints; a backend that round-trips through JSON or
        // a text column does not, and this class is in no position to know
        // which is installed.
        //
        // ⚠ `ctype_digit` rather than `is_numeric`: ids are unsigned
        // integers, and `is_numeric` would also accept `"1e3"`, `" 7"` and
        // `"-1"`. The cast must mean what the string says.
        if (is_int($id)) {
            return $id;
        }

        return is_string($id) && $id !== '' && ctype_digit($id) ? (int) $id : 0;
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
