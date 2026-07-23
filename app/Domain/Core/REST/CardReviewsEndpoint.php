<?php
/**
 * CardReviewsEndpoint — handles
 *   GET /bcc/v1/entities/{target_kind}/{target_id}/reviews
 *
 * Reviews-tab data plane for entity profiles (/v, /p, /c) AND member
 * profiles (/u — target_kind=user_profile). Mirrors the shape of
 * `/users/{handle}/reviews` but filters by `votes.page_id` instead of
 * `votes.voter_user_id` — i.e. "reviews filed against this target"
 * rather than "reviews this user wrote."
 *
 * For `user_profile`, target_id is the RAW user id; it translates to
 * the member's self-page (MemberSelfPageService::selfPageId) where
 * member-review votes live — same bridge the write path uses
 * (PostsEndpoint kind=review + target_kind=user_profile).
 *
 * Auth-OPTIONAL: anonymous viewers read reviews. Reviews are a
 * trust-evaluation signal viewers need regardless of session state.
 * Received member reviews are deliberately PUBLIC — the subject's
 * `reviews_hidden` privacy flag governs only the written list on
 * `/users/{handle}/reviews` (Phillip, 2026-07-22).
 *
 * Cache: matches `EntityAttestationsEndpoint` (the sibling by-target
 * read) — anon gets `public, max-age=30`; authed gets
 * `private, max-age=30` so future per-viewer fields can't leak through
 * a stale shared cache.
 *
 * Reuses `CardViewService` implicitly via the `target_id` contract
 * (card.id == wp_post.ID for the underlying PeepSo page); no card-
 * existence check here, because `CardReviewsService::getReviews`
 * returns an empty page when the page has no active votes — which is
 * also the correct response for a nonexistent or invalid `target_id`.
 *
 * @package BCC\Trust\Core\REST
 * @since 2026-05-14 (Phase 2 entity tab parity)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\CardReviewsService;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CardReviewsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /**
     * The three entity-card kinds plus member profiles. For
     * `user_profile`, target_id is the raw user id (translated to the
     * self-page in list()); `/users/{handle}/reviews` is the DIFFERENT
     * direction — reviews the user wrote.
     *
     * @var list<string>
     */
    private const ALLOWED_TARGET_KINDS = [
        'validator_card',
        'project_card',
        'creator_card',
        'user_profile',
    ];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/entities/(?P<target_kind>[a-z_]+)/(?P<target_id>\d+)/reviews',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                'permission_callback' => '__return_true',
                'args' => [
                    'target_kind' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'target_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $targetKind = (string) $request->get_param('target_kind');
        if (!in_array($targetKind, self::ALLOWED_TARGET_KINDS, true)) {
            return self::noStore(ApiResponse::error(
                'bcc_invalid_request',
                'Invalid target_kind.',
                400
            ));
        }

        $targetId = (int) $request->get_param('target_id');
        if ($targetId <= 0) {
            return self::noStore(ApiResponse::error(
                'bcc_invalid_request',
                'Invalid target_id.',
                400
            ));
        }

        // Member reviews live on the deterministic self-page id, never
        // the raw user id (same translation as CardViewService).
        $pageId = $targetKind === 'user_profile'
            ? MemberSelfPageService::selfPageId($targetId)
            : $targetId;

        $page    = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');
        $viewer  = get_current_user_id();

        $data = (new CardReviewsService(Plugin::instance()->voteRepository()))
            ->getReviews($pageId, $viewer, $page, $perPage);

        $response = ApiResponse::ok($data);
        if ($viewer > 0) {
            $response->header('Cache-Control', 'private, max-age=30');
            $response->header('Vary', 'Authorization, Cookie');
        } else {
            $response->header('Cache-Control', 'public, max-age=30');
        }
        return $response;
    }

    private static function noStore(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
