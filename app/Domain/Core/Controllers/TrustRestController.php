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

        } catch (Exception $e) {
            // Map known eligibility exceptions to §1.4.6 / Phase γ stable
            // codes so the frontend can branch on `err.code` instead of
            // pattern-matching `err.message`. The canonical UX path for
            // soft gates is the server-rendered `permissions.can_endorse`
            // boolean + `unlock_hint` (§1.4.5); the 400/403 responses
            // below are the race-condition / direct-call fallback.
            //
            // Substring matching is fragile but bounded — see
            // EndorsementService::endorsePage() for the exception sites
            // (L74, L82, L94, L114, L135, L150, L246-258, L273, L285).
            $msg = $e->getMessage();

            if (str_contains($msg, 'Authentication required')) {
                return self::errorWithCode('bcc_unauthorized', $msg, 401);
            }
            if (str_contains($msg, 'Invalid page')) {
                return self::errorWithCode('bcc_invalid_request', $msg, 400);
            }
            if (str_contains($msg, 'own page')) {
                return self::errorWithCode('bcc_endorse_self', $msg, 403);
            }
            if (str_contains($msg, 'already endorsed')) {
                return self::errorWithCode('bcc_conflict', $msg, 409);
            }
            if (str_contains($msg, 'flagged for unusual activity')) {
                return self::errorWithCode('bcc_fraud_locked', $msg, 403);
            }
            if (str_contains($msg, 'system is busy')) {
                // Concurrency-lock contention — retryable. Surface as
                // rate-limited so the existing client backoff path applies.
                return self::errorWithCode('bcc_rate_limited', $msg, 429);
            }
            if (str_contains($msg, 'onboarding quests') || str_contains($msg, 'unlock endorsements')
                || str_contains($msg, 'days old')
            ) {
                // Soft gate — surface the service message verbatim as the
                // unlock hint per §1.4.5 (data.unlock_hint companion).
                return self::errorWithCode(
                    'bcc_permission_denied',
                    $msg,
                    403,
                    ['unlock_hint' => $msg]
                );
            }

            \BCC\Core\Log\Logger::error('[bcc-trust] endorse() unexpected error', ['error' => $msg]);
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

        } catch (Exception $e) {
            $msg = $e->getMessage();

            if (str_contains($msg, 'Authentication required')) {
                return self::errorWithCode('bcc_unauthorized', $msg, 401);
            }
            if (str_contains($msg, 'system is busy')) {
                return self::errorWithCode('bcc_rate_limited', $msg, 429);
            }
            if (str_contains($msg, 'not found')) {
                return self::errorWithCode('bcc_not_found', $msg, 404);
            }

            \BCC\Core\Log\Logger::error('[bcc-trust] revoke_endorsement() unexpected error', ['error' => $msg]);
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