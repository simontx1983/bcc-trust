<?php
/**
 * Activity Stream Writer — §A3 event-bus subscriber that translates
 * BCC domain events into peepso_activities rows.
 *
 * Subscribed events (registered in Plugin::registerAsyncJobs):
 *
 *   bcc_watch_batch_emitted → inserts a 'watch_batch' activity row
 *                             (pointing at a fresh bcc_watch_batches
 *                             snapshot for the at-emit-time card_count
 *                             and more_count). Frozen-history per §C3:
 *                             this row is never updated; subsequent
 *                             unwatches do not retroactively edit it.
 *
 *   bcc_page_claimed        → inserts a 'page_claim' activity row
 *                             (pointing at the bcc_onchain_claims.id
 *                             from the underlying entity claim).
 *
 *   bcc_review_published    → inserts a 'review' activity row
 *                             (pointing at the bcc_trust_votes.id from
 *                             PostsService::createReview, fired only
 *                             after BOTH the vote-cast and the
 *                             explanation update have succeeded —
 *                             bare upvotes/downvotes do not surface
 *                             in the feed as "review" posts).
 *
 *   bcc_blog_post_created   → inserts a 'blog' activity row (§D6)
 *                             pointing at the wp_posts.ID written by
 *                             PeepSoStatusWriter::createSelfBlogPost.
 *                             FeedItemNormalizer maps act_module_id
 *                             'blog' → post_kind 'blog_excerpt' so the
 *                             Floor renders the excerpt and the per-
 *                             user blog tab renders the full body.
 *
 * Intentionally NOT subscribed:
 *
 *   bcc_card_watched        — watches are silent until §C3 batch closes.
 *                             Writing per-watch rows would create the
 *                             wrong feed shape (one item per card
 *                             instead of one per batch).
 *
 * Single source of truth per §A4: this subscriber is the ONLY code
 * path that inserts BCC-owned peepso_activities rows. All read-side
 * feed surfaces continue to use ActivityFeedService → FeedRankingService.
 *
 * @package BCC\Trust\Core\Services\Feed
 * @since V1 (2026-04, Watch Phase 4)
 */

namespace BCC\Trust\Core\Services\Feed;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\PeepSoActivityWriter;
use BCC\Trust\Core\Repositories\WatchBatchRepository;
use BCC\Trust\Onchain\Repositories\ClaimRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ActivityStreamWriter
{
    /** post_meta key — module string for the wp_post backing this activity. */
    private const META_MODULE  = '_bcc_activity_module';
    /** post_meta key — id of the BCC sidecar row this activity points at. */
    private const META_SIDECAR = '_bcc_activity_sidecar_id';

    public function __construct(
        private readonly WatchBatchRepository $watchBatchRepo,
        private readonly PeepSoActivityWriter $activityWriter
    ) {
    }

    /**
     * Create the backing wp_post that PeepSo's read path joins for
     * actor + timestamp + active-state. Returns the wp_post.ID on
     * success, 0 on failure.
     *
     * Why this exists: PeepSo's peepso_activities table doesn't store
     * the actor/time/status — those are derived from wp_posts via
     * `act_external_id`. BCC modules (review/watch_batch/page_claim/
     * blog) traditionally pointed `act_external_id` at sidecar tables,
     * which broke the JOIN. This helper plants a thin wp_post for
     * each event and stashes the sidecar id in post_meta so
     * FeedRankingService::hydrateBodies can still find the rich body
     * data.
     */
    private function createBackingPost(
        int $authorId,
        string $moduleId,
        int $sidecarId
    ): int {
        // post_type = PeepSo's canonical CPT slug `peepso-post` (11
        // chars). wp_posts.post_type is varchar(20); the fabricated
        // `peepso-activity-status` (22 chars) this helper originally
        // used fails WP 6.7+'s strict length validation, so EVERY
        // review/watch_batch/page_claim backing post silently failed
        // (db_insert_error → return 0 → no feed row). Same fix as
        // PeepSoStatusWriter::createSelfBlogPost — module
        // discrimination lives in the META_MODULE post_meta, not the
        // post_type.
        $postId = wp_insert_post([
            'post_type'    => 'peepso-post',
            'post_status'  => 'publish',
            'post_author'  => $authorId,
            'post_title'   => sprintf('%s-%d-%d', $moduleId, $authorId, $sidecarId),
            'post_content' => '',
        ], true);

        if (is_wp_error($postId) || (int) $postId <= 0) {
            return 0;
        }

        $postId = (int) $postId;
        update_post_meta($postId, self::META_MODULE,  $moduleId);
        update_post_meta($postId, self::META_SIDECAR, $sidecarId);
        return $postId;
    }

    /**
     * Subscriber for bcc_watch_batch_emitted.
     *
     * Two writes (best-effort sequencing — no transaction):
     *   1. INSERT bcc_watch_batches snapshot → returns batch row id
     *   2. INSERT peepso_activities row → act_external_id = batch row id
     *
     * Idempotency: the batch_id has a UNIQUE KEY, so step 1 is a no-op
     * on duplicate event delivery (record() returns the existing id).
     * Step 2 then writes a second activity row pointing at the same
     * batch — undesirable but bounded; WatchBatchAggregator's own
     * `WHERE batch_id IS NULL` guard makes the duplicate event
     * delivery itself rare.
     *
     * `$topCards` is the canonical list<{follow_id, card_handle}> shape
     * emitted by WatchBatchAggregator, but the parameter type stays
     * loose `array` because WordPress's add_action plumbing doesn't
     * carry generic types — strict typing the lambda is impossible
     * without an inline @var override (forbidden by §L6).
     *
     * @param array<int, mixed> $topCards
     */
    public function handleWatchBatchEmitted(
        int $userId,
        string $batchId,
        int $cardCount,
        array $topCards,
        int $moreCount
    ): void {
        unset($topCards); // Phase 4 stores only the summary; top_cards
                          // are already in bcc_watch_meta and joinable
                          // by batch_id when feed renderers need them.

        if ($userId <= 0 || $batchId === '') {
            return;
        }

        $batchRowId = $this->watchBatchRepo->record($userId, $batchId, $cardCount, $moreCount);
        if ($batchRowId === 0) {
            Logger::error('[ActivityStreamWriter] failed to record watch-batch snapshot', [
                'user_id'  => $userId,
                'batch_id' => $batchId,
            ]);
            return;
        }

        $wpPostId = $this->createBackingPost($userId, 'watch_batch', $batchRowId);
        if ($wpPostId === 0) {
            Logger::error('[ActivityStreamWriter] failed to create wp_post for watch_batch', [
                'user_id'  => $userId,
                'batch_id' => $batchId,
            ]);
            return;
        }

        // Watch-batch activities live on the actor's wall (act_owner_id
        // = actor) so they surface in the feed of users following the
        // actor. act_external_id points at the wp_post we just created;
        // the sidecar batch row id is in post_meta for body hydration.
        $actId = $this->activityWriter->insert(
            $userId,           // actor
            $userId,           // owner (self-wall)
            'watch_batch',
            $wpPostId
        );

        if ($actId === 0) {
            Logger::error('[ActivityStreamWriter] failed to insert watch_batch activity', [
                'user_id'            => $userId,
                'batch_id'           => $batchId,
                'watch_batch_row_id' => $batchRowId,
            ]);
            return;
        }

        Logger::info('[ActivityStreamWriter] watch_batch activity written', [
            'user_id'            => $userId,
            'batch_id'           => $batchId,
            'watch_batch_row_id' => $batchRowId,
            'act_id'             => $actId,
            'card_count'         => $cardCount,
        ]);
    }

    /**
     * Subscriber for bcc_page_claimed.
     *
     * Inserts a 'page_claim' activity row pointing at the
     * bcc_onchain_claims.id (resolved via ClaimRepository::getUserClaim
     * — the event payload doesn't carry it directly so we look it
     * up by the (user, entity_type, entity_id) triple). The act lives
     * on the actor's wall, NOT the page's wall — peepso follows are
     * user→user, so following the claimer is what surfaces this in
     * feeds; following the page-as-an-owner is a separate concept
     * deferred to Phase 5+.
     */
    public function handlePageClaimed(
        int $userId,
        int $pageId,
        string $entityType,
        int $entityId,
        string $role
    ): void {
        unset($pageId, $role); // Phase 4 stores only the claim FK; the
                               // page + role are derivable from the
                               // claim row when feed renderers need them.

        if ($userId <= 0 || $entityId <= 0 || $entityType === '') {
            return;
        }

        $claim = ClaimRepository::getUserClaim($userId, $entityType, $entityId);
        if ($claim === null) {
            // Defensive — bcc_page_claimed only fires after a
            // successful claim, but the row could have been deleted
            // between event emission and this subscriber running.
            Logger::warning('[ActivityStreamWriter] page_claimed event with no resolvable claim', [
                'user_id'     => $userId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
            ]);
            return;
        }

        $wpPostId = $this->createBackingPost($userId, 'page_claim', (int) $claim->id);
        if ($wpPostId === 0) {
            Logger::error('[ActivityStreamWriter] failed to create wp_post for page_claim', [
                'user_id'  => $userId,
                'claim_id' => $claim->id,
            ]);
            return;
        }

        $actId = $this->activityWriter->insert(
            $userId,                // actor
            $userId,                // owner (self-wall — see method doc)
            'page_claim',
            $wpPostId
        );

        if ($actId === 0) {
            Logger::error('[ActivityStreamWriter] failed to insert page_claim activity', [
                'user_id'  => $userId,
                'claim_id' => $claim->id,
            ]);
            return;
        }

        Logger::info('[ActivityStreamWriter] page_claim activity written', [
            'user_id'     => $userId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'claim_id'    => $claim->id,
            'act_id'      => $actId,
        ]);
    }

    /**
     * Subscriber for bcc_review_published.
     *
     * The review's vote row already lives in bcc_trust_votes; this
     * subscriber's only job is to translate the published review
     * into a peepso_activities row so the §F3 feed surfaces it as
     * a `post_kind='review'` item (FeedItemNormalizer maps
     * `act_module_id='review'` → `post_kind='review'`).
     *
     * The review's wall is the AUTHOR's wall (act_owner_id =
     * act_user_id), matching how watches and claims surface — viewers
     * who follow the reviewer see it, viewers who follow the target
     * page do NOT (page-wall surfacing is a Phase 5+ concept).
     */
    public function handleReviewPublished(
        int $authorId,
        int $pageId,
        int $voteId,
        string $explanation
    ): void {
        unset($pageId, $explanation); // peepso_activities row only
                                      // carries the FK; renderers join
                                      // bcc_trust_votes for the body.

        if ($authorId <= 0 || $voteId <= 0) {
            return;
        }

        $wpPostId = $this->createBackingPost($authorId, 'review', $voteId);
        if ($wpPostId === 0) {
            Logger::error('[ActivityStreamWriter] failed to create wp_post for review', [
                'user_id' => $authorId,
                'vote_id' => $voteId,
            ]);
            return;
        }

        $actId = $this->activityWriter->insert(
            $authorId,    // actor
            $authorId,    // owner (self-wall — review surfaces to followers)
            'review',
            $wpPostId
        );

        if ($actId === 0) {
            Logger::error('[ActivityStreamWriter] failed to insert review activity', [
                'user_id' => $authorId,
                'vote_id' => $voteId,
            ]);
            return;
        }

        Logger::info('[ActivityStreamWriter] review activity written', [
            'user_id' => $authorId,
            'vote_id' => $voteId,
            'act_id'  => $actId,
        ]);
    }

    /**
     * Subscriber for bcc_blog_post_created (§D6).
     *
     * The wp_post (post_type=peepso-post) was written by
     * PeepSoStatusWriter::createSelfBlogPost — post_excerpt holds the
     * Floor teaser, post_content holds the full body. This subscriber
     * adds the peepso_activities row that surfaces it in feeds.
     *
     * Module is 'blog' (mapped to post_kind 'blog_excerpt' by
     * FeedItemNormalizer); external_id is the wp_posts.ID so renderers
     * can join wp_posts for the body fields.
     *
     * Idempotency (PR-A): the event may fire more than once across
     * the lifetime of a blog post — initial publish via PostsService,
     * draft→publish transition via BlogStatusTransitionHandler, and
     * defensive re-dispatch on rare retry paths. We refuse to insert
     * a second peepso_activities row for the same wp_post by checking
     * the existing (act_module_id='blog', act_external_id=$postId)
     * pair first. The check is bounded LIMIT 1 against an existing
     * index. Draft posts are also rejected so a published-while-draft
     * event flap can't surface a draft on the floor.
     */
    public function handleBlogPostCreated(int $authorId, int $postId): void
    {
        if ($authorId <= 0 || $postId <= 0) {
            return;
        }

        // PR-A guard: drafts must not surface as feed activity. The
        // event firing on a draft is a contract violation (PostsService
        // only emits on publish + BlogStatusTransitionHandler only emits
        // on draft→publish), but a defensive check here keeps the
        // assertion local — easier to grep than tracing the event
        // graph if the guarantee ever drifts.
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            Logger::warning('[ActivityStreamWriter] blog_post_created skipped — not published', [
                'user_id'    => $authorId,
                'post_id'    => $postId,
                'post_status'=> $post instanceof \WP_Post ? $post->post_status : '(missing)',
            ]);
            return;
        }

        // Blog is a special case — the wp_post IS the body source-of-
        // truth (post_excerpt + post_content), there's no separate
        // sidecar table. We just tag the post with the module meta so
        // FeedRankingService::hydrateBodies can find it through the
        // same lookup path as other BCC modules.
        update_post_meta($postId, '_bcc_activity_module',     'blog');
        update_post_meta($postId, '_bcc_activity_sidecar_id', $postId);

        // ── Idempotency check ───────────────────────────────────────
        //
        // peepso_activities has no UNIQUE constraint on (module,
        // external_id) — adding one would require coordinating a
        // schema change with PeepSo, who owns the table. Instead we
        // check-then-insert via the Repository (§1 — no raw $wpdb
        // in Services). Race: two concurrent dispatches could both
        // miss the existing row and both insert. In practice the
        // event subscribers run on action-scheduler, which serializes
        // per-hook, so the race window is empty. If we ever observe
        // duplicates in logs we tighten this to a DB-level lock or a
        // sidecar dedup table.
        $existingActId = $this->activityWriter->findActIdForExternal('blog', $postId);
        if ($existingActId > 0) {
            Logger::info('[ActivityStreamWriter] blog activity already exists — skipping insert', [
                'user_id'    => $authorId,
                'post_id'    => $postId,
                'existing_act_id' => $existingActId,
            ]);
            return;
        }

        $actId = $this->activityWriter->insert(
            $authorId,   // actor
            $authorId,   // owner (self-wall — blog surfaces to followers)
            'blog',
            $postId
        );

        if ($actId === 0) {
            Logger::error('[ActivityStreamWriter] failed to insert blog activity', [
                'user_id' => $authorId,
                'post_id' => $postId,
            ]);
            return;
        }

        Logger::info('[ActivityStreamWriter] blog activity written', [
            'user_id' => $authorId,
            'post_id' => $postId,
            'act_id'  => $actId,
        ]);
    }

}
