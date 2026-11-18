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
        $postId = self::resolveLiveTarget($actId);
        if ($postId === null) {
            return self::notFound();
        }

        if (!self::audienceAllows($userId, $actId, $postId)) {
            return self::notFound();
        }

        return self::checkPost($userId, $postId);
    }

    /**
     * The gate for the COMMENT routes (comment stoke, comment helpful-mark),
     * which take a comment act id and answer "Comment not found." for
     * anything they cannot act on.
     *
     * Order: the act must be a published comment; then it must be LIVE
     * (F-265: not hidden itself, and its parent post published and not
     * moderation-hidden, via {@see resolveLiveTarget()}); then the parent
     * post's membership gate ({@see checkPost()}). Every refusal before the
     * membership step is the route's own unknown-comment 404, so a removed
     * thread and a secret group's comment both read as a comment that does
     * not exist.
     */
    public static function checkComment(int $userId, int $commentActId): ?WP_REST_Response
    {
        $notFound = self::notFound(self::NOT_FOUND_COMMENT);
        $meta     = $commentActId > 0
            ? Plugin::instance()->commentRepository()->getCommentMeta($commentActId)
            : null;
        if ($meta === null) {
            return $notFound;
        }
        $parentPostId = self::resolveLiveTarget($commentActId);
        if ($parentPostId === null) {
            return $notFound;
        }

        // §F-02 — the PARENT post's audience. A comment carries none of its
        // own, and the meta is already in hand here, so this needs no
        // second read. ⚠ `parent_act_id` of 0 means the parent post has no
        // activity row, which is a REAL value (the repository selects
        // COALESCE(pa.act_id, 0)) — see audienceAllows() for why that
        // SKIPS the gate rather than refusing.
        $parentActId = (int) ($meta->parent_act_id ?? 0);
        if ($parentActId > 0
            && !\BCC\Trust\Core\Services\FeedItemReportVisibility::audienceAllows(
                $userId,
                $parentActId,
                $parentPostId
            )
        ) {
            return $notFound;
        }

        return self::checkPost($userId, $parentPostId, self::NOT_FOUND_COMMENT);
    }

    private const NOT_FOUND_COMMENT = 'Comment not found.';

    /**
     * The wp_post that gates an interaction with `$actId`, or NULL when the
     * act is not something anyone may still interact with: unknown, off the
     * feed allowlist, or NOT LIVE (F-265).
     *
     * Not live = the post (or, for a comment, its parent post) is trashed or
     * unpublished, or is moderation-hidden, or the comment itself is hidden.
     * Such a post is gone from every feed and its permalink 404s, so a
     * reaction, stoke or helpful-mark on it, and the bell that would follow,
     * must be refused the same way. The rule is
     * {@see FeedItemReportVisibility::isLiveWith()}, shared with the comment
     * routes and NotificationDispatcher.
     *
     * Membership is NOT checked here ({@see checkPost()} does that), so a
     * caller with no viewer (a notification) can use it as-is.
     */
    public static function resolveLiveTarget(int $actId): ?int
    {
        if ($actId <= 0) {
            return null;
        }
        $hidden = Plugin::instance()->hiddenActivityRepository();

        $comment = Plugin::instance()->commentRepository()->getCommentMeta($actId);
        if ($comment !== null) {
            $parentPostId = (int) $comment->parent_post_id;
            // A comment hanging off a trashed/draft parent is not reachable
            // on any feed, so it is "not found" too. (getCommentMeta only
            // answers for a PUBLISHED comment.)
            if ($parentPostId <= 0 || get_post_status($parentPostId) !== 'publish') {
                return null;
            }
            // F-265: nor one under a moderation-hidden parent, nor a hidden
            // comment. 0 = parent act not resolvable: nothing to look up.
            $parentActId = (int) ($comment->parent_act_id ?? 0);
            if (($parentActId > 0 && $hidden->exists($parentActId)) || $hidden->exists($actId)) {
                return null;
            }
            return $parentPostId;
        }

        $row = PeepSoActivityRepository::getById(
            $actId,
            \BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds()
        );
        if ($row === null) {
            return null;
        }
        $postId = (int) $row->act_external_id;
        if ($postId <= 0) {
            return null;
        }
        if (get_post_type($postId) === self::COMMENT_POST_TYPE) {
            // A comment act getCommentMeta() could not resolve to a
            // published parent. Never gate it on its own post row.
            return null;
        }
        if (!\BCC\Trust\Core\Services\FeedItemReportVisibility::isLiveWith($row, $hidden)) {
            return null;
        }

        return $postId;
    }

    /**
     * §F-02 — may this viewer see the POST behind this act at all?
     *
     * Without this, anyone holding a feed id could react to, stoke or
     * helpful-mark a followers-only or only-me post, and ring its author's
     * bell from content they were never shown. The rule itself is
     * {@see FeedItemReportVisibility::audienceAllows()}, which reuses
     * bcc-core's single SQL predicate — there is no second ladder here.
     *
     * ⚠ A COMMENT act is judged on its PARENT POST's act row, never its
     * own: the predicate reads `act_access`, and a comment does not
     * meaningfully carry one.
     *
     * ⚠⚠ AN UNRESOLVABLE PARENT ACT SKIPS THIS GATE, deliberately. It is a
     * KNOWN, ACCEPTED narrowing rather than an oversight, so here is
     * exactly what it costs.
     *
     * `CommentRepository::getCommentMeta()` selects
     * `COALESCE(pa.act_id, 0)` over a LEFT JOIN matching on BOTH
     * `act_external_id` AND `act_comment_module_id`, so 0 means "no
     * activity row matched that PAIR" — which is two different situations:
     *
     *   - the parent post genuinely has no activity row. Then it is in no
     *     feed and has no permalink, so the disclosure this gate closes
     *     does not exist for it, and refusing would only break ordinary
     *     interactions.
     *   - the MODULE half of the join did not match, in which case the
     *     parent activity DOES exist and may well be feed-visible. This
     *     one is a real, if narrow, gap: on such a comment a viewer who
     *     may not see the parent post can still stoke or helpful-mark it.
     *
     * The second case is why this is a trade and not a free pass. It is
     * accepted because these are WRITE paths — no post content and no
     * thread is disclosed, only interaction counts — because the
     * membership gate still applies (a closed-group comment is still
     * refused), and because failing closed would break interaction for the
     * first case, which is the one the repository's own docblock calls
     * "unresolvable".
     *
     * ⛔ Do NOT flip this to a refusal, or widen it, without first
     * measuring how often that module half fails in production. If it is
     * ~never, failing closed is strictly better and should be the
     * decision; this note exists so whoever checks need not re-derive the
     * question. `testAnUnresolvableParentActSkipsTheGate` pins the current
     * behaviour either way, so a change there is a conscious one.
     *
     * ⚠ Costs one extra indexed read by act id on these write paths. That
     * is the price of asking bcc-core's predicate instead of restating it;
     * restating it is what §11 forbids and what drifts.
     */
    private static function audienceAllows(int $userId, int $actId, int $postId): bool
    {
        $comment = Plugin::instance()->commentRepository()->getCommentMeta($actId);
        if ($comment !== null) {
            $parentActId = (int) ($comment->parent_act_id ?? 0);
            if ($parentActId <= 0) {
                return true; // cannot ask; see the docblock
            }

            return \BCC\Trust\Core\Services\FeedItemReportVisibility::audienceAllows(
                $userId,
                $parentActId,
                $postId
            );
        }

        return \BCC\Trust\Core\Services\FeedItemReportVisibility::audienceAllows(
            $userId,
            $actId,
            $postId
        );
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
