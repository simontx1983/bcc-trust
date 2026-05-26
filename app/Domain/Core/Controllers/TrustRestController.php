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

use BCC\Trust\Core\Exceptions\VoteEligibilityException;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\RateLimiter;

/**
 * Known-safe exception messages that can be returned to the client.
 * Any message not in this list gets replaced with a generic error.
 */
const BCC_SAFE_EXCEPTION_MESSAGES = [
    // VoteEligibilityException
    'You have already voted on this page.',
    'Your previous vote on this page was removed by dispute adjudication and cannot be recast.',
    'Email verification required before voting.',
    'Your account is suspended.',
    'Your account has been flagged for suspicious activity.',
    'Only trusted or elite members can downvote.',
    'Identity verification required for downvoting.',
    'Daily downvote limit reached.',
    'Page owner cannot vote on their own page.',
    'Voting is temporarily unavailable.',
    'Invalid vote type',
    'Invalid page',
    // InvalidArgumentException (report_vote)
    'User not authenticated',
    'Vote ID required',
    'Reason required',
    'Daily flag limit reached. You can flag up to 5 votes per day.',
    'Insufficient reputation to flag votes. Build your reputation through positive contributions first.',
    'Your account is under review. Flagging is temporarily unavailable.',
    'Vote not found',
    'You cannot flag your own vote.',
    'Page owners cannot flag votes on their own pages.',
    'You have already reported this vote',
];

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

        register_rest_route('bcc-trust/v1', '/vote', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'vote'],
            'permission_callback' => [self::class, 'permission_check'],
            'args'                => [
                'page_id'     => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                // Closure instead of 'intval' — WP passes 3 args to sanitize
                // callbacks (value, request, key), and PHP 8 fatals because
                // intval is an internal function that rejects extra args.
                'vote_type'   => ['required' => true, 'type' => 'integer', 'sanitize_callback' => static fn($value) => (int) $value],
                'category_id' => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
                'reason'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ]);

        register_rest_route('bcc-trust/v1', '/remove-vote', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'remove_vote'],
            'permission_callback' => [self::class, 'permission_check'],
            'args'                => [
                'page_id'     => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'category_id' => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
            ],
        ]);

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

        register_rest_route('bcc-trust/v1', '/user/(?P<id>\d+)/pages/scores', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_user_pages_scores'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('bcc-trust/v1', '/device-fingerprint', [
            'methods'             => 'POST',
            'callback'            => [UserStatusController::class, 'store_fingerprint'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('bcc-trust/v1', '/user/status', [
            'methods'             => 'GET',
            'callback'            => [UserStatusController::class, 'get_user_status'],
            'permission_callback' => [self::class, 'permission_check']
        ]);

        register_rest_route('bcc-trust/v1', '/report-vote', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'report_vote'],
            'permission_callback' => [self::class, 'permission_check'],
            'args'                => [
                'vote_id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'reason'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
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
     * Cast a vote on a page.
     *
     * Thin controller: validate → idempotency check → delegate → return.
     * Idempotency logic lives in VoteService; the controller only routes.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function vote(WP_REST_Request $request) {
        try {
            $pageId   = (int) $request->get_param('page_id');
            $voteType = (int) $request->get_param('vote_type');

            if (!$pageId) {
                throw new Exception('Page ID required');
            }
            if (!in_array($voteType, [1, -1])) {
                throw new Exception('Invalid vote type');
            }

            $voteService = \BCC\Trust\Core\Plugin::instance()->voteService();
            $userId      = get_current_user_id();
            $clientKey   = (string) ($request->get_header('X-Idempotency-Key') ?? '');

            // SECURITY: idempotency is consulted INSIDE castPageVote — AFTER all
            // eligibility/rate/fraud/coordination gates re-run. A replayed key
            // cannot bypass a mid-window state change (admin suspension, daily
            // cap exhaustion). The short IDEM_TTL (15s) covers SPA double-clicks
            // and network retries only — it is not an authorization cache.

            // Rate limiting is enforced once inside VoteEligibilityChecker::check().
            // Do NOT add a second RateLimiter::enforce() call here.

            $fingerprintData = $request->get_param('fingerprint');
            if ($fingerprintData) {
                AuditLogger::log('vote_attempt', $pageId, [
                    'vote_type'        => $voteType,
                    'fingerprint'      => $fingerprintData['hash'] ?? 'unknown',
                    'automation_score' => $fingerprintData['automation_score'] ?? 0
                ], 'page');
            }

            $reason = $request->get_param('reason');
            $result = $voteService->castPageVote(
                $pageId,
                $voteType,
                $fingerprintData,
                $reason ? (string) $reason : null,
                $clientKey
            );

            // Store for idempotency replay (only when client provides a key).
            // Stored AFTER a successful vote so the cached result reflects post-mutation state.
            if ($clientKey !== '') {
                $voteService->storeIdempotency($userId, $pageId, $voteType, $clientKey, $result);
            }

            // Audit log the successful vote (after writes commit, before response).
            // §VIII.30: audit logging must never break the mutation path — AuditLogger
            // already swallows insert failures internally.
            AuditLogger::log('vote_cast', $pageId, [
                'vote_type' => $voteType,
            ], 'page');

            return self::success($result);

        } catch (VoteEligibilityException $e) {
            if (isset($pageId)) {
                AuditLogger::log('vote_error', $pageId, [
                    'error'     => $e->getMessage(),
                    'vote_type' => $voteType ?? null
                ], 'page');
            }
            return self::safeExceptionError($e, 'vote()');
        } catch (Exception $e) {
            if (isset($pageId)) {
                AuditLogger::log('vote_error', $pageId, [
                    'error'     => $e->getMessage(),
                    'vote_type' => $voteType ?? null
                ], 'page');
            }
            // Route rate-limit / fail-closed exceptions through the shared
            // mapper so 429 / 503 surface correctly instead of collapsing to
            // a generic 500 that encourages aggressive client retries.
            $code = (int) $e->getCode();
            if ($code === 429 || $code === 503) {
                return self::safeExceptionError($e, 'vote()');
            }
            \BCC\Core\Log\Logger::error('[bcc-trust] vote() unexpected error', ['error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

    /**
     * Remove a vote
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function remove_vote(WP_REST_Request $request) {
        // Nonce is validated automatically by WordPress via the X-WP-Nonce header.

        try {
            // Use a separate bucket so removals don't exhaust the vote quota.
            RateLimiter::enforce('vote_remove');

            $pageId = (int) $request->get_param('page_id');

            if (!$pageId) {
                throw new Exception('Page ID required');
            }

            $result = (\BCC\Trust\Core\Plugin::instance()->voteService())->removePageVote($pageId);

            return self::success($result);

        } catch (VoteEligibilityException $e) {
            return self::safeExceptionError($e, 'remove_vote()');
        } catch (Exception $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] remove_vote() unexpected error', ['error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
        }
    }

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
                return self::error('Invalid endorsement context.', 400);
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

    /**
     * Get scores for all pages owned by a user
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function get_user_pages_scores(WP_REST_Request $request) {
        if (!RateLimiter::allow('api')) {
            return self::error('Too many requests. Please try again later.', 429);
        }

        try {
            $userId = (int) $request['id'];
            $currentUser = get_current_user_id();
            if ($userId !== $currentUser && !current_user_can('manage_options')) {
                return self::error('You can only view your own page scores.', 403);
            }

            $plugin = \BCC\Trust\Core\Plugin::instance();
            $repo   = $plugin->scoreRepository();
            $pages  = $repo->getByOwnerId($userId);

            // Trust score + tier + derived counters come from the read model
            // (bcc_page_read_model), which is the canonical source for all
            // UI-facing consumers per the architecture rules. The score
            // repository is the write-side aggregate — reading it directly
            // here bypassed the read model and drifted from what /discover,
            // the page header, and search showed.
            $readModel = $plugin->pageReadModelRepository();
            $pageIds   = array_map(
                static fn($page): int => (int) $page->score->getPageId(),
                $pages
            );
            $rmByPageId = $readModel->getByPageIds($pageIds);

            $isAdmin = current_user_can('manage_options');
            $result  = [];
            foreach ($pages as $page) {
                /** @var \BCC\Trust\Core\ValueObjects\PageScore $score */
                $score  = $page->score;
                $pageId = (int) $score->getPageId();
                $rm     = $rmByPageId[$pageId] ?? null;

                $entry = [
                    'page_id'         => $pageId,
                    'page_title'      => $page->post_title ?? '',
                    'total_score'     => $rm !== null
                        ? (float) $rm->trust_score
                        : $score->getTotalScore(),
                    'reputation_tier' => $rm !== null
                        ? (string) $rm->reputation_tier
                        : $score->getReputationTier(),
                    'vote_count'      => $rm !== null
                        ? (int) $rm->vote_count
                        : $score->getVoteCount(),
                    'confidence_score'=> $rm !== null
                        ? (float) $rm->confidence_score
                        : $score->getConfidenceScore(),
                ];
                if ($isAdmin) {
                    // Fraud alerts are a metadata flag on the write-side
                    // score row (not surfaced in the read model); keep
                    // reading from scoreRepository for admin-only context.
                    $entry['has_fraud_alerts'] = $score->hasFraudAlerts();
                }
                $result[] = $entry;
            }

            return self::success([
                'user_id' => $userId,
                'pages'   => $result,
                'count'   => count($result)
            ]);

        } catch (\Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'Trust engine error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return new \WP_Error('trust_engine_error', 'An unexpected error occurred.', ['status' => 500]);
        }
    }

    /**
     * Report a vote
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function report_vote(WP_REST_Request $request) {
        // Nonce is validated automatically by WordPress via the X-WP-Nonce header.

        try {
            $p = \BCC\Trust\Core\Plugin::instance();

            $userId = get_current_user_id();
            if (!$userId) {
                throw new \InvalidArgumentException('User not authenticated');
            }

            $voteId = (int) $request->get_param('vote_id');
            $reason = sanitize_text_field($request->get_param('reason'));

            if (!$voteId) {
                throw new \InvalidArgumentException('Vote ID required');
            }
            if (!$reason) {
                throw new \InvalidArgumentException('Reason required');
            }

            // Rate limit — check BEFORE any DB queries to prevent abuse.
            // Uses 'vote_flag' bucket to avoid collision with FlagEndpoint's 'flag' bucket.
            if (!RateLimiter::allow('vote_flag', 5, DAY_IN_SECONDS)) {
                throw new \InvalidArgumentException('Daily flag limit reached. You can flag up to 5 votes per day.');
            }

            // Reputation gate
            $flaggerInfo      = $p->userInfoRepository()->getByUserId($userId);
            $allowedFlagTiers = ['neutral', 'trusted', 'elite'];
            $flaggerTier      = $flaggerInfo->reputation_tier ?? 'risky';
            if (!in_array($flaggerTier, $allowedFlagTiers, true)) {
                throw new \InvalidArgumentException('Insufficient reputation to flag votes. Build your reputation through positive contributions first.');
            }
            if ($flaggerInfo && (int) $flaggerInfo->fraud_score >= BCC_TRUST_FRAUD_MEDIUM) {
                throw new \InvalidArgumentException('Your account is under review. Flagging is temporarily unavailable.');
            }

            // Vote existence
            $vote = $p->voteRepository()->getById($voteId);
            if (!$vote) {
                throw new \InvalidArgumentException('Vote not found');
            }

            // Self-flag prevention
            if ((int) $vote->voter_user_id === $userId) {
                throw new \InvalidArgumentException('You cannot flag your own vote.');
            }

            // Page-owner prevention
            $pageOwnerId = $p->scoreRepository()->getPageOwnerId((int) $vote->page_id);
            if ($pageOwnerId && $pageOwnerId === $userId) {
                throw new \InvalidArgumentException('Page owners cannot flag votes on their own pages.');
            }

            // Duplicate check
            $flagsRepo = $p->flagsRepository();
            if ($flagsRepo->hasUserFlaggedVote($voteId, $userId)) {
                throw new \InvalidArgumentException('You have already reported this vote');
            }

            // Create flag. Returns 0 when the UNIQUE(vote_id, flagger_user_id)
            // constraint rejects a parallel duplicate — treat as already-flagged
            // and bail out without incrementing audit or review-queue state.
            $flagId = $flagsRepo->create($voteId, $userId, $reason);
            if ($flagId === 0) {
                return self::success(['reported' => true]);
            }

            AuditLogger::flagCreated($voteId, $userId, $reason);

            // Check if vote needs review queue
            $flagCount = $flagsRepo->countForVote($voteId);
            if ($flagCount >= 3) {
                $p->voteRepository()->markForReview($voteId);

                AuditLogger::log('vote_pending_review', (int) $vote->page_id, [
                    'vote_id'     => $voteId,
                    'flag_count'  => $flagCount,
                    'voter_id'    => $vote->voter_user_id,
                    'flagged_by'  => $userId,
                    'vote_weight' => $vote->weight,
                ], 'page');
            }

            return self::success([
                'reported' => true,
            ]);

        } catch (\InvalidArgumentException $e) {
            return self::safeExceptionError($e, 'report_vote()');
        } catch (Exception $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] report_vote() unexpected error', ['error' => $e->getMessage()]);
            return self::error('An unexpected error occurred.', 500);
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

    private static function error(string $message, int $status): WP_Error {
        return new WP_Error('trust_error', $message, ['status' => $status]);
    }

    /**
     * Emit a WP_Error with a stable §1.4.6 / Phase γ error code.
     *
     * Use this for new code paths where the frontend branches on
     * `err.code` (see bcc-frontend/src/lib/api/errors.ts). The default
     * `error()` helper above stamps every response with `trust_error`,
     * which is §γ-incompatible — only retained for legacy call sites.
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

    /**
     * Return exception message to client only if it is a known-safe string.
     * Unknown messages are replaced with a generic error and logged.
     *
     * Status-code mapping:
     *   - code 429 (RateLimiter::enforce throws with this code) → 429 Too Many Requests.
     *     The thrown message includes a dynamic "wait N seconds" suffix that can't
     *     be whitelisted in BCC_SAFE_EXCEPTION_MESSAGES, so we recognise the code.
     *   - code 503 → 503 Service Unavailable (reserved for fail-closed degradations).
     *   - message in BCC_SAFE_EXCEPTION_MESSAGES → 400 with the raw message.
     *   - anything else → 400 with a generic message, full detail logged.
     */
    private static function safeExceptionError(\Throwable $e, string $context): WP_Error {
        $code = (int) $e->getCode();

        // Rate-limit denial MUST surface as 429, not 400/500. Prior behaviour
        // turned "Too many requests" into an "unexpected error" which some
        // clients retry aggressively — exactly the wrong response.
        if ($code === 429) {
            return self::error($e->getMessage(), 429);
        }

        if ($code === 503) {
            return self::error('Service temporarily unavailable. Please try again.', 503);
        }

        if (in_array($e->getMessage(), BCC_SAFE_EXCEPTION_MESSAGES, true)) {
            return self::error($e->getMessage(), 400);
        }
        \BCC\Core\Log\Logger::error("[bcc-trust] {$context} unexpected domain exception", [
            'error' => $e->getMessage(),
            'class' => get_class($e),
        ]);
        return self::error('This action could not be completed. Please try again.', 400);
    }
}