<?php
/**
 * Posts Service — §D1 status post writer (V1 scope).
 *
 * Owns the create-status flow: validation → PeepSo write → §A3 event.
 * Higher-level concerns live elsewhere:
 *   - REST shape / HTTP status mapping → PostsEndpoint
 *   - PeepSo-specific persistence       → bcc-core PeepSoStatusWriter
 *   - downstream side effects (rank progression, notifications)
 *                                       → subscribers to bcc_post_created
 *
 * V1 scope (per §D2 / scope-freeze §P1):
 *   - status posts only on the actor's own wall
 *   - 1..STATUS_MAX_LENGTH characters after trim (per §D2 500-char cap)
 *   - no reputation/level gate on status (only auth)
 *   - composer surface does not yet expose post-as-entity, embeds,
 *     reviews, disputes, blogs — those are explicit V1.5/V2 work
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §D1 status composer)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Core\PeepSo\PeepSoStatusWriter;
use BCC\Core\Security\Throttle;
use BCC\Trust\Core\Repositories\VoteRepository;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

final class PostsService
{
    /** §D2 — status posts capped at 500 chars after trim. */
    public const STATUS_MAX_LENGTH = 500;

    /**
     * §D2 — review bodies allow long-form text. The cap is generous
     * but bounded so the textarea + DB column stay reasonable;
     * `bcc_trust_votes.explanation` is `TEXT` (64KB), well above
     * this limit.
     */
    public const REVIEW_BODY_MAX_LENGTH = 4000;

    /** §D6 — blog excerpt cap (Floor renders this, so it's bounded). */
    public const BLOG_EXCERPT_MIN_LENGTH = 80;
    public const BLOG_EXCERPT_MAX_LENGTH = 500;

    /**
     * §D6 — blog full_text cap. wp_posts.post_content is LONGTEXT
     * (4 GB) so this is a sanity bound, not a storage one. Equivalent
     * to ~30 standard book pages — generous for V1, tightenable later.
     */
    public const BLOG_FULL_TEXT_MAX_LENGTH = 60000;

    /**
     * §D2 — review grade buckets. Map to the existing trust system's
     * vote_type values (-1 / 0 / +1) so castPageVote consumes them
     * without translation. Frontend submits the symbolic key; the
     * service translates.
     *
     * @var array<string, int>
     */
    private const REVIEW_GRADE_TO_VOTE_TYPE = [
        'trust'   => 1,
        'neutral' => 0,
        'caution' => -1,
    ];

    public function __construct(
        private readonly VoteService $voteService,
        private readonly VoteRepository $voteRepository,
        private readonly FeatureAccessService $featureAccess
    ) {
    }

    /**
     * Create a status post on the viewer's own wall.
     *
     * Auth is the caller's responsibility (REST endpoint checks
     * get_current_user_id() before invoking). This method assumes a
     * valid viewer id.
     *
     * On success fires `do_action('bcc_post_created', $authorId,
     * $postId, $actId)` for the §A3 event bus. Subscribers (rank
     * progression, notification dispatcher, future activity
     * aggregators) attach independently.
     *
     * @return array{
     *   ok: true,
     *   feed_id: string,
     *   post_id: int,
     *   act_id: int
     * }|array{error: string, message: string}
     */
    public function createStatus(int $authorId, string $content): array
    {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Status content is required.',
            ];
        }
        if (mb_strlen($trimmed) > self::STATUS_MAX_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf(
                    'Status posts cap at %d characters.',
                    self::STATUS_MAX_LENGTH
                ),
            ];
        }

        // Burst seatbelt — tunable via includes/config/limits.php.
        // Tight enough to clip accidental floods + double-submits +
        // scripted bursts; loose enough that humans never hit it.
        // NOT a primary defense. When this fires repeatedly in logs,
        // it's the early signal to layer in proper abuse signals
        // (fingerprint / IP / fraud score) per the §K1 deferred work.
        $burstKey = "status_post:{$authorId}:burst";
        if (!Throttle::allow(
            $burstKey,
            BCC_TRUST_RATE_LIMIT_STATUS_POST,
            BCC_TRUST_RATE_WINDOW_STATUS_POST
        )) {
            // info-level — this is an observability signal we want
            // to grep for, not a debug breadcrumb. Frequency climb
            // = signal to layer in the §K1 fingerprint/IP/fraud gates.
            Logger::info('[PostsService] status burst seatbelt fired', [
                'user_id' => $authorId,
                'limit'   => BCC_TRUST_RATE_LIMIT_STATUS_POST,
                'window'  => BCC_TRUST_RATE_WINDOW_STATUS_POST,
            ]);
            return [
                'error'   => 'bcc_rate_limited',
                'message' => 'Too fast. Wait a moment before posting again.',
            ];
        }

        $result = PeepSoStatusWriter::createSelfStatus($authorId, $trimmed);
        if ($result['ok'] === false) {
            return self::mapWriterError($result['reason']);
        }

        $postId = $result['post_id'];
        $actId  = $result['act_id'];

        // §A3 event bus — single emission per state change. Subscribers
        // run async via Action Scheduler (cf. Plugin.php's existing
        // bcc_pull_batch_emitted / bcc_page_claimed wiring) so the
        // originating request returns immediately.
        do_action('bcc_post_created', $authorId, $postId, $actId);

        return [
            'ok'      => true,
            'feed_id' => 'feed_' . $actId,
            'post_id' => $postId,
            'act_id'  => $actId,
        ];
    }

    /**
     * Create a §D2 review on the target page.
     *
     * Two-step write so the security-critical castPageVote pipeline
     * (fraud detection / fan-in / sybil / idempotency / vesting)
     * stays untouched:
     *
     *   1. VoteService::castPageVote(...) — registers the trust
     *      signal (vote_type + weight). Fires `bcc_trust_vote_cast`
     *      on the §A3 bus.
     *   2. VoteRepository::setExplanation(...) — attaches the
     *      long-form review body.
     *   3. `bcc_review_published` event — ActivityStreamWriter
     *      subscribes and inserts the peepso_activities row so the
     *      review surfaces in the feed (single graph rule §C2).
     *
     * If step 1 fails (eligibility / fraud / rate limit), nothing
     * is written. If step 2 fails the vote still stands as a bare
     * trust signal — degraded but not orphaned.
     *
     * Permission gate: §D2 requires Level 2 (LEVEL_ACTIVE) AND
     * reputation tier ≥ neutral. Both are enforced server-side via
     * FeatureAccessService::canPerform('write_review', $userId).
     *
     * @return array{
     *   ok: true,
     *   feed_id: string|null,
     *   vote_id: int,
     *   page_id: int,
     *   grade: string
     * }|array{error: string, message: string}
     */
    public function createReview(
        int $authorId,
        int $pageId,
        string $grade,
        string $body
    ): array {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        if ($pageId <= 0) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Target page is required.',
            ];
        }

        $voteType = self::REVIEW_GRADE_TO_VOTE_TYPE[$grade] ?? null;
        if ($voteType === null) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Grade must be trust, neutral, or caution.',
            ];
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Review body is required.',
            ];
        }
        if (mb_strlen($trimmed) > self::REVIEW_BODY_MAX_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf(
                    'Reviews cap at %d characters.',
                    self::REVIEW_BODY_MAX_LENGTH
                ),
            ];
        }

        // §D2 + §O5 — Level 2 + tier ≥ neutral.
        $access = $this->featureAccess->canPerform($authorId, 'write_review');
        if ($access['allowed'] !== true) {
            return [
                'error'   => 'bcc_forbidden',
                'message' => $access['unlock_hint']
                    ?? 'You need to reach the Active level before writing reviews.',
            ];
        }

        // ── Step 1: trust signal write (full security pipeline) ─────────
        $voteId = 0;
        try {
            $result = $this->voteService->castPageVote($pageId, $voteType);
            $voteId = (int) ($result['vote_id'] ?? 0);
        } catch (Exception $e) {
            // VoteService throws on rate limit / fraud / coordination
            // gates; surface as bcc_forbidden so the UI shows the
            // server message rather than a generic 500.
            return [
                'error'   => 'bcc_forbidden',
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Review could not be cast.',
            ];
        }

        if ($voteId <= 0) {
            return [
                'error'   => 'bcc_unavailable',
                'message' => 'Review could not be saved. Try again.',
            ];
        }

        // ── Step 2: long-form body ──────────────────────────────────────
        $this->voteRepository->setExplanation($voteId, $trimmed);

        // ── Step 3: §A3 event — ActivityStreamWriter subscribes. ────────
        do_action('bcc_review_published', $authorId, $pageId, $voteId, $trimmed);

        return [
            'ok'      => true,
            // Feed_id is filled by ActivityStreamWriter once the
            // peepso_activities row lands. Caller invalidates the
            // feed query and refetches; the new review surfaces on
            // the next paint.
            'feed_id' => null,
            'vote_id' => $voteId,
            'page_id' => $pageId,
            'grade'   => $grade,
        ];
    }

    /**
     * Remove the viewer's existing review on a page.
     *
     * Symmetric counterpart to createReview: the security-critical
     * removal pipeline (score reversal, cache invalidation, fraud
     * audit) lives in VoteService::removePageVote — we call that
     * directly. The explanation column drops with the row, so no
     * follow-up cleanup is needed.
     *
     * Idempotent: removing when no vote exists returns success
     * (vote_id 0). Caller can refetch the card view-model to confirm
     * `viewer_has_reviewed` flipped back to false.
     *
     * @return array{ok: true, page_id: int}|array{error: string, message: string}
     */
    public function removeReview(int $authorId, int $pageId): array
    {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if ($pageId <= 0) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Target page is required.',
            ];
        }

        try {
            $this->voteService->removePageVote($pageId);
        } catch (Exception $e) {
            return [
                'error'   => 'bcc_unavailable',
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Could not remove your review.',
            ];
        }

        return [
            'ok'      => true,
            'page_id' => $pageId,
        ];
    }

    /**
     * Create a §D6 blog post on the viewer's own wall.
     *
     * The body splits into two server-known parts:
     *   - `excerpt` (300–500 chars) — what the Floor renders as a teaser
     *   - `full_text` (no character cap, 60k bound) — what the blog tab
     *     renders in full
     *
     * Storage path:
     *   1. PeepSoStatusWriter::createSelfBlogPost writes the wp_post
     *      (post_type=peepso-activity-status, excerpt + content fields).
     *   2. `bcc_blog_post_created` event fires; ActivityStreamWriter
     *      subscribes async and inserts the peepso_activities row with
     *      act_module_id='blog' (the canonical BCC pattern for non-status
     *      kinds, mirroring how reviews and pull-batches surface).
     *
     * The Floor surfaces blog rows via the existing FeedItemNormalizer
     * mapping (`blog → blog_excerpt`); the per-user blog tab reads from
     * `GET /users/:handle/blog` which hydrates `body.full_text` too.
     *
     * V1 scope:
     *   - any auth (no level/tier gate per §D6 — all users have a blog)
     *   - same status-burst throttle as createStatus (no per-blog limit
     *     yet; blogs are slower to write so the burst is unlikely)
     *
     * V1.5 deferred:
     *   - share_to_floor toggle (Phase 5 composer concern; V1 always shares)
     *   - blog-as-entity composer (claimed validators/creators posting)
     *   - markdown / image embed rendering
     *
     * @return array{
     *   ok: true,
     *   post_id: int,
     *   excerpt_length: int,
     *   full_text_length: int
     * }|array{error: string, message: string}
     */
    public function createBlog(
        int $authorId,
        string $excerpt,
        string $fullText
    ): array {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        $excerpt  = trim($excerpt);
        $fullText = trim($fullText);

        if ($excerpt === '') {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Blog excerpt is required.',
            ];
        }
        $excerptLen = mb_strlen($excerpt);
        if ($excerptLen < self::BLOG_EXCERPT_MIN_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf(
                    'Blog excerpt must be at least %d characters.',
                    self::BLOG_EXCERPT_MIN_LENGTH
                ),
            ];
        }
        if ($excerptLen > self::BLOG_EXCERPT_MAX_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf(
                    'Blog excerpt caps at %d characters.',
                    self::BLOG_EXCERPT_MAX_LENGTH
                ),
            ];
        }

        if ($fullText === '') {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Blog body is required.',
            ];
        }
        $fullTextLen = mb_strlen($fullText);
        if ($fullTextLen > self::BLOG_FULL_TEXT_MAX_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf(
                    'Blog body caps at %d characters.',
                    self::BLOG_FULL_TEXT_MAX_LENGTH
                ),
            ];
        }

        // Reuse the status burst seatbelt — blogs are heavier writes
        // but share the same "don't let a script flood the wall" intent.
        $burstKey = "blog_post:{$authorId}:burst";
        if (!Throttle::allow(
            $burstKey,
            BCC_TRUST_RATE_LIMIT_STATUS_POST,
            BCC_TRUST_RATE_WINDOW_STATUS_POST
        )) {
            Logger::info('[PostsService] blog burst seatbelt fired', [
                'user_id' => $authorId,
                'limit'   => BCC_TRUST_RATE_LIMIT_STATUS_POST,
                'window'  => BCC_TRUST_RATE_WINDOW_STATUS_POST,
            ]);
            return [
                'error'   => 'bcc_rate_limited',
                'message' => 'Too fast. Wait a moment before posting again.',
            ];
        }

        $postId = PeepSoStatusWriter::createSelfBlogPost(
            $authorId,
            $excerpt,
            $fullText
        );
        if ($postId <= 0) {
            return [
                'error'   => 'bcc_unavailable',
                'message' => 'Could not save your blog post. Try again.',
            ];
        }

        // §A3 event — ActivityStreamWriter subscribes (async via WP-cron
        // queue) and inserts the peepso_activities row. The originating
        // request returns the post_id immediately; the activity row may
        // land a tick later. Frontend invalidates the feed query and
        // refetches once the response resolves.
        do_action('bcc_blog_post_created', $authorId, $postId);

        return [
            'ok'              => true,
            'post_id'         => $postId,
            'excerpt_length'  => $excerptLen,
            'full_text_length' => $fullTextLen,
        ];
    }

    /**
     * Map PeepSoStatusWriter's reason codes to the BCC error envelope.
     *
     * @return array{error: string, message: string}
     */
    private static function mapWriterError(string $reason): array
    {
        return match ($reason) {
            'unavailable'   => ['error' => 'bcc_unavailable',     'message' => 'Status service is offline. Try again shortly.'],
            'forbidden'     => ['error' => 'bcc_forbidden',       'message' => 'You do not have permission to post.'],
            'empty_content' => ['error' => 'bcc_invalid_request', 'message' => 'Status content is required.'],
            'persist_failed'=> ['error' => 'bcc_unavailable',     'message' => 'Could not save your post. Try again.'],
            default         => ['error' => 'bcc_unavailable',     'message' => 'Could not save your post.'],
        };
    }
}
