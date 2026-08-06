<?php
/**
 * Comment Stoke Endpoint — handles /bcc/v1/comments/{id}/stoke.
 *
 * Routes registered:
 *   - POST   /comments/{id}/stoke — stoke a comment (idempotent)
 *   - DELETE /comments/{id}/stoke — un-stoke (idempotent)
 *
 * A PeepSo comment is itself a `peepso_activities` row with its own
 * act_id, and `bcc_trust_stokes` keys on (act_id, user_id) generically —
 * so stoking a comment is just a stoke on the comment's act_id, sharing
 * StokeRepository with the post rail. No schema change.
 *
 * Dedicated endpoint rather than a reuse of /feed/{id}/stoke because the
 * group gate MUST resolve membership off the comment's PARENT post: a
 * comment's own wp_post carries no `peepso_group_id`, so gating on the
 * comment's act_id (as GroupInteractionGate::check does for posts) would
 * silently pass every gated thread. We resolve the parent via
 * CommentRepository::getCommentMeta and gate with checkPost().
 *
 * Response is the comment's stoke pair only — `{ stoke_count,
 * viewer_has_stoked }`. No `heat_stage`: a comment is a plain like, not
 * the post's velocity rail.
 *
 * Stoke is cosmetic for trust — this endpoint never writes to
 * bcc_trust_scores.
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\GroupInteractionGate;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CommentStokeEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Per-user-per-minute throttle. Mirrors StokeEndpoint — one-per-target-per-person, generous. */
    private const STOKE_RATE_LIMIT  = 60;
    private const STOKE_RATE_WINDOW = 60;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/comments/(?P<id>\d+)/stoke',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'addStoke'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/comments/(?P<id>\d+)/stoke',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'removeStoke'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function addStoke(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handle($request, true);
    }

    public function removeStoke(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handle($request, false);
    }

    /**
     * Shared body for POST/DELETE — same auth, throttle, gate, and
     * response; only the write call differs.
     */
    private function handle(WP_REST_Request $request, bool $isAdd): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('comment_stoke', self::STOKE_RATE_LIMIT, self::STOKE_RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many stokes. Please wait.', 429);
        }

        // §M suspension-gate parity — participation writes opt out of
        // the suspension admin-bypass: a suspended member, admin
        // included, cannot stoke (either direction of the toggle).
        // Throttle stays FIRST (throttle-before-credentials rule).
        // Mirrors StokeEndpoint / ReactionsEndpoint.
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return ApiResponse::error('bcc_forbidden', 'Your account is suspended.', 403);
        }

        $commentActId = (int) $request->get_param('id');
        if ($commentActId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid comment id.', 400);
        }

        // Resolve the comment → its parent post, and gate on the parent's
        // group membership (the comment row carries no peepso_group_id).
        $meta = Plugin::instance()->commentRepository()->getCommentMeta($commentActId);
        if ($meta === null) {
            return ApiResponse::error('bcc_not_found', 'Comment not found.', 404);
        }

        $gateError = GroupInteractionGate::checkPost($userId, (int) $meta->parent_post_id);
        if ($gateError !== null) {
            return $gateError;
        }

        $stokeRepo = Plugin::instance()->stokeRepository();
        $ok = $isAdd
            ? $stokeRepo->addStoke($commentActId, $userId)
            : $stokeRepo->removeStoke($commentActId, $userId);
        if (!$ok) {
            return ApiResponse::error(
                'bcc_internal_error',
                $isAdd ? 'Failed to stoke.' : 'Failed to un-stoke.',
                500
            );
        }

        $state = [
            'stoke_count'       => $stokeRepo->countForActivity($commentActId),
            'viewer_has_stoked' => $stokeRepo->viewerHasStoked($commentActId, $userId),
        ];

        $response = ApiResponse::ok($state);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
