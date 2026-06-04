<?php
/**
 * Pages Endpoints — handles /bcc/v1/pages/:id/* routes.
 *
 * Phase 1 routes registered:
 *   - POST /pages/:id/claim — claim a peepso-page (§B5 single-claim-wins)
 *
 * The :id path segment is a peepso-page post_id. The request body
 * carries the underlying on-chain entity reference (entity_type +
 * entity_id) — pre-baked into the card view-model's `actions.claim.body`
 * so the frontend dispatches it without doing its own mapping.
 *
 * Implementation:
 *   1. Pre-check idempotency: if the user already has a verified claim
 *      on this entity, return 200 with `status: "already_verified"`
 *      so the frontend doesn't have to distinguish "fresh success" from
 *      "harmless re-submit."
 *   2. Otherwise delegate the on-chain ownership check + exclusive
 *      claim creation to BCC\Trust\Onchain\Services\ClaimService::claim
 *      (existing — unchanged). That service handles:
 *        - load entity (validator / collection)
 *        - match user's verified wallets to operator / creator
 *        - advisory-locked exclusive claim creation
 *        - bcc_onchain_claim_verified event for trust scoring
 *   3. Mirror the verified claim as an entity_type='page' row so
 *      CardViewService::isPageClaimed picks it up.
 *   4. Fire `bcc_page_claimed` for UI / notification / feed / analytics
 *      subscribers — they MUST NOT depend on bcc_onchain_claim_verified
 *      since that only fires for the trust-engine pathway.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Services\BlogCoverImageWriter;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Services\ClaimService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class PagesEndpoint
{
    private const ROUTE_NAMESPACE   = 'bcc/v1';
    /** Shared throttle bucket with EntityClaimEndpoint for consistency. */
    private const RATE_LIMIT_BUCKET = 'claim_entity';
    private const RATE_LIMIT_BUDGET = 10;
    private const RATE_LIMIT_WINDOW = 60;

    /** @var list<string> */
    private const VALID_ENTITY_TYPES = ['validator', 'collection'];

    public static function register(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/pages/(?P<id>\d+)/claim',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [new self(), 'claim'],
                // Auth checked inside the handler so unauthenticated
                // requests can return the canonical error envelope.
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'entity_type' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => self::VALID_ENTITY_TYPES,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'entity_id' => [
                        'required' => true,
                        'type'     => 'integer',
                        'minimum'  => 1,
                    ],
                ],
            ]
        );

        // Claimer-only page avatar: upload (POST) / remove (DELETE). Lets a
        // verified operator override the auto-imported validator logo with
        // their own image (stored as the page's featured image, which the
        // crest resolver ranks above the auto logo).
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/pages/(?P<id>\d+)/avatar',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [new self(), 'uploadAvatar'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'minimum'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [new self(), 'deleteAvatar'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'minimum'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * POST /pages/:id/avatar — claimer uploads a page image.
     *
     * Gate: authenticated AND holds a verified `page` claim on this page.
     * Persists via BlogCoverImageWriter (attachment owned by the uploader)
     * then pins it with set_post_thumbnail so CardViewService's crest
     * resolver picks it up above the auto-imported logo.
     */
    public function uploadAvatar(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $pageId = (int) $request->get_param('id');

        $gate = self::requireClaimer($pageId, $userId);
        if ($gate !== null) {
            return $gate;
        }

        $files = $request->get_file_params();
        $file  = isset($files['avatar']) && is_array($files['avatar']) ? $files['avatar'] : null;
        if ($file === null) {
            return ApiResponse::error('bcc_invalid_request', 'No image uploaded (expected field "avatar").', 400);
        }

        // Reuse the §D6 cover-image writer: validates size/MIME from file
        // magic, rate-limits, and produces an uploader-owned attachment.
        $result = (new BlogCoverImageWriter())->upload($userId, $file);
        if (!isset($result['ok'])) {
            /** @var array{error: string, message: string, data?: array<string, mixed>} $result */
            $status = match ($result['error']) {
                'bcc_unauthorized' => 401,
                'bcc_rate_limited' => 429,
                'bcc_invalid_request' => 400,
                default            => 503,
            };
            return ApiResponse::error($result['error'], $result['message'], $status);
        }

        $attachmentId = (int) $result['attachment_id'];
        set_post_thumbnail($pageId, $attachmentId);

        Logger::audit('page_avatar_set', [
            'user_id'       => $userId,
            'page_id'       => $pageId,
            'attachment_id' => $attachmentId,
            'via'           => 'rest',
        ]);

        $response = ApiResponse::ok([
            'page_id'   => $pageId,
            'image_url' => (string) $result['url'],
        ]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * DELETE /pages/:id/avatar — claimer removes their uploaded image,
     * reverting to the auto-imported logo (or the initials crest).
     */
    public function deleteAvatar(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $pageId = (int) $request->get_param('id');

        $gate = self::requireClaimer($pageId, $userId);
        if ($gate !== null) {
            return $gate;
        }

        delete_post_thumbnail($pageId);

        Logger::audit('page_avatar_cleared', [
            'user_id' => $userId,
            'page_id' => $pageId,
            'via'     => 'rest',
        ]);

        $response = ApiResponse::ok([
            'page_id'   => $pageId,
            'image_url' => null,
        ]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * Shared gate for the avatar routes: returns an error response when the
     * caller is unauthenticated, the page doesn't exist, or the caller does
     * not hold a verified `page` claim on it; null when allowed to proceed.
     */
    private static function requireClaimer(int $pageId, int $userId): ?WP_REST_Response
    {
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        if ($pageId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'page id is required.', 400);
        }

        $post = get_post($pageId);
        if (!$post instanceof \WP_Post
            || $post->post_type !== 'peepso-page'
            || $post->post_status !== 'publish'
        ) {
            return ApiResponse::error('bcc_not_found', 'Page not found.', 404);
        }

        $claim = ClaimRepository::getUserClaim($userId, 'page', $pageId);
        if ($claim === null || (string) $claim->status !== 'verified') {
            return ApiResponse::error(
                'bcc_forbidden',
                'Only the verified operator of this page can change its image.',
                403
            );
        }

        return null;
    }

    public function claim(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow(self::RATE_LIMIT_BUCKET, self::RATE_LIMIT_BUDGET, self::RATE_LIMIT_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many claim attempts. Please wait.', 429);
        }

        $pageId     = (int) $request->get_param('id');
        $entityType = (string) $request->get_param('entity_type');
        $entityId   = (int) $request->get_param('entity_id');

        if ($pageId <= 0 || $entityId <= 0 || !in_array($entityType, self::VALID_ENTITY_TYPES, true)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'page id, entity_type, and entity_id are required.',
                400
            );
        }

        $post = get_post($pageId);
        if (!$post instanceof \WP_Post
            || $post->post_type !== 'peepso-page'
            || $post->post_status !== 'publish'
        ) {
            return ApiResponse::error('bcc_not_found', 'Page not found.', 404);
        }

        // ── Idempotency pre-check ─────────────────────────────────────
        // If the user already has a verified claim, return 200 with
        // status="already_verified" so the frontend doesn't have to
        // distinguish from a fresh success.
        $existing = ClaimRepository::getUserClaim($userId, $entityType, $entityId);
        if ($existing !== null && (string) $existing->status === 'verified') {
            $role = (string) $existing->claim_role;
            return self::successResponse(
                claimId:    (int) $existing->id,
                pageId:     $pageId,
                entityType: $entityType,
                entityId:   $entityId,
                role:       $role,
                isPrimary:  true,
                status:     'already_verified',
                message:    'You are already the verified ' . self::roleLabel($role) . '.',
                userId:     $userId,
                fireEvent:  false
            );
        }

        // Fail-loud: ClaimService is a hard in-plugin dependency.
        if (!class_exists(ClaimService::class)) {
            throw new \RuntimeException('Onchain ClaimService not autoloaded');
        }

        $result = ClaimService::claim($userId, $entityType, $entityId);

        if (!$result['success']) {
            return self::mapClaimFailure($result);
        }

        // Mirror to entity_type='page' so CardViewService sees is_claimed.
        // Best-effort: if the mirror fails, the underlying claim (and
        // its trust-scoring event) is already in place — log and
        // continue rather than rolling back.
        $role       = (string) ($result['role'] ?? 'operator');
        $underlying = ClaimRepository::getUserClaim($userId, $entityType, $entityId);
        if ($underlying !== null) {
            $mirror = ClaimRepository::createExclusiveClaim(
                $userId,
                'page',
                $pageId,
                (string) $underlying->wallet_address,
                (int) $underlying->chain_id,
                $role
            );
            if (!$mirror['success']) {
                Logger::warning('[PagesEndpoint] page-claim mirror failed; underlying claim retained', [
                    'user_id'     => $userId,
                    'page_id'     => $pageId,
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'reason'      => $mirror['message'] ?? '',
                ]);
            }
        }

        Logger::audit('page_claimed', [
            'user_id'     => $userId,
            'page_id'     => $pageId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'role'        => $role,
            'via'         => 'rest',
        ]);

        return self::successResponse(
            claimId:    (int) ($result['claim_id'] ?? 0),
            pageId:     $pageId,
            entityType: $entityType,
            entityId:   $entityId,
            role:       $role,
            isPrimary:  (bool) ($result['is_primary'] ?? false),
            status:     'verified',
            message:    'Verified as ' . self::roleLabel($role) . '.',
            userId:     $userId,
            fireEvent:  true
        );
    }

    /**
     * Build the success response for both fresh and idempotent claims.
     *
     * Fires the canonical `bcc_page_claimed` action when fireEvent=true
     * (fresh success only — idempotent re-submits don't re-fire). UI,
     * notification, feed, and analytics subscribers hook this event;
     * they MUST NOT subscribe to bcc_onchain_claim_verified, which is
     * trust-engine-internal.
     */
    private static function successResponse(
        int $claimId,
        int $pageId,
        string $entityType,
        int $entityId,
        string $role,
        bool $isPrimary,
        string $status,
        string $message,
        int $userId,
        bool $fireEvent
    ): WP_REST_Response {
        if ($fireEvent) {
            do_action('bcc_page_claimed', $userId, $pageId, $entityType, $entityId, $role);
        }

        $response = ApiResponse::ok([
            'claim_id'    => $claimId,
            'page_id'     => $pageId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'role'        => $role,
            'is_primary'  => $isPrimary,
            'status'      => $status,
            'message'     => $message,
            'claimed_by'  => self::buildClaimedBy($userId),
        ]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * @return array{user_id: int, handle: string}
     */
    private static function buildClaimedBy(int $userId): array
    {
        $user   = get_userdata($userId);
        $handle = (string) get_user_meta($userId, 'bcc_handle', true);

        if ($handle === '' && $user !== false) {
            $handle = (string) $user->user_login;
        }

        return [
            'user_id' => $userId,
            'handle'  => $handle,
        ];
    }

    /**
     * @param array{success: bool, message: string, claim_id?: int, role?: string, needs_wallet?: bool, chain_slug?: string, error?: string, is_primary?: bool} $result
     */
    private static function mapClaimFailure(array $result): WP_REST_Response
    {
        if (!empty($result['needs_wallet'])) {
            return ApiResponse::error(
                'bcc_precondition_failed',
                'Connect a wallet first to claim this.',
                412
            );
        }

        if (($result['error'] ?? '') === 'already_claimed') {
            return ApiResponse::error(
                'bcc_conflict',
                'This entity has already been claimed by another user.',
                409
            );
        }

        // Default failure: no matching wallet. Sanitize the message to
        // avoid leaking internal phrasing (operator address strings,
        // moniker references, etc.) — keep it user-facing.
        return ApiResponse::error(
            'bcc_forbidden',
            'Your connected wallet does not match the operator for this entity.',
            403
        );
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            'operator' => 'operator',
            'creator'  => 'creator',
            'holder'   => 'holder',
            default    => $role,
        };
    }
}
