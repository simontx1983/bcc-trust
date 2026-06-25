<?php
/**
 * Trust REST Controller
 *
 * Handles all REST API endpoints with PageScore value objects.
 *
 * @package BCC\Trust\Core\Controllers
 * @version 2.1.1
 *
 * Fixes in this version:
 *  - get_user_status: removed reference to non-existent `pages_joined` column
 *  - store_fingerprint: automation_score now capped at 100 with LEAST()
 *  - get_fraud_trend: fixed AND/OR operator precedence with parentheses around OR clauses
 *  - get_page_score: endorsement_count fallback now checks === null instead of falsy
 */

namespace BCC\Trust\Core\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class TrustRestController {

    /**
     * @return void
     */
    public static function register_routes() {

        // ======================================================
        // PUBLIC ENDPOINTS (for frontend)
        // ======================================================

        register_rest_route('bcc-trust/v1', '/endorse', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'endorse'],
            'permission_callback' => [self::class, 'permission_check'],
            'args'                => [
                'page_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'context' => ['type' => 'string', 'default' => 'general', 'enum' => ['general'], 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        register_rest_route('bcc-trust/v1', '/revoke-endorsement', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'revoke_endorsement'],
            'permission_callback' => [self::class, 'permission_check'],
            'args'                => [
                'page_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'context' => ['type' => 'string', 'default' => 'general', 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        // Removed: /page/{id}/score — trust-frontend.js now reads from the
        // unified /bcc/v1/page/{id} endpoint via bccPageStore (single source
        // of truth shared with trust-header.js and all blocks).

        register_rest_route('bcc-trust/v1', '/device-fingerprint', [
            'methods'             => 'POST',
            'callback'            => [UserStatusController::class, 'store_fingerprint'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        // ======================================================
        // ADMIN ENDPOINTS (for admin.js)
        // Delegated to AdminStatsController
        // ======================================================

        register_rest_route('bcc-trust/v1', '/fraud/stats', [
            'methods'             => 'GET',
            'callback'            => [AdminStatsController::class, 'get_fraud_stats'],
            'permission_callback' => [AdminStatsController::class, 'admin_permission_check']
        ]);

        register_rest_route('bcc-trust/v1', '/users/high-risk', [
            'methods'             => 'GET',
            'callback'            => [AdminStatsController::class, 'get_high_risk_users'],
            'permission_callback' => [AdminStatsController::class, 'admin_permission_check']
        ]);

        register_rest_route('bcc-trust/v1', '/activity/fraud', [
            'methods'             => 'GET',
            'callback'            => [AdminStatsController::class, 'get_fraud_activity'],
            'permission_callback' => [AdminStatsController::class, 'admin_permission_check']
        ]);

        register_rest_route('bcc-trust/v1', '/stats/trust-trend', [
            'methods'             => 'GET',
            'callback'            => [AdminStatsController::class, 'get_trust_trend'],
            'permission_callback' => [AdminStatsController::class, 'admin_permission_check']
        ]);

        register_rest_route('bcc-trust/v1', '/stats/risk-distribution', [
            'methods'             => 'GET',
            'callback'            => [AdminStatsController::class, 'get_risk_distribution'],
            'permission_callback' => [AdminStatsController::class, 'admin_permission_check']
        ]);

        register_rest_route('bcc-trust/v1', '/stats/fraud-trend', [
            'methods'             => 'GET',
            'callback'            => [AdminStatsController::class, 'get_fraud_trend'],
            'permission_callback' => [AdminStatsController::class, 'admin_permission_check']
        ]);

        register_rest_route('bcc-trust/v1', '/stats/devices', [
            'methods'             => 'GET',
            'callback'            => [AdminStatsController::class, 'get_device_stats'],
            'permission_callback' => [AdminStatsController::class, 'admin_permission_check']
        ]);

        register_rest_route('bcc-trust/v1', '/analyze-user/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [AdminStatsController::class, 'analyze_user'],
            'permission_callback' => [AdminStatsController::class, 'admin_permission_check'],
        ]);
    }

    public static function permission_check(): bool {
        return is_user_logged_in() && \BCC\Core\Permissions\Permissions::is_not_suspended();
    }

    // ======================================================
    // PUBLIC ENDPOINTS
    // ======================================================

    /**
     * Endorse a page
     *
     * Thin controller: validate → delegate → return.
     * Cache invalidation and response assembly are handled by the service.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function endorse(WP_REST_Request $request) {
        try {
            // Rate limiting is handled by EndorsementService — do NOT duplicate here.

            $pageId      = (int) $request->get_param('page_id');
            $context     = $request->get_param('context') ?? 'general';
            $allowedContexts = ['general'];
            if (!in_array($context, $allowedContexts, true)) {
                return self::errorWithCode('bcc_invalid_request', 'Invalid endorsement context.', 400);
            }
            $reason      = $request->get_param('reason');
            $fingerprint = $request->get_param('fingerprint');

            if (!$pageId) {
                throw new Exception('Page ID required');
            }

            $result = (\BCC\Trust\Core\Plugin::instance()->endorsementService())
                ->endorsePage($pageId, $context, $reason, $fingerprint);

            return self::success($result);

        } catch (\BCC\Trust\Core\Exceptions\EndorsementException $e) {
            // Typed eligibility/throttle failure: the (code, status, data)
            // triple is carried ON the exception (its named constructors are
            // the single source of truth for the §1.4 mapping), so a future
            // copy-edit to a message can no longer reroute the response. The
            // canonical UX path for soft gates is still the server-rendered
            // `permissions.can_endorse` boolean + `unlock_hint` (§1.4.5);
            // these responses are the race-condition / direct-call fallback.
            return self::errorWithCode($e->errorCode(), $e->getMessage(), $e->httpStatus(), $e->data() ?: null);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] endorse() unexpected error', ['error' => $e->getMessage()]);
            return self::errorWithCode('bcc_internal', 'An unexpected error occurred.', 500);
        }
    }

    /**
     * Revoke an endorsement
     *
     * Thin controller: validate → delegate → return.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function revoke_endorsement(WP_REST_Request $request) {
        try {
            // Rate limiting is handled by EndorsementService — do NOT duplicate here.

            $pageId  = (int) $request->get_param('page_id');
            $context = $request->get_param('context') ?? 'general';

            if (!$pageId) {
                throw new Exception('Page ID required');
            }

            $result = (\BCC\Trust\Core\Plugin::instance()->endorsementService())
                ->revokePageEndorsement($pageId, $context);

            return self::success($result);

        } catch (\BCC\Trust\Core\Exceptions\EndorsementException $e) {
            // Typed failure — code/status/data carried on the exception
            // (see endorse() above for the rationale).
            return self::errorWithCode($e->errorCode(), $e->getMessage(), $e->httpStatus(), $e->data() ?: null);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] revoke_endorsement() unexpected error', ['error' => $e->getMessage()]);
            return self::errorWithCode('bcc_internal', 'An unexpected error occurred.', 500);
        }
    }

    // ======================================================
    // HELPER METHODS
    // ======================================================

    /**
     * @param array<string, mixed> $data
     */
    private static function success(array $data): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data'    => $data
        ], 200);
    }

    /**
     * Emit a WP_Error with a stable §1.4.6 / Phase γ error code.
     *
     * All error responses from this controller go through here. The
     * code becomes `error.code` on the envelope (per
     * Envelope::wrap()); the frontend branches on it (see
     * bcc-frontend/src/lib/api/errors.ts — humanizeCode).
     *
     * `$data` flows through the envelope as `error.data` (e.g.
     * `unlock_hint` for soft gates per §1.4.5).
     *
     * @param array<string, mixed>|null $data
     */
    private static function errorWithCode(string $code, string $message, int $status, ?array $data = null): WP_Error {
        $payload = ['status' => $status];
        if ($data !== null) {
            $payload = array_merge($data, $payload);
        }
        return new WP_Error($code, $message, $payload);
    }
}