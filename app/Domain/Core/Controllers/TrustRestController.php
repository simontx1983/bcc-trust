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
 *  - store_fingerprint: automation_score now capped at 100 with LEAST()
 *  - get_fraud_trend: fixed AND/OR operator precedence with parentheses around OR clauses
 *  - get_page_score: endorsement_count fallback now checks === null instead of falsy
 */

namespace BCC\Trust\Core\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

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
     * Endorse an entity page.
     *
     * Slice E cutover: the legacy endorsement write path is retired —
     * this now casts a kind=vouch ATTESTATION on the entity card
     * (validator/project/creator) via AttestationService::cast(). cast()
     * fires bcc_attestation_created, which synchronously recomputes the
     * entity's trust score (the priority-25 score subscriber), so the
     * score read below is fresh on return.
     *
     * The response SHAPE is preserved byte-compatibly with the legacy
     * EndorsementService::endorsePage result (FE EndorseButton /
     * EntityProfile consume it via endorse-endpoints.ts) — `action`,
     * `page_id`, `vote` (always null), `endorsement`, `score`,
     * `votes_up`, `votes_down`, `endorsement_count`.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function endorse(WP_REST_Request $request) {
        try {
            $viewerId = get_current_user_id();
            if ($viewerId <= 0) {
                return self::errorWithCode('bcc_unauthorized', 'Authentication required.', 401);
            }

            // Per-user throttle — this legacy endpoint casts the same 'vouch'
            // attestation as POST /me/attestations, which is rate-limited
            // 10/60; without this the legacy path was an unthrottled write
            // hole into the trust graph. [audit M]
            if (!\BCC\Core\Security\Throttle::allow('attestation_cast:' . $viewerId, 10, 60)) {
                return self::errorWithCode('bcc_rate_limited', 'Too many requests.', 429);
            }

            $pageId  = (int) $request->get_param('page_id');
            $context = $request->get_param('context') ?? 'general';
            $allowedContexts = ['general'];
            if (!in_array($context, $allowedContexts, true)) {
                return self::errorWithCode('bcc_invalid_request', 'Invalid endorsement context.', 400);
            }
            $reasonRaw = $request->get_param('reason');
            $reason    = is_string($reasonRaw) ? $reasonRaw : null;

            if (!$pageId) {
                return self::errorWithCode('bcc_invalid_request', 'Page ID required.', 400);
            }

            // Entity endorse only ever targets entity card pages. Resolve
            // the §J.1 target_kind from the page's _bcc_page_type; reject
            // non-entity pages (member self-pages, unrecognized types).
            $targetKind = \BCC\Trust\Core\Services\AttestationService::targetKindForPage($pageId);
            if ($targetKind === null) {
                return self::errorWithCode('bcc_invalid_request', 'This page cannot be endorsed.', 400);
            }

            $plugin = \BCC\Trust\Core\Plugin::instance();

            // cast() recomputes the entity score synchronously via the
            // bcc_attestation_created subscriber before it returns.
            $cast = $plugin->attestationService()->cast(
                $viewerId,
                $targetKind,
                $pageId,
                'vouch',
                $reason
            );

            return self::success(self::buildEndorseResponse('endorse', $pageId, $targetKind, $cast));

        } catch (\BCC\Trust\Core\Services\AttestationException $e) {
            // Typed eligibility/throttle/self/not-found failure: the
            // (code, status, data) triple is carried ON the exception, so
            // a copy-edit to a message cannot reroute the response. The
            // canonical UX path for soft gates is still the server-rendered
            // `permissions.can_endorse` + `unlock_hint` (§1.4.5); these
            // responses are the race / direct-call fallback.
            [$endorseCode, $endorseStatus] = self::mapEndorseError($e);
            return self::errorWithCode($endorseCode, $e->getMessage(), $endorseStatus, $e->data ?: null);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] endorse() unexpected error', ['error' => $e->getMessage()]);
            return self::errorWithCode('bcc_internal', 'An unexpected error occurred.', 500);
        }
    }

    /**
     * Revoke an entity endorsement.
     *
     * Slice E cutover: resolves the viewer's active kind=vouch
     * attestation on the entity page and revokes it via
     * AttestationService::revokeByTarget(), which fires
     * bcc_attestation_revoked (re-folding the entity score). Response
     * shape mirrors endorse() with `action: revoke_endorsement` and
     * `endorsement: null`.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function revoke_endorsement(WP_REST_Request $request) {
        try {
            $viewerId = get_current_user_id();
            if ($viewerId <= 0) {
                return self::errorWithCode('bcc_unauthorized', 'Authentication required.', 401);
            }

            // Throttle the revoke path too — same bucket as the cast, so a
            // rapid cast/revoke toggle can't be used to churn the score. [audit M]
            if (!\BCC\Core\Security\Throttle::allow('attestation_cast:' . $viewerId, 10, 60)) {
                return self::errorWithCode('bcc_rate_limited', 'Too many requests.', 429);
            }

            $pageId = (int) $request->get_param('page_id');
            if (!$pageId) {
                return self::errorWithCode('bcc_invalid_request', 'Page ID required.', 400);
            }

            $targetKind = \BCC\Trust\Core\Services\AttestationService::targetKindForPage($pageId);
            if ($targetKind === null) {
                return self::errorWithCode('bcc_invalid_request', 'This page cannot be endorsed.', 400);
            }

            $plugin = \BCC\Trust\Core\Plugin::instance();

            // revokeByTarget routes through the full revoke() (soft-delete
            // + audit + cache + bcc_attestation_revoked → score re-fold);
            // a missing row is an idempotent no-op (the response still
            // reflects the current post-revoke counts).
            $plugin->attestationService()->revokeByTarget($viewerId, $targetKind, $pageId, 'vouch');

            return self::success(self::buildEndorseResponse('revoke_endorsement', $pageId, $targetKind, null));

        } catch (\BCC\Trust\Core\Services\AttestationException $e) {
            [$endorseCode, $endorseStatus] = self::mapEndorseError($e);
            return self::errorWithCode($endorseCode, $e->getMessage(), $endorseStatus, $e->data ?: null);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] revoke_endorsement() unexpected error', ['error' => $e->getMessage()]);
            return self::errorWithCode('bcc_internal', 'An unexpected error occurred.', 500);
        }
    }

    /**
     * Map an AttestationException to the §4.25 /endorse PUBLIC error contract.
     * Slice E routes /endorse through the attestation eligibility system, but
     * the endpoint's documented error vocabulary stays stable (the FE +
     * contract depend on it). Codes already in the endorse vocabulary
     * (bcc_invalid_request, bcc_rate_limited) pass through unchanged.
     *
     * @return array{0: string, 1: int}
     */
    private static function mapEndorseError(\BCC\Trust\Core\Services\AttestationException $e): array {
        $ex = \BCC\Trust\Core\Services\AttestationException::class;
        switch ($e->errorCode) {
            case $ex::CODE_SELF:            return ['bcc_endorse_self', 403];
            case $ex::CODE_INELIGIBLE:     return ['bcc_permission_denied', 403];
            case $ex::CODE_FRAUD_BLOCKED:  return ['bcc_fraud_locked', 403];
            case $ex::CODE_NOT_FOUND:      return ['bcc_invalid_request', 400];
            case $ex::CODE_INTERNAL:       return ['bcc_internal', 500];
            default:                       return [$e->errorCode, $e->status];
        }
    }

    /**
     * Assemble the legacy-shaped endorse/revoke response from the fresh
     * post-mutation entity state. Byte-compatible with the retired
     * EndorsementService::endorsePage / revokePageEndorsement result so
     * the FE (endorse-endpoints.ts EndorseResponse) consumes it without
     * translation:
     *   { action, page_id, vote:null, endorsement, score,
     *     votes_up, votes_down, endorsement_count }
     *
     * `endorsement` carries the freshly-cast attestation when $cast is
     * non-null (endorse path), else null (revoke path). `score` is the
     * recomputed PageScore wire object (cast() / revoke() recompute it
     * synchronously). `endorsement_count` is the active vouch count on
     * the target.
     *
     * @param array<string, mixed>|null $cast The §J.2 cast() result, or
     *     null on the revoke path.
     * @return array<string, mixed>
     */
    private static function buildEndorseResponse(string $action, int $pageId, string $targetKind, ?array $cast): array {
        $plugin = \BCC\Trust\Core\Plugin::instance();

        $freshScore = $plugin->scoreRepository()->getByPageId($pageId);
        $voteCounts = $plugin->voteRepository()->getVoteCountsByType($pageId);

        $counts          = $plugin->attestationRepository()->countByTarget($targetKind, $pageId);
        $endorsementCount = (int) ($counts['vouch_count'] ?? 0);

        $endorsement = null;
        if ($cast !== null) {
            $endorsement = [
                'endorsement_id' => (int) ($cast['id'] ?? 0),
                'page_title'     => (string) get_the_title($pageId),
                'context'        => 'vouch',
                'weight'         => (float) ($cast['weight_at_time'] ?? 0.0),
            ];
        }

        return [
            'action'            => $action,
            'page_id'           => $pageId,
            'vote'              => null,
            'endorsement'       => $endorsement,
            'score'             => $freshScore ? $freshScore->toApiResponse() : null,
            'votes_up'          => (int) ($voteCounts['upvotes'] ?? 0),
            'votes_down'        => (int) ($voteCounts['downvotes'] ?? 0),
            'endorsement_count' => $endorsementCount,
        ];
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