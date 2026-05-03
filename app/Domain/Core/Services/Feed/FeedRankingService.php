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
 * Body-hydration responsibility: this service ALSO owns the post-process
 * step that fills `body` for BCC-owned modules (pull_batch, page_claim).
 * bcc-core's ActivityFeedService returns body=[] for those because they
 * read from bcc-trust sidecar tables (bcc_pull_batches, bcc_pull_meta,
 * bcc_onchain_claims) which bcc-core can't see. Bulk-loaded across the
 * whole feed page so per-page query cost stays bounded.
 *
 * @package BCC\Trust\Core\Services\Feed
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services\Feed;

use BCC\Core\Feed\ActivityFeedService;
use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Repositories\BinderRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\PullBatchRepository;
use BCC\Trust\Core\Repositories\PullMetaRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Core\Support\ReactionTypeRegistry;
use BCC\Trust\Onchain\Repositories\ClaimRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedRankingService
{
    /** §C3 cap for pull_batch top_cards display. */
    private const TOP_CARDS_DISPLAY = 3;

    public function __construct(
        private readonly ActivityFeedService $activityFeed,
        private readonly ReputationRepository $reputationRepo,
        private readonly PullBatchRepository $pullBatchRepo,
        private readonly PullMetaRepository $pullMetaRepo,
        private readonly BinderRepository $binderRepo,
        private readonly PeepSoReactionRepository $reactionRepo,
        private readonly VoteRepository $voteRepo,
        private readonly HiddenActivityRepository $hiddenRepo
    ) {
    }

    /**
     * Hot feed (§F2) — global trending, anonymous-OK, zero-follow fallback.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    public function getHotFeed(?string $cursor = null, int $limit = 20): array
    {
        $excluded = $this->reputationRepo->getCautionAndRiskyUserIds();
        $hidden   = $this->hiddenRepo->getAllHiddenIds();

        $payload = $this->activityFeed->getFeed(
            0,
            ActivityFeedService::SCOPE_FOR_YOU,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden
        );

        $payload['items'] = $this->hydrateBodies($payload['items']);
        // viewerId=0 — anonymous; reaction hydration fills counts but
        // skips viewer_reaction (always null for anon).
        $payload['items'] = $this->hydrateReactions($payload['items'], 0);
        $payload['items'] = $this->hydrateAuthorBadges($payload['items']);
        $payload['items'] = self::hydrateViewerPermissions($payload['items'], 0);
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

        $payload = $this->activityFeed->getFeed(
            $viewerId,
            $scope,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden
        );

        $payload['items'] = $this->hydrateBodies($payload['items']);
        $payload['items'] = $this->hydrateReactions($payload['items'], $viewerId);
        $payload['items'] = $this->hydrateAuthorBadges($payload['items']);
        $payload['items'] = self::hydrateViewerPermissions($payload['items'], $viewerId);
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

        $payload = $this->activityFeed->getActivityForAuthor(
            $authorId,
            $viewerId,
            $cursor,
            $limit,
            $excluded === [] ? null : $excluded,
            $hidden === []   ? null : $hidden
        );

        $payload['items'] = $this->hydrateBodies($payload['items']);
        $payload['items'] = $this->hydrateReactions($payload['items'], $viewerId);
        $payload['items'] = $this->hydrateAuthorBadges($payload['items']);
        $payload['items'] = self::hydrateViewerPermissions($payload['items'], $viewerId);
        return $payload;
    }

    // ──────────────────────────────────────────────────────────────────
    // Body hydration for BCC-owned modules
    // ──────────────────────────────────────────────────────────────────

    /**
     * Post-process feed items: for BCC-owned post_kinds, replace the
     * empty `body` left by ActivityFeedService with kind-specific data
     * read from sidecar tables.
     *
     * Bulk-loads everything in one round-trip per kind — feed pages
     * cap at 50 items, so even worst-case the hydration adds ~3 small
     * queries (bcc_pull_batches, bcc_pull_meta, bcc_onchain_claims).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateBodies(array $items): array
    {
        if ($items === []) {
            return [];
        }

        // ActivityStreamWriter parks the BCC sidecar id on the backing
        // wp_post via post_meta — `act_external_id` itself points at
        // the wp_post for the actor/timestamp/JOIN. Resolve those back
        // to sidecar ids before bulk-loading bodies. One meta-cache
        // prime, then per-id reads hit the cache for free.
        $extIdsAll = [];
        foreach ($items as $item) {
            $extId = is_int($item['external_id'] ?? null) ? $item['external_id'] : 0;
            if ($extId > 0) {
                $extIdsAll[$extId] = true;
            }
        }
        if ($extIdsAll !== []) {
            update_meta_cache('post', array_keys($extIdsAll));
        }

        // Bucket sidecar ids by kind for bulk-loading. Keep the
        // ext→sidecar map so we can stitch bodies back into items.
        $pullBatchExtToSid = [];
        $pageClaimExtToSid = [];
        $reviewExtToSid    = [];
        foreach ($items as $item) {
            $kind  = is_string($item['post_kind'] ?? null) ? $item['post_kind'] : '';
            $extId = is_int($item['external_id'] ?? null) ? $item['external_id'] : 0;
            if ($extId <= 0) {
                continue;
            }
            $sid = (int) get_post_meta($extId, '_bcc_activity_sidecar_id', true);
            if ($sid <= 0) {
                continue;
            }
            if ($kind === 'pull_batch') {
                $pullBatchExtToSid[$extId] = $sid;
            } elseif ($kind === 'page_claim') {
                $pageClaimExtToSid[$extId] = $sid;
            } elseif ($kind === 'review') {
                $reviewExtToSid[$extId] = $sid;
            }
        }

        $pullBatchBodies = $this->loadPullBatchBodies(array_values(array_unique($pullBatchExtToSid)));
        $pageClaimBodies = $this->loadPageClaimBodies(array_values(array_unique($pageClaimExtToSid)));
        $reviewBodies    = $this->loadReviewBodies(array_values(array_unique($reviewExtToSid)));

        $hydrated = [];
        foreach ($items as $item) {
            $kind  = is_string($item['post_kind'] ?? null) ? $item['post_kind'] : '';
            $extId = is_int($item['external_id'] ?? null) ? $item['external_id'] : 0;

            if ($kind === 'pull_batch' && isset($pullBatchExtToSid[$extId], $pullBatchBodies[$pullBatchExtToSid[$extId]])) {
                $item['body'] = $pullBatchBodies[$pullBatchExtToSid[$extId]];
            } elseif ($kind === 'page_claim' && isset($pageClaimExtToSid[$extId], $pageClaimBodies[$pageClaimExtToSid[$extId]])) {
                $item['body'] = $pageClaimBodies[$pageClaimExtToSid[$extId]];
            } elseif ($kind === 'review' && isset($reviewExtToSid[$extId], $reviewBodies[$reviewExtToSid[$extId]])) {
                $item['body'] = $reviewBodies[$reviewExtToSid[$extId]];
            }
            $hydrated[] = $item;
        }
        return $hydrated;
    }

    /**
     * Bulk-load review bodies. Returns map keyed by bcc_trust_votes.id.
     *
     * Body shape (per FeedItemNormalizer's review contract):
     *   {
     *     grade:        'trust' | 'neutral' | 'caution',  // symbolic, not vote_type int
     *     text:         string,                           // the explanation column
     *     page_id:      int,                              // target peepso-page id
     *     page_handle:  string,                           // target page slug
     *     page_name:    string,                           // target page display name
     *     page_kind:    'validator'|'project'|'creator'|'',// drives /v|/p|/c link prefix
     *   }
     *
     * Two queries: votes by id (bulk) + posts by id (WP's get_posts
     * batch). The page lookup is fast — WP's per-process post cache
     * makes repeats free, and the feed page caps at 50 items.
     *
     * @param list<int> $voteIds
     * @return array<int, array<string, mixed>>
     */
    private function loadReviewBodies(array $voteIds): array
    {
        if ($voteIds === []) {
            return [];
        }

        $votes = $this->voteRepo->findManyByIds($voteIds);
        if ($votes === []) {
            return [];
        }

        // Bulk-resolve target page handles. WP's get_posts caches
        // per-process, so per-id lookups are fine after this prime.
        $pageIds = [];
        foreach ($votes as $vote) {
            $pageIds[(int) ($vote->page_id ?? 0)] = true;
        }
        unset($pageIds[0]);
        $pages = [];
        if ($pageIds !== []) {
            /** @var list<\WP_Post> $rows */
            $rows = get_posts([
                'post_type'      => 'any',
                'post__in'       => array_keys($pageIds),
                'posts_per_page' => count($pageIds),
                'post_status'    => 'any',
                // Suppress WP's default ordering so unstable orderings
                // don't shuffle the FK lookup; we re-key by id below.
                'orderby'        => 'post__in',
            ]);
            foreach ($rows as $post) {
                $pages[$post->ID] = $post;
            }
        }

        $bodies = [];
        foreach ($votes as $voteId => $vote) {
            $voteType   = (int) ($vote->vote_type ?? 0);
            $explanation = (string) ($vote->explanation ?? '');
            $pageId     = (int) ($vote->page_id ?? 0);
            $page       = $pages[$pageId] ?? null;

            $pageKind = '';
            if ($page !== null) {
                $rawType = (string) get_post_meta($page->ID, '_bcc_page_type', true);
                if ($rawType !== '') {
                    $pageKind = PageTypeMap::kindForPageType($rawType) ?? '';
                }
            }

            $bodies[$voteId] = [
                'grade'       => self::voteTypeToGrade($voteType),
                'text'        => $explanation,
                'page_id'     => $pageId,
                'page_handle' => $page !== null ? (string) $page->post_name  : '',
                'page_name'   => $page !== null ? (string) $page->post_title : '',
                'page_kind'   => $pageKind,
            ];
        }
        return $bodies;
    }

    /**
     * Convert the integer vote_type stored in bcc_trust_votes back to
     * the symbolic grade key the frontend speaks. Mirror of
     * PostsService::REVIEW_GRADE_TO_VOTE_TYPE.
     */
    private static function voteTypeToGrade(int $voteType): string
    {
        if ($voteType > 0) return 'trust';
        if ($voteType < 0) return 'caution';
        return 'neutral';
    }

    /**
     * Bulk-load pull_batch bodies: snapshot + top 3 card handles per
     * batch. Returns map keyed by bcc_pull_batches.id.
     *
     * Frozen-history rule (§C3): card_count and more_count come from
     * the bcc_pull_batches snapshot taken at emit time, NOT a live
     * COUNT(*) on bcc_pull_meta — subsequent unpulls don't shift the
     * displayed numbers.
     *
     * @param list<int> $batchRowIds
     * @return array<int, array<string, mixed>>
     */
    private function loadPullBatchBodies(array $batchRowIds): array
    {
        if ($batchRowIds === []) {
            return [];
        }

        $batches = $this->pullBatchRepo->findManyByIds($batchRowIds);
        if ($batches === []) {
            return [];
        }

        // Pull all member rows for these batches in one query.
        $batchIds = [];
        foreach ($batches as $batch) {
            $batchIds[] = (string) $batch->batch_id;
        }
        $membersByBatchId = $this->pullMetaRepo->findManyByBatchIds($batchIds);

        // Collect top-3 follow_ids per batch + dedupe across batches
        // for one bulk handle lookup.
        $topFollowIdsPerBatch = [];
        $allFollowIds = [];
        foreach ($membersByBatchId as $batchId => $members) {
            $top = array_slice($members, 0, self::TOP_CARDS_DISPLAY);
            $topIds = [];
            foreach ($top as $row) {
                $followId = (int) $row->follow_id;
                $topIds[] = $followId;
                $allFollowIds[$followId] = true;
            }
            $topFollowIdsPerBatch[$batchId] = $topIds;
        }
        $handleMap = $this->binderRepo->findHandlesForFollowIds(array_keys($allFollowIds));

        // Compose bodies indexed by bcc_pull_batches.id (matches
        // act_external_id at the call site).
        $bodies = [];
        foreach ($batches as $internalId => $batch) {
            $batchIdStr = (string) $batch->batch_id;
            $topIds     = $topFollowIdsPerBatch[$batchIdStr] ?? [];
            $topCards   = [];
            foreach ($topIds as $fid) {
                $topCards[] = [
                    'follow_id'   => $fid,
                    'card_handle' => $handleMap[$fid] ?? '',
                ];
            }

            $bodies[$internalId] = [
                'batch_id'   => $batchIdStr,
                'card_count' => (int) $batch->card_count,
                'more_count' => (int) $batch->more_count,
                'top_cards'  => $topCards,
            ];
        }
        return $bodies;
    }

    // ──────────────────────────────────────────────────────────────────
    // Author badge hydration (§N8 — post-claim OPERATOR chip)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Post-process feed items to flip `author.is_operator = true` on
     * any author who holds a verified operator/creator claim. Single
     * GROUP BY query across all distinct author ids on the page —
     * scales linearly with authors-per-page (capped at 50), not events.
     *
     * The badge is identity-level (not per-post): once you're an
     * operator anywhere, every post you author surfaces with the
     * OPERATOR chip. This matches the §N8 narrative ("operator badge
     * on every post you write here") for the simplest V1 ship — a
     * per-post variant ("posted as the entity, not as the human")
     * needs the §D3 identity-toggle work first.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateAuthorBadges(array $items): array
    {
        if ($items === []) {
            return [];
        }

        // Collect distinct author ids.
        $authorIds = [];
        foreach ($items as $item) {
            $author = is_array($item['author'] ?? null) ? $item['author'] : [];
            $uid    = is_int($author['user_id'] ?? null) ? $author['user_id'] : 0;
            if ($uid > 0) {
                $authorIds[$uid] = true;
            }
        }
        if ($authorIds === []) {
            return $items;
        }

        $operatorSet = ClaimRepository::getOperatorUserIdSet(array_keys($authorIds));
        if ($operatorSet === []) {
            return $items;
        }

        $hydrated = [];
        foreach ($items as $item) {
            $author = is_array($item['author'] ?? null) ? $item['author'] : [];
            $uid    = is_int($author['user_id'] ?? null) ? $author['user_id'] : 0;
            if ($uid > 0 && isset($operatorSet[$uid])) {
                $author['is_operator'] = true;
                $item['author'] = $author;
            }
            $hydrated[] = $item;
        }
        return $hydrated;
    }

    // ──────────────────────────────────────────────────────────────────
    // Reaction hydration (§D5)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Post-process feed items to attach the §D5 `reactions` block. One
     * batched count query + (when viewerId>0) one batched viewer-state
     * query covers the entire page.
     *
     * Output shape on each item per §3.3:
     *   reactions: {
     *     counts: { solid: int, vouch: int, stand_behind: int },
     *     viewer_reaction: 'solid'|'vouch'|'stand_behind'|null
     *   }
     *
     * Defensive posture matches LivingService: when ReactionTypeRegistry
     * isn't seeded yet (fresh install before ReactionSeeder runs), the
     * id→kind map is empty and every item ends up with all-zero counts +
     * null viewer_reaction. Reactions are supplementary chrome on the
     * feed; they MUST NOT block the page from rendering.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateReactions(array $items, int $viewerId): array
    {
        if ($items === []) {
            return [];
        }

        // Build the type_id → kind map once. Empty when reactions
        // haven't been seeded; the loop below leaves the default
        // empty block intact in that case.
        $idToKind = self::reactionIdToKindMap();

        // Extract act_ids from each item's normalized 'feed_<actId>' id.
        // (act_id, not external_id — external_id is module-specific.)
        $actIds  = [];
        $actById = [];
        foreach ($items as $idx => $item) {
            $rawId = is_string($item['id'] ?? null) ? $item['id'] : '';
            if ($rawId === '' || strncmp($rawId, 'feed_', 5) !== 0) {
                continue;
            }
            $actId = (int) substr($rawId, 5);
            if ($actId <= 0) {
                continue;
            }
            $actIds[]         = $actId;
            $actById[$idx]    = $actId;
        }

        if ($actIds === [] || $idToKind === []) {
            return $items;
        }

        $countsByAct = $this->reactionRepo->countsByActIds($actIds);
        $viewerByAct = $viewerId > 0
            ? $this->reactionRepo->viewerReactionsByActIds($viewerId, $actIds)
            : [];

        $hydrated = [];
        foreach ($items as $idx => $item) {
            $actId = $actById[$idx] ?? 0;
            if ($actId <= 0) {
                $hydrated[] = $item;
                continue;
            }

            $counts = ['solid' => 0, 'vouch' => 0, 'stand_behind' => 0];
            foreach ($countsByAct[$actId] ?? [] as $typeId => $count) {
                $kind = $idToKind[$typeId] ?? null;
                if ($kind !== null) {
                    $counts[$kind] = $count;
                }
            }

            $viewerKind = null;
            $viewerType = $viewerByAct[$actId] ?? 0;
            if ($viewerType > 0) {
                $viewerKind = $idToKind[$viewerType] ?? null;
            }

            $item['reactions'] = [
                'counts'          => $counts,
                'viewer_reaction' => $viewerKind,
            ];
            $hydrated[] = $item;
        }
        return $hydrated;
    }

    /**
     * Build a {type_id: kind} reverse lookup from the registry. Returns
     * an empty map when reactions haven't been seeded — callers treat
     * that as "no reactions to hydrate" and leave defaults intact.
     *
     * @return array<int, string>
     */
    private static function reactionIdToKindMap(): array
    {
        $map = [];

        $solidId = ReactionTypeRegistry::solidId();
        if ($solidId !== null) {
            $map[$solidId] = 'solid';
        }

        $vouchId = ReactionTypeRegistry::vouchId();
        if ($vouchId !== null) {
            $map[$vouchId] = 'vouch';
        }

        $standBehindId = ReactionTypeRegistry::standBehindId();
        if ($standBehindId !== null) {
            $map[$standBehindId] = 'stand_behind';
        }

        return $map;
    }

    /**
     * Bulk-load page_claim bodies. Returns map keyed by
     * bcc_onchain_claims.id.
     *
     * @param list<int> $claimIds
     * @return array<int, array<string, mixed>>
     */
    private function loadPageClaimBodies(array $claimIds): array
    {
        if ($claimIds === []) {
            return [];
        }

        $claims = ClaimRepository::findManyByIds($claimIds);
        $bodies = [];
        foreach ($claims as $id => $claim) {
            $role = (string) $claim->claim_role;
            $bodies[$id] = [
                'claim_id'    => (int) $claim->id,
                'entity_type' => (string) $claim->entity_type,
                'entity_id'   => (int) $claim->entity_id,
                'role'        => $role,
                'verified_at' => self::toIso8601((string) ($claim->verified_at ?? '')),
                // Pre-rendered summary per §A2 — frontend renders verbatim.
                // Page name resolution would add a query per feed page;
                // until that's worth it, "this page" stands in.
                'summary'     => $role !== ''
                    ? "Claimed this page as {$role}."
                    : 'Claimed this page.',
            ];
        }
        return $bodies;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private static function toIso8601(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * §K1 Phase B — set `permissions.can_report.allowed` per item based
     * on viewer identity. Reporting is allowed for any authed viewer
     * who isn't the post's author. Anonymous + self-authored posts
     * stay at `allowed: false` (the default).
     *
     * Server-side validation in ContentReportService is the source of
     * truth — this just drives the frontend "Report" affordance
     * visibility per §A2.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function hydrateViewerPermissions(array $items, int $viewerId): array
    {
        if ($items === []) {
            return [];
        }
        $hydrated = [];
        foreach ($items as $item) {
            $author     = is_array($item['author'] ?? null) ? $item['author'] : [];
            $authorId   = is_int($author['user_id'] ?? null) ? $author['user_id'] : 0;
            $canReport  = $viewerId > 0 && $authorId > 0 && $authorId !== $viewerId;

            $perms = is_array($item['permissions'] ?? null) ? $item['permissions'] : [];
            $perms['can_report'] = [
                'allowed'     => $canReport,
                'unlock_hint' => $canReport ? null : ($viewerId > 0 ? null : 'Sign in to report.'),
            ];
            $item['permissions'] = $perms;
            $hydrated[] = $item;
        }
        return $hydrated;
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
