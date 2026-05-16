<?php
/**
 * MeReliabilityEndpoint — handles GET /bcc/v1/me/reliability.
 *
 * The §J.5 self-mirror surface — the operator's view of their own
 * reliability-as-judge track record. Mirror, not stigma; status-
 * anxiety mitigation per §2.7 of the threat model.
 *
 * Auth: Bearer required. Self-only — there is no third-party
 * variant of this endpoint in V1 per §J.10 q14. The
 * `operator_reliability` numeric only ever flows through this route,
 * for this user.
 *
 * §J.4.1 asymmetric display: the public attestor mini-view (returned
 * by `/entities/.../attestations`) carries only the categorical
 * `reliability_standing` enum. This endpoint additionally carries the
 * numeric and the trend block — exposed ONLY because the requester
 * IS the subject. Slice E populates the numeric + trends from the
 * `bcc_attestor_reliability_cache` table; today the service returns
 * the V1 baseline (0.0 / steady / newly_active) plus live counts for
 * the few fields that are real on day one (slots used, lifetime
 * cast count, slots_total from tier baseline).
 *
 * Cache: `private, max-age=60` per the §J.5 contract. Generation-
 * counter invalidation isn't wired in Slice E yet either; the 60-second
 * window is short enough that a fresh cast surfaces within a minute.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 Trust Attestation Layer PR-2 (2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MeReliabilityEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/reliability',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'get'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function get(WP_REST_Request $request): WP_REST_Response
    {
        unset($request); // No request params on this surface.

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return self::noStore(ApiResponse::error(
                'bcc_unauthorized',
                'Sign in required.',
                401
            ));
        }

        try {
            $data = Plugin::instance()->attestationService()->getReliabilityFor($userId);
        } catch (Throwable $e) {
            Logger::error('[MeReliabilityEndpoint] get unhandled', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return self::noStore(ApiResponse::error(
                'bcc_internal_error',
                'Could not load reliability. Please try again.',
                500
            ));
        }

        $response = ApiResponse::ok($data);
        // §J.5 cache: private, 60s. Per-user payload — never shared.
        $response->header('Cache-Control', 'private, max-age=60');
        return $response;
    }

    private static function noStore(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
