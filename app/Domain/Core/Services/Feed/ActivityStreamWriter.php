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
use BCC\Trust\Core\Announcements\AnnouncementFeature;
use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Announcements\AnnouncementPublicationGuard;
use BCC\Trust\Core\Announcements\AnnouncementVisibility;
use BCC\Trust\Core\Repositories\AnnouncementRepository;
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

    /**
     * ⚠ The announcement repository is OPTIONAL and last, so every existing
     * two-argument construction (Plugin::activityStreamWriter and the unit
     * suites) keeps working unchanged. It is only touched by the two
     * announcement handlers below.
     */
    public function __construct(
        private readonly WatchBatchRepository $watchBatchRepo,
        private readonly PeepSoActivityWriter $activityWriter,
        private ?AnnouncementRepository $announcementRepo = null
    ) {
    }

    /**
     * §1 — the repository is the only thing allowed to touch $wpdb, so the
     * projection asks it rather than re-deriving a visibility predicate here.
     */
    private function announcementRepo(): AnnouncementRepository
    {
        return $this->announcementRepo ??= new AnnouncementRepository();
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

    /**
     * Subscriber for `bcc_announcement_published` — §4.32.8 feed projection.
     *
     * ⚠⚠ "EXACTLY ONE ACTIVITY PER ANNOUNCEMENT, created only once the
     * announcement is due AND authorized. Early projection is refused."
     * That sentence is the whole contract of this method, and each half is
     * enforced by a different mechanism:
     *
     *   - "due AND authorized" is delegated to
     *     {@see AnnouncementRepository::findPublic()}, which composes the
     *     canonical §4.32.1 three-predicate SQL. It is deliberately NOT
     *     re-implemented in PHP here: a second copy of that predicate is
     *     exactly the drift §11 forbids, and the one that matters most
     *     (predicate 3, the publication-authorization marker) is the one a
     *     hand-written copy would be most likely to drop.
     *   - "exactly one" is the idempotency check against
     *     `(module=announcement, external_id=$announcementId)`, the same
     *     check-then-insert the blog handler uses. This is also what makes
     *     "EDITS DO NOT BUMP THE FEED" true: an edit re-firing this event
     *     finds the existing row and returns without touching feed position
     *     or activity identity.
     *
     * ⚠ Blocked rows are refused explicitly even though a blocked row is
     * stored `draft` with its marker stripped, so `findPublic()` would already
     * refuse it. The redundant check is kept because the two facts have
     * different owners — arm C owns the block, the repository owns the
     * predicate — and a future change to either must not silently let a
     * withheld notice onto the floor.
     *
     * ⚠ Archival is NOT a reason for `findPublic()` to refuse: §4.32.6 keeps
     * an archived announcement publicly readable, so it still returns the row.
     * But an archived notice must not project a NEW activity, because
     * {@see handleAnnouncementArchived()} withdraws activities. Checked below.
     */
    public function handleAnnouncementPublished(int $operatorId, int $announcementId): void
    {
        if ($operatorId <= 0 || $announcementId <= 0) {
            return;
        }

        // §4.32.10 — while the switch is OFF, "creation, projection and
        // reconciliation stop". Projection is named explicitly.
        if (!AnnouncementFeature::isEnabled()) {
            return;
        }

        $post = get_post($announcementId);
        if (!$post instanceof \WP_Post || $post->post_type !== AnnouncementPostType::POST_TYPE) {
            return;
        }

        // A3 stores the validator page id in post_parent. A row with no parent
        // cannot be resolved and therefore cannot be projected.
        $pageId = (int) $post->post_parent;
        if ($pageId <= 0) {
            Logger::warning('[ActivityStreamWriter] announcement projection skipped - no page', [
                'announcement_id' => $announcementId,
            ]);
            return;
        }

        if (AnnouncementPublicationGuard::isBlocked($announcementId)) {
            Logger::warning('[ActivityStreamWriter] announcement projection refused - blocked', [
                'announcement_id' => $announcementId,
                'page_id'         => $pageId,
            ]);
            return;
        }

        // Archived notices stay readable but gain no new feed presence.
        $archivedAt = (string) get_post_meta($announcementId, AnnouncementVisibility::META_ARCHIVED_AT, true);
        if (trim($archivedAt) !== '') {
            return;
        }

        // ⚠⚠ THE AUTHORITY AND DUE-TIME GATE. All three §4.32.1 predicates,
        // from the single canonical SQL source.
        try {
            $row = $this->announcementRepo()->findPublic($pageId, $announcementId);
        } catch (\Throwable $e) {
            // Fail CLOSED. An unreadable predicate is not permission to publish.
            Logger::error('[ActivityStreamWriter] announcement projection refused - predicate unreadable', [
                'announcement_id' => $announcementId,
                'page_id'         => $pageId,
                'error'           => $e->getMessage(),
            ]);
            return;
        }

        if ($row === null) {
            Logger::info('[ActivityStreamWriter] announcement projection refused - not due or not authorized', [
                'announcement_id' => $announcementId,
                'page_id'         => $pageId,
            ]);
            return;
        }

        // §4.32.8 — the card "never hosts a discussion
        // (`peepso_disable_comments` is set on the backing post)". The
        // announcement comment thread (A5) lives on the canonical page.
        update_post_meta($announcementId, 'peepso_disable_comments', '1');

        // The announcement wp_post IS the body source of truth, exactly as for
        // blog. No separate sidecar row, so the module meta points at itself.
        update_post_meta($announcementId, self::META_MODULE,  'announcement');
        update_post_meta($announcementId, self::META_SIDECAR, $announcementId);

        $existingActId = $this->activityWriter->findActIdForExternal('announcement', $announcementId);
        if ($existingActId > 0) {
            // Not a fault: this is the edit path and the re-delivery path.
            Logger::info('[ActivityStreamWriter] announcement activity exists - not bumping', [
                'announcement_id' => $announcementId,
                'existing_act_id' => $existingActId,
            ]);
            return;
        }

        $actId = $this->activityWriter->insert(
            $operatorId,
            $operatorId,
            'announcement',
            $announcementId
        );

        if ($actId === 0) {
            Logger::error('[ActivityStreamWriter] failed to insert announcement activity', [
                'announcement_id' => $announcementId,
                'page_id'         => $pageId,
                'operator_id'     => $operatorId,
            ]);
            return;
        }

        Logger::info('[ActivityStreamWriter] announcement activity written', [
            'announcement_id' => $announcementId,
            'page_id'         => $pageId,
            'act_id'          => $actId,
        ]);
    }

    /**
     * Subscriber for `bcc_announcement_archived` — §4.32.8 "Archival withdraws
     * the activity", and §4.32.13 "one-way; unpins; withdraws the feed
     * activity".
     *
     * ⚠ The feature switch is deliberately NOT consulted here. Withdrawal is
     * the SAFE direction: if the switch were turned off between a publish and
     * an archive, refusing to withdraw would leave a live card on the floor
     * for a notice its operator has retired. Turning a feature off must never
     * strand content that was already projected.
     *
     * ⚠ A missing activity is not an error. A notice archived before it was
     * ever due never projected one, and that is the common case.
     */
    public function handleAnnouncementArchived(int $announcementId): void
    {
        if ($announcementId <= 0) {
            return;
        }

        $actId = $this->activityWriter->findActIdForExternal('announcement', $announcementId);
        if ($actId <= 0) {
            return;
        }

        if (!$this->activityWriter->deleteByActId($actId)) {
            Logger::error('[ActivityStreamWriter] failed to withdraw announcement activity', [
                'announcement_id' => $announcementId,
                'act_id'          => $actId,
            ]);
            return;
        }

        Logger::info('[ActivityStreamWriter] announcement activity withdrawn', [
            'announcement_id' => $announcementId,
            'act_id'          => $actId,
        ]);
    }

}
