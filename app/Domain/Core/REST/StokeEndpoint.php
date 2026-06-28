<?php
/**
 * Stoke Endpoint — handles /bcc/v1/feed/{id}/stoke.
 *
 * Routes registered:
 *   - POST   /feed/{id}/stoke — stoke (idempotent: one per person)
 *   - DELETE /feed/{id}/stoke — un-stoke (idempotent)
 *
 * Both routes are auth-required. Auth is checked inside each handler so
 * unauthenticated requests return the canonical error envelope.
 *
 * Stoke is NOT a reaction-kind write — unlike /reactions (set/replace,
 * one kind per viewer, backed by PeepSo's peepso_reactions), Stoke is
 * backed by its own bcc_trust_stokes table via StokeRepository rather
 * than PeepSoReactionWriter (X-"like" model: one stoke per person, the
 * row's existence IS the stoke, no accumulate/cap). It slots into the
 * same surrounding architecture though: same Throttle pattern, same
 * GroupInteractionGate membership check, same bcc_reaction_added-style
 * event-emission convention, and the same response shape (api-contract
 * -v1.md §2.11's reactions block) — extended with `heat_stage` (aggregate
 * heat) + `viewer_has_stoked` + `stoke_count` (public total), all
 * additive.
 *
 * Stoke is cosmetic for trust — this endpoint never writes to
 * bcc_trust_scores.
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\Feed\ReactionGrammarMap;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\GroupInteractionGate;
use BCC\Trust\Core\Support\ReactionStateComposer;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class StokeEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Per-user-per-minute throttle. Generous — Stoke is now one-per-post-per-person, not a repeat-tap accumulator. */
    private const STOKE_RATE_LIMIT  = 60;
    private const STOKE_RATE_WINDOW = 60;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/(?P<id>\d+)/stoke',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'addStoke'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/feed/(?P<id>\d+)/stoke',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'removeStoke'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function addStoke(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('stoke', self::STOKE_RATE_LIMIT, self::STOKE_RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many stokes. Please wait.', 429);
        }

        $actId = (int) $request->get_param('id');
        if ($actId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid post id.', 400);
        }

        $gateError = GroupInteractionGate::check($userId, $actId);
        if ($gateError !== null) {
            return $gateError;
        }

        if (!Plugin::instance()->stokeRepository()->addStoke($actId, $userId)) {
            return ApiResponse::error('bcc_internal_error', 'Failed to stoke.', 500);
        }

        // §A3-style event layered on the write, mirroring
        // bcc_reaction_added — subscribers (future analytics) listen
        // here rather than reaching into the repository directly.
        do_action('bcc_stoke_added', $userId, $actId);

        return self::buildStateResponse($actId, $userId);
    }

    public function removeStoke(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('stoke', self::STOKE_RATE_LIMIT, self::STOKE_RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many stokes. Please wait.', 429);
        }

        $actId = (int) $request->get_param('id');
        if ($actId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid post id.', 400);
        }

        $gateError = GroupInteractionGate::check($userId, $actId);
        if ($gateError !== null) {
            return $gateError;
        }

        if (!Plugin::instance()->stokeRepository()->removeStoke($actId, $userId)) {
            return ApiResponse::error('bcc_internal_error', 'Failed to un-stoke.', 500);
        }

        do_action('bcc_stoke_removed', $userId, $actId);

        return self::buildStateResponse($actId, $userId);
    }

    /**
     * Same `reactions` shape ReactionsEndpoint returns (so the frontend
     * cache patch never drops the legacy kind_grammar/counts/
     * viewer_reaction trio it still carries, even though the rail no
     * longer renders them), extended with heat_stage + viewer_has_stoked
     * + stoke_count.
     */
    private static function buildStateResponse(int $actId, int $viewerId): WP_REST_Response
    {
        $module  = PeepSoActivityRepository::getModuleIdByActId($actId) ?? '';
        $grammar = ReactionGrammarMap::grammarForModule($module);

        $state = ReactionStateComposer::compose($actId, $viewerId, $grammar);

        $stokeRepo = Plugin::instance()->stokeRepository();
        $heat      = $stokeRepo->heatByActIds([$actId]);
        $state['heat_stage']        = $heat[$actId] ?? 1;
        $state['viewer_has_stoked'] = $stokeRepo->viewerHasStoked($actId, $viewerId);
        $state['stoke_count']       = $stokeRepo->countForActivity($actId);

        $response = ApiResponse::ok($state);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
