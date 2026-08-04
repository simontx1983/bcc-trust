<?php
/**
 * POST /bcc/v1/me/findings/{id}/appeal — the once-only member appeal
 * on a misconduct finding (Rank Phase 8; spec §15.5).
 *
 * Authenticated members only; a member may appeal ONLY their own
 * finding (a foreign or unknown id 404s identically — no existence
 * leak), and only ONCE: the repository gate flips appeal_status
 * '' → 'requested' in a single guarded UPDATE, so replays and races
 * get a clean 409. Admins adjudicate in wp-admin (Rank Findings);
 * outcomes land as append-only decision rows + a bell notice.
 *
 * @package BCC\Trust\Core\REST
 * @since Rank redesign Phase 8 (2026-08-04)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MeFindingAppealEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/me/findings/(?P<id>\d+)/appeal';

    /** Appeal-request throttle: 5 per rolling hour per member. */
    private const RATE_LIMIT  = 5;
    private const RATE_WINDOW = 3600;

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_PATH, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [new self(), 'handle'],
            // Auth in-handler (MeProgressionEndpoint pattern) — bearer
            // resolution happens upstream; anonymous gets a clean 401.
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => static fn ($value): bool => is_numeric($value),
                ],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in to request an appeal.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('finding_appeal:' . $userId, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $findingId = (int) $request->get_param('id');

        $repository = \BCC\Trust\Core\Plugin::instance()->findingsRepository();

        // Own finding only — a foreign or unknown id 404s identically
        // so the endpoint can't be used to probe finding existence.
        $finding = $repository->getById($findingId);
        if ($finding === null || (int) $finding->subject_user_id !== $userId) {
            return ApiResponse::error('bcc_not_found', 'Finding not found.', 404);
        }

        // Once-only gate (§15.5): a single guarded UPDATE — already
        // requested or already adjudicated reads as a conflict.
        if (!$repository->requestAppeal($findingId, $userId)) {
            return ApiResponse::error('bcc_conflict', 'An appeal has already been requested for this finding.', 409);
        }

        \BCC\Core\Log\Logger::audit('rank_appeal_requested', [
            'finding_id' => $findingId,
            'subject_id' => $userId,
        ]);
        do_action('bcc_rank_appeal_requested', $userId, $findingId);

        $response = ApiResponse::ok([
            'ok'            => true,
            'finding_id'    => $findingId,
            'appeal_status' => 'requested',
        ]);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
