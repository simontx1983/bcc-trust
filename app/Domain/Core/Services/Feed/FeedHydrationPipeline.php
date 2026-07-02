<?php
/**
 * Feed Hydration Pipeline — the §F3 post-query enrichment chain.
 *
 * Extracted from FeedRankingService (Phase 11 architecture split #1).
 * Behavior-preserving: this class owns the IDENTICAL 8-step hydration
 * chain every feed surface (hot, tag, for-you, author wall, group) ran
 * inline after its source query. FeedRankingService keeps the source
 * query + visibility-exclusion logic and now delegates enrichment here
 * via the single `hydrate()` entrypoint.
 *
 * The chain order is load-bearing — `hydrateGroupContexts` must run
 * before `hydrateCommentCounts` (the latter reads the `group` block the
 * former attaches to gate comment-count visibility). Do not reorder.
 *
 * Body-hydration responsibility: this pipeline owns the post-process
 * step that fills `body` for BCC-owned modules (watch_batch, page_claim,
 * review, photo, gif, blog_excerpt). The per-kind loaders live in the
 * sibling *BodyHydrator classes; this pipeline keeps the
 * bucket-and-dispatch pass plus the mention overlay.
 *
 * @package BCC\Trust\Core\Services\Feed
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services\Feed;

use BCC\Core\Feed\ReactionGrammarMap;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\GifRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\StokeRepository;
use BCC\Trust\Core\Services\AttestationService;
use BCC\Trust\Core\Services\AuthorBadgeResolver;
use BCC\Trust\Core\Services\BlogService;
use BCC\Trust\Core\Services\CommentService;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\Mentions\MentionOverlayService;
use BCC\Trust\Core\Support\ReactionGrammarRegistry;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Onchain\Repositories\ClaimRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedHydrationPipeline
{
    public function __construct(
        private readonly PeepSoReactionRepository $reactionRepo,
        private readonly StokeRepository $stokeRepo,
        private readonly GroupContextResolver $groupContextResolver,
        private readonly CommentRepository $commentRepo,
        private readonly GifRepository $gifRepo,
        private readonly MentionOverlayService $mentionOverlay,
        private readonly AuthorBadgeResolver $authorBadgeResolver,
        private readonly AttestationService $attestationService,
        private readonly BlogService $blogService,
        private readonly WatchBatchBodyHydrator $watchBatchBodyHydrator,
        private readonly ReviewBodyHydrator $reviewBodyHydrator,
        private readonly PhotoBodyHydrator $photoBodyHydrator,
        private readonly GifBodyHydrator $gifBodyHydrator,
        private readonly PageClaimBodyHydrator $pageClaimBodyHydrator
    ) {
    }

    /**
     * Run the canonical 8-step hydration chain over a feed page.
     *
     * The order is load-bearing — bodies first (so mention overlay and
     * comment-count lookups have the final text/external_id in hand),
     * group contexts before comment counts (the comment-count gate reads
     * the `group` block group contexts attach). This is the exact chain
     * every feed surface ran inline before the Phase-11 extraction.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function hydrate(array $items, int $viewerId): array
    {
        $items = $this->hydrateBodies($items);
        $items = $this->hydrateReactions($items, $viewerId);
        $items = $this->hydrateAuthorBadges($items);
        $items = $this->hydrateAuthorRanks($items);
        $items = $this->hydrateAuthorVouch($items, $viewerId);
        $items = $this->hydrateSocialProofReactors($items);
        $items = self::hydrateViewerPermissions($items, $viewerId);
        $items = $this->hydrateGroupContexts($items);
        $items = $this->hydrateCommentCounts($items, $viewerId);
        return $items;
    }

    // ──────────────────────────────────────────────────────────────────
    // Group context hydration (Path A: emit verification metadata,
    // no server-side ranking change)
    // ──────────────────────────────────────────────────────────────────

    /**
     * For each feed item whose backing wp_post is associated with a
     * PeepSo group (via the `peepso_group_id` post-meta key PeepSo
     * writes when a status post is created inside a group), attach a
     * `group` block to the item:
     *
     *   group: {
     *     id: int,
     *     type: 'nft' | 'local' | 'system' | 'user',
     *     verification: { kind: 'on_chain', label: 'On-Chain Verified' } | null
     *   }
     *
     * Items with no group association get no `group` field — absence is
     * the signal "this is not a group post." The frontend can render a
     * verified badge per item or sort/filter client-side.
     *
     * Server-side ranking by verified status is intentionally not
     * applied here — the current ranking layer is recency-only and
     * adding a multiplier without telemetry is premature. See
     * docs/api-contract-v1.md changelog v1.5 (Path A note).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateGroupContexts(array $items): array
    {
        if ($items === []) {
            return [];
        }

        // Extract the underlying act_id for each item — for native
        // PeepSo modules act_id matches wp_posts.ID, which is what
        // PeepSo's `peepso_group_id` post-meta is keyed on. The id is
        // encoded in the public `id` field as `feed_<act_id>`.
        $actIdsByItem = [];
        foreach ($items as $i => $item) {
            $idField = isset($item['id']) && is_string($item['id']) ? $item['id'] : '';
            if (preg_match('/^feed_(\d+)$/', $idField, $m) === 1) {
                $actIdsByItem[$i] = (int) $m[1];
            }
        }
        if ($actIdsByItem === []) {
            return $items;
        }

        update_meta_cache('post', array_values($actIdsByItem));

        $groupIdsByItem = [];
        $allGroupIds   = [];
        foreach ($actIdsByItem as $i => $actId) {
            $gid = (int) get_post_meta($actId, 'peepso_group_id', true);
            if ($gid > 0) {
                $groupIdsByItem[$i]    = $gid;
                $allGroupIds[$gid]     = true;
            }
        }
        if ($allGroupIds === []) {
            return $items;
        }

        $contexts = $this->groupContextResolver->forManyGroups(array_keys($allGroupIds));

        foreach ($groupIdsByItem as $i => $gid) {
            $ctx = $contexts[$gid] ?? null;
            if (!($ctx instanceof GroupContext)) {
                continue;
            }
            $items[$i]['group'] = [
                'id'           => $ctx->groupId,
                'type'         => $ctx->type->value,
                'verification' => $ctx->verification?->toApiResponse(),
            ];
        }

        return $items;
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
     * queries (bcc_watch_batches, bcc_watch_meta, bcc_onchain_claims).
     *
     * ┌─── post_kind precedence rules (v1.5) ─────────────────────────┐
     * │                                                                │
     * │ The post_kind a FeedItem ships with is resolved in two layers:│
     * │                                                                │
     * │   1. **Module-default mapping.** FeedItemNormalizer looks up   │
     * │      `MODULE_TO_KIND[act_module_id]` to assign the base kind   │
     * │      from PeepSo's stored module ID (status=1, photo=4, etc.).│
     * │                                                                │
     * │   2. **Metadata semantic override.** This pass reads post_meta │
     * │      on the wp_post and may *promote* a base kind to a more   │
     * │      specific semantic kind when discriminating metadata is   │
     * │      present. Example: a status post (kind=1, base='status')  │
     * │      with `peepso_giphy` post_meta is promoted to 'gif'.      │
     * │                                                                │
     * │ **Metadata overrides take precedence over module defaults.**  │
     * │ This is the canonical rule — when future kinds (poll, mood,   │
     * │ celebration, scheduled-state overlays, etc.) need similar     │
     * │ semantic discrimination, they extend this layer rather than   │
     * │ inventing parallel pipelines or polluting MODULE_TO_KIND with │
     * │ meta-aware entries.                                            │
     * │                                                                │
     * │ The override happens BEFORE the bucket-and-bulk-load pass so  │
     * │ promoted items get their body hydrated under the correct kind.│
     * │                                                                │
     * └────────────────────────────────────────────────────────────────┘
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

        // ─── Layer-2: metadata semantic override ─────────────────────
        // GIF posts arrive here with kind='status' (PeepSo's giphy
        // plugin doesn't stamp a distinct module_id; status and GIF
        // share act_module_id=1). Promote them to kind='gif' when
        // the wp_post carries a `peepso_giphy` post_meta. The
        // GifRepository batch-reads via the now-warm meta cache.
        $statusExtIds = [];
        foreach ($items as $item) {
            $kind  = is_string($item['post_kind'] ?? null) ? $item['post_kind'] : '';
            $extId = is_int($item['external_id'] ?? null) ? $item['external_id'] : 0;
            if ($kind === 'status' && $extId > 0) {
                $statusExtIds[] = $extId;
            }
        }
        $gifUrlByExtId = $this->gifRepo->findByPostIds($statusExtIds);
        if ($gifUrlByExtId !== []) {
            foreach ($items as $i => $item) {
                $extId = is_int($item['external_id'] ?? null) ? $item['external_id'] : 0;
                if (isset($gifUrlByExtId[$extId])) {
                    $items[$i]['post_kind'] = 'gif';
                }
            }
        }

        // Bucket sidecar ids by kind for bulk-loading. Keep the
        // ext→sidecar map so we can stitch bodies back into items.
        $watchBatchExtToSid = [];
        $pageClaimExtToSid = [];
        $reviewExtToSid    = [];
        // Photo posts use the wp_post.ID directly as the lookup key
        // (no sidecar — the photo lives in peepso_photos keyed by
        // pho_post_id = wp_post.ID). Collect external_ids only.
        $photoExtIds = [];
        // GIF posts (post-promotion above) — body comes from the
        // gifUrlByExtId map already in hand; we don't need a second
        // bucket pass, just track the ext_ids that need a body shape.
        $gifExtIds = [];
        // §D6 blog excerpts — like photos, the wp_post IS the body
        // source-of-truth (post_title/post_excerpt/post_content plus
        // sidecar post_meta). No separate sidecar table. Collect
        // external_ids and delegate to BlogService.
        $blogExtIds = [];
        foreach ($items as $item) {
            $kind  = is_string($item['post_kind'] ?? null) ? $item['post_kind'] : '';
            $extId = is_int($item['external_id'] ?? null) ? $item['external_id'] : 0;
            if ($extId <= 0) {
                continue;
            }
            if ($kind === 'photo') {
                $photoExtIds[$extId] = true;
                continue;
            }
            if ($kind === 'gif') {
                $gifExtIds[$extId] = true;
                continue;
            }
            if ($kind === 'blog_excerpt') {
                $blogExtIds[$extId] = true;
                continue;
            }
            $sid = (int) get_post_meta($extId, '_bcc_activity_sidecar_id', true);
            if ($sid <= 0) {
                continue;
            }
            if ($kind === 'watch_batch') {
                $watchBatchExtToSid[$extId] = $sid;
            } elseif ($kind === 'page_claim') {
                $pageClaimExtToSid[$extId] = $sid;
            } elseif ($kind === 'review') {
                $reviewExtToSid[$extId] = $sid;
            }
        }

        $watchBatchBodies = $this->watchBatchBodyHydrator->loadWatchBatchBodies(array_values(array_unique($watchBatchExtToSid)));
        $pageClaimBodies = $this->pageClaimBodyHydrator->loadPageClaimBodies(array_values(array_unique($pageClaimExtToSid)));
        $reviewBodies    = $this->reviewBodyHydrator->loadReviewBodies(array_values(array_unique($reviewExtToSid)));
        $photoBodies     = $this->photoBodyHydrator->loadPhotoBodies(array_keys($photoExtIds));
        $gifBodies       = $this->gifBodyHydrator->loadGifBodies(array_keys($gifExtIds), $gifUrlByExtId);
        $blogBodies      = $this->loadBlogBodies(array_keys($blogExtIds));

        $hydrated = [];
        foreach ($items as $item) {
            $kind  = is_string($item['post_kind'] ?? null) ? $item['post_kind'] : '';
            $extId = is_int($item['external_id'] ?? null) ? $item['external_id'] : 0;

            if ($kind === 'watch_batch' && isset($watchBatchExtToSid[$extId], $watchBatchBodies[$watchBatchExtToSid[$extId]])) {
                $item['body'] = $watchBatchBodies[$watchBatchExtToSid[$extId]];
            } elseif ($kind === 'page_claim' && isset($pageClaimExtToSid[$extId], $pageClaimBodies[$pageClaimExtToSid[$extId]])) {
                $item['body'] = $pageClaimBodies[$pageClaimExtToSid[$extId]];
            } elseif ($kind === 'review' && isset($reviewExtToSid[$extId], $reviewBodies[$reviewExtToSid[$extId]])) {
                $item['body'] = $reviewBodies[$reviewExtToSid[$extId]];
            } elseif ($kind === 'photo' && isset($photoBodies[$extId])) {
                $item['body'] = $photoBodies[$extId];
            } elseif ($kind === 'gif' && isset($gifBodies[$extId])) {
                $item['body'] = $gifBodies[$extId];
            } elseif ($kind === 'blog_excerpt' && isset($blogBodies[$extId])) {
                $item['body'] = $blogBodies[$extId];
            }
            $hydrated[] = $item;
        }

        // §3.3.12 — Layer-3: mentions[] overlay.
        //
        // Apply AFTER body stitching so we have the final raw text in
        // hand (status bodies arrive pre-hydrated with `text`; photo /
        // gif bodies just got their `caption` filled). One batched
        // overlay across the whole page primes the user-meta cache
        // once; per-row resolution is then in-memory.
        //
        // Range offsets reference the raw stored body text per the
        // §3.3.12 invariant — the overlay never modifies the text
        // itself, only emits ranges *into* it.
        return $this->applyMentionOverlay($hydrated);
    }

    /**
     * Append `mentions: Mention[]` to every status / photo / gif body,
     * keyed off the raw text/caption field. Bodies that have no
     * mention tokens get `mentions: []` for shape stability — clients
     * can blindly read `body.mentions` without checking `kind`.
     *
     * Other post_kinds (review, watch_batch, page_claim, signal,
     * project_drop, nft_drop, blog_excerpt, dispute_signed) do NOT
     * carry mentions in V1d. Their bodies are unchanged.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function applyMentionOverlay(array $items): array
    {
        if ($items === []) {
            return [];
        }

        // Bucket text by pseudo-key (the index in $items) — we don't
        // need post-id-keyed batching here because each item's body
        // text is independent. The overlay's batched user-resolution
        // is what saves work; per-content extraction is cheap.
        $contentByKey = [];
        foreach ($items as $i => $item) {
            $kind = is_string($item['post_kind'] ?? null) ? $item['post_kind'] : '';
            if ($kind !== 'status' && $kind !== 'photo' && $kind !== 'gif') {
                continue;
            }
            $body = is_array($item['body'] ?? null) ? $item['body'] : [];
            $text = self::resolveMentionableText($kind, $body);
            if ($text !== '') {
                $contentByKey[$i] = $text;
            }
        }

        $overlayByKey = $this->mentionOverlay->buildOverlayBatch($contentByKey);

        $out = [];
        foreach ($items as $i => $item) {
            $kind = is_string($item['post_kind'] ?? null) ? $item['post_kind'] : '';
            if ($kind === 'status' || $kind === 'photo' || $kind === 'gif') {
                $body = is_array($item['body'] ?? null) ? $item['body'] : [];
                $body['mentions'] = $overlayByKey[$i] ?? [];
                $item['body'] = $body;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Pull the mentionable raw text out of a body for overlay
     * extraction. Status posts use `text`; photo + gif use `caption`
     * (which can be null on photo-only / GIF-only posts — coerce to
     * '' so the overlay extractor short-circuits).
     *
     * @param array<string, mixed> $body
     */
    private static function resolveMentionableText(string $kind, array $body): string
    {
        if ($kind === 'status') {
            return is_string($body['text'] ?? null) ? $body['text'] : '';
        }
        // photo + gif both use `caption`, which is `string|null`.
        $caption = $body['caption'] ?? null;
        return is_string($caption) ? $caption : '';
    }

    /**
     * §D6 blog body hydration for the Floor context.
     *
     * The wp_post is the source-of-truth (post_title, post_excerpt,
     * post_content) plus the post-meta + chain-tag sidecars
     * (`_bcc_blog_category`, `_bcc_blog_tags`, `_bcc_blog_disclosure`,
     * `_thumbnail_id`, and the `bcc_blog_chain_tags` join).
     *
     * `BlogService::hydrateForPostId` carries the full body shape;
     * here we pass `$includeFullText = false` so the Floor payload
     * stays small (Floor renders excerpt + chips + title only; the
     * full body lives on the blog tab). Per-row reads — V1.5 should
     * batch through BlogChainTagRepository::findByPostIds + a single
     * ChainRepository fetch when blog excerpts go multi-author on
     * the Floor.
     *
     * @param list<int> $postIds
     * @return array<int, array<string, mixed>>
     */
    private function loadBlogBodies(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $out */
        $out = [];
        foreach ($postIds as $postId) {
            $out[$postId] = $this->blogService->hydrateForPostId($postId, false);
        }
        return $out;
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
            $uid    = is_int($author['id'] ?? null) ? $author['id'] : 0;
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
            $uid    = is_int($author['id'] ?? null) ? $author['id'] : 0;
            if ($uid > 0 && isset($operatorSet[$uid])) {
                $author['is_operator'] = true;
                $item['author'] = $author;
            }
            $hydrated[] = $item;
        }
        return $hydrated;
    }

    // ──────────────────────────────────────────────────────────────────
    // Author rank-chip hydration (Sprint 1 Identity Grammar cohesion)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Overlay rank-chip fields on every author block: reputation_tier,
     * reputation_tier_label, card_tier, tier_label, rank_label. The
     * rank_label is now level-derived (not tier-derived); reputation_tier_label
     * is the honest member trust chip. Server-resolved per §A2 so the
     * frontend's AuthorBadge never derives card_tier from
     * reputation_tier client-side.
     *
     * This bcc-trust layer is where the chip fields appear — bcc-core's
     * ActivityFeedService::hydrateAuthors emits card_tier:null /
     * rank_label:null sentinels (it can't see the trust read model)
     * and this hydrator overwrites them with real values. Same
     * pattern as `hydrateAuthorBadges` (operator chip) — bcc-trust
     * is the only place that knows about trust state.
     *
     * Bounded: one batched query inside AuthorBadgeResolver
     * (ReputationRepository::getTiersForUsers) regardless of items
     * count; feed page cap is 50, comment drawer cap is 50.
     *
     * NOTE: ActivityFeedService emits `author.id` (the user id) — this
     * hydrator reads from `id` to match what's actually on the wire.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateAuthorRanks(array $items): array
    {
        if ($items === []) {
            return [];
        }

        // Collect distinct positive author ids from the payload's
        // emitted `author.id` field.
        $authorIds = [];
        foreach ($items as $item) {
            $author = is_array($item['author'] ?? null) ? $item['author'] : [];
            $uid    = is_int($author['id'] ?? null) ? $author['id'] : 0;
            if ($uid > 0) {
                $authorIds[$uid] = true;
            }
        }
        if ($authorIds === []) {
            return $items;
        }

        $badgeMap = $this->authorBadgeResolver->resolveForUsers(array_keys($authorIds));

        $hydrated = [];
        foreach ($items as $item) {
            $author = is_array($item['author'] ?? null) ? $item['author'] : [];
            $uid    = is_int($author['id'] ?? null) ? $author['id'] : 0;
            if ($uid > 0 && isset($badgeMap[$uid])) {
                $badge = $badgeMap[$uid];
                $author['reputation_tier']       = $badge['reputation_tier'];
                $author['reputation_tier_label'] = $badge['reputation_tier_label'];
                $author['card_tier']             = $badge['card_tier'];
                $author['tier_label']            = $badge['tier_label'];
                $author['rank_label']            = $badge['rank_label'];
                $item['author'] = $author;
            }
            $hydrated[] = $item;
        }
        return $hydrated;
    }

    /**
     * Overlay the viewer's per-author vouch state + can-vouch permission onto
     * each item's `author` block — the data behind the feed-byline Vouch
     * toggle (vouch is author credibility, not a post reaction).
     *
     * Batched in one bounded IN-list read across the page's distinct authors
     * (§4 no-N+1), via AttestationService::getViewerVouchStateForAuthors.
     * Anon viewers get an empty state map → the fields are simply not emitted,
     * keeping the anon payload byte-identical to the pre-vouch shape.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateAuthorVouch(array $items, int $viewerId): array
    {
        if ($items === [] || $viewerId <= 0) {
            return $items;
        }

        $authorIds = [];
        foreach ($items as $item) {
            $author = is_array($item['author'] ?? null) ? $item['author'] : [];
            $uid    = is_int($author['id'] ?? null) ? $author['id'] : 0;
            if ($uid > 0) {
                $authorIds[$uid] = true;
            }
        }
        if ($authorIds === []) {
            return $items;
        }

        $vouchState = $this->attestationService->getViewerVouchStateForAuthors(
            $viewerId,
            array_keys($authorIds)
        );
        if ($vouchState === []) {
            return $items;
        }

        $hydrated = [];
        foreach ($items as $item) {
            $author = is_array($item['author'] ?? null) ? $item['author'] : [];
            $uid    = is_int($author['id'] ?? null) ? $author['id'] : 0;
            if ($uid > 0 && isset($vouchState[$uid])) {
                $author['viewer_attestation'] = $vouchState[$uid]['viewer_attestation'];
                $author['can_vouch']          = $vouchState[$uid]['can_vouch'];
                $item['author'] = $author;
            }
            $hydrated[] = $item;
        }
        return $hydrated;
    }

    // ──────────────────────────────────────────────────────────────────
    // Social-proof reactor hydration (Sprint 1 — stacked-avatar rail)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Attach up to 3 most-recent unique reactors to each item's
     * `social_proof` block as `recent_reactors[]`. Drives the stacked
     * <Avatar size="xs" /> faces on ReactionRail (replaces the
     * text-only "X and 3 others reacted" headline as primary signal).
     *
     * One batched query (PeepSoReactionRepository::
     * recentReactorIdsByActIds) using ROW_NUMBER() OVER PARTITION
     * to bound the row count at `count(actIds) × 3` — capped at
     * 50 × 3 = 150 rows per feed page. Per §4 (bounded queries) the
     * LIMIT is implicit in the partitioning function.
     *
     * Hydrates identity (handle/display_name/avatar_url) via
     * get_users batched call + get_user_meta for the bcc_handle.
     *
     * Defensive posture: an act with no reactions stays without a
     * `recent_reactors` field — absence signals "no reactors yet" to
     * the frontend, which gracefully falls back to the headline (or
     * to nothing when headline is also absent). Items that already
     * carry a `social_proof.headline` get `recent_reactors` merged in.
     * Items with no social_proof at all get `social_proof: {
     * headline: null, recent_reactors: [...] }` constructed.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateSocialProofReactors(array $items): array
    {
        if ($items === []) {
            return [];
        }

        // Extract act_ids the same way hydrateReactions does — from
        // the normalized 'feed_<actId>' id field.
        $actIds  = [];
        $actByItem = [];
        foreach ($items as $idx => $item) {
            $rawId = is_string($item['id'] ?? null) ? $item['id'] : '';
            if ($rawId === '' || strncmp($rawId, 'feed_', 5) !== 0) {
                continue;
            }
            $actId = (int) substr($rawId, 5);
            if ($actId <= 0) {
                continue;
            }
            $actIds[]            = $actId;
            $actByItem[$idx]     = $actId;
        }
        if ($actIds === []) {
            return $items;
        }

        $reactorsByAct = $this->reactionRepo->recentReactorIdsByActIds($actIds, 3);
        if ($reactorsByAct === []) {
            return $items;
        }

        // Collect all distinct reactor user_ids across the page so we
        // can batch the identity lookup. Cap is 50 acts × 3 = 150
        // reactors max — well under any get_users practical limit.
        $userIdSet = [];
        foreach ($reactorsByAct as $uids) {
            foreach ($uids as $uid) {
                if ($uid > 0) {
                    $userIdSet[$uid] = true;
                }
            }
        }
        if ($userIdSet === []) {
            return $items;
        }
        $reactorUserIds = array_keys($userIdSet);

        $identityByUser = self::hydrateReactorIdentities($reactorUserIds);

        $hydrated = [];
        foreach ($items as $idx => $item) {
            $actId = $actByItem[$idx] ?? 0;
            $uids  = $actId > 0 ? ($reactorsByAct[$actId] ?? []) : [];
            if ($uids === []) {
                $hydrated[] = $item;
                continue;
            }

            $reactors = [];
            foreach ($uids as $uid) {
                $row = $identityByUser[$uid] ?? null;
                if ($row === null) {
                    continue;
                }
                $reactors[] = $row;
            }
            if ($reactors === []) {
                $hydrated[] = $item;
                continue;
            }

            $existing = is_array($item['social_proof'] ?? null) ? $item['social_proof'] : null;
            if ($existing === null) {
                $item['social_proof'] = [
                    'headline'        => null,
                    'recent_reactors' => $reactors,
                ];
            } else {
                $existing['recent_reactors'] = $reactors;
                $item['social_proof'] = $existing;
            }

            $hydrated[] = $item;
        }
        return $hydrated;
    }

    /**
     * Batch-hydrate reactor identity (handle/display_name/avatar_url)
     * from a list of user_ids. Single get_users() roundtrip + one
     * meta-cache prime; per-user get_user_meta reads hit memory after
     * the prime fires.
     *
     * Each returned row matches the §2.2 SocialProof reactor shape
     * the frontend's FeedSocialProofReactor type encodes.
     *
     * @param list<int> $userIds
     * @return array<int, array{handle: string, display_name: string, avatar_url: string}>
     */
    private static function hydrateReactorIdentities(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $users = get_users([
            'include' => $userIds,
            'fields'  => ['ID', 'display_name', 'user_login'],
            'number'  => count($userIds),
        ]);

        // Prime the user_meta cache for bcc_handle reads — one query
        // instead of one per user.
        update_meta_cache('user', array_map(static fn ($u) => (int) $u->ID, $users));

        $out = [];
        foreach ($users as $u) {
            $id = (int) $u->ID;
            if ($id <= 0) {
                continue;
            }
            $handleMeta = get_user_meta($id, 'bcc_handle', true);
            $handle = is_string($handleMeta) && $handleMeta !== ''
                ? $handleMeta
                : (string) $u->user_login;

            $displayName = is_string($u->display_name) && $u->display_name !== ''
                ? $u->display_name
                : (string) $u->user_login;

            // Cached, shared seam (§11). get_avatar_url() here runs through
            // PeepSo's get_avatar_url filter, which constructs a PeepSoUser
            // and calls get_avatar() — the same per-user peepso_users SELECT
            // + usr_avatar_custom write the member-card path already routes
            // through bcc-core PeepSoMediaCache. filter_avatar resolves
            // get_avatar() ('full'), identical to what avatarUrl() returns,
            // so this is a behavior-preserving swap that also aligns the feed
            // with the "resolve PeepSo directly" convention CardViewService
            // documents.
            $avatarUrl = \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrl($id);

            $out[$id] = [
                'handle'       => $handle,
                'display_name' => $displayName,
                'avatar_url'   => $avatarUrl,
            ];
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────
    // Reaction hydration (§D5)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Post-process feed items to attach the §2.11 `reactions` block.
     * Grammar-aware (v1.5): every item's reactions block carries a
     * `kind_grammar` discriminator and a counts dict zero-filled for
     * the kinds that grammar exposes. The grammar comes from the
     * item's post_kind via ReactionGrammarMap.
     *
     * One batched count query + (when viewerId>0) one batched
     * viewer-state query covers the entire page, regardless of how
     * many distinct grammars are present — peepso_reactions stores
     * every kind in one table, so we read all reaction-type IDs at
     * once and group by grammar in PHP.
     *
     * Output shape per item (api-contract-v1.md §2.11):
     *   reactions: {
     *     kind_grammar: 'trust' | 'social' | 'tribal',
     *     counts: { <kind>: int, ... }      // keys depend on grammar
     *     viewer_reaction: <kind> | null,   // belongs to grammar
     *     heat_stage: int,                  // 1-5, Stoke aggregate — additive, not grammar-gated
     *     viewer_has_stoked: bool,          // Stoke personal axis — additive
     *     stoke_count: int                  // Stoke public total — additive
     *   }
     * heat_stage/viewer_has_stoked/stoke_count are Stoke's fields —
     * purely additive on top of the legacy trio above (the rail stopped
     * rendering kind_grammar/counts/viewer_reaction, it didn't remove
     * them from the wire contract).
     *
     * Defensive posture matches LivingService: when reaction kinds
     * can't be resolved (fresh install before ReactionSeeder runs;
     * PeepSo defaults missing on a non-English locale), the id→kind
     * map is partial or empty and items end up with the grammar's
     * zero-filled counts + null viewer_reaction. Reactions are
     * supplementary chrome — they MUST NOT block the page from
     * rendering.
     *
     * Cross-grammar viewer_reaction guard: if a viewer reacted with
     * a kind that doesn't belong to the post's grammar (only possible
     * via a stale cached reaction or a direct DB write), that reaction
     * is ignored — viewer_reaction comes back null. The rail then
     * shows them as "not yet reacted" rather than rendering a kind
     * that has no rail entry. The endpoint enforces grammar-correct
     * writes going forward.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateReactions(array $items, int $viewerId): array
    {
        if ($items === []) {
            return [];
        }

        // Build the type_id → kind map once across BCC-seeded + PeepSo
        // defaults. Empty (or partial) when not all kinds are
        // resolvable; the loop below leaves missing-kind counts at 0.
        $idToKind = ReactionGrammarRegistry::idToKindMap();

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

        if ($actIds === []) {
            return $items;
        }

        // Even when $idToKind is empty (unseeded install), we still
        // overwrite each item's reactions with the grammar-correct
        // zero shape — leaving the contract-correct kind_grammar
        // discriminator on every item is more important than skipping
        // a no-op count loop.
        $countsByAct = $idToKind === []
            ? []
            : $this->reactionRepo->countsByActIds($actIds);
        $viewerByAct = ($viewerId > 0 && $idToKind !== [])
            ? $this->reactionRepo->viewerReactionsByActIds($viewerId, $actIds)
            : [];

        // Stoke (additive — see schema-stokes.php for why this is its
        // own table rather than a peepso_reactions kind). heat_stage and
        // stoke_count are public; viewer_has_stoked only needs a query
        // for authed viewers.
        $heatByAct  = $this->stokeRepo->heatByActIds($actIds);
        $countByAct = $this->stokeRepo->countsByActIds($actIds);
        $viewerStokedAct = $viewerId > 0
            ? $this->stokeRepo->viewerStokedActIds($viewerId, $actIds)
            : [];

        $hydrated = [];
        foreach ($items as $idx => $item) {
            $actId = $actById[$idx] ?? 0;
            if ($actId <= 0) {
                $hydrated[] = $item;
                continue;
            }

            $postKind = is_string($item['post_kind'] ?? null) ? $item['post_kind'] : 'status';
            $grammar  = ReactionGrammarMap::grammarFor($postKind);
            $counts   = ReactionGrammarMap::emptyCountsFor($grammar);

            foreach ($countsByAct[$actId] ?? [] as $typeId => $count) {
                $kind = $idToKind[$typeId] ?? null;
                if ($kind !== null && array_key_exists($kind, $counts)) {
                    $counts[$kind] = $count;
                }
            }

            $viewerKind = null;
            $viewerType = $viewerByAct[$actId] ?? 0;
            if ($viewerType > 0) {
                $candidate = $idToKind[$viewerType] ?? null;
                if ($candidate !== null
                    && ReactionGrammarMap::kindBelongsToGrammar($candidate, $grammar)
                ) {
                    $viewerKind = $candidate;
                }
            }

            $item['reactions'] = [
                'kind_grammar'       => $grammar,
                'counts'             => $counts,
                'viewer_reaction'    => $viewerKind,
                'heat_stage'         => $heatByAct[$actId] ?? 1,
                'viewer_has_stoked'  => $viewerStokedAct[$actId] ?? false,
                'stoke_count'        => $countByAct[$actId] ?? 0,
            ];
            $hydrated[] = $item;
        }
        return $hydrated;
    }

    // ──────────────────────────────────────────────────────────────────
    // Viewer-permission hydration (§K1 Phase B — Report affordance)
    // ──────────────────────────────────────────────────────────────────

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
            $authorId   = is_int($author['id'] ?? null) ? $author['id'] : 0;
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

    // ──────────────────────────────────────────────────────────────────
    // Comment-count hydration (v1.5)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Attach `comment_count: int` to every feed item via one batched
     * COUNT(*) GROUP BY across all parent posts on the page.
     *
     * The lookup key is `external_id` — for status posts this is the
     * parent's wp_posts.ID directly; for BCC-owned modules
     * (review/watch_batch/page_claim/blog) this is the sidecar
     * `peepso-activity-status` wp_posts.ID created by
     * ActivityStreamWriter. Comments on either kind store the same
     * `act_comment_object_id`, so the same join works.
     *
     * Holder-Groups defense-in-depth: items with a `group` block
     * (set immediately upstream by hydrateGroupContexts) have their
     * comment_count zeroed when the viewer isn't a member of that
     * group. The upstream feed query (PeepSoActivityRepository::
     * getActivities) doesn't filter by group membership, so without
     * this gate a non-member could see comment-count signal on a
     * gated post they shouldn't even be aware exists. The leak
     * surface is only this slice (counts), but the same membership
     * check is the right shape if/when other gated fields surface.
     * Reuses CommentService::READ_ALLOWED_STATUSES as the single
     * source of truth for "can this viewer read this group's
     * content."
     *
     * Other defensive posture: if the repo returns no counts
     * (peepso deactivated, or genuinely zero comments page-wide),
     * every item just gets `comment_count: 0`. The frontend's count
     * chip is still rendered — just with no badge — so the
     * discoverability of the drawer is preserved.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateCommentCounts(array $items, int $viewerId): array
    {
        if ($items === []) {
            return [];
        }

        // Build the gated-skip set: external_ids the viewer must NOT
        // see comment counts for. One getMembershipStatus query per
        // gated item; pages cap at 50 and gated items are a subset,
        // so the worst-case query count is bounded.
        $gatedSkip = [];
        foreach ($items as $item) {
            $groupId = isset($item['group']) && is_array($item['group'])
                ? (int) ($item['group']['id'] ?? 0)
                : 0;
            if ($groupId <= 0) {
                continue;
            }
            $eid = (int) ($item['external_id'] ?? 0);
            if ($eid <= 0) {
                continue;
            }
            if ($viewerId <= 0) {
                $gatedSkip[$eid] = true;
                continue;
            }
            $status = PeepSoGroupRepository::getMembershipStatus($viewerId, $groupId);
            if ($status === null
                || !in_array($status, CommentService::READ_ALLOWED_STATUSES, true)
            ) {
                $gatedSkip[$eid] = true;
            }
        }

        $extIds = [];
        foreach ($items as $item) {
            $eid = (int) ($item['external_id'] ?? 0);
            if ($eid > 0 && !isset($gatedSkip[$eid])) {
                $extIds[] = $eid;
            }
        }
        $countsByParent = $extIds === []
            ? []
            : $this->commentRepo->countsByParentPostIds($extIds);

        $hydrated = [];
        foreach ($items as $item) {
            $eid = (int) ($item['external_id'] ?? 0);
            $item['comment_count'] = isset($gatedSkip[$eid])
                ? 0
                : ($countsByParent[$eid] ?? 0);
            $hydrated[] = $item;
        }
        return $hydrated;
    }
}
