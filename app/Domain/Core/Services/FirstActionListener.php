<?php
/**
 * First-Action Listener — closes the Day-1 recognition gap by stashing
 * a Heavy-tier celebration the first time a user takes each of three
 * meaningful actions:
 *
 *   - first_post   — first status post the user authors
 *   - first_review — first vote+explanation (review) the user publishes
 *   - first_blog   — first blog post the user publishes
 *
 * Why these three (and not more): each is unambiguously triggered by
 * an existing `bcc_*` action hook with `(authorId)` in the args — no
 * new queries, no target-user lookups, no new abstractions. They
 * collectively cover the "I appeared on the floor" arc that the
 * retention audit flagged as the single biggest Day-1 dead zone.
 *
 * What's deliberately deferred:
 *   - first_watcher (someone watched MY card)            → needs an
 *     owner-of-target lookup on `bcc_card_pulled`; the hook today
 *     gives the puller's id + the target page-or-user id, not the
 *     owner of the target. Worth adding once a target-owner helper
 *     lands.
 *   - first_local_joined                                  → PeepSo
 *     `peepso_action_group_user_join` hook needed; out of scope here.
 *   - first_reply_received                                → comments
 *     hook + owner-of-comment-target chain.
 *   - first_vouch_given                                   → no clean
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
    private const META_FIRST_POST   = 'bcc_first_post_celebrated';
    private const META_FIRST_REVIEW = 'bcc_first_review_celebrated';
    private const META_FIRST_BLOG   = 'bcc_first_blog_celebrated';

    /**
     * Stable kind/icon pairs the frontend CelebrationToast maps to
     * presets. Each `kind` is a separate animation variant so the
     * toast for a first review feels different from a first blog,
     * even though both are stash-and-pull.
     */
    private const KIND_FIRST_POST   = 'first_post';
    private const KIND_FIRST_REVIEW = 'first_review';
    private const KIND_FIRST_BLOG   = 'first_blog';

    private const ICON_FIRST_POST   = 'first-post';
    private const ICON_FIRST_REVIEW = 'first-review';
    private const ICON_FIRST_BLOG   = 'first-blog';

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
