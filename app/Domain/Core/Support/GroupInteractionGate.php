<?php
/**
 * Group Interaction Gate — §4.7.6 membership check shared by every
 * write-side endpoint that acts on an activity (reactions, stoke).
 *
 * A write on a GROUP-scoped post requires active group membership —
 * even when the post itself is publicly readable (`public_group` /
 * `public_all`). Non-members may READ those posts but comments +
 * reactions + stoke stay gated behind membership (e.g. NFT ownership).
 * Mirrors the membership check in CommentService::gateForParent,
 * reusing the canonical GroupsService::resolveGroupAccess seam.
 *
 * Extracted from ReactionsEndpoint (§11 reuse) so StokeEndpoint
 * doesn't duplicate the same five lines of post-meta + access lookup.
 *
 * @package BCC\Trust\Core\Support
 */

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Plugin;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupInteractionGate
{
    /** wp_posts.post_type of a PeepSo comment's own post row. */
    private const COMMENT_POST_TYPE = 'peepso-comment';

    /**
     * Returns an error response to short-circuit on, or null when the
     * interaction is allowed (non-group post, or viewer is an active
     * member).
     *
     * ⚠ Accepts ANY act id, and a comment is an act too. A comment's own
     * wp_post carries no `peepso_group_id`, so gating on it directly would
     * wave every comment in a closed or secret group through. A comment act
     * is therefore gated on its PARENT post, the same resolution
     * CommentStokeEndpoint uses (CommentRepository::getCommentMeta +
     * checkPost).
     *
     * Fails CLOSED on an act it cannot place, with a 404. The writers behind
     * this gate (stoke, helpful-mark) insert blindly, so a null here used to
     * let them write rows against a nonexistent act. A comment act whose
     * parent can't be resolved (e.g. unpublished) gets the same 404 and is
     * never gated on its own post row.
     *
     * Only FEED activities qualify (F-163): the lookup is restricted to
     * FeedItemNormalizer::publicFeedModuleIds(), the same canonical
     * allowlist the feed and the single-item permalink gate enforce. A DM
     * (PeepSo module 6), a group-join stream row or any unknown module is
     * an act too, but it is not something a feed route may touch, so it
     * gets the same 404 as an act that does not exist.
     */
    public static function check(int $userId, int $actId): ?WP_REST_Response
    {
        $comment = Plugin::instance()->commentRepository()->getCommentMeta($actId);
        if ($comment !== null) {
            $parentPostId = (int) $comment->parent_post_id;
            // A comment hanging off a trashed/draft parent is not reachable
            // on any feed, so it is "not found" too.
            if ($parentPostId <= 0 || get_post_status($parentPostId) !== 'publish') {
                return self::notFound();
            }
            return self::checkPost($userId, $parentPostId);
        }

        $row = PeepSoActivityRepository::getById(
            $actId,
            \BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds()
        );
        if ($row === null) {
            return self::notFound();
        }
        $postId = (int) $row->act_external_id;
        if ($postId <= 0) {
            return self::notFound();
        }
        if (get_post_type($postId) === self::COMMENT_POST_TYPE) {
            // A comment act getCommentMeta() could not resolve to a
            // published parent. Never gate it on its own post row.
            return self::notFound();
        }

        return self::checkPost($userId, $postId);
    }

    private const NOT_FOUND_POST = 'Post not found.';

    private static function notFound(string $message = self::NOT_FOUND_POST): WP_REST_Response
    {
        return ApiResponse::error('bcc_not_found', $message, 404);
    }

    /**
     * Same membership gate as check(), but keyed by a resolved parent
     * wp_post ID rather than an activity id. Comment-stoke uses this: a
     * comment's own activity row carries no `peepso_group_id`, so the
     * gate must resolve membership off the PARENT post the comment hangs
     * under (its `act_comment_object_id`).
     *
     * ⚠ EXISTENCE ORACLE (F-163). resolveGroupAccess() returns null for
     * "no such group" AND for "secret group, viewer not a member". That must
     * read as NOT FOUND, byte-identical to the caller's own unknown-id 404.
     * A "join the group" 403 there would confirm a secret group's post or
     * comment exists. The 403 stays for closed and open groups, whose
     * existence is public.
     *
     * $notFoundMessage is the caller's OWN unknown-id message, so the bodies
     * match exactly. The comment routes say "Comment not found." for an
     * unknown comment, so they pass that.
     */
    public static function checkPost(int $userId, int $postId, string $notFoundMessage = self::NOT_FOUND_POST): ?WP_REST_Response
    {
        if ($postId <= 0) {
            return null;
        }

        $groupId = (int) get_post_meta($postId, 'peepso_group_id', true);
        if ($groupId <= 0) {
            // Not a group-scoped post — no membership gate (PeepSo's own
            // block-by-author rules still apply downstream).
            return null;
        }

        $access = Plugin::instance()->groupsService()->resolveGroupAccess($userId, $groupId);
        if ($access === null) {
            // Missing group, or SECRET + non-member: indistinguishable from
            // an id that does not exist.
            return self::notFound($notFoundMessage);
        }
        if ($access['isMember'] !== true) {
            return ApiResponse::error(
                'bcc_permission_denied',
                'Join the group to react here.',
                403
            );
        }

        return null;
    }
}
