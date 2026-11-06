<?php
/**
 * Pages Endpoints — handles /bcc/v1/pages/:id/* routes.
 *
 * Phase 1 routes registered:
 *   - GET  /pages/:id/feed  — public stream of a validator page's posts
 *   - POST /pages/:id/claim — claim a peepso-page (§B5 single-claim-wins)
 *
 * The :id path segment is a peepso-page post_id. The request body
 * carries the underlying on-chain entity reference (entity_type +
 * entity_id) — pre-baked into the card view-model's `actions.claim.body`
 * so the frontend dispatches it without doing its own mapping.
 *
 * Implementation:
 *   0. Refuse unless the entity is bound to this page. The pair is
 *      client-supplied and the wallet proof only covers the entity.
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

use BCC\Core\DB\AdvisoryLock;
use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\BlogCoverImageWriter;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Services\ClaimService;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority;
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

    private const DEFAULT_FEED_LIMIT = 20;
    private const MAX_FEED_LIMIT     = 50;

    /** @var list<string> */
    private const VALID_ENTITY_TYPES = ['validator', 'collection'];

    /**
     * Per-page advisory lock serialising the WHOLE `bcc_page_claimed`
     * dispatch. 64-char ceiling on MySQL >= 5.7, so the page id is the only
     * variable part. {@see dispatchPageClaimed()}.
     */
    private const CLAIM_EVENT_LOCK_PREFIX = 'bcc_page_claim_event:';

    /**
     * Seconds to wait for that lock. Matches
     * ClaimRepository::createExclusiveClaim(), and is a WAIT rather than a
     * non-blocking try on purpose: a second delivery that is simply dropped
     * would never write its activity row, and for a distinct second claim on
     * the same page (a multi-chain operator) there is nothing to re-drive it.
     * Waiting lets it run after the holder, where the in-lock re-check
     * decides whether it still has work.
     */
    private const CLAIM_EVENT_LOCK_WAIT = 5;

    public static function register(): void
    {
        // Public stream of a validator page's everyday posts. Same args and
        // same in-handler gate shape as GET /groups/{id}/feed.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/pages/(?P<id>\d+)/feed',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'feed'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'cursor' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => self::DEFAULT_FEED_LIMIT,
                        'minimum'           => 1,
                        'maximum'           => self::MAX_FEED_LIMIT,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

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
     * GET /pages/:id/feed — the posts written for a validator page, newest
     * first. Public, and filtered by the post marker only: a post stays here
     * after its author stops being the page's operator.
     */
    public function feed(WP_REST_Request $request): WP_REST_Response
    {
        $pageId = (int) $request->get_param('id');
        if ($pageId <= 0 || !ValidatorPageOperatorAuthority::isValidatorPage($pageId)) {
            return ApiResponse::error('bcc_not_found', 'Page not found.', 404);
        }

        $cursorRaw = $request->get_param('cursor');
        $cursor    = is_string($cursorRaw) && $cursorRaw !== '' ? $cursorRaw : null;

        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = self::DEFAULT_FEED_LIMIT;
        }
        if ($limit > self::MAX_FEED_LIMIT) {
            $limit = self::MAX_FEED_LIMIT;
        }

        $payload = Plugin::instance()->feedRankingService()->getActivityForPage(
            $pageId,
            get_current_user_id(),
            $cursor,
            $limit
        );

        $response = ApiResponse::ok($payload);
        // Same policy as /groups/{id}/feed: per-viewer exclusions (block
        // lists) make shared caching unsafe, public or not.
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    /**
     * POST /pages/:id/avatar — claimer uploads a page image.
     *
     * Gate: authenticated AND holds a verified operator/creator claim on
     * an entity whose wallet link binds to this page (canonical claim→page
     * resolution — see ClaimRepository::userHasVerifiedClaimOnPage).
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
     * not hold a verified operator/creator claim resolving to it; null when
     * allowed to proceed.
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

        // Canonical claim→page resolution: the caller holds a VERIFIED
        // operator/creator claim on an entity (validator/collection) whose
        // wallet link binds to this page — the same source of truth as the
        // read model's has_verified_claim flag. Deliberately NOT the
        // entity_type='page' mirror row: the mirror is best-effort (a failed
        // mirror write is never repaired — the idempotent re-claim path
        // returns before re-mirroring), so gating on it 403s real verified
        // operators whose mirror row is missing.
        if (!ClaimRepository::userHasVerifiedClaimOnPage($userId, $pageId)) {
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

        // ── Page binding ──────────────────────────────────────────────
        // `id` and `entity_id` are both client-supplied, and the wallet proof
        // below covers the entity only. Refuse before anything is read as a
        // claim or written: everything after this line trusts the pair.
        if (!ClaimService::isEntityBoundToPage($entityType, $entityId, $pageId)) {
            Logger::warning('[PagesEndpoint] claim refused: entity is not bound to page', [
                'user_id'     => $userId,
                'page_id'     => $pageId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
            ]);
            return ApiResponse::error(
                'bcc_forbidden',
                'That entity is not attached to this page.',
                403
            );
        }

        // ── Idempotency pre-check ─────────────────────────────────────
        // If the user already has a verified claim, return 200 with
        // status="already_verified" so the frontend doesn't have to
        // distinguish from a fresh success.
        $existing = ClaimRepository::getUserClaim($userId, $entityType, $entityId);
        if ($existing !== null && (string) $existing->status === 'verified') {
            $role = (string) $existing->claim_role;

            // ⚠⚠ SELF-REPAIR of an interrupted claim.
            //
            // The claim row and the ownership transfer are written by two
            // different things: the row inside ClaimService::claim(), the
            // transfer later on `bcc_page_claimed`. If the request died
            // between them the claim is verified and the page is still
            // unowned, and a retry used to return 200 `already_verified`
            // without repairing anything — while
            // `userHasVerifiedClaimOnPage()` kept passing, so it LOOKED
            // healthy and the operator never owned their page.
            //
            // Re-firing is safe on two legs, and it needs both:
            //
            //   1. the dispatch is SERIALISED per page by
            //      dispatchPageClaimed()'s advisory lock, so two overlapping
            //      retries cannot interleave their check-then-insert
            //      sequences; and
            //   2. each subscriber is replay-safe by STRUCTURED IDENTITY,
            //      not by title, so a SEQUENTIAL replay is a no-op.
            //
            // Leg 2 alone was not enough. Every guard below is a SELECT
            // followed by an INSERT with no unique index behind it
            // (`wp_postmeta` and `wp_peepso_activities` are both unique on
            // their own primary key only), so without leg 1 two simultaneous
            // retries both read "absent" and both insert.
            //
            // The subscribers:
            //   • priority 10 ActivityStreamWriter — createBackingPost()
            //     reuses the post matching (author, module, sidecar) and
            //     insertActivityOnce() reuses the row matching
            //     (act_external_id, act_module_id), so no duplicate wall
            //     entry;
            //   • priority 20 PeepSoIntegration — gated on post_author === 0
            //     and self-disarms once set;
            //   • priority 30 ValidatorMsgQueueWorker — idempotent by
            //     construction (INSERT … ON DUPLICATE KEY UPDATE id = id).
            //
            // ⚠ Gated on post_author === 0 — "nobody owns this yet" — the
            // same condition the transfer itself requires. So this finishes
            // an INTERRUPTED transfer and can never move a page away from an
            // existing owner, which is the agreed ownership rule.
            //
            // ⚠⚠ This read is a FAST PATH ONLY, and is not the gate. It runs
            // outside the lock, so between here and the dispatch a concurrent
            // request can complete the very transfer it is testing for.
            // `onlyIfUnowned: true` makes dispatchPageClaimed() re-read it
            // while holding the lock, and that read is the authoritative one.
            $needsTransfer = (int) get_post_field('post_author', $pageId) === 0;

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
                fireEvent:  $needsTransfer,
                onlyIfUnowned: true
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
     * Fires the canonical `bcc_page_claimed` action when fireEvent=true —
     * on a fresh success, and on a retry whose ownership transfer never
     * completed. UI, notification, feed, and analytics subscribers hook this
     * event; they MUST NOT subscribe to bcc_onchain_claim_verified, which is
     * trust-engine-internal.
     *
     * @param bool $onlyIfUnowned re-check, under the dispatch lock, that the
     *        page is still unowned before firing. True for the retry path,
     *        whose whole purpose is finishing an interrupted transfer; false
     *        for a fresh claim, which must be announced whoever owns the page
     *        (a second chain's claim on an already-owned page still needs its
     *        own activity row and its own messaging activation).
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
        bool $fireEvent,
        bool $onlyIfUnowned = false
    ): WP_REST_Response {
        if ($fireEvent) {
            self::dispatchPageClaimed($userId, $pageId, $entityType, $entityId, $role, $onlyIfUnowned);
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
     * Dispatch `bcc_page_claimed` once, under a per-page advisory lock.
     *
     * ⚠⚠ WHY A LOCK AT ALL. Every subscriber's idempotency guard is
     * check-then-act with NO database constraint behind it, measured:
     *
     *   - priority 10 ActivityStreamWriter — `get_posts(meta_query)` then
     *     `wp_insert_post()`, and `wp_postmeta` is unique on `meta_id` only;
     *     then `findActIdForExternal()` then a plain `$wpdb->insert`, and
     *     `wp_peepso_activities` is unique on `act_id` only (its
     *     `act_external_index` and `act_module_index` are two separate
     *     NON-unique indexes, so there is no composite to violate). Two
     *     simultaneous deliveries therefore both read "absent" and both
     *     insert — two backing posts and two wall entries for one claim.
     *   - priority 20 PeepSoIntegration — reads `post_author`, then writes
     *     it, then drains `bcc_page_follows` through PeepSo's own
     *     SELECT-then-INSERT follow writer, over a table with no unique
     *     (active, passive) index.
     *   - priority 30 ValidatorMsgQueueWorker — the ONE that is already
     *     constraint-safe, via `uq_page` + INSERT ... ON DUPLICATE KEY.
     *
     * The existing `bcc_claim:<type>:<id>:<role>` lock does not cover any of
     * this: ClaimRepository::createExclusiveClaim() releases it in its own
     * `finally` before returning, and the retry path never calls
     * ClaimService::claim() at all, so it holds no lock whatsoever. Hence a
     * lock of this dispatch's own, keyed on the PAGE — the thing all three
     * subscribers write against — rather than on the claim.
     *
     * Serialising is deliberately preferred to adding a unique index:
     * `wp_peepso_activities` is PeepSo's table, shared with PeepSo-native
     * rows whose `act_external_id` defaults to 0, so a unique
     * (act_module_id, act_external_id) pair may already be violated by data
     * this plugin does not own. One lock fixes all three subscribers at once
     * and needs no migration.
     *
     * ⚠ Reentrancy is not a hazard here. MySQL's GET_LOCK is reentrant
     * within a session, so a nested acquire of this key by a subscriber
     * would succeed rather than deadlock; none takes it, and the two other
     * advisory locks in this path (`bcc_claim:...` and `bcc_vmq_page:...`)
     * use different keys and are not held at this point — the first is
     * released before ClaimService returns, and the second is taken by a
     * background job, never inline.
     *
     * ON CONTENTION the event is NOT dispatched, and that is logged rather
     * than swallowed. It stays retryable because nothing anywhere records
     * "this event was delivered": the retry trigger is the page still having
     * `post_author === 0`, which a priority-20 run clears. So a contended or
     * crashed dispatch leaves the page in exactly the state that makes the
     * next claim submission re-fire it. ⚠ That retry is user-initiated —
     * there is no cron repair pass — so the warning below is the only
     * signal an operator gets.
     *
     * ⚠⚠ `post_author !== 0` MEANS "THE TRANSFER COMPLETED", NOT "THE
     * EVENT COMPLETED". The priority-20 subscriber moves `post_author`
     * FIRST and drains `bcc_page_follows` LAST, so a drain that stopped on a
     * DB fault after a successful transfer is invisible to the check below
     * — and that check gates the re-fire, so nothing would ever come back
     * for it. The drain therefore gets a retry of its own, through a hook of
     * its own; see the branch below and
     * PeepSoIntegration::onClaimFollowersDrain().
     */
    private static function dispatchPageClaimed(
        int $userId,
        int $pageId,
        string $entityType,
        int $entityId,
        string $role,
        bool $onlyIfUnowned
    ): void {
        $lockKey = self::CLAIM_EVENT_LOCK_PREFIX . $pageId;

        if (!AdvisoryLock::acquire($lockKey, self::CLAIM_EVENT_LOCK_WAIT)) {
            // Held by a concurrent request for the same page (or the lock
            // itself errored — acquire() returns false for both and logs the
            // driver error separately). Either way: do not dispatch, do not
            // release a lock we never took.
            Logger::warning('[bcc-trust] bcc_page_claimed NOT dispatched - page event lock contended', [
                'page_id'     => $pageId,
                'user_id'     => $userId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'retryable'   => true,
            ]);
            return;
        }

        try {
            if ($onlyIfUnowned && (int) get_post_field('post_author', $pageId) !== 0) {
                // ⭐ THE AUTHORITATIVE CHECK, and the reason the lock is
                // taken before it rather than after. A concurrent delivery
                // completed the transfer while this request was waiting, so
                // re-firing the whole event would only re-drive three replay
                // guards — and two of those writes are not idempotent in a
                // way a replay guard can fully cover under concurrency.
                Logger::info('[bcc-trust] bcc_page_claimed re-fire skipped - transfer already completed', [
                    'page_id' => $pageId,
                    'user_id' => $userId,
                ]);

                // ⚠ The transfer completing is NOT the event completing
                // (see the ⚠⚠ note above). Retry the one step that has no
                // other trigger — the follower drain — and only that step,
                // under the lock this dispatch already holds, so it cannot
                // race a concurrent drain of the same page.
                //
                // Repeating it is safe BY CONSTRUCTION, not by assumption:
                // the drain deletes a row only after
                // PeepSoFollowWriter::follow() has returned a non-zero
                // follow id for it in that same pass, which for an
                // already-migrated row is the EXISTING id. So no row is ever
                // removed on the strength of an earlier pass's claim to have
                // migrated it. See PeepSoIntegration::onClaimFollowersDrain().
                do_action('bcc_page_claim_followers_drain', $userId, $pageId);
                return;
            }

            do_action('bcc_page_claimed', $userId, $pageId, $entityType, $entityId, $role);
        } finally {
            // Released on every exit path, including a subscriber throwing:
            // the three registered at priorities 10 and 30 catch their own
            // failures, but priority 20 is bound directly and does not.
            AdvisoryLock::release($lockKey);
        }
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

        // ⚠ A SERVER problem must never read as a WALLET problem.
        //
        // Before this branch, everything except needs_wallet and
        // already_claimed fell through to the 403 below — so an advisory-lock
        // timeout (two quick clicks), a write failure or a transient DB error
        // told a legitimate operator that their wallet does not match the
        // validator. The plausible reaction is to unlink a correct wallet or
        // give up, and the response left support nothing to diagnose with.
        //
        // 503 is retryable and matches the house code for "we could not
        // complete this, try again".
        if (($result['error'] ?? '') === 'transient') {
            return ApiResponse::error(
                'bcc_unavailable',
                'We could not complete the claim just now. Please try again in a moment.',
                503
            );
        }

        // Not a wallet mismatch either: the entity itself is gone.
        if (($result['error'] ?? '') === 'entity_not_found') {
            return ApiResponse::error(
                'bcc_not_found',
                'That entity could not be found.',
                404
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
