<?php
/**
 * Reactions Endpoint — handles /bcc/v1/reactions/* routes.
 *
 * Routes registered:
 *   - POST   /reactions          — set/replace viewer's reaction on an act
 *   - DELETE /reactions/:feed_id — remove viewer's reaction
 *
 * Both routes are auth-required. Auth is checked inside each handler
 * so unauthenticated requests return the canonical
 * `{error: {code, message, status}}` envelope.
 *
 * Contract:
 *   - Request body / URL identifies the target via `feed_id` (e.g.
 *     "feed_1234"), the same opaque string the feed item exposes.
 *     Server strips the prefix to recover the act_id; clients never
 *     see numeric IDs and never compute them.
 *
 *   - `reaction` accepts the §D5 kinds: 'solid' | 'vouch' | 'stand_behind'.
 *     The kind → reaction_type post-ID mapping lives in
 *     ReactionTypeRegistry (single source of truth).
 *
 *   - Response (both POST + DELETE):
 *       {
 *         counts: { solid: int, vouch: int, stand_behind: int },
 *         viewer_reaction: 'solid' | 'vouch' | 'stand_behind' | null
 *       }
 *     Same shape as the `reactions` block on FeedItem. Frontend
 *     applies the response directly without a feed refetch.
 *
 * All BCC writes route through bcc-core's `PeepSoReactionWriter`
 * (single-graph rule, mirrors PeepSoFollowWriter for reactions).
 *
 * Cache: `no-store`. Mutation responses are per-viewer + per-target;
 * never cacheable.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §D5 reactions)
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\PeepSo\PeepSoReactionWriter;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\ReactionTypeRegistry;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class ReactionsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Per-user-per-minute throttle. Reactions are inexpensive — generous budget. */
    private const REACT_RATE_LIMIT  = 60;
    private const REACT_RATE_WINDOW = 60;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/reactions',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'setReaction'],
                'permission_callback' => '__return_true',
                'args' => [
                    'feed_id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'reaction' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => ReactionTypeRegistry::ALL_KINDS,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/reactions/(?P<feed_id>[a-z0-9_]+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'removeReaction'],
                'permission_callback' => '__return_true',
                'args' => [
                    'feed_id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    public function setReaction(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('react', self::REACT_RATE_LIMIT, self::REACT_RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many reactions. Please wait.', 429);
        }

        $actId = self::parseFeedId((string) $request->get_param('feed_id'));
        if ($actId === null) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid feed_id.', 400);
        }

        $kind = (string) $request->get_param('reaction');
        $typeId = ReactionTypeRegistry::idFor($kind);
        if ($typeId === null) {
            // ReactionSeeder hasn't run yet, or the kind isn't seeded.
            // This is a server-state problem (admin action needed),
            // not a client error — fail with 503.
            return ApiResponse::error(
                'bcc_unavailable',
                'Reactions are not yet available. Try again shortly.',
                503
            );
        }

        if (!PeepSoReactionWriter::setReaction($actId, $typeId)) {
            return ApiResponse::error('bcc_internal_error', 'Failed to set reaction.', 500);
        }

        // §A3 — emit a BCC event layered on top of PeepSo's native
        // reaction hook. Subscribers (NotificationDispatcher today;
        // future analytics) listen to bcc_reaction_added, not the
        // PeepSo hook directly. Single emission per state change.
        do_action('bcc_reaction_added', $userId, $actId, $kind);

        return self::buildStateResponse($actId, $userId);
    }

    public function removeReaction(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('react', self::REACT_RATE_LIMIT, self::REACT_RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many reactions. Please wait.', 429);
        }

        $actId = self::parseFeedId((string) $request->get_param('feed_id'));
        if ($actId === null) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid feed_id.', 400);
        }

        if (!PeepSoReactionWriter::removeReaction($actId)) {
            return ApiResponse::error('bcc_internal_error', 'Failed to remove reaction.', 500);
        }

        // Symmetric counterpart to bcc_reaction_added. Subscribers can
        // tear down their own state (e.g. retract a notification that
        // was sent moments ago).
        do_action('bcc_reaction_removed', $userId, $actId);

        return self::buildStateResponse($actId, $userId);
    }

    /**
     * Compose the post-mutation response: kind→count map + the
     * viewer's current reaction. Same shape as FeedItem.reactions
     * so the frontend applies it directly to the cache without
     * shape translation.
     */
    private static function buildStateResponse(int $actId, int $viewerId): WP_REST_Response
    {
        $repo = Plugin::instance()->peepSoReactionRepository();

        // Kind label → numeric type ID (filtered to seeded kinds only).
        $kindToTypeId = [];
        foreach (ReactionTypeRegistry::ALL_KINDS as $kind) {
            $typeId = ReactionTypeRegistry::idFor($kind);
            if ($typeId !== null) {
                $kindToTypeId[$kind] = $typeId;
            }
        }

        // Reverse the map for fast type-id → kind lookup on the count rows.
        $typeIdToKind = array_flip($kindToTypeId);

        $counts = [];
        foreach (array_keys($kindToTypeId) as $kind) {
            $counts[$kind] = 0;
        }

        $rawCounts = $repo->countsByActId($actId);
        foreach ($rawCounts as $typeId => $count) {
            if (isset($typeIdToKind[$typeId])) {
                $counts[$typeIdToKind[$typeId]] = $count;
            }
        }

        $viewerTypeId  = $repo->viewerReactionForActId($actId, $viewerId);
        $viewerReaction = $viewerTypeId !== null && isset($typeIdToKind[$viewerTypeId])
            ? $typeIdToKind[$viewerTypeId]
            : null;

        $response = ApiResponse::ok([
            'counts'          => $counts,
            'viewer_reaction' => $viewerReaction,
        ]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * Parse a "feed_<n>" string into its numeric act_id. Returns
     * null on any deviation from the expected shape — clients should
     * round-trip the exact id the server emitted on the feed.
     */
    private static function parseFeedId(string $feedId): ?int
    {
        if (!str_starts_with($feedId, 'feed_')) {
            return null;
        }
        $rest = substr($feedId, 5);
        if ($rest === '' || !ctype_digit($rest)) {
            return null;
        }
        $value = (int) $rest;
        return $value > 0 ? $value : null;
    }
}
