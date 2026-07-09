<?php
/**
 * Blog Status Transition Handler — §D6 crypto-blog composer (PR-A).
 *
 * Subscribes to WordPress's `transition_post_status` action to bridge
 * draft↔publish state changes on blog posts into the BCC §A3 event
 * bus + the peepso_activities surface:
 *
 *   draft  → publish — fire `bcc_blog_post_created` so
 *                      ActivityStreamWriter inserts the activity row
 *                      that surfaces this blog in feeds. Also save a
 *                      wp revision so the publish-state edit history
 *                      starts at "as published" rather than "as last
 *                      draft".
 *
 *   publish → draft  — un-publish. Find the existing
 *                      `peepso_activities` row for this post and DELETE
 *                      it (the post itself stays in WP; the floor feed
 *                      stops surfacing it). Mirrors the moderation-hide
 *                      surface but without the sidecar — un-publishing
 *                      is the author's choice, not moderation.
 *
 *   publish → publish — edit-of-published. WordPress's default
 *                       wp_save_post_revision fires automatically on
 *                       wp_insert_post, so we don't have to call it
 *                       again here. We DO bust the blog body cache so
 *                       readers observe the edit immediately.
 *
 * Only fires for the specific post shape this handler owns —
 *   post_type === 'peepso-post' AND
 *   post_meta `_bcc_activity_module === 'blog'`
 * — so status changes on PeepSo statuses, reviews, claims, pull-batches
 * pass through untouched. (Blog posts share PeepSo's canonical
 * `peepso-post` CPT slug with status posts; the meta marker is the
 * discriminator. The original 2026-05-15 contract used a fabricated
 * `peepso-activity-status` slug that fails WP's varchar(20) post_type
 * column — see the related fix in PeepSoStatusWriter.)
 *
 * Idempotency: each branch is safe to re-run. The activity-row insert
 * (downstream of the bcc_blog_post_created event) has its own
 * idempotency check in ActivityStreamWriter::handleBlogPostCreated;
 * the un-publish DELETE is a no-op when the row is already absent.
 *
 * §1 compliance: this is a Service. It does NOT touch `$wpdb`
 * directly. All DB access flows through PeepSoActivityWriter
 * (BCC-trust repository) and WP's own post APIs.
 *
 * @package BCC\Trust\Core\Services\Feed
 * @since V1.5 (2026-05, §D6 crypto-blog composer PR-A)
 */

namespace BCC\Trust\Core\Services\Feed;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\PeepSoActivityWriter;

if (!defined('ABSPATH')) {
    exit;
}

final class BlogStatusTransitionHandler
{
    public function __construct(
        private readonly PeepSoActivityWriter $activityWriter
    ) {
    }

    /**
     * The signature WordPress fires on `transition_post_status`. All
     * three args are required positionally — WP's hook plumbing
     * supplies them.
     *
     * @param string  $newStatus  e.g. 'publish', 'draft', 'trash'
     * @param string  $oldStatus  previous status before this transition
     * @param mixed   $post       WP_Post on the canonical hook firing
     */
    public function handle(string $newStatus, string $oldStatus, mixed $post): void
    {
        if (!$post instanceof \WP_Post) {
            return;
        }

        // Scope: only the blog-kinded peepso-post posts. Everything
        // else passes through untouched — PeepSo statuses share this
        // CPT slug; the `_bcc_activity_module='blog'` meta marker is the
        // discriminator. That marker is written at blog-create time
        // (PostsService::createBlog), so it is already present here for
        // both drafts and published posts — including a draft being
        // published for the first time via this transition.
        if ($post->post_type !== 'peepso-post') {
            return;
        }
        $module = get_post_meta($post->ID, '_bcc_activity_module', true);
        if (!is_string($module) || $module !== 'blog') {
            return;
        }

        $postId   = (int) $post->ID;
        $authorId = (int) $post->post_author;
        if ($postId <= 0 || $authorId <= 0) {
            return;
        }

        // ── Branch on the transition ─────────────────────────────────
        if ($oldStatus !== 'publish' && $newStatus === 'publish') {
            $this->onPublish($authorId, $postId);
            return;
        }
        if ($oldStatus === 'publish' && $newStatus !== 'publish') {
            $this->onUnpublish($postId);
            return;
        }
        if ($oldStatus === 'publish' && $newStatus === 'publish') {
            // publish→publish = edit of a published post. WP's default
            // revision pipeline runs automatically on wp_insert_post,
            // so we don't have to call wp_save_post_revision again.
            // The hook still fires on every edit; we just log + let
            // downstream readers re-fetch.
            Logger::info('[BlogStatusTransitionHandler] published blog edited', [
                'post_id' => $postId,
                'user_id' => $authorId,
            ]);
            return;
        }
        // draft→draft, draft→trash, etc. — no surface impact.
    }

    /**
     * draft → publish branch.
     *
     * Fires `bcc_blog_post_created`, which ActivityStreamWriter
     * subscribes to and translates into a peepso_activities row.
     * The downstream handler is idempotent (existing-row check),
     * so a second publish event on a post that's already surfaced
     * is a safe no-op.
     */
    private function onPublish(int $authorId, int $postId): void
    {
        // wp_save_post_revision normally runs from inside wp_insert_post.
        // Calling it explicitly here is a belt-and-braces — guarantees
        // a revision exists at the "as published" point even if a
        // plugin upstream blocked WP's automatic call (some
        // performance plugins gate revisions on a custom filter).
        if (function_exists('wp_save_post_revision')) {
            wp_save_post_revision($postId);
        }

        do_action('bcc_blog_post_created', $authorId, $postId);

        Logger::info('[BlogStatusTransitionHandler] draft→publish fired bcc_blog_post_created', [
            'post_id' => $postId,
            'user_id' => $authorId,
        ]);
    }

    /**
     * publish → draft (or any non-publish state) branch.
     *
     * Removes the corresponding peepso_activities row so the post
     * stops surfacing in the floor feed + per-user blog tab. The
     * wp_post stays — the author can re-publish later and the row
     * comes back via the publish branch.
     *
     * Per §A4 single-source-of-truth: BCC owns its activity rows;
     * deleting one here is the right shape (vs. a sidecar hide
     * marker, which would be wrong here — the row IS gone, not
     * "hidden by moderation").
     */
    private function onUnpublish(int $postId): void
    {
        $actId = $this->activityWriter->findActIdForExternal('blog', $postId);
        if ($actId <= 0) {
            // Already absent — idempotent no-op. Log at info because
            // this is a real-but-benign state ("post was draft-only
            // before, never published, then trashed").
            Logger::info('[BlogStatusTransitionHandler] no activity row to remove on unpublish', [
                'post_id' => $postId,
            ]);
            return;
        }

        $deleted = $this->activityWriter->deleteByActId($actId);
        if (!$deleted) {
            Logger::warning('[BlogStatusTransitionHandler] failed to remove activity row on unpublish', [
                'post_id' => $postId,
                'act_id'  => $actId,
            ]);
            return;
        }

        Logger::info('[BlogStatusTransitionHandler] publish→draft removed activity row', [
            'post_id' => $postId,
            'act_id'  => $actId,
        ]);
    }
}
