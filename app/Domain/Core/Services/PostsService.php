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
use BCC\Core\PeepSo\PeepSoGifWriter;
use BCC\Core\PeepSo\PeepSoPhotoWriter;
use BCC\Core\PeepSo\PeepSoStatusWriter;
use BCC\Core\Security\Throttle;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\Mentions\MentionExtractor;
use BCC\Trust\Core\Services\Mentions\MentionPolicy;
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
     * v1.5 photo-post caption cap. Photo posts allow caption-only OR
     * photo-only posting; when present the caption shares the same
     * 500-char ceiling as a status post (same composer textarea, same
     * voice). Empty caption is allowed and becomes a photo-only post.
     */
    public const PHOTO_CAPTION_MAX_LENGTH = 500;

    /** v1.5 GIF-post caption cap. Same shape as PHOTO_CAPTION_MAX_LENGTH. */
    public const GIF_CAPTION_MAX_LENGTH = 500;

    /**
     * v1.5 — max @-mentions per post body / caption.
     *
     * Mention fanout abuse (one post mass-tagging dozens of users)
     * is a well-known attack on social systems; uncapped fanout
     * creates a notification-spam vector. Ten is generous for
     * legitimate social use ("thanks @a @b @c …") and clips the
     * abuse pattern hard. Enforced server-side in createStatus /
     * createPhotoPost / createGifPost / CommentService.
     *
     * Per §3.3.12 — over-cap returns `bcc_too_many_mentions` with
     * `{max: 10}` echoed in the error payload.
     */
    public const MENTIONS_PER_POST_MAX = 10;

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
     * }|array{error: string, message: string, data?: array<string, mixed>}
     */
    public function createStatus(int $authorId, string $content, int $groupId = 0): array
    {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        // Group-wall validation runs BEFORE content / mention / throttle
        // gates so a non-member never burns a throttle slot probing
        // group existence. Returns null on success (allowed); an error
        // envelope on failure.
        if ($groupId > 0) {
            $gateError = self::gateGroupPost($authorId, $groupId);
            if ($gateError !== null) {
                return $gateError;
            }
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

        // §3.3.12 — strict-reject mention tokens that fail the
        // privacy policy (banned/blocked/hidden/private targets) or
        // overflow the per-post cap. Runs BEFORE the writer fires so
        // a rejected post never lands in the activity stream.
        $mentionError = self::validateMentions($authorId, $trimmed);
        if ($mentionError !== null) {
            return $mentionError;
        }

        $result = PeepSoStatusWriter::createSelfStatus($authorId, $trimmed, $groupId);
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
     * Create a §1.5 photo post on the viewer's own wall.
     *
     * Caption is OPTIONAL — photo-only posting is a real social use
     * case ("here's a meme", "photo from the conference"). When
     * present the caption rides the same 500-char status cap.
     *
     * Storage path:
     *   1. PeepSoPhotoWriter::createSelfPhotoPost validates the file
     *      (mime, size), stages it in PeepSo's tmp dir, sets the
     *      $_POST keys PeepSo's photo filter+hook chain expects, calls
     *      `PeepSoActivity::add_post(...)` — PeepSo handles wp_post,
     *      activity row, image processing pipeline, S3, notifications.
     *   2. We resolve the (post_id, act_id, photo_id) triple from the
     *      writer's response.
     *   3. Fire `bcc_post_created` so existing §A3 subscribers (rank
     *      progression, future analytics) light up uniformly across
     *      status / blog / photo paths.
     *
     * The burst seatbelt mirrors createStatus / createBlog — same
     * "don't let scripts flood the wall" intent.
     *
     * `$file` is the loose shape WP_REST_Request->get_file_params()
     * returns — keys defined by `$_FILES` semantics but each value is
     * `mixed` from PHPStan's perspective, so we accept the wider
     * shape and re-narrow at the writer boundary (which already does
     * defensive validation: error code, tmp_name, size, mime).
     *
     * @param array<string, mixed> $file
     * @return array{
     *   ok: true,
     *   feed_id: string,
     *   post_id: int,
     *   act_id: int,
     *   photo_id: int
     * }|array{error: string, message: string, data?: array<string, mixed>}
     */
    public function createPhotoPost(int $authorId, array $file, string $caption, int $groupId = 0): array
    {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        // Group-wall validation runs BEFORE caption / throttle / file
        // gates — same ordering as createStatus.
        if ($groupId > 0) {
            $gateError = self::gateGroupPost($authorId, $groupId);
            if ($gateError !== null) {
                return $gateError;
            }
        }

        $captionTrimmed = trim($caption);
        if (mb_strlen($captionTrimmed) > self::PHOTO_CAPTION_MAX_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf(
                    'Captions cap at %d characters.',
                    self::PHOTO_CAPTION_MAX_LENGTH
                ),
            ];
        }

        // Burst seatbelt — same constant as status posts (limits +
        // window from includes/config/limits.php). Photos are heavier
        // writes than status but social-usage frequency is comparable.
        // NOT a primary defense; fires-in-logs is the §K1 signal.
        $burstKey = "photo_post:{$authorId}:burst";
        if (!Throttle::allow(
            $burstKey,
            BCC_TRUST_RATE_LIMIT_STATUS_POST,
            BCC_TRUST_RATE_WINDOW_STATUS_POST
        )) {
            Logger::info('[PostsService] photo burst seatbelt fired', [
                'user_id' => $authorId,
                'limit'   => BCC_TRUST_RATE_LIMIT_STATUS_POST,
                'window'  => BCC_TRUST_RATE_WINDOW_STATUS_POST,
            ]);
            return [
                'error'   => 'bcc_rate_limited',
                'message' => 'Too fast. Wait a moment before posting again.',
            ];
        }

        // §3.3.12 — same write-time validation as createStatus. Runs
        // BEFORE the writer fires so a caption with a forbidden mention
        // token never produces a photo activity row.
        $mentionError = self::validateMentions($authorId, $captionTrimmed);
        if ($mentionError !== null) {
            return $mentionError;
        }

        $result = PeepSoPhotoWriter::createSelfPhotoPost($authorId, $file, $captionTrimmed, $groupId);
        if ($result['ok'] === false) {
            return self::mapPhotoWriterError($result['reason']);
        }

        $postId  = $result['post_id'];
        $actId   = $result['act_id'];
        $photoId = $result['photo_id'];

        // §A3 event bus — uniform with status / blog. Subscribers
        // attach independently and run async via Action Scheduler.
        do_action('bcc_post_created', $authorId, $postId, $actId);

        return [
            'ok'       => true,
            'feed_id'  => 'feed_' . $actId,
            'post_id'  => $postId,
            'act_id'   => $actId,
            'photo_id' => $photoId,
        ];
    }

    /**
     * Create a v1.5 GIF post on the viewer's own wall.
     *
     * Caption is OPTIONAL (photo-only and GIF-only posts are real
     * social use cases — "this gif says it all"). When present the
     * caption rides the same 500-char status cap.
     *
     * Storage path:
     *   1. PeepSoGifWriter::createSelfGifPost validates the URL
     *      (must contain `giphy.com` — matches PeepSo's own check),
     *      sets the $_POST keys PeepSo's giphy hook chain expects,
     *      calls `PeepSoActivity::add_post(...)`. PeepSo's
     *      `PeepSoGiphy::after_add_post` saves the URL to post_meta
     *      `peepso_giphy` on the wp_post. The activity row keeps
     *      `act_module_id = 1` (status) — GIF posts are
     *      discriminated post-hoc by the post_meta in
     *      FeedRankingService::hydrateBodies.
     *   2. We resolve the (post_id, act_id) tuple from the writer.
     *   3. Fire `bcc_post_created` so existing §A3 subscribers light
     *      up uniformly across status / photo / GIF paths.
     *
     * Burst seatbelt mirrors createStatus / createPhotoPost.
     *
     * @return array{
     *   ok: true,
     *   feed_id: string,
     *   post_id: int,
     *   act_id: int
     * }|array{error: string, message: string, data?: array<string, mixed>}
     */
    public function createGifPost(int $authorId, string $url, string $caption, int $groupId = 0): array
    {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        // Group-wall validation runs BEFORE caption / throttle / URL
        // gates — same ordering as createStatus / createPhotoPost.
        if ($groupId > 0) {
            $gateError = self::gateGroupPost($authorId, $groupId);
            if ($gateError !== null) {
                return $gateError;
            }
        }

        $captionTrimmed = trim($caption);
        if (mb_strlen($captionTrimmed) > self::GIF_CAPTION_MAX_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf(
                    'Captions cap at %d characters.',
                    self::GIF_CAPTION_MAX_LENGTH
                ),
            ];
        }

        // Burst seatbelt — same constants as status / photo. Honest
        // about social-usage frequency; GIF posts shouldn't exceed
        // status posts in burst frequency. NOT a primary defense;
        // fires-in-logs is the §K1 signal.
        $burstKey = "gif_post:{$authorId}:burst";
        if (!Throttle::allow(
            $burstKey,
            BCC_TRUST_RATE_LIMIT_STATUS_POST,
            BCC_TRUST_RATE_WINDOW_STATUS_POST
        )) {
            Logger::info('[PostsService] gif burst seatbelt fired', [
                'user_id' => $authorId,
                'limit'   => BCC_TRUST_RATE_LIMIT_STATUS_POST,
                'window'  => BCC_TRUST_RATE_WINDOW_STATUS_POST,
            ]);
            return [
                'error'   => 'bcc_rate_limited',
                'message' => 'Too fast. Wait a moment before posting again.',
            ];
        }

        // §3.3.12 — same write-time validation as status / photo.
        $mentionError = self::validateMentions($authorId, $captionTrimmed);
        if ($mentionError !== null) {
            return $mentionError;
        }

        $result = PeepSoGifWriter::createSelfGifPost($authorId, $url, $captionTrimmed, $groupId);
        if ($result['ok'] === false) {
            return self::mapGifWriterError($result['reason']);
        }

        $postId = $result['post_id'];
        $actId  = $result['act_id'];

        // §A3 event bus — uniform with status / photo. Subscribers
        // attach independently.
        do_action('bcc_post_created', $authorId, $postId, $actId);

        return [
            'ok'      => true,
            'feed_id' => 'feed_' . $actId,
            'post_id' => $postId,
            'act_id'  => $actId,
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

    /**
     * Map PeepSoPhotoWriter's reason codes to the BCC error envelope.
     * Distinct from mapWriterError because the photo path has its own
     * failure modes (file validation, mime, size cap) that don't exist
     * on the status path.
     *
     * @return array{error: string, message: string}
     */
    private static function mapPhotoWriterError(string $reason): array
    {
        return match ($reason) {
            'unavailable'      => ['error' => 'bcc_unavailable',     'message' => 'Photo service is offline. Try again shortly.'],
            'forbidden'        => ['error' => 'bcc_forbidden',       'message' => 'You do not have permission to post.'],
            'upload_failed'    => ['error' => 'bcc_invalid_request', 'message' => 'Photo upload failed. Try again.'],
            'invalid_upload'   => ['error' => 'bcc_invalid_request', 'message' => 'Invalid photo upload.'],
            'too_large'        => ['error' => 'bcc_invalid_request', 'message' => 'Photo is too large. 5 MB max.'],
            'unsupported_mime' => ['error' => 'bcc_invalid_request', 'message' => 'Photo must be JPEG, PNG, WebP, or GIF.'],
            'tmp_unavailable'  => ['error' => 'bcc_unavailable',     'message' => 'Photo storage is unavailable. Try again shortly.'],
            'persist_failed'   => ['error' => 'bcc_unavailable',     'message' => 'Could not save your photo. Try again.'],
            default            => ['error' => 'bcc_unavailable',     'message' => 'Could not save your photo.'],
        };
    }

    /**
     * Map PeepSoGifWriter's reason codes to the BCC error envelope.
     * Distinct from mapWriterError / mapPhotoWriterError because the
     * GIF path has only one validation failure mode (URL must contain
     * giphy.com) — no file handling, no mime check, no size cap.
     *
     * @return array{error: string, message: string}
     */
    private static function mapGifWriterError(string $reason): array
    {
        return match ($reason) {
            'unavailable'    => ['error' => 'bcc_unavailable',     'message' => 'GIF service is offline. Try again shortly.'],
            'forbidden'      => ['error' => 'bcc_forbidden',       'message' => 'You do not have permission to post.'],
            'invalid_url'    => ['error' => 'bcc_invalid_request', 'message' => 'GIF URL must come from Giphy.'],
            'persist_failed' => ['error' => 'bcc_unavailable',     'message' => 'Could not save your GIF. Try again.'],
            default          => ['error' => 'bcc_unavailable',     'message' => 'Could not save your GIF.'],
        };
    }

    /**
     * §3.3.12 — write-time mention validation.
     *
     * Strict-reject any post body whose @-mention tokens reference
     * banned/blocked/hidden/private users (`bcc_invalid_mention_target`)
     * or exceed the per-post cap (`bcc_too_many_mentions`).
     *
     * Privacy posture: failure error payloads echo the offending
     * `user_id` but DO NOT leak the failure reason (blocked vs hidden
     * vs banned vs private). The frontend surfaces a generic "could
     * not mention this user" message.
     *
     * Returns `null` when the body passes; an error envelope when
     * it doesn't (so the caller can `return $err` directly).
     *
     * @return array{error: string, message: string, data?: array<string, mixed>}|null
     */
    private static function validateMentions(int $authorId, string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        $candidateIds = MentionExtractor::extractUserIds($body);
        if ($candidateIds === []) {
            return null;
        }

        // Per-post cap. PeepSo's notification dispatcher fans out one
        // notification per mentioned id — uncapped fanout is a known
        // mention-bombing vector. The cap fires before policy filtering
        // so a malicious caller can't probe the policy by stuffing a
        // body with hundreds of fake ids and observing which surface
        // in the rejection.
        if (count($candidateIds) > self::MENTIONS_PER_POST_MAX) {
            return [
                'error'   => 'bcc_too_many_mentions',
                'message' => sprintf(
                    'You can mention up to %d people per post.',
                    self::MENTIONS_PER_POST_MAX
                ),
                'data' => ['max' => self::MENTIONS_PER_POST_MAX],
            ];
        }

        $allowed = MentionPolicy::filterMentionable($authorId, $candidateIds);
        $allowedSet = array_fill_keys($allowed, true);
        foreach ($candidateIds as $cid) {
            if (!isset($allowedSet[$cid])) {
                // Strict reject. Single offender per error response —
                // we don't dump the full disallowed list because that
                // would leak the policy outcome for every id at once.
                return [
                    'error'   => 'bcc_invalid_mention_target',
                    'message' => 'Could not mention that user.',
                    'data' => ['user_id' => $cid],
                ];
            }
        }
        return null;
    }

    /**
     * Group-wall write gate.
     *
     * Two-step check that mirrors the §4.7.5 / §4.7.6 read-side
     * defense-in-depth pattern:
     *
     *   1. {@see GroupsService::resolveGroupAccess} returns null when
     *      the group does not exist OR the viewer is anonymous OR the
     *      group is `secret` privacy and the viewer is not a member.
     *      All three failures collapse to 404 — the error message is
     *      identical so a probe can't distinguish "no such group" from
     *      "secret group, not in." This matches §4.7.5's posture.
     *
     *   2. With access resolved we then require active membership
     *      (READ_ALLOWED_STATUSES — same set the comment-count gate
     *      and FeedRankingService::hydrateGroupContexts use). A non-
     *      member of an open / closed group sees 403 with a filterable
     *      "Join the group to post here." message.
     *
     * The 403 message can be customised per-deployment via the
     * `bcc_group_post_membership_required` filter — the contract pin
     * is the error code (`bcc_permission_denied`); the human-facing
     * copy is filterable so V2 group-creator overrides ("Only
     * approved members can post in #foo") slot in without contract
     * churn.
     *
     * Returns null when the gate passes; an error envelope when it
     * doesn't (so the caller can `return $err` directly).
     *
     * @return array{error: string, message: string}|null
     */
    private static function gateGroupPost(int $authorId, int $groupId): ?array
    {
        $access = Plugin::instance()->groupsService()->resolveGroupAccess($authorId, $groupId);
        if ($access === null) {
            // Group does not exist OR is secret-and-viewer-not-a-member.
            // Single error message — defense-in-depth, no existence leak.
            return [
                'error'   => 'bcc_not_found',
                'message' => 'Group not found.',
            ];
        }

        if ($access['isMember'] !== true) {
            $message = (string) apply_filters(
                'bcc_group_post_membership_required',
                'Join the group to post here.',
                $authorId,
                $groupId
            );
            return [
                'error'   => 'bcc_permission_denied',
                'message' => $message,
            ];
        }

        return null;
    }
}
