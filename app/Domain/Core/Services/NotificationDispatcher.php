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
 *   - bcc_card_pulled            → notify followee user
 *   - bcc_rank_awarded           → notify the user (self-notification
 *                                   — gives an audit trail beyond the
 *                                   §O1.2 Heavy toast)
 *   - bcc_trust_endorsement_added → notify page owner (V1.5 follow-up)
 *
 * Deferred (per §P parking lot): @mentions (composer v2),
 * follow-posts (cross-graph, expensive), comments (PeepSo native
 * surface). Each will land as its source event reaches V1.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §I1)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Core\PeepSo\PeepSoNotificationWriter;
use BCC\Core\Repositories\PeepSoActivityRepository;
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
    // bcc_reaction_added — Solid/Vouch/Stand-behind on a post
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the post author when someone reacts to their post.
     *
     * Skips when the actor IS the author (self-reactions don't
     * generate noise — the user already knows they reacted).
     *
     * @param string $kind §D5 reaction key — 'solid', 'vouch', 'stand_behind'
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
    // bcc_card_pulled — someone followed (pulled) you
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the followee when someone pulls their card. The
     * followee is always a user id (binder is a projection of
     * PeepSo follows — see §C2). Self-pulls are not possible from
     * the binder UI; we still guard.
     *
     * Note: pulls run through C3's batch aggregator on the feed
     * side (one feed item per 10-min window). The notification side
     * is intentionally per-event for now — a pull-batch-collapse
     * pattern would be V2 work after we see the volume.
     *
     * @param string $targetKind 'validator'|'project'|'creator'|'member'
     */
    public function onCardPulled(int $viewerId, int $followId, string $targetKind, int $targetId): void
    {
        unset($followId, $targetKind);
        if ($viewerId <= 0 || $targetId <= 0 || $viewerId === $targetId) {
            return;
        }
        try {
            $actorHandle = self::resolveHandle($viewerId);
            $message     = sprintf('@%s pulled your card.', $actorHandle);

            $this->dispatch(
                $viewerId,
                $targetId,
                $message,
                NotificationType::CARD_PULLED,
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

    // ──────────────────────────────────────────────────────────────────
    // bcc_trust_endorsement_added — endorsement on a page
    // ──────────────────────────────────────────────────────────────────

    /**
     * Notify the page owner when someone endorses their page.
     * Skips self-endorsements (defense-in-depth — EndorsementService
     * already rejects them, but the dispatcher stays safe even if a
     * future code path sneaks one through).
     *
     * Context isn't surfaced in the message: V1 only supports the
     * 'general' context per the controller's allowlist, so no
     * disambiguation needed. Context arg is preserved on the
     * subscriber for forward compatibility.
     */
    public function onEndorseAdded(int $endorserId, int $pageId, string $context): void
    {
        unset($context); // Reserved for future context-aware messaging.
        if ($endorserId <= 0 || $pageId <= 0) {
            return;
        }
        try {
            $ownerId = $this->pageOwnerResolver->getPageOwner($pageId);
            if ($ownerId <= 0 || $ownerId === $endorserId) {
                return;
            }

            $actorHandle = self::resolveHandle($endorserId);
            $pageName    = self::resolvePageName($pageId);
            $message     = $pageName !== ''
                ? sprintf('@%s endorsed %s.', $actorHandle, $pageName)
                : sprintf('@%s endorsed your page.', $actorHandle);

            $this->dispatch(
                $endorserId,
                $ownerId,
                $message,
                NotificationType::ENDORSE,
                $pageId,
                0
            );

            // V2 Phase 1: parallel push enqueue. Same self-gating
            // semantics as review (above).
            $this->pushDispatcher->enqueue($ownerId, 'endorse', [
                'actor_handle' => $actorHandle,
                'page_id'      => $pageId,
                'page_name'    => $pageName,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('[NotificationDispatcher] endorse dispatch failed', [
                'endorser_id' => $endorserId,
                'page_id'     => $pageId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

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
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Resolvers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Look up the post author the activity row points to. Returns 0
     * when the act / post is missing (deleted post, stale event).
     */
    private function resolveActAuthor(int $actId): int
    {
        $row = PeepSoActivityRepository::getById($actId);
        if ($row === null) {
            return 0;
        }
        $postId = isset($row->act_external_id) ? (int) $row->act_external_id : 0;
        if ($postId <= 0) {
            return 0;
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return 0;
        }
        return (int) $post->post_author;
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
     * Map §D5 reaction kinds to the verb that reads naturally in
     * the notification headline. Plain-English first per §N1 — the
     * user shouldn't have to learn the brand name to understand
     * what just happened.
     */
    private static function reactionVerb(string $kind): string
    {
        return match ($kind) {
            'solid'        => 'agreed with',
            'vouch'        => 'vouched for',
            'stand_behind' => 'is standing behind',
            default        => 'reacted to',
        };
    }
}
