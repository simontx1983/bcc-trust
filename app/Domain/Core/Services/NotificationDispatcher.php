<?php
/**
 * Notification Dispatcher — translates §A3 BCC events into
 * peepso_notifications rows per §I1.
 *
 * One method per subscribed event. Each method:
 *   1. Resolves the recipient (the user who should be notified)
 *   2. Builds a server-rendered message string per §A2
 *   3. Calls PeepSoNotificationWriter — PeepSo handles opt-out /
 *      block / dedupe / SSE poke
 *
 * Why subscribe sync (not Action Scheduler queued):
 *   - PeepSo's add_notification is a single insert + a couple of
 *     usermeta reads. Sub-millisecond cost, well inside the §L1
 *     300ms budget.
 *   - The originating request would gain little from queuing: the
 *     reaction / review / card-pull writes are themselves the
 *     bottleneck, not the notification write.
 *   - Try/catch wraps every dispatch so a notification failure
 *     never breaks the originating request. Failed writes log at
 *     warning level with the full event payload — that's the
 *     observability hook for §K1 abuse signals later.
 *
 * V1 coverage (§I2 launch checklist):
 *   - bcc_reaction_added         → notify post author
 *   - bcc_review_published       → notify page owner
 *   - bcc_card_watched           → notify followee user
 *   - bcc_rank_awarded           → notify the user (self-notification
 *                                   — gives an audit trail beyond the
 *                                   §O1.2 Heavy toast)
 *   - bcc_trust_endorsement_added → notify page owner (V1.5 follow-up)
 *   - user_register              → self-notification welcome on signup
 *                                   (V2 Phase 2 retention slice — proves
 *                                   the bell channel works within
 *                                   seconds of opting in; idempotent via
 *                                   `bcc_welcomed` user_meta)
 *
 * @mention dispatch (V2 retention slice, locked 2026-05-11):
 *   - onMentioned + dispatchMentionsFor land bell + push events when
 *     @{handle} tokens are present in a post or comment body. Wired
 *     via `bcc_post_created` / `bcc_comment_created` subscribers in
 *     Plugin.php — original-write only. NO edit hook is subscribed;
 *     the edit-as-ping abuse vector is structurally closed.
 *   - Dedup is structural: MentionExtractor::extractUserIds returns
 *     unique-by-first-occurrence ids, so three `@bob` tokens in one
 *     post produce exactly one bell row for Bob.
 *
 * Primary-Local post dispatch (V2 retention slice, locked 2026-05-11):
 *   - dispatchPrimaryLocalPostFor + onPostInPrimaryLocal fan out
 *     `LOCAL_POST` bell + push events to every user whose
 *     `bcc_primary_local_group_id` matches the post's group_id.
 *   - Wired via a SECOND `bcc_post_created` subscriber in Plugin.php
 *     at priority 31 (after the mention subscriber at 30) — both
 *     events can fire for the same post (intentional independence;
 *     mention = "you were called out", local-post = "activity in
 *     your Local"). Mention and Local-post each carry their own
 *     pref toggle.
 *   - Always async via AsyncDispatcher — a popular Local could fan
 *     out to thousands of recipients; sync would blow the §L1 300ms
 *     request budget. The originating post-create returns
 *     immediately; the async worker handles the dispatcher loop.
 *   - Coalesced via 5-min per-(recipient, group) transient on bell
 *     and the existing PushDispatcher debounce on push.
 *
 * Comment-received dispatch (V2 retention slice, locked 2026-05-13):
 *   - onCommentReceived sends a `COMMENT_RECEIVED` bell + push to the
 *     PARENT POST's author when someone comments on their post.
 *   - Wired via a SECOND `bcc_comment_created` subscriber in Plugin.php
 *     at priority 31 (after the mention subscriber at 30). If the
 *     commenter @-tags the post author, BOTH dispatchers fire for the
 *     same recipient — semantically distinct events (mention =
 *     "called out"; comment-received = "your post has activity"),
 *     each independently toggleable in prefs.
 *   - Single-recipient (the post author); no fan-out cost concern,
 *     dispatch is sync.
 *   - Coalesced via 5-min per-(recipient, post) transient on bell so
 *     a hot post doesn't spam the author; push self-debounces.
 *
 * Deferred (per §P parking lot): follow-posts (cross-graph, expensive).
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §I1)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Core\PeepSo\PeepSoNotificationWriter;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Services\BadgesService;
use BCC\Trust\Core\Services\Mentions\MentionExtractor;
use BCC\Trust\Core\Services\Mentions\MentionPolicy;
use BCC\Trust\Core\Support\NotificationPrefs;
use BCC\Trust\Core\Support\NotificationType;
use BCC\Trust\Core\Support\RankCatalog;

if (!defined('ABSPATH')) {
    exit;
}

final class NotificationDispatcher
{
    public function __construct(
        private readonly PageOwnerResolver $pageOwnerResolver,
        private readonly PushDispatcher $pushDispatcher,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_reaction_added — a reaction on a post (solid / social kinds)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the post author when someone reacts to their post.
     *
     * Skips when the actor IS the author (self-reactions don't
     * generate noise — the user already knows they reacted).
     *
     * @param string $kind reaction key — 'solid' (trust grammar) or a
     *                      social kind. NOTE: `vouch`/`stand_behind` are
     *                      NOT reactions — they're attestations (a
     *                      different system, notified separately below).
     */
    public function onReactionAdded(int $actorId, int $actId, string $kind): void
    {
        if ($actorId <= 0 || $actId <= 0) {
            return;
        }
        try {
            $authorId = $this->resolveActAuthor($actId);
            if ($authorId <= 0 || $authorId === $actorId) {
                return;
            }

            $actorHandle = self::resolveHandle($actorId);
            $verb        = self::reactionVerb($kind);
            $message     = sprintf('@%s %s your post.', $actorHandle, $verb);

            $this->dispatch(
                $actorId,
                $authorId,
                $message,
                NotificationType::REACTION,
                $actId,
                $actId
            );
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] reaction dispatch failed', [
                'actor_id' => $actorId,
                'act_id'   => $actId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_review_published — review on a page
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the page owner when someone publishes a review on
     * their page. Skips self-reviews (claimed operators reviewing
     * their own page).
     */
    public function onReviewPublished(int $authorId, int $pageId, int $voteId, string $explanation): void
    {
        unset($explanation, $voteId); // body content stays on the card; notification is the meta event
        if ($authorId <= 0 || $pageId <= 0) {
            return;
        }
        try {
            $ownerId = $this->pageOwnerResolver->getPageOwner($pageId);
            if ($ownerId <= 0 || $ownerId === $authorId) {
                return;
            }

            $actorHandle = self::resolveHandle($authorId);
            $pageName    = self::resolvePageName($pageId);
            $message     = $pageName !== ''
                ? sprintf('@%s reviewed %s.', $actorHandle, $pageName)
                : sprintf('@%s reviewed your page.', $actorHandle);

            $this->dispatch(
                $authorId,
                $ownerId,
                $message,
                NotificationType::REVIEW,
                $pageId,
                0
            );

            // V2 Phase 1: queue a push to the page owner. The dispatcher
            // self-gates on push prefs + 5-min debounce so this is safe
            // to call unconditionally — bell + push toggles are
            // independent surfaces.
            $this->pushDispatcher->enqueue($ownerId, 'review', [
                'actor_handle' => $actorHandle,
                'page_id'      => $pageId,
                'page_name'    => $pageName,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] review dispatch failed', [
                'author_id' => $authorId,
                'page_id'   => $pageId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_card_watched — someone watched (followed) you
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the followee when someone watches their card. The
     * followee is always a user id (watchlist is a projection of
     * PeepSo follows — see §C2). Self-watches are not possible from
     * the watchlist UI; we still guard.
     *
     * Note: watches run through §C3's batch aggregator on the feed
     * side (one feed item per 10-min window). The notification side
     * is intentionally per-event for now — a watch-batch-collapse
     * pattern would be V2 work after we see the volume.
     *
     * @param string $targetKind 'validator'|'project'|'creator'|'member'
     */
    public function onCardWatched(int $viewerId, int $followId, string $targetKind, int $targetId): void
    {
        unset($followId, $targetKind);
        if ($viewerId <= 0 || $targetId <= 0 || $viewerId === $targetId) {
            return;
        }
        try {
            $actorHandle = self::resolveHandle($viewerId);
            $message     = sprintf('@%s watched your card.', $actorHandle);

            $this->dispatch(
                $viewerId,
                $targetId,
                $message,
                NotificationType::CARD_WATCHED,
                $viewerId, // external_id → actor profile (where the followee likely wants to land)
                0
            );
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] pull dispatch failed', [
                'viewer_id' => $viewerId,
                'target_id' => $targetId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // NOTE: the bcc_trust_endorsement_added bell/push subscriber was
    // retired with the legacy endorse write path — vouch casts dispatch
    // owner notifications via the bcc_attestation_created subscribers.

    // ──────────────────────────────────────────────────────────────────
    // bcc_rank_awarded — self-notification, audit trail beyond toast
    // ──────────────────────────────────────────────────────────────────

    /**
     * Drop a self-notification when a user's auto-derived rank
     * climbs. The §O1.2 Heavy celebration handles the in-the-moment
     * dopamine; this row gives the user something to scroll back to
     * in the bell dropdown a week later.
     *
     * `from_user` and `to_user` are intentionally the same id —
     * PeepSo accepts that and the row reads naturally ("You earned
     * Journeyman.").
     */
    public function onRankAwarded(int $userId, string $newRank, string $oldRank): void
    {
        unset($oldRank);
        if ($userId <= 0) {
            return;
        }
        try {
            $label = RankCatalog::getLabel($newRank);
            if ($label === null) {
                return;
            }
            $message = sprintf('You earned the %s rank.', $label);

            $this->dispatch(
                $userId,
                $userId,
                $message,
                NotificationType::RANK_UP,
                $userId,
                0
            );
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] rank dispatch failed', [
                'user_id' => $userId,
                'rank'    => $newRank,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // user_register — first-touch welcome on signup
    // ──────────────────────────────────────────────────────────────────

    /**
     * Drop a welcome bell notification when a new user account is
     * created. Self-notification (from_user = to_user = the new user)
     * — same shape as `onRankAwarded`. The point isn't to surface a
     * social event; it's to prove the notification channel works the
     * moment the user reaches the bell, so opt-ins they made during
     * onboarding aren't met by silence for days.
     *
     * Idempotency: `bcc_welcomed` user_meta gates the dispatch. Once
     * set, the welcome will never fire again for this user — even if
     * `user_register` somehow re-fires (it shouldn't, but defense in
     * depth). The meta value is the signup timestamp, which doubles
     * as a "when did this account join" marker for future cohort
     * analysis without touching wp_users.
     *
     * Hooks into the WordPress core `user_register` action so it
     * fires for ANY user creation path — /auth/signup, wp-admin Add
     * User, wp-cli, partner integrations. New BCC users get the
     * welcome regardless of how they were created.
     */
    public function onUserSignup(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        // Idempotency guard — meta_key write is atomic via WordPress's
        // wp_options-style upsert. A duplicate user_register event would
        // see the meta and bail.
        $existing = get_user_meta($userId, 'bcc_welcomed', true);
        if ($existing !== '' && $existing !== false) {
            return;
        }

        try {
            $message = 'Welcome to The Floor. Keep tabs on cards, post reviews, build trust.';
            $this->dispatch(
                $userId,
                $userId,
                $message,
                NotificationType::WELCOME,
                $userId,
                0
            );
            update_user_meta($userId, 'bcc_welcomed', (string) time());
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] welcome dispatch failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_post_created / bcc_comment_created — @-mention dispatch
    // ──────────────────────────────────────────────────────────────────

    /**
     * Extract every @-mention from $body, apply the policy filter (same
     * gate as write-time validation in PostsService::validateMentions /
     * CommentService::validateMentions — banned/blocked/private targets
     * are silently dropped), then drop a bell + push for each surviving
     * mentionee.
     *
     * Dedup is structural: MentionExtractor::extractUserIds returns
     * unique-by-first-occurrence ids, so three `@bob` tokens in one
     * body produce exactly one bell row for Bob. The 10-mentions-per-
     * post cap is already enforced at write-time validation — we don't
     * re-cap here (a hand-crafted body that smuggled past the
     * validator would still be bounded by the MentionPolicy filter,
     * which is the load-bearing privacy gate).
     *
     * Wrapped in try/catch at the orchestrator level so a single bad
     * mentionee never aborts the rest of the batch and never bubbles
     * to the originating write. Per-mentionee failures are already
     * caught inside onMentioned.
     *
     * @param int    $authorId   user_id of the post/comment author
     * @param int    $postId     wp_post.ID of the post containing the body
     *                           (used as `external_id` in the bell row)
     * @param string $body       raw `wp_posts.post_content` (untouched —
     *                           the extractor strips PeepSo's token shape)
     * @param int    $actId      activity row id whose URL the bell should
     *                           deep-link to. For post mentions this is
     *                           the post's own act_id; for comment
     *                           mentions this is the PARENT post's act_id
     *                           so the user lands on the post on the
     *                           floor (the FE has no comment-anchor
     *                           consumer in V1 — see api-contract §4.18).
     * @param bool   $isComment  true when the body is a comment, false
     *                           for status/photo/gif post bodies
     */
    public function dispatchMentionsFor(
        int $authorId,
        int $postId,
        string $body,
        int $actId,
        bool $isComment
    ): void {
        if ($authorId <= 0 || $postId <= 0 || $body === '') {
            return;
        }
        try {
            $candidates = MentionExtractor::extractUserIds($body);
            if ($candidates === []) {
                return;
            }
            // Re-apply the same policy gate as write-time validation.
            // In a sync request this is identical to the validator's
            // pass (no race); the second call costs one bounded
            // WP_User_Query but keeps the dispatcher safe even if a
            // future code path skips validateMentions.
            $allowed = MentionPolicy::filterMentionable($authorId, $candidates);
            if ($allowed === []) {
                return;
            }
            foreach ($allowed as $mentioneeId) {
                $this->onMentioned($authorId, $mentioneeId, $postId, $actId, $isComment);
            }
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] mention dispatch orchestrator failed', [
                'author_id'  => $authorId,
                'post_id'    => $postId,
                'is_comment' => $isComment,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch a single mention bell + push. Mirrors onReactionAdded
     * shape — sync write, self-skip, per-recipient error log. The
     * shared dispatch() helper applies the bell pref gate; the push
     * dispatcher self-gates on the push pref + 5-min debounce.
     *
     * Marked public so it can be exercised by unit tests; the
     * orchestrator above is the canonical entry point in production.
     */
    public function onMentioned(
        int $authorId,
        int $mentioneeId,
        int $postId,
        int $actId,
        bool $isComment
    ): void {
        if ($authorId <= 0 || $mentioneeId <= 0 || $postId <= 0) {
            return;
        }
        if ($mentioneeId === $authorId) {
            // Self-mention: same posture as self-reactions. The composer
            // doesn't currently strip self-tokens, so this is the
            // load-bearing skip.
            return;
        }
        try {
            $actorHandle = self::resolveHandle($authorId);
            $message     = $isComment
                ? sprintf('@%s mentioned you in a comment.', $actorHandle)
                : sprintf('@%s mentioned you in a post.', $actorHandle);

            $this->dispatch(
                $authorId,
                $mentioneeId,
                $message,
                NotificationType::MENTION,
                $postId,
                $actId
            );

            // Parallel push enqueue — debounced to one push per
            // (recipient, 'mention') per 5 min, count-aggregated.
            // PushDispatcher::enqueue self-gates on push prefs.
            $this->pushDispatcher->enqueue($mentioneeId, 'mention', [
                'actor_handle' => $actorHandle,
                'post_id'      => $postId,
                'act_id'       => $actId,
                'is_comment'   => $isComment,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] mention dispatch failed', [
                'author_id'    => $authorId,
                'mentionee_id' => $mentioneeId,
                'post_id'      => $postId,
                'is_comment'   => $isComment,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_primary_local_post_fanout (async) — primary-Local post dispatch
    // ──────────────────────────────────────────────────────────────────

    /** 5-min per-(recipient, group) bell-coalescing window. */
    private const LOCAL_POST_BELL_WINDOW_SECS = 300;

    /** Recipient hard cap per fan-out — revisit when a real Local crosses ~500. */
    private const LOCAL_POST_RECIPIENT_CAP = 1000;

    /**
     * Fan out a "new post in your primary Local" event to every user
     * whose `bcc_primary_local_group_id` user_meta points at this group.
     * Called from the `bcc_primary_local_post_fanout` async worker
     * (Plugin.php) — Plugin.php pre-gates that the group is a Local
     * before enqueueing, so the orchestrator can trust the caller most
     * of the time but re-verifies (defense in depth — a Local could be
     * deleted between enqueue and worker pickup).
     *
     * Recipient cap of LOCAL_POST_RECIPIENT_CAP prevents a runaway
     * "Local with 50k members" from blowing the worker. Today no Local
     * approaches this; cursor pagination across multiple async jobs is
     * the future move when one does.
     *
     * Wrapped in try/catch so a single bad recipient never aborts the
     * batch and never bubbles to the async worker.
     */
    public function dispatchPrimaryLocalPostFor(
        int $authorId,
        int $postId,
        int $actId,
        int $groupId
    ): void {
        if ($authorId <= 0 || $postId <= 0 || $groupId <= 0) {
            return;
        }
        try {
            // Re-verify the group is still a Local. PeepSoGroupRepository::
            // findOneById applies the `post_title LIKE 'Local %'` filter,
            // so a null return means it was deleted, renamed off-prefix,
            // or never was a Local. Either way: don't dispatch.
            $group = PeepSoGroupRepository::findOneById($groupId);
            if ($group === null) {
                return;
            }
            $localName = isset($group->post_title) ? (string) $group->post_title : '';
            $localSlug = isset($group->post_name)  ? (string) $group->post_name  : '';

            $recipients = PeepSoGroupRepository::findUsersByPrimaryLocal(
                $groupId,
                self::LOCAL_POST_RECIPIENT_CAP
            );
            if ($recipients === []) {
                return;
            }

            $authorHandle = self::resolveHandle($authorId);

            foreach ($recipients as $recipientId) {
                $this->onPostInPrimaryLocal(
                    $authorId,
                    $recipientId,
                    $postId,
                    $actId,
                    $groupId,
                    $authorHandle,
                    $localName,
                    $localSlug
                );
            }
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] local-post orchestrator failed', [
                'author_id' => $authorId,
                'post_id'   => $postId,
                'group_id'  => $groupId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch a single primary-Local post bell + push. Mirrors
     * onReactionAdded shape — self-skip, per-recipient error log,
     * shared dispatch() helper for bell write, PushDispatcher::enqueue
     * for push.
     *
     * Bell coalescing: a 5-min per-(recipient, group) transient gates
     * the bell write. The transient is set BEFORE the dispatch call
     * even if the bell pref is disabled — this keeps coalescing
     * consistent regardless of pref state, so toggling the pref
     * mid-window doesn't produce a surprise burst when re-enabled.
     *
     * Push: always enqueued (PushDispatcher self-debounces on its own
     * 5-min (recipient, eventType) window and self-gates on push prefs).
     *
     * Marked public so it can be exercised by unit tests; the
     * orchestrator above is the canonical entry point in production.
     */
    public function onPostInPrimaryLocal(
        int $authorId,
        int $recipientId,
        int $postId,
        int $actId,
        int $groupId,
        string $authorHandle,
        string $localName,
        string $localSlug
    ): void {
        if ($authorId <= 0 || $recipientId <= 0 || $groupId <= 0) {
            return;
        }
        if ($recipientId === $authorId) {
            // Self-skip: the author posted, they know. Defensively
            // duplicated by dispatch()'s $toUserId !== $fromUserId check.
            return;
        }
        try {
            $transientKey = sprintf(
                'bcc_local_post_notified_%d_%d',
                $recipientId,
                $groupId
            );

            $coalesced = get_transient($transientKey) !== false;
            if (!$coalesced) {
                // Set the gate BEFORE the dispatch so a second concurrent
                // post for the same (recipient, group) sees the lock
                // even if the bell write is still in flight.
                set_transient($transientKey, 1, self::LOCAL_POST_BELL_WINDOW_SECS);

                $message = $localName !== ''
                    ? sprintf('@%s posted in %s.', $authorHandle, $localName)
                    : sprintf('@%s posted in your Local.', $authorHandle);

                $this->dispatch(
                    $authorId,
                    $recipientId,
                    $message,
                    NotificationType::LOCAL_POST,
                    $groupId,   // external_id → the Local group
                    $actId
                );
            }

            // Push always enqueues — its own 5-min debounce coalesces
            // rapid bursts into "N new posts in {Local}." The bell
            // coalescing is independent of push aggregation; both align
            // on the same 5-min window so a user sees at most one bell +
            // one push per Local per window.
            $this->pushDispatcher->enqueue($recipientId, 'local_post', [
                'actor_handle' => $authorHandle,
                'group_id'     => $groupId,
                'local_name'   => $localName,
                'local_slug'   => $localSlug,
                'act_id'       => $actId,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] local-post dispatch failed', [
                'author_id'    => $authorId,
                'recipient_id' => $recipientId,
                'group_id'     => $groupId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_comment_created (sync) — comment-on-your-post dispatch
    // ──────────────────────────────────────────────────────────────────

    /** 5-min per-(recipient, post) bell-coalescing window — matches LOCAL_POST. */
    private const COMMENT_RECEIVED_BELL_WINDOW_SECS = 300;

    /**
     * Notify the parent-post author when someone comments on their post.
     * Self-skip when the commenter IS the post author.
     *
     * Recipient resolution lives in this method (not the Plugin.php
     * subscriber) so the subscriber stays a thin trigger and the lookup
     * logic lives in one place alongside the bell-write logic.
     *
     * Bell coalescing: a 5-min per-(recipient, post) transient gates
     * the bell write so a hot post (50 comments in 10 min) doesn't
     * produce 50 bell rows for the post author. Push always enqueues
     * — PushDispatcher's own 5-min (recipient, eventType) debounce
     * coalesces rapid bursts into "N new comments on your post."
     *
     * If the commenter @-tagged the post author, the mention dispatcher
     * ALSO fires for the same recipient. Both events are sent — they're
     * semantically distinct (mention = "called out"; comment-received
     * = "your post has activity") and independently toggleable. See
     * the @see note on dispatchMentionsFor.
     */
    public function onCommentReceived(int $commenterId, int $parentActId, int $newCommentPostId): void
    {
        unset($newCommentPostId);
        if ($commenterId <= 0 || $parentActId <= 0) {
            return;
        }
        try {
            // Resolve parent post → its author. Mirrors resolveActAuthor
            // shape; inlined here because we need both the post_id AND
            // the author for the dispatch payload.
            $parentAct = PeepSoActivityRepository::getById($parentActId);
            if ($parentAct === null) {
                return;
            }
            $parentPostId = isset($parentAct->act_external_id) ? (int) $parentAct->act_external_id : 0;
            if ($parentPostId <= 0) {
                return;
            }
            $parentPost = get_post($parentPostId);
            if (!$parentPost instanceof \WP_Post) {
                return;
            }
            $postAuthorId = (int) $parentPost->post_author;
            if ($postAuthorId <= 0 || $postAuthorId === $commenterId) {
                // Self-skip: the post author is commenting on their own
                // post; they obviously know they did it.
                return;
            }

            $actorHandle = self::resolveHandle($commenterId);

            $transientKey = sprintf(
                'bcc_comment_received_notified_%d_%d',
                $postAuthorId,
                $parentPostId
            );

            $coalesced = get_transient($transientKey) !== false;
            if (!$coalesced) {
                // Set the gate BEFORE the dispatch so a second concurrent
                // comment for the same (recipient, post) sees the lock
                // even if the bell write is still in flight.
                set_transient($transientKey, 1, self::COMMENT_RECEIVED_BELL_WINDOW_SECS);

                $message = sprintf('@%s commented on your post.', $actorHandle);

                $this->dispatch(
                    $commenterId,
                    $postAuthorId,
                    $message,
                    NotificationType::COMMENT_RECEIVED,
                    $parentPostId,   // external_id → the parent post
                    $parentActId
                );
            }

            // Push always enqueues — its own 5-min debounce coalesces
            // rapid bursts into "N new comments on your post." Bell
            // coalescing is independent of push aggregation; both
            // align on the same 5-min window so a user sees at most
            // one bell + one push per post per window.
            $this->pushDispatcher->enqueue($postAuthorId, 'comment_received', [
                'actor_handle' => $actorHandle,
                'post_id'      => $parentPostId,
                'act_id'       => $parentActId,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] comment-received dispatch failed', [
                'commenter_id'  => $commenterId,
                'parent_act_id' => $parentActId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_attestation_created — V2 Trust Attestation Layer
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the target operator when someone vouches for or stands
     * behind them. Two distinct event types (ATTESTATION_VOUCH_RECEIVED
     * / ATTESTATION_STAND_BEHIND_RECEIVED) so the recipient can toggle
     * each in prefs independently.
     *
     * Structural self-skip: the service rejects self-attest before
     * dispatching; the guard here is defense-in-depth in case a future
     * code path slips one through.
     *
     * Bell shape: external_id is the attestation_id (so a future
     * "view this attestation" deep-link is structurally possible);
     * act_id is 0 (attestations are not anchored to activity rows).
     *
     * @param int    $attestorId    Author of the attestation
     * @param int    $attestationId The new attestation row id
     * @param string $targetKind    user_profile | *_card
     * @param int    $targetId      target row id (for push payload)
     * @param string $kind          vouch | stand_behind
     * @param int    $targetOwnerId Recipient (user_profile target → target user; *_card → post_author)
     */
    public function onAttestationCreated(
        int $attestorId,
        int $attestationId,
        string $targetKind,
        int $targetId,
        string $kind,
        int $targetOwnerId
    ): void {
        if ($attestorId <= 0 || $attestationId <= 0 || $targetOwnerId <= 0) {
            return;
        }
        if ($attestorId === $targetOwnerId) {
            return; // self-attest — structurally rejected upstream, DiD.
        }
        if ($kind !== 'vouch' && $kind !== 'stand_behind') {
            return; // unknown kind — drop silently.
        }
        try {
            $actorHandle = self::resolveHandle($attestorId);
            $message     = $kind === 'vouch'
                ? sprintf('@%s vouched for you.', $actorHandle)
                : sprintf('@%s is standing behind you.', $actorHandle);
            $type        = $kind === 'vouch'
                ? NotificationType::ATTESTATION_VOUCH_RECEIVED
                : NotificationType::ATTESTATION_STAND_BEHIND_RECEIVED;
            $pushEvent   = $kind === 'vouch'
                ? 'attestation_vouch_received'
                : 'attestation_stand_behind_received';

            $this->dispatch(
                $attestorId,
                $targetOwnerId,
                $message,
                $type,
                $attestationId, // external_id → attestation row id
                0               // no associated activity row
            );

            // Parallel push enqueue — debounced to one push per
            // (recipient, eventType) per 5 min, count-aggregated.
            $this->pushDispatcher->enqueue($targetOwnerId, $pushEvent, [
                'actor_handle'    => $actorHandle,
                'attestation_id'  => $attestationId,
                'target_kind'     => $targetKind,
                'target_id'       => $targetId,
                'kind'            => $kind,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] attestation-created dispatch failed', [
                'attestor_id'    => $attestorId,
                'attestation_id' => $attestationId,
                'target_kind'    => $targetKind,
                'target_id'      => $targetId,
                'kind'           => $kind,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_attestation_revoked — neutral state-change notice (§J.3.2)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the (former) target when an attestation is revoked. Wording
     * is intentionally neutral per the §J.3.2 asymmetric-display rule
     * — no stigma copy, no "you lost" framing. Revocation reads as a
     * state-change notice, not a punishment.
     *
     * Per the contract §J.3 "Reputation Score impact on attestor: none"
     * — revocation is healthy signal of changing assessment.
     */
    public function onAttestationRevoked(
        int $attestorId,
        int $attestationId,
        string $targetKind,
        int $targetId,
        string $kind,
        int $targetOwnerId
    ): void {
        if ($attestorId <= 0 || $attestationId <= 0 || $targetOwnerId <= 0) {
            return;
        }
        if ($attestorId === $targetOwnerId) {
            return;
        }
        try {
            $actorHandle = self::resolveHandle($attestorId);
            $message     = $kind === 'stand_behind'
                ? sprintf('@%s is no longer standing behind you.', $actorHandle)
                : sprintf('@%s revoked their vouch.', $actorHandle);

            $this->dispatch(
                $attestorId,
                $targetOwnerId,
                $message,
                NotificationType::ATTESTATION_REVOKED,
                $attestationId,
                0
            );

            $this->pushDispatcher->enqueue($targetOwnerId, 'attestation_revoked', [
                'actor_handle'    => $actorHandle,
                'attestation_id'  => $attestationId,
                'target_kind'     => $targetKind,
                'target_id'       => $targetId,
                'kind'            => $kind,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] attestation-revoked dispatch failed', [
                'attestor_id'    => $attestorId,
                'attestation_id' => $attestationId,
                'target_kind'    => $targetKind,
                'target_id'      => $targetId,
                'kind'           => $kind,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_attestation_reaffirmed — soft renewal positive nudge
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the target operator on attestation reaffirmation. Soft
     * renewal positive signal — the recipient sees that the attestor
     * still endorses them. Light copy, push debounce coalesces bursts.
     */
    public function onAttestationReaffirmed(
        int $attestorId,
        int $attestationId,
        string $targetKind,
        int $targetId,
        string $kind,
        int $targetOwnerId
    ): void {
        if ($attestorId <= 0 || $attestationId <= 0 || $targetOwnerId <= 0) {
            return;
        }
        if ($attestorId === $targetOwnerId) {
            return;
        }
        try {
            $actorHandle = self::resolveHandle($attestorId);
            $message     = $kind === 'stand_behind'
                ? sprintf('@%s reaffirmed standing behind you.', $actorHandle)
                : sprintf('@%s reaffirmed their vouch.', $actorHandle);

            $this->dispatch(
                $attestorId,
                $targetOwnerId,
                $message,
                NotificationType::ATTESTATION_REAFFIRMED,
                $attestationId,
                0
            );

            $this->pushDispatcher->enqueue($targetOwnerId, 'attestation_reaffirmed', [
                'actor_handle'    => $actorHandle,
                'attestation_id'  => $attestationId,
                'target_kind'     => $targetKind,
                'target_id'       => $targetId,
                'kind'            => $kind,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] attestation-reaffirmed dispatch failed', [
                'attestor_id'    => $attestorId,
                'attestation_id' => $attestationId,
                'target_kind'    => $targetKind,
                'target_id'      => $targetId,
                'kind'           => $kind,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_divergence_state_warning — system-generated 24h heads-up
    // when an entity transitions INTO polarizing or disputed (§J.7)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the target operator when their entity transitioned into a
     * polarizing or disputed divergence state. PR-8b — fired by the
     * daily `PolarizationTransitionNotifier` worker, NOT by direct
     * mutation hooks (the state is derived, not user-cast).
     *
     * Recipient is the target owner — for entity cards that's the
     * page_author; for user_profile that's the user themselves. Caller
     * supplies the recipient already resolved.
     *
     * From-user is 0 (system event — no actor). PeepSoNotificationWriter
     * accepts 0 for system notifications. Deep-link target is
     * /me/reliability (the §J.5 self-mirror with the explainer body).
     *
     * Wording is calibrated per §2.7 — descriptive, never prescriptive.
     * "Your entity has transitioned into X" — no "you should attest
     * more" or "consider responding" nudge.
     *
     * @param int    $targetOwnerId Recipient user_id.
     * @param string $newState      DivergenceStateClassifier::STATE_*
     *                              (only polarizing/disputed in scope
     *                              — caller filters; other slugs are
     *                              silently dropped here).
     * @param string $targetKind    user_profile | *_card per §J.8.
     * @param int    $targetId      The entity row id.
     */
    public function onDivergenceStateTransitioned(
        int $targetOwnerId,
        string $newState,
        string $targetKind,
        int $targetId
    ): void {
        if ($targetOwnerId <= 0 || $targetId <= 0) {
            return;
        }
        // Only fire on the two states that warrant a heads-up. Any
        // other slug (well_regarded, untested, poorly_regarded) is a
        // non-event for notification purposes.
        if ($newState !== 'polarizing' && $newState !== 'disputed') {
            return;
        }

        try {
            $message = $newState === 'disputed'
                ? 'Your entity is now under active dispute. The panel mechanic will resolve it.'
                : 'Your entity reads as polarizing — reliable operators currently disagree on you.';

            // System event — but PeepSoNotificationWriter REJECTS sender
            // id <= 0 by contract ('invalid_user'), so the original
            // dispatch(0, …) here NEVER delivered a bell (found live
            // 2026-07-03 while wiring the holder-community-live bell,
            // which hit the identical wall). Send as the platform's
            // first administrator instead.
            $senderId = self::resolveSystemSenderId();
            if ($senderId <= 0) {
                Logger::warning('[NotificationDispatcher] divergence warning: no administrator to send as — bell skipped', [
                    'target_owner_id' => $targetOwnerId,
                ]);
                return;
            }

            $this->dispatch(
                $senderId,
                $targetOwnerId,
                $message,
                NotificationType::DIVERGENCE_STATE_WARNING,
                $targetId, // external_id → the target entity row
                0          // no associated activity row
            );

            // Parallel push enqueue. PushDispatcher's per-(recipient,
            // eventType) 5-minute debounce piles up — combined with
            // the notifier's 24h coalescing at the storage layer
            // (last_notified_at), the recipient sees at most one
            // push per state-transition.
            $this->pushDispatcher->enqueue($targetOwnerId, 'divergence_state_warning', [
                'new_state'    => $newState,
                'target_kind'  => $targetKind,
                'target_id'    => $targetId,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] divergence-state-warning dispatch failed', [
                'target_owner_id' => $targetOwnerId,
                'new_state'       => $newState,
                'target_kind'     => $targetKind,
                'target_id'       => $targetId,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // bcc_gated_group_provisioned — waitlist go-live bell
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify everyone who WAITLISTED a collection (explicit opt-in via
     * the stance panel) that its holder community is now live.
     *
     * This is the waitlist's payoff — verification converts directly
     * into founding members instead of an empty room. Idempotent: only
     * stance rows with `notified_at IS NULL` are fetched, and every
     * delivered user is stamped afterwards, so provisioning re-runs
     * (the daily sweep re-fires the hook path for its whole scan on
     * some flows) can never double-bell.
     *
     * System event — no human actor (same posture as the divergence
     * warning above).
     */
    public function onHolderCommunityProvisioned(int $groupId, int $collectionId, int $chainId, string $contract): void
    {
        $contract = strtolower(trim($contract));
        if ($groupId <= 0 || $chainId <= 0 || $contract === '') {
            return;
        }

        try {
            $pending = \BCC\Trust\Onchain\Repositories\CollectionSignalRepository::waitlistUserIdsPending($chainId, $contract);
            if ($pending === []) {
                return;
            }

            $name = null;
            if ($collectionId > 0) {
                foreach (\BCC\Trust\Onchain\Repositories\CollectionRepository::findManyByIds([$collectionId]) as $row) {
                    $name = is_string($row->collection_name ?? null) && $row->collection_name !== ''
                        ? (string) $row->collection_name
                        : null;
                }
            }

            $message = $name !== null
                ? sprintf("The %s holders community is live — you're on its waitlist and you qualify to join.", $name)
                : 'A holders community you waitlisted is live — you qualify to join.';

            // Sender = the group's owner (provisioning sets post_author).
            // PeepSoNotificationWriter REJECTS sender id <= 0 by contract
            // ('invalid_user'), so a bare "system" 0 never delivers —
            // caught live 2026-07-03; the divergence-state warning above
            // shares this latent bug (tracked separately).
            $senderId = (int) get_post_field('post_author', $groupId);
            if ($senderId <= 0) {
                Logger::warning('[NotificationDispatcher] holder-community-live: group has no owner — bell skipped', [
                    'group_id' => $groupId,
                ]);
                return;
            }

            // get_post_field is typed array<int>|int|string by the stubs;
            // post_name is always a string in practice — guard to satisfy L8.
            $groupSlugRaw = get_post_field('post_name', $groupId);
            $groupSlug    = is_string($groupSlugRaw) ? $groupSlugRaw : '';

            $delivered = [];
            foreach ($pending as $userId) {
                $this->dispatch(
                    $senderId,
                    $userId,
                    $message,
                    NotificationType::HOLDER_COMMUNITY_LIVE,
                    $groupId, // external_id → the PeepSo group
                    0         // no associated activity row
                );
                $delivered[] = $userId;

                $this->pushDispatcher->enqueue($userId, 'holder_community_live', [
                    'group_id'         => $groupId,
                    'group_slug'       => $groupSlug,
                    'collection_name'  => $name ?? '',
                    'collection_id'    => $collectionId,
                    'chain_id'         => $chainId,
                    'contract_address' => $contract,
                ]);
            }

            \BCC\Trust\Onchain\Repositories\CollectionSignalRepository::markNotified($chainId, $contract, $delivered);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] holder-community-live dispatch failed', [
                'group_id'      => $groupId,
                'collection_id' => $collectionId,
                'chain_id'      => $chainId,
                'contract'      => $contract,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Shared write path
    // ──────────────────────────────────────────────────────────────────

    private function dispatch(
        int $fromUserId,
        int $toUserId,
        string $message,
        string $type,
        int $externalId,
        int $actId
    ): void {
        // §I1 — per-event bell preference. When the recipient has
        // disabled this event type, drop the write before it touches
        // peepso_notifications. The originating action still completes
        // (per §A3 dispatchers don't break their producers); we just
        // skip the side-effect.
        if (!NotificationPrefs::isBellEnabled($toUserId, $type)) {
            return;
        }

        $result = PeepSoNotificationWriter::addNotification(
            $fromUserId,
            $toUserId,
            $message,
            $type,
            BCC_NOTIFICATION_MODULE_ID,
            $externalId,
            $actId
        );

        if ($result['ok'] === false) {
            Logger::warning('[NotificationDispatcher] write failed', [
                'from_user_id' => $fromUserId,
                'to_user_id'   => $toUserId,
                'type'         => $type,
                'reason'       => $result['reason'] ?? 'unknown',
            ]);
            return;
        }

        // Invalidate the recipient's /me/badges cache so the next poll
        // surfaces the new unread count. Single chokepoint — every
        // BCC-driven bell row flows through here. PeepSo-internal
        // bell writes (e.g. from peepso-messages itself) won't hit
        // this bump; the 15s TTL is the safety net for that case.
        // Skipped when PeepSo silently suppressed the write
        // (notification_id === 0 for opt-out / blocked).
        if (($result['notification_id'] ?? 0) > 0) {
            BadgesService::bumpForUser($toUserId);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Resolvers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Sender for system-originated bells (no human actor).
     *
     * PeepSo has no system-user concept and its notification writer
     * rejects sender id <= 0, so system events send as the platform's
     * first administrator (lowest user id, deterministic). Request-
     * cached — the admin set doesn't change mid-request.
     *
     * Returns 0 when no administrator exists (broken install — callers
     * log and skip rather than throw; a bell is never worth breaking
     * its producer per §A3).
     */
    private static function resolveSystemSenderId(): int
    {
        static $senderId = null;
        if ($senderId !== null) {
            return $senderId;
        }

        $admins = get_users([
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => 1,
            'fields'  => 'ID',
        ]);

        $senderId = isset($admins[0]) ? (int) $admins[0] : 0;
        return $senderId;
    }

    /**
     * Look up the post author the activity row points to. Returns 0
     * when the act / post is missing (deleted post, stale event).
     */
    private function resolveActAuthor(int $actId): int
    {
        return PeepSoActivityRepository::getAuthorId($actId);
    }

    private static function resolveHandle(int $userId): string
    {
        $handle = (string) get_user_meta($userId, 'bcc_handle', true);
        if ($handle !== '') {
            return $handle;
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return 'someone';
        }
        return $user->user_login !== '' ? $user->user_login : 'someone';
    }

    private static function resolvePageName(int $pageId): string
    {
        $post = get_post($pageId);
        if (!$post instanceof \WP_Post) {
            return '';
        }
        return (string) $post->post_title;
    }

    /**
     * Map trust reaction kinds to the verb that reads naturally in
     * the notification headline. Plain-English first per §N1 — the
     * user shouldn't have to learn the brand name to understand
     * what just happened.
     */
    private static function reactionVerb(string $kind): string
    {
        return match ($kind) {
            'solid' => 'agreed with',
            default => 'reacted to',
        };
    }
}
