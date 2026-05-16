<?php
/**
 * First-Action Listener — closes the Day-1 recognition gap by stashing
 * a Heavy-tier celebration the first time a user takes each of three
 * meaningful actions:
 *
 *   - first_post                  — first status post the user authors
 *   - first_review                — first vote+explanation (review) the user publishes
 *   - first_blog                  — first blog post the user publishes
 *   - first_watcher               — first time someone else watches the user's
 *                                   page or follows them as a member
 *   - first_endorsement_received  — first time someone endorses a page the user owns
 *
 * The latter two are the social-gravity retention moments — they
 * close the audit's #1 weak loop ("someone chose to keep tabs on
 * your work" was bell-only with no payoff). They fire on the
 * RECIPIENT, not the actor — which means a brand-new account that
 * doesn't even know about reactions yet gets recognition the moment
 * another human's signal lands on them.
 *
 * Self-action guard: when the actor IS the recipient (e.g. the
 * page owner pulls their own card during testing, or an endorser
 * is the page owner), the listener no-ops. Celebrations require a
 * second human.
 *
 * What's still deliberately deferred:
 *   - first_local_joined                  → PeepSo
 *     `peepso_action_group_user_join` hook needed; out of scope here.
 *   - first_reply_received                → comments
 *     hook + owner-of-comment-target chain.
 *   - first_vouch_given                   → no clean
 *     `bcc_reaction_added` actor-only hook exposed for this listener.
 *
 * Tone: each label uses the civic-instrument register the platform
 * already speaks — "On the record.", "On the books." — not arcade-
 * game superlatives. The icon string is a stable frontend key the
 * CelebrationToast component maps to an SVG asset.
 *
 * Idempotency: each first-action type is one-shot per user lifetime,
 * gated by a dedicated user_meta flag. The first event a user
 * triggers stashes the celebration AND sets the flag; all subsequent
 * events of the same kind no-op. Re-running activation/migration
 * cannot re-fire celebrations because the meta survives.
 *
 * Seed-quietly invariant: this listener does NOT back-fill for users
 * who already have prior activity. The flag exists from this commit
 * onward — only users whose first action lands AFTER deployment get
 * a celebration. Backfilling would generate a wave of toasts for
 * established users at deploy time and would feel synthetic.
 *
 * @package BCC\Trust\Core\Services
 * @since V1.5 retention pass (2026-05-13)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\CelebrationStash;

if (!defined('ABSPATH')) {
    exit;
}

final class FirstActionListener
{
    /**
     * User-meta keys gating each first-action celebration. One key per
     * action type — never reused, never sharded — so adding a new
     * first-action celebration in the future doesn't migrate existing
     * users out of recognition for actions they've already done.
     */
    private const META_FIRST_POST                = 'bcc_first_post_celebrated';
    private const META_FIRST_REVIEW              = 'bcc_first_review_celebrated';
    private const META_FIRST_BLOG                = 'bcc_first_blog_celebrated';
    private const META_FIRST_WATCHER             = 'bcc_first_watcher_celebrated';
    private const META_FIRST_ENDORSEMENT_RECEIVED = 'bcc_first_endorsement_received_celebrated';

    /**
     * Stable kind/icon pairs the frontend CelebrationToast maps to
     * presets. Each `kind` is a separate animation variant so the
     * toast for a first review feels different from a first blog,
     * even though both are stash-and-pull.
     */
    private const KIND_FIRST_POST                 = 'first_post';
    private const KIND_FIRST_REVIEW               = 'first_review';
    private const KIND_FIRST_BLOG                 = 'first_blog';
    private const KIND_FIRST_WATCHER              = 'first_watcher';
    private const KIND_FIRST_ENDORSEMENT_RECEIVED = 'first_endorsement_received';

    private const ICON_FIRST_POST                 = 'first-post';
    private const ICON_FIRST_REVIEW               = 'first-review';
    private const ICON_FIRST_BLOG                 = 'first-blog';
    private const ICON_FIRST_WATCHER              = 'first-watcher';
    private const ICON_FIRST_ENDORSEMENT_RECEIVED = 'first-endorsement-received';

    /**
     * `bcc_post_created` handler. Hook signature:
     *   (int $authorId, int $postId, int $actId)
     *
     * We only use $authorId; the others are intentionally typed-wide
     * so future telemetry consumers can attach without churn.
     */
    public function onPostCreated(int $authorId): void
    {
        $this->stashFirstAction(
            $authorId,
            self::META_FIRST_POST,
            self::KIND_FIRST_POST,
            'First post on the floor.',
            self::ICON_FIRST_POST,
            'post_created'
        );
    }

    /**
     * `bcc_review_published` handler. Hook signature:
     *   (int $authorId, int $pageId, int $voteId, string $explanation)
     *
     * Fires AFTER a vote+explanation is written — reviews are the
     * highest-effort first action a new user can take.
     */
    public function onReviewPublished(int $authorId): void
    {
        $this->stashFirstAction(
            $authorId,
            self::META_FIRST_REVIEW,
            self::KIND_FIRST_REVIEW,
            'First review on the record.',
            self::ICON_FIRST_REVIEW,
            'review_published'
        );
    }

    /**
     * `bcc_blog_post_created` handler. Hook signature:
     *   (int $authorId, int $postId)
     *
     * Blog posts are long-form authoring — a different first than
     * a status post. We celebrate both so the user understands the
     * two surfaces are separate routes to standing.
     */
    public function onBlogPostCreated(int $authorId): void
    {
        $this->stashFirstAction(
            $authorId,
            self::META_FIRST_BLOG,
            self::KIND_FIRST_BLOG,
            'First letter from the floor.',
            self::ICON_FIRST_BLOG,
            'blog_post_created'
        );
    }

    /**
     * `bcc_card_pulled` handler — fires on the RECIPIENT (the owner of
     * the watched page, or the user-being-followed in user→user follows).
     *
     * Hook signature:
     *   (int $viewerId, int $followId, string $targetKind, int $targetId)
     *
     * Resolution of "who got watched":
     *   - targetKind === 'user'  → recipient IS $targetId (direct user follow)
     *   - targetKind === 'page'  → recipient is the page-owner of $targetId
     *
     * Self-follow guard: when the viewer IS the recipient (a user
     * pulled their own card during testing, or a corrupt event), the
     * listener no-ops. First-watcher requires a second human.
     *
     * This is the load-bearing social-gravity celebration. Pre-cleanup
     * the audit identified "someone watched your card" as emotionally
     * high-value but offline-invisible — bell-only, no celebration. The
     * first-time stash converts a passive notification into a recorded
     * moment the recipient sees on their next session.
     */
    public function onCardPulled(int $viewerId, int $followId, string $targetKind, int $targetId): void
    {
        $recipientId = $this->resolvePullRecipient($targetKind, $targetId);
        if ($recipientId <= 0 || $recipientId === $viewerId) {
            return;
        }

        $this->stashFirstAction(
            $recipientId,
            self::META_FIRST_WATCHER,
            self::KIND_FIRST_WATCHER,
            'First watcher on your card.',
            self::ICON_FIRST_WATCHER,
            'card_pulled'
        );
    }

    /**
     * `bcc_trust_endorsement_added` handler — fires on the RECIPIENT
     * (the owner of the endorsed page).
     *
     * Hook signature:
     *   (int $endorserUserId, int $pageId, string $context)
     *
     * Self-endorse guard: when the endorser is the page owner, the
     * listener no-ops. (The endorse service itself usually rejects
     * self-endorsements upstream, but the guard is defense-in-depth.)
     *
     * Why endorsements specifically: an endorsement is a vested trust
     * bonus on the recipient's page — costly to give, reputational on
     * the giver, and durable. First endorsement received is the moment
     * the platform's commitment ladder paid off for the user without
     * them having to do anything.
     */
    public function onEndorsementAdded(int $endorserUserId, int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        $ownerId = (int) Plugin::instance()->scoreRepository()->getPageOwnerId($pageId);
        if ($ownerId <= 0 || $ownerId === $endorserUserId) {
            return;
        }

        $this->stashFirstAction(
            $ownerId,
            self::META_FIRST_ENDORSEMENT_RECEIVED,
            self::KIND_FIRST_ENDORSEMENT_RECEIVED,
            'First endorsement on your page.',
            self::ICON_FIRST_ENDORSEMENT_RECEIVED,
            'endorsement_added'
        );
    }

    /**
     * Resolve the user id that should receive the first_watcher
     * celebration given a `bcc_card_pulled` hook payload. Encapsulates
     * the two watch target shapes so the hook handler stays
     * single-line at the call site.
     *
     *   - targetKind === 'user' → the target IS a user id (direct follow)
     *   - targetKind === 'page' → look up the page-owner via the
     *     existing ScoreRepository::getPageOwnerId helper
     *
     * Returns 0 (which the caller treats as "skip") for unknown kinds
     * or when the page-owner lookup yields no row. This keeps the
     * listener forward-compatible if a future target kind (group,
     * collection) is added: it no-ops cleanly until a resolver lands.
     */
    private function resolvePullRecipient(string $targetKind, int $targetId): int
    {
        if ($targetId <= 0) {
            return 0;
        }

        if ($targetKind === 'user') {
            return $targetId;
        }

        if ($targetKind === 'page') {
            $owner = Plugin::instance()->scoreRepository()->getPageOwnerId($targetId);
            return (int) $owner;
        }

        return 0;
    }

    /**
     * Shared one-shot stashing logic.
     *
     * Idempotent: reading the gate meta is a single usermeta call
     * (cached by WP object cache); writing the gate + the celebration
     * is two writes only on the first-ever invocation. Every later
     * invocation does one read and returns.
     *
     * No-ops on anonymous/system events (`$userId <= 0`). Logs every
     * actual stashing so ops can confirm rollout impact in the audit
     * log; quietly skips on subsequent invocations to avoid log spam.
     */
    private function stashFirstAction(
        int $userId,
        string $metaKey,
        string $kind,
        string $label,
        string $icon,
        string $hookContext
    ): void {
        if ($userId <= 0) {
            return;
        }

        $already = get_user_meta($userId, $metaKey, true);
        if (is_string($already) && $already !== '') {
            return;
        }

        // Set the gate FIRST so even a re-entrant hook fire (cron + sync
        // path racing) emits exactly one celebration. CelebrationStash
        // is single-slot per-user; double-firing would overwrite a
        // pre-existing celebration which is also fine, but the gate
        // makes the intent explicit.
        update_user_meta($userId, $metaKey, (string) time());

        CelebrationStash::pushHeavy($userId, $kind, $label, $icon);

        Logger::info('[FirstActionListener] first-action celebration stashed', [
            'user_id' => $userId,
            'kind'    => $kind,
            'hook'    => $hookContext,
        ]);
    }
}
