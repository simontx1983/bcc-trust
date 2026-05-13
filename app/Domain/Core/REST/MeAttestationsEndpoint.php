<?php
/**
 * MeAttestationsEndpoint — handles /bcc/v1/me/attestations mutations.
 *
 * Three routes per the locked §4.20 §J wire contract:
 *
 *   POST   /me/attestations              — cast a new attestation
 *   DELETE /me/attestations/{id}         — revoke (soft-delete)
 *   POST   /me/attestations/{id}/reaffirm — refresh decay baseline
 *
 * Auth: Bearer required — every route operates on get_current_user_id().
 * Anonymous calls return 401 with the canonical error envelope.
 *
 * Throttle: per-user bucket at the endpoint boundary. The service's
 * advisory locks serialize correctness (slot count, idempotency, order
 * computation); the throttle prevents flooding upstream of the locks
 * so a buggy client can't queue requests faster than the system can
 * service them.
 *
 * Response envelope: every success goes through ApiResponse::ok (§1.4);
 * every refusal goes through ApiResponse::error with the locked
 * `bcc_attestation_*` error catalogue. AttestationException is the
 * single typed bridge — the service throws it, this endpoint catches
 * it once.
 *
 * Cache: `private, no-store` on every response — mutation surfaces
 * have no shared-cache value.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 Trust Attestation Layer Slice C (2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Log\Logger;
use BCC\Core\Security\Throttle;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\AttestationException;
use BCC\Trust\Core\Support\ApiResponse;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MeAttestationsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Per-user throttle: 10 requests per 60s per endpoint family. */
    private const THROTTLE_LIMIT  = 10;
    private const THROTTLE_WINDOW = 60;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/attestations',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'postCast'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/attestations/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'deleteRevoke'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/attestations/(?P<id>\d+)/reaffirm',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'postReaffirm'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /me/attestations
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postCast(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!Throttle::allow(
            'attestation_cast:' . $userId,
            self::THROTTLE_LIMIT,
            self::THROTTLE_WINDOW
        )) {
            return self::noStore(
                ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429)
            );
        }

        $kind        = self::readStringParam($request, 'kind');
        $targetKind  = self::readStringParam($request, 'target_kind');
        $targetId    = self::readIntParam($request, 'target_id');
        $contextNote = self::readNullableStringParam($request, 'context_note');

        try {
            $data = Plugin::instance()->attestationService()->cast(
                $userId,
                $targetKind,
                $targetId,
                $kind,
                $contextNote
            );
        } catch (AttestationException $e) {
            return self::translateException($e);
        } catch (Throwable $e) {
            Logger::error('[MeAttestationsEndpoint] cast unhandled', [
                'user_id'     => $userId,
                'target_kind' => $targetKind,
                'target_id'   => $targetId,
                'kind'        => $kind,
                'error'       => $e->getMessage(),
            ]);
            return self::noStore(ApiResponse::error(
                'bcc_internal_error',
                'Could not cast attestation. Please try again.',
                500
            ));
        }

        $status = ($data['status'] ?? '') === 'created' ? 201 : 200;
        return self::noStore(ApiResponse::ok($data, $status));
    }

    // ──────────────────────────────────────────────────────────────────
    // DELETE /me/attestations/{id}
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteRevoke(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!Throttle::allow(
            'attestation_revoke:' . $userId,
            self::THROTTLE_LIMIT,
            self::THROTTLE_WINDOW
        )) {
            return self::noStore(
                ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429)
            );
        }

        $attestationId = (int) $request->get_param('id');

        try {
            $data = Plugin::instance()->attestationService()->revoke($userId, $attestationId);
        } catch (AttestationException $e) {
            return self::translateException($e);
        } catch (Throwable $e) {
            Logger::error('[MeAttestationsEndpoint] revoke unhandled', [
                'user_id'        => $userId,
                'attestation_id' => $attestationId,
                'error'          => $e->getMessage(),
            ]);
            return self::noStore(ApiResponse::error(
                'bcc_internal_error',
                'Could not revoke attestation. Please try again.',
                500
            ));
        }

        return self::noStore(ApiResponse::ok($data));
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /me/attestations/{id}/reaffirm
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postReaffirm(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!Throttle::allow(
            'attestation_reaffirm:' . $userId,
            self::THROTTLE_LIMIT,
            self::THROTTLE_WINDOW
        )) {
            return self::noStore(
                ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429)
            );
        }

        $attestationId = (int) $request->get_param('id');

        try {
            $data = Plugin::instance()->attestationService()->reaffirm($userId, $attestationId);
        } catch (AttestationException $e) {
            return self::translateException($e);
        } catch (Throwable $e) {
            Logger::error('[MeAttestationsEndpoint] reaffirm unhandled', [
                'user_id'        => $userId,
                'attestation_id' => $attestationId,
                'error'          => $e->getMessage(),
            ]);
            return self::noStore(ApiResponse::error(
                'bcc_internal_error',
                'Could not reaffirm attestation. Please try again.',
                500
            ));
        }

        return self::noStore(ApiResponse::ok($data));
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    private static function readStringParam(WP_REST_Request $request, string $name): string
    {
        $raw = $request->get_param($name);
        return is_string($raw) ? $raw : '';
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    private static function readNullableStringParam(WP_REST_Request $request, string $name): ?string
    {
        $raw = $request->get_param($name);
        if ($raw === null) {
            return null;
        }
        return is_string($raw) ? $raw : null;
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    private static function readIntParam(WP_REST_Request $request, string $name): int
    {
        $raw = $request->get_param($name);
        if (is_numeric($raw)) {
            return (int) $raw;
        }
        return 0;
    }

    private static function translateException(AttestationException $e): WP_REST_Response
    {
        return self::noStore(ApiResponse::error(
            $e->errorCode,
            $e->getMessage(),
            $e->status,
            $e->data !== [] ? $e->data : null
        ));
    }

    private static function noStore(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
