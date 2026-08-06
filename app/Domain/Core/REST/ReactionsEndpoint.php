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
 *   - `reaction` accepts any kind from `ReactionGrammarMap::allKnownKinds()`
 *     across grammars: trust ('solid') and
 *     social ('like' | 'love' | 'haha' | 'wow' | 'fire'). The kind →
 *     reaction_type post-ID mapping lives in ReactionGrammarRegistry,
 *     which composes BCC-seeded IDs (ReactionTypeRegistry) with
 *     PeepSo-default IDs (looked up by post_title).
 *
 *   - Cross-grammar guard: a kind that doesn't belong to the post's
 *     grammar is rejected with `bcc_invalid_request`. Prevents writes
 *     like "fire on a review" or "solid on a status."
 *
 *   - Response (both POST + DELETE) — grammar-aware shape from
 *     api-contract-v1.md §2.11:
 *       {
 *         kind_grammar: 'trust' | 'social' | 'tribal',
 *         counts: { <kind>: int, ... },     // keys depend on grammar
 *         viewer_reaction: <kind> | null    // belongs to grammar
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
 * @since V1 (2026-04, §D5 reactions); v1.5 (2026-05, social grammar)
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\Feed\ReactionGrammarMap;
use BCC\Core\PeepSo\PeepSoReactionWriter;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\GroupInteractionGate;
use BCC\Trust\Core\Support\ReactionGrammarRegistry;
use BCC\Trust\Core\Support\ReactionStateComposer;
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
                        // All known kinds across grammars (trust + social).
                        // Cross-grammar mismatch is rejected at handler
                        // time, not enum time, so the error message can
                        // reference the post's actual grammar.
                        'enum'              => ReactionGrammarMap::allKnownKinds(),
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

        // §M suspension-gate parity — participation writes opt out of
        // the suspension admin-bypass: a suspended member, admin
        // included, cannot react. Throttle stays FIRST (throttle-
        // before-credentials rule). Mirrors MyGroupsEndpoint::postJoin.
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return ApiResponse::error('bcc_forbidden', 'Your account is suspended.', 403);
        }

        $actId = self::parseFeedId((string) $request->get_param('feed_id'));
        if ($actId === null) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid feed_id.', 400);
        }

        $gateError = GroupInteractionGate::check($userId, $actId);
        if ($gateError !== null) {
            return $gateError;
        }

        $kind = (string) $request->get_param('reaction');

        // Cross-grammar guard: the kind MUST belong to the post's
        // grammar. Prevents fire-on-review / solid-on-status writes
        // that would persist as orphan rows the rail never renders.
        $module  = PeepSoActivityRepository::getModuleIdByActId($actId) ?? '';
        $grammar = ReactionGrammarMap::grammarForModule($module);
        if (!ReactionGrammarMap::kindBelongsToGrammar($kind, $grammar)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'That reaction is not available on this post.',
                400
            );
        }

        $typeId = ReactionGrammarRegistry::idFor($kind);
        if ($typeId === null) {
            // BCC seed hasn't run yet, OR the PeepSo default for this
            // kind isn't discoverable (admin renamed/deleted the CPT
            // post). Server-state problem, not client error — 503.
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

        return self::buildStateResponse($actId, $userId, $grammar);
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

        // §M suspension-gate parity — same gate as setReaction (a
        // suspended account's reaction rail is frozen both ways).
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId, false)) {
            return ApiResponse::error('bcc_forbidden', 'Your account is suspended.', 403);
        }

        $actId = self::parseFeedId((string) $request->get_param('feed_id'));
        if ($actId === null) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid feed_id.', 400);
        }

        $gateError = GroupInteractionGate::check($userId, $actId);
        if ($gateError !== null) {
            return $gateError;
        }

        // Resolve the post's grammar so the response is in the right
        // shape regardless of whether the viewer had a prior reaction.
        $module  = PeepSoActivityRepository::getModuleIdByActId($actId) ?? '';
        $grammar = ReactionGrammarMap::grammarForModule($module);

        if (!PeepSoReactionWriter::removeReaction($actId)) {
            return ApiResponse::error('bcc_internal_error', 'Failed to remove reaction.', 500);
        }

        return self::buildStateResponse($actId, $userId, $grammar);
    }

    /**
     * Compose the post-mutation response: grammar discriminator +
     * kind→count map + the viewer's current reaction. Same shape as
     * FeedItem.reactions (api-contract-v1.md §2.11) so the frontend
     * applies it directly to the cache without shape translation.
     *
     * Caller passes the resolved grammar so we don't re-query the
     * activity row — set/remove already looked it up for the
     * cross-grammar guard. Composition itself lives in
     * ReactionStateComposer (§11 reuse — StokeEndpoint shares it).
     */
    private static function buildStateResponse(int $actId, int $viewerId, string $grammar): WP_REST_Response
    {
        $response = ApiResponse::ok(ReactionStateComposer::compose($actId, $viewerId, $grammar));
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
