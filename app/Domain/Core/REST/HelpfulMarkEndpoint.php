<?php
/**
 * Helpful-Mark Endpoint — the deliberate §9.2 "Mark helpful" endorsement
 * on member content (posts AND comments).
 *
 * Routes registered:
 *   - POST   /feed/{id}/helpful      — mark a post act helpful (idempotent)
 *   - DELETE /feed/{id}/helpful      — un-mark (idempotent)
 *   - POST   /comments/{id}/helpful  — mark a comment act helpful
 *   - DELETE /comments/{id}/helpful  — un-mark
 *
 * A Helpful mark is NOT a reaction-kind write. Unlike /reactions
 * (cosmetic, PeepSo-backed) and /stoke (cosmetic feed heat), it is the
 * sanctioned, credibility-gated Rank "helping" evidence route (§9.2):
 * a credible marker's mark credits the CONTENT AUTHOR's Rank helping
 * category (subscriber wiring in Plugin.php resolves author + gates
 * credibility off the emitted `bcc_helpful_mark_added` event). The mark
 * store is deliberately its own table (`bcc_trust_helpful_marks`) so the
 * §8.5 line stays sharp: cosmetic reactions carry no Rank weight; this
 * deliberate mark does.
 *
 * Both a post and a comment are `peepso_activities` rows with their own
 * act_id, and `bcc_trust_helpful_marks` keys on (act_id, user_id)
 * generically. The ONLY difference is the group gate: a post gates on
 * its own act (GroupInteractionGate::check); a comment's own wp_post
 * carries no `peepso_group_id`, so a comment gates on its PARENT post
 * (checkPost) — same split StokeEndpoint / CommentStokeEndpoint use.
 *
 * Both routes are auth-required + throttled. Response (all four): the
 * mark pair `{ helpful_count, viewer_has_marked }`. Cache: `no-store`.
 *
 * @package BCC\Trust\Core\REST
 * @since Rank redesign (helping emitters)
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

final class HelpfulMarkEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Per-user-per-minute throttle. Mirrors the Stoke pair — deliberate, one-per-target-per-person. */
    private const MARK_RATE_LIMIT  = 60;
    private const MARK_RATE_WINDOW = 60;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/(?P<id>\d+)/helpful',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'addPostMark'],
                'permission_callback' => '__return_true',
            ]
        );
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/(?P<id>\d+)/helpful',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'removePostMark'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/comments/(?P<id>\d+)/helpful',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'addCommentMark'],
                'permission_callback' => '__return_true',
            ]
        );
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/comments/(?P<id>\d+)/helpful',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'removeCommentMark'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function addPostMark(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handle($request, true, false);
    }

    public function removePostMark(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handle($request, false, false);
    }

    public function addCommentMark(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handle($request, true, true);
    }

    public function removeCommentMark(WP_REST_Request $request): WP_REST_Response
    {
        return $this->handle($request, false, true);
    }

    /**
     * Shared body — same auth, throttle, gate, write, event, and
     * response for all four routes; only the group-gate resolution
     * (post act vs comment parent) and the write verb differ.
     */
    private function handle(WP_REST_Request $request, bool $isAdd, bool $isComment): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('helpful_mark', self::MARK_RATE_LIMIT, self::MARK_RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many marks. Please wait.', 429);
        }

        $actId = (int) $request->get_param('id');
        if ($actId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid id.', 400);
        }

        $gateError = $isComment
            ? $this->gateComment($userId, $actId)
            : GroupInteractionGate::check($userId, $actId);
        if ($gateError !== null) {
            return $gateError;
        }

        $repo = Plugin::instance()->helpfulMarkRepository();

        if ($isAdd) {
            $markId = $repo->addMark($actId, $userId);
            if ($markId <= 0) {
                return ApiResponse::error('bcc_internal_error', 'Failed to mark helpful.', 500);
            }
            // §A3-style event layered on the write. The Rank subscriber
            // (Plugin.php) resolves the content author, gates the marker's
            // §9.2 credibility, and mints helping evidence keyed on $markId.
            // Cosmetic / self / non-credible marks are recorded here but
            // mint NO Rank evidence — the gate lives in the subscriber.
            do_action('bcc_helpful_mark_added', $userId, $actId, $markId);
        } else {
            $markId = $repo->removeMark($actId, $userId);
            // Reverse only when a mark actually existed. A no-op un-mark
            // (markId 0) fires nothing — reversing a nonexistent source
            // would be harmless but pointless.
            if ($markId > 0) {
                do_action('bcc_helpful_mark_removed', $userId, $actId, $markId);
            }
        }

        $state = [
            'helpful_count'     => $repo->countForActivity($actId),
            'viewer_has_marked' => $repo->viewerHasMarked($actId, $userId),
        ];

        $response = ApiResponse::ok($state);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * Comment gate — resolve the comment act to its parent post and gate
     * on the parent's group membership (the comment row carries no
     * `peepso_group_id`). Mirrors CommentStokeEndpoint.
     */
    private function gateComment(int $userId, int $commentActId): ?WP_REST_Response
    {
        $meta = Plugin::instance()->commentRepository()->getCommentMeta($commentActId);
        if ($meta === null) {
            return ApiResponse::error('bcc_not_found', 'Comment not found.', 404);
        }

        return GroupInteractionGate::checkPost($userId, (int) $meta->parent_post_id);
    }
}
