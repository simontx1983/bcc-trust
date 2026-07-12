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
    /**
     * Returns an error response to short-circuit on, or null when the
     * interaction is allowed (non-group post, or viewer is an active
     * member).
     */
    public static function check(int $userId, int $actId): ?WP_REST_Response
    {
        $row = PeepSoActivityRepository::getById($actId);
        if ($row === null) {
            // Unknown act — not a group-gate concern; let the caller's
            // own not-found/failure path surface it.
            return null;
        }
        $postId = (int) $row->act_external_id;
        if ($postId <= 0) {
            return null;
        }

        return self::checkPost($userId, $postId);
    }

    /**
     * Same membership gate as check(), but keyed by a resolved parent
     * wp_post ID rather than an activity id. Comment-stoke uses this: a
     * comment's own activity row carries no `peepso_group_id`, so the
     * gate must resolve membership off the PARENT post the comment hangs
     * under (its `act_comment_object_id`).
     */
    public static function checkPost(int $userId, int $postId): ?WP_REST_Response
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
        if ($access === null || $access['isMember'] !== true) {
            return ApiResponse::error(
                'bcc_permission_denied',
                'Join the group to react here.',
                403
            );
        }

        return null;
    }
}
