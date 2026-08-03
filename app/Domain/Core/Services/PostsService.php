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
use BCC\Trust\Core\Repositories\BlogChainTagRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\Mentions\MentionExtractor;
use BCC\Trust\Core\Services\Mentions\MentionPolicy;
use BCC\Trust\Core\ValueObjects\BlogCategory;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

final class PostsService
{
    /** §D2 — status posts capped at 500 chars after trim. */
    public const STATUS_MAX_LENGTH = 500;

    /**
     * Per-post visibility values (group-scoped posts only). Controls
     * whether a group-tagged post syndicates to the GLOBAL feed:
     *   - members_only — group-internal; never appears in the global feed.
     *   - public_group — group-internal but discoverable as a group surface
     *     (Phase 2/3 read-gate concern; in Phase 1 this is NOT global).
     *   - public_all   — syndicates to the global feed.
     * Default + fallback is `members_only` (defense in depth). Only the
     * writer acts on this, and only when groupId > 0.
     *
     * @var list<string>
     */
    private const VISIBILITY_VALUES = ['members_only', 'public_group', 'public_all'];

    /** Default + fallback per-post visibility. */
    private const VISIBILITY_DEFAULT = 'members_only';

    /**
     * The visibility that syndicates a group post to the global /
     * anonymous feed. Setting it is authorization-gated per group (see
     * {@see gateGroupPost} → GroupsService::canUsePublicAll); the other
     * two values stay within the group's own audience.
     */
    private const VISIBILITY_PUBLIC_ALL = 'public_all';

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

    // ── §D6 crypto-blog composer (PR-A) caps ────────────────────────────
    //
    // All five caps are public consts so the REST endpoint, tests, and
    // future PATCH handler (PR-B) can reference the same numbers. The
    // values are also documented in docs/api-contract-v1.md §3.3.8.

    /** Headline length cap. Frontend enforces 'required'; service treats
     *  title as optional in PR-A so the legacy 3-arg create-path keeps
     *  working without churn. */
    public const BLOG_TITLE_MAX_LENGTH = 120;

    /** Max free-form (non-chain) tags per post. */
    public const BLOG_TAGS_MAX = 5;

    /** Max length per free-form tag. Tags are normalized to lowercase
     *  alnum + dash so the cap is roughly "two short words." */
    public const BLOG_TAG_LEN_MAX = 24;

    /** Max chain tags per post. Mirrors BlogChainTagRepository's
     *  MAX_TAGS_PER_POST — kept in two places intentionally; bumping
     *  one without the other is a contract drift the tests catch. */
    public const BLOG_CHAIN_TAGS_MAX = 3;

    /** Max tickers per disclosure block (e.g. ["BTC","ETH","SOL",…]). */
    public const BLOG_DISCLOSURE_TICKERS_MAX = 20;

    /** Max length of a single ticker (covers DEX pair symbols like
     *  WBTC.b — 12 chars handles the long tail). */
    public const BLOG_DISCLOSURE_TICKER_LEN_MAX = 12;

    /** Max length of the disclosure free-text note. */
    public const BLOG_DISCLOSURE_NOTE_MAX = 500;

    /** Max numbered citations per blog post. Each citation is a free
     *  string (URL or short reference). The composer renders them as a
     *  numbered list at the post footer. */
    public const BLOG_SOURCES_MAX = 20;

    /** Max length of a single source string. Wide enough for a long
     *  URL plus a short trailing label ("https://… — title"). */
    public const BLOG_SOURCE_LEN_MAX = 280;

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
        // §D6 PR-A — chain-tag join writer. Optional in the signature
        // (defaults to null + a fresh instance) so test paths and
        // third-party callers wiring this service by hand don't break.
        // Plugin always passes the singleton; the null fallback is
        // never taken in production.
        private readonly ?BlogChainTagRepository $blogChainTagRepository = null
    ) {
    }

    /**
     * Lazily resolve the chain-tag repo. Production calls always come
     * through Plugin's accessor, which supplies the singleton — this
     * path is the legacy 3-arg test-harness fallback only.
     */
    private function chainTagRepo(): BlogChainTagRepository
    {
        return $this->blogChainTagRepository ?? new BlogChainTagRepository();
    }

    /**
     * Clamp a per-post visibility value to the allowed set. Anything
     * unrecognized collapses to the default (`members_only`) — the most
     * conservative state, so a malformed value can never accidentally
     * publish a group post to the global feed.
     */
    private static function normalizeVisibility(string $visibility): string
    {
        return in_array($visibility, self::VISIBILITY_VALUES, true)
            ? $visibility
            : self::VISIBILITY_DEFAULT;
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
    public function createStatus(
        int $authorId,
        string $content,
        int $groupId = 0,
        string $visibility = self::VISIBILITY_DEFAULT
    ): array {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        // Defense in depth: anything outside the allowed set falls back
        // to members_only. The REST enum already gates this, but the
        // service is the security boundary.
        $visibility = self::normalizeVisibility($visibility);

        // Group-wall validation runs BEFORE content / mention / throttle
        // gates so a non-member never burns a throttle slot probing
        // group existence. Returns null on success (allowed); an error
        // envelope on failure.
        if ($groupId > 0) {
            $gateError = self::gateGroupPost($authorId, $groupId, $visibility);
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

        $result = PeepSoStatusWriter::createSelfStatus($authorId, $trimmed, $groupId, $visibility);
        if ($result['ok'] === false) {
            return self::mapWriterError($result['reason']);
        }

        $postId = $result['post_id'];
        $actId  = $result['act_id'];

        // §A3 event bus — single emission per state change. Subscribers
        // run async via Action Scheduler (cf. Plugin.php's existing
        // bcc_watch_batch_emitted / bcc_page_claimed wiring) so the
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
     * Permission gate (Rank Phase 5 final policy): Apprentice+ AND
     * reputation tier ≥ Neutral, enforced server-side via
     * CapabilityResolver::can($userId, 'write_review').
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

        // Rank Phase 5 final policy — CapabilityResolver is authoritative:
        // Apprentice+ AND Neutral+ (New Members cannot write reviews;
        // Journeyman is never required).
        $decision = \BCC\Trust\Core\Plugin::instance()
            ->capabilityResolver()
            ->can($authorId, 'write_review');
        if (!$decision->isAllowed()) {
            return [
                'error'   => 'bcc_forbidden',
                // States the requirement the user can act on.
                'message' => 'Become an Apprentice and keep your standing at Neutral or better to write reviews.',
            ];
        }

        // ── Step 1: trust signal write (full security pipeline) ─────────
        $voteId = 0;
        try {
            $result = $this->voteService->castPageVote($pageId, $voteType);
            $voteId = (int) ($result['vote_id'] ?? 0);
        } catch (\BCC\Trust\Core\Exceptions\VoteEligibilityException $e) {
            // Eligibility/gate rejection with an operator-authored,
            // user-safe message (rate limit / fraud / coordination) —
            // safe to forward verbatim.
            return [
                'error'   => 'bcc_forbidden',
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Review could not be cast.',
            ];
        } catch (Exception $e) {
            // Unexpected runtime failure — never forward the internal
            // message to the client (γ-leak). Log it, return a generic.
            \BCC\Core\Log\Logger::error('[PostsService] castPageVote failed', [
                'page_id' => $pageId,
                'error'   => $e->getMessage(),
            ]);
            return [
                'error'   => 'bcc_forbidden',
                'message' => 'Review could not be cast.',
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
        } catch (\BCC\Trust\Core\Exceptions\VoteEligibilityException $e) {
            // Operator-authored, user-safe gate message — forward verbatim.
            return [
                'error'   => 'bcc_unavailable',
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Could not remove your review.',
            ];
        } catch (Exception $e) {
            // Unexpected runtime failure — never forward internals.
            \BCC\Core\Log\Logger::error('[PostsService] removePageVote failed', [
                'page_id' => $pageId,
                'error'   => $e->getMessage(),
            ]);
            return [
                'error'   => 'bcc_unavailable',
                'message' => 'Could not remove your review.',
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
     * PR-A widens the signature to accept the crypto-blog composer's
     * sidecar fields. All new params are optional — legacy 3-arg
     * callers continue to work unchanged, just landing as a
     * categoryless/tagless blog row.
     *
     * Validation order (each gate returns immediately on failure):
     *   1. auth        — authorId > 0
     *   2. excerpt     — required, length window
     *   3. fullText    — required, length cap
     *   4. title       — optional; if present, sanitize + length cap
     *   5. category    — optional; pre-narrowed to enum or null by caller
     *   6. tags        — optional; normalized lowercase, alnum-dash, capped
     *   7. chainIds    — optional; each FK-validated against chain registry, capped
     *   8. disclosure  — optional; ticker shape + note cap
     *   9. coverImageId— optional; existence + author-ownership check
     *  10. status      — must be 'draft' or 'publish'
     *  11. throttle    — status burst seatbelt
     *  12. PeepSo write
     *  13. side-car meta + chain-tag join
     *  14. §A3 event for publish (drafts do NOT fire — ActivityStreamWriter
     *      would insert an activity row that surfaces the draft on the
     *      floor before publish)
     *
     * @param list<string> $tags           Free-form tags (will be normalized).
     * @param list<int>    $chainIds       Chain registry IDs.
     * @param array{tickers?: list<string>, note?: string}|null $disclosure
     *                                     Disclosure block — tickers + note.
     * @param list<string> $sources        Free-form citation strings;
     *                                     trimmed, deduped, capped server-side.
     *
     * @return array{
     *   ok: true,
     *   post_id: int,
     *   excerpt_length: int,
     *   full_text_length: int,
     *   status: string
     * }|array{error: string, message: string, data?: array<string, mixed>}
     */
    public function createBlog(
        int $authorId,
        string $excerpt,
        string $fullText,
        ?string $title = null,
        ?BlogCategory $category = null,
        array $tags = [],
        array $chainIds = [],
        ?array $disclosure = null,
        ?int $coverImageId = null,
        string $status = 'publish',
        array $sources = []
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

        // ── Title: optional in PR-A (frontend enforces required) ─────
        //
        // sanitize_text_field strips tags + newlines. We accept null
        // (legacy 3-arg callers) but also '' (empty REST string) —
        // both collapse to "no title" so the WP post gets post_title=''.
        $titleSanitized = '';
        if (is_string($title) && trim($title) !== '') {
            $titleSanitized = sanitize_text_field($title);
            if (mb_strlen($titleSanitized) > self::BLOG_TITLE_MAX_LENGTH) {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => sprintf(
                        'Blog title caps at %d characters.',
                        self::BLOG_TITLE_MAX_LENGTH
                    ),
                ];
            }
        }

        // ── Tags: lowercase alnum-dash, deduped, capped ──────────────
        $tagsClean = self::normalizeBlogTags($tags);
        if ($tagsClean === null) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Tags may only contain lowercase letters, digits, and dashes.',
            ];
        }
        if (count($tagsClean) > self::BLOG_TAGS_MAX) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf(
                    'Blogs cap at %d tags.',
                    self::BLOG_TAGS_MAX
                ),
                'data'    => ['max' => self::BLOG_TAGS_MAX],
            ];
        }

        // ── Chain tags: FK-validate, dedupe, cap ─────────────────────
        $chainIdsClean = self::normalizeChainIds($chainIds);
        if (count($chainIdsClean) > self::BLOG_CHAIN_TAGS_MAX) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf(
                    'Blogs cap at %d chain tags.',
                    self::BLOG_CHAIN_TAGS_MAX
                ),
                'data'    => ['max' => self::BLOG_CHAIN_TAGS_MAX],
            ];
        }
        foreach ($chainIdsClean as $cid) {
            // ChainRepository::getById hits the active-chain cache
            // first then falls back to a direct query. Inactive chains
            // are rejected here — a deactivated chain shouldn't be
            // pickable in the composer at all, but a stale frontend
            // cache could send one and we want a clean error envelope.
            if (\BCC\Core\ServiceLocator::resolveChainRead()->getById($cid) === null) {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => 'Unknown chain tag.',
                    'data'    => ['chain_id' => $cid],
                ];
            }
        }

        // ── Disclosure block: shape + caps ───────────────────────────
        $disclosureClean = null;
        if ($disclosure !== null) {
            $disclosureClean = self::normalizeDisclosure($disclosure);
            if (!is_array($disclosureClean)) {
                // String error code from the normalizer — surface as
                // bcc_invalid_request with the offending field hinted.
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => (string) $disclosureClean,
                ];
            }
        }

        // ── Sources: shape + caps ────────────────────────────────────
        $sourcesClean = self::normalizeSources($sources);
        if (!is_array($sourcesClean)) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => (string) $sourcesClean,
            ];
        }

        // ── Cover image: must be the author's own attachment ─────────
        //
        // Author-ownership check is the security gate: without it, a
        // user could pin another user's photo as their blog cover
        // (information-disclosure for restricted attachments, plus
        // attribution confusion). We resolve via get_post + check
        // post_type === 'attachment' AND post_author === authorId.
        if ($coverImageId !== null && $coverImageId > 0) {
            $attachment = get_post($coverImageId);
            if (!$attachment instanceof \WP_Post
                || $attachment->post_type !== 'attachment'
                || (int) $attachment->post_author !== $authorId
            ) {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => 'Cover image not found or not owned by you.',
                ];
            }
        }

        // ── Status validation ────────────────────────────────────────
        if ($status !== 'draft' && $status !== 'publish') {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Status must be "draft" or "publish".',
            ];
        }

        // ── Throttle (shared with status posts) ──────────────────────
        //
        // Same seatbelt as the legacy path. Drafts are throttled too —
        // a script could mass-create drafts to fill the user's tab
        // even without publishing, and the cost of a draft is the
        // same as a publish on the wp_posts insert.
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

        // ── Persistence ──────────────────────────────────────────────
        $postId = PeepSoStatusWriter::createSelfBlogPost(
            $authorId,
            $excerpt,
            $fullText,
            $titleSanitized !== '' ? $titleSanitized : null,
            $status
        );
        if ($postId <= 0) {
            return [
                'error'   => 'bcc_unavailable',
                'message' => 'Could not save your blog post. Try again.',
            ];
        }

        // ── §D6 blog discriminator — stamp at CREATION (draft + publish) ──
        //
        // `_bcc_activity_module='blog'` is the marker every blog surface
        // uses to recognise a blog-kinded peepso-post: getBlogForEdit,
        // updateBlog, and BlogStatusTransitionHandler ALL gate on it.
        // handleBlogPostCreated also writes it — but only on publish — so a
        // draft born here would carry no marker and be unreachable for
        // load / edit / publish (a chicken-and-egg deadlock: the one handler
        // that could stamp it, BlogStatusTransitionHandler, first checks for
        // the very marker that was never written). Stamp it here so a draft
        // is editable and publishable from birth.
        //
        // Do NOT move this back inside the publish branch — that is exactly
        // the bug this fixes. It does not surface the draft anywhere: the
        // peepso_activities row is inserted only by the publish-gated
        // handleBlogPostCreated, so feeds and the blog tab still see
        // published posts only. handleBlogPostCreated re-writing this on
        // publish is an idempotent no-op.
        update_post_meta($postId, '_bcc_activity_module', 'blog');

        // ── Side-car meta + chain-tag join ───────────────────────────
        //
        // Each meta key is OPTIONAL on the post — the hydrator returns
        // null/[] when absent. Storing absent fields would be wasteful
        // and would make backfill of pre-PR-A blog posts visible as
        // "real null" vs "field never set" inconsistency.
        if ($category !== null) {
            update_post_meta($postId, '_bcc_blog_category', $category->value);
        }
        if ($tagsClean !== []) {
            $tagsJson = wp_json_encode(array_values($tagsClean));
            if (is_string($tagsJson)) {
                update_post_meta($postId, '_bcc_blog_tags', $tagsJson);
            }
        }
        if ($disclosureClean !== null) {
            $discJson = wp_json_encode($disclosureClean);
            if (is_string($discJson)) {
                update_post_meta($postId, '_bcc_blog_disclosure', $discJson);
            }
        }
        if ($sourcesClean !== []) {
            $srcJson = wp_json_encode(array_values($sourcesClean));
            if (is_string($srcJson)) {
                update_post_meta($postId, '_bcc_blog_sources', $srcJson);
            }
        }
        if ($coverImageId !== null && $coverImageId > 0) {
            // Native WP — surfaces as the featured image on the wp_post
            // AND drives get_the_post_thumbnail_url() in the hydrator.
            set_post_thumbnail($postId, $coverImageId);
        }

        // Persist chain tags (empty list is a valid "no chains" state).
        $this->chainTagRepo()->replace($postId, $chainIdsClean);

        // ── §A3 event — publish only ────────────────────────────────
        //
        // Drafts must NOT dispatch bcc_blog_post_created — that event
        // is the trigger ActivityStreamWriter::handleBlogPostCreated
        // listens for to insert the peepso_activities row, which is
        // what surfaces the post in the floor feed + per-user blog
        // tab. A draft surfacing in either place is the failure mode
        // we're guarding against. The draft→publish transition is
        // handled by BlogStatusTransitionHandler.
        if ($status === 'publish') {
            do_action('bcc_blog_post_created', $authorId, $postId);
        }

        return [
            'ok'               => true,
            'post_id'          => $postId,
            'excerpt_length'   => $excerptLen,
            'full_text_length' => $fullTextLen,
            'status'           => $status,
        ];
    }

    /**
     * Partial-update an existing blog post (§D6 PR-B owner edit).
     *
     * Wraps WordPress's `wp_save_post_revision()` + `wp_update_post()`
     * so the existing draft↔publish status transition handler picks
     * up status flips for free (via `transition_post_status`).
     *
     * Partial-update semantics:
     *   - `null` on any nullable param means "leave unchanged."
     *   - Empty array on `$tags` or `$chainIds` means "clear" (caller
     *     supplied an empty list deliberately — semantically distinct
     *     from null).
     *   - `$disclosure` is the exception to the "null = unchanged"
     *     rule: `null` means CLEAR (delete the sidecar meta) and a
     *     struct means REPLACE. The REST handler enforces this
     *     contract at its boundary — if the caller did not include
     *     `disclosure` in the request body, the handler MUST forward
     *     the internal "no-op" sentinel below rather than null.
     *     Without that boundary discipline a partial-update request
     *     that omits `disclosure` would silently wipe the existing
     *     disclosure block.
     *   - `$coverImageId === 0` means "un-pin the cover." A positive
     *     int means "replace" (must be author-owned). `null` means
     *     "leave unchanged."
     *   - `$status` is `'draft'|'publish'|null`. `null` = unchanged.
     *
     * Validation reuses the same private normalizers + FK-validators
     * as {@see createBlog}, so the error messages stay identical
     * across create + update.
     *
     * Ordering:
     *   1. Load + identify the post (existence, type, module tag).
     *   2. Ownership check — viewer must equal post_author.
     *   3. Per-field validation (every supplied field passes the
     *      create-path validator before any DB write).
     *   4. wp_save_post_revision — fires BEFORE any mutation so the
     *      revision captures pre-change state. Idempotent on retry
     *      (WP's revision API absorbs duplicate calls within its
     *      configured threshold; we don't try to dedupe here).
     *   5. wp_update_post — title / excerpt / content / status. The
     *      transition_post_status hook fires automatically; the
     *      BlogStatusTransitionHandler is already wired and handles
     *      the draft→publish / publish→draft side effects.
     *   6. Sidecar meta — _bcc_blog_category, _bcc_blog_tags,
     *      _bcc_blog_disclosure. delete_post_meta when caller asks
     *      to clear (empty array / null disclosure).
     *   7. Cover image pin — set_post_thumbnail or delete_post_thumbnail.
     *   8. Chain-tag join — replace() (writes empty set when caller
     *      passed an empty array; cache invalidation runs inside).
     *
     * Failure during the post-revision phase leaves the revision as
     * a no-op artifact. Acceptable: WP's retention pruning eventually
     * collects it. The alternative (transactional rollback over
     * wp_posts + postmeta + sidecar tables + WP cache invalidation)
     * isn't worth the complexity for a content edit.
     *
     * @param list<string>|null                              $tags
     *        `null` = unchanged; `[]` = clear; non-empty = replace.
     * @param list<int>|null                                 $chainIds
     *        `null` = unchanged; `[]` = clear; non-empty = replace.
     * @param array{tickers?: list<string>, note?: string}|array{__noop__: true}|null $disclosure
     *        `null` = clear; struct = replace; `{__noop__:true}` =
     *        internal sentinel from the REST layer meaning "unchanged."
     * @param list<string>|null                              $sources
     *        `null` = unchanged; `[]` = clear; non-empty = replace.
     *
     * @return array{
     *   ok: true,
     *   post_id: int,
     *   status: string
     * }|array{error: string, message: string, data?: array<string, mixed>}
     */
    public function updateBlog(
        int $postId,
        int $authorId,
        ?string $title,
        ?string $excerpt,
        ?string $content,
        ?BlogCategory $category,
        ?array $tags,
        ?array $chainIds,
        ?array $disclosure,
        ?int $coverImageId,
        ?string $status,
        ?array $sources = null
    ): array {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        if ($postId <= 0) {
            return ['error' => 'bcc_not_found', 'message' => 'Blog post not found.'];
        }

        // ── Load + identify ──────────────────────────────────────────
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return ['error' => 'bcc_not_found', 'message' => 'Blog post not found.'];
        }

        // Defense-in-depth: confirm the post is actually a blog (not a
        // status / review / pull-batch). post_type is PeepSo's canonical
        // `peepso-post` (status posts AND blog posts share this CPT —
        // the discriminator is the `_bcc_activity_module='blog'` meta
        // marker, NOT the post_type). The original 2026-05-15 contract
        // used a fabricated `peepso-activity-status` slug that fails WP's
        // varchar(20) post_type column — see the related fix in
        // `PeepSoStatusWriter::createSelfBlogPost`.
        if ($post->post_type !== 'peepso-post') {
            return ['error' => 'bcc_not_found', 'message' => 'Blog post not found.'];
        }
        $module = get_post_meta($postId, '_bcc_activity_module', true);
        if (!is_string($module) || $module !== 'blog') {
            return ['error' => 'bcc_not_found', 'message' => 'Blog post not found.'];
        }

        // ── Ownership ────────────────────────────────────────────────
        if ((int) $post->post_author !== $authorId) {
            return ['error' => 'bcc_forbidden', 'message' => 'You can only edit your own blog posts.'];
        }

        // ── Per-field validation (mirrors createBlog) ───────────────
        $excerptClean = null;
        if ($excerpt !== null) {
            $excerptClean = trim($excerpt);
            if ($excerptClean === '') {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => 'Blog excerpt is required.',
                ];
            }
            $excerptLen = mb_strlen($excerptClean);
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
        }

        $contentClean = null;
        if ($content !== null) {
            $contentClean = trim($content);
            if ($contentClean === '') {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => 'Blog body is required.',
                ];
            }
            $contentLen = mb_strlen($contentClean);
            if ($contentLen > self::BLOG_FULL_TEXT_MAX_LENGTH) {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => sprintf(
                        'Blog body caps at %d characters.',
                        self::BLOG_FULL_TEXT_MAX_LENGTH
                    ),
                ];
            }
        }

        // Title — three-state: null (unchanged), '' (clear), non-empty
        // (replace). sanitize_text_field strips tags + newlines.
        $titleAction    = 'unchanged'; // 'unchanged' | 'clear' | 'set'
        $titleSanitized = '';
        if ($title !== null) {
            $trimmed = trim($title);
            if ($trimmed === '') {
                $titleAction = 'clear';
            } else {
                $titleSanitized = sanitize_text_field($title);
                if (mb_strlen($titleSanitized) > self::BLOG_TITLE_MAX_LENGTH) {
                    return [
                        'error'   => 'bcc_invalid_request',
                        'message' => sprintf(
                            'Blog title caps at %d characters.',
                            self::BLOG_TITLE_MAX_LENGTH
                        ),
                    ];
                }
                $titleAction = 'set';
            }
        }

        // Tags — null=unchanged, []=clear, non-empty=replace.
        $tagsClean = null;
        if ($tags !== null) {
            $normalized = self::normalizeBlogTags($tags);
            if ($normalized === null) {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => 'Tags may only contain lowercase letters, digits, and dashes.',
                ];
            }
            if (count($normalized) > self::BLOG_TAGS_MAX) {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => sprintf('Blogs cap at %d tags.', self::BLOG_TAGS_MAX),
                    'data'    => ['max' => self::BLOG_TAGS_MAX],
                ];
            }
            $tagsClean = $normalized;
        }

        // Chain ids — null=unchanged, []=clear, non-empty=replace +
        // each FK-validated against the chain registry.
        $chainIdsClean = null;
        if ($chainIds !== null) {
            $normalized = self::normalizeChainIds($chainIds);
            if (count($normalized) > self::BLOG_CHAIN_TAGS_MAX) {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => sprintf('Blogs cap at %d chain tags.', self::BLOG_CHAIN_TAGS_MAX),
                    'data'    => ['max' => self::BLOG_CHAIN_TAGS_MAX],
                ];
            }
            foreach ($normalized as $cid) {
                if (\BCC\Core\ServiceLocator::resolveChainRead()->getById($cid) === null) {
                    return [
                        'error'   => 'bcc_invalid_request',
                        'message' => 'Unknown chain tag.',
                        'data'    => ['chain_id' => $cid],
                    ];
                }
            }
            $chainIdsClean = $normalized;
        }

        // Disclosure — three-state field tunneled through `?array`:
        //   - null            → clear
        //   - normal array    → replace
        //   - {__noop__: true} → leave unchanged (internal sentinel)
        //
        // PHP's nullable type can only express two states (null vs.
        // value), so the REST handler encodes "leave unchanged" as a
        // tiny internal-only sentinel array. The alternative — adding
        // a separate `bool $disclosureChanged` flag — would be sludge
        // for callers; the alternative-alternative — splitting
        // updateBlog into a dozen single-field setters — wrecks the
        // partial-update API. The sentinel is only readable from the
        // REST handler, never the wire (clients omit the field).
        $disclosureAction = 'unchanged'; // 'unchanged' | 'clear' | 'replace'
        $disclosureClean  = null;
        if (is_array($disclosure) && isset($disclosure['__noop__']) && $disclosure['__noop__'] === true) {
            // "Leave unchanged" — REST handler signaled an omitted
            // field. Skip both clear + replace branches.
            $disclosureAction = 'unchanged';
        } elseif ($disclosure === null) {
            $disclosureAction = 'clear';
        } else {
            $normalized = self::normalizeDisclosure($disclosure);
            if (!is_array($normalized)) {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => (string) $normalized,
                ];
            }
            $disclosureClean  = $normalized;
            $disclosureAction = 'replace';
        }

        // Sources — null=unchanged, []=clear, non-empty=replace.
        // Same `?array` tunnel as tags/chainIds; no sentinel needed
        // because an empty array IS a meaningful state here ("clear").
        $sourcesAction = 'unchanged'; // 'unchanged' | 'clear' | 'replace'
        $sourcesClean  = [];
        if ($sources !== null) {
            $normalized = self::normalizeSources($sources);
            if (!is_array($normalized)) {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => (string) $normalized,
                ];
            }
            $sourcesClean  = $normalized;
            $sourcesAction = $sourcesClean === [] ? 'clear' : 'replace';
        }

        // Cover image — null=unchanged, 0=un-pin, positive=replace.
        $coverAction = 'unchanged'; // 'unchanged' | 'clear' | 'set'
        if ($coverImageId !== null) {
            if ($coverImageId === 0) {
                $coverAction = 'clear';
            } elseif ($coverImageId > 0) {
                $attachment = get_post($coverImageId);
                if (!$attachment instanceof \WP_Post
                    || $attachment->post_type !== 'attachment'
                    || (int) $attachment->post_author !== $authorId
                ) {
                    return [
                        'error'   => 'bcc_invalid_request',
                        'message' => 'Cover image not found or not owned by you.',
                    ];
                }
                $coverAction = 'set';
            } else {
                // Negative id is a contract violation.
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => 'Cover image id must be non-negative.',
                ];
            }
        }

        // Status — null=unchanged, must be 'draft' or 'publish' otherwise.
        $statusClean = null;
        if ($status !== null) {
            if ($status !== 'draft' && $status !== 'publish') {
                return [
                    'error'   => 'bcc_invalid_request',
                    'message' => 'Status must be "draft" or "publish".',
                ];
            }
            $statusClean = $status;
        }

        // ── Revision FIRST (captures pre-change state) ───────────────
        //
        // wp_save_post_revision absorbs duplicate calls within WP's
        // built-in revision threshold (default: skips if no content
        // change since the last revision). Retries on the same post
        // do NOT double-create revisions — WP handles idempotency.
        if (function_exists('wp_save_post_revision')) {
            wp_save_post_revision($postId);
        }

        // ── wp_update_post (title / excerpt / content / status) ─────
        //
        // Build the update array only with fields the caller asked to
        // change. wp_update_post merges with the existing post — fields
        // we don't include stay as-is. The transition_post_status hook
        // fires AUTOMATICALLY when post_status differs from the
        // stored value; BlogStatusTransitionHandler handles the
        // draft→publish (fires bcc_blog_post_created) and
        // publish→draft (removes peepso_activities row) side effects.
        $postUpdate = ['ID' => $postId];
        if ($excerptClean !== null) {
            $postUpdate['post_excerpt'] = wp_kses_post($excerptClean);
        }
        if ($contentClean !== null) {
            $postUpdate['post_content'] = wp_kses_post($contentClean);
        }
        if ($titleAction === 'clear') {
            $postUpdate['post_title'] = '';
        } elseif ($titleAction === 'set') {
            $postUpdate['post_title'] = $titleSanitized;
        }
        if ($statusClean !== null) {
            $postUpdate['post_status'] = $statusClean;
        }

        // Only call wp_update_post if there's something on the post
        // itself to change — sidecar / chain-tag changes don't need
        // to bump the wp_post row. (Skipping the call also avoids a
        // no-op revision insert when only sidecar meta changes.)
        if (count($postUpdate) > 1) {
            $result = wp_update_post($postUpdate, true);
            if (is_wp_error($result) || (int) $result <= 0) {
                $errMsg = is_wp_error($result) ? $result->get_error_message() : 'wp_update_post returned non-positive id';
                Logger::warning('[PostsService] updateBlog wp_update_post failed', [
                    'user_id' => $authorId,
                    'post_id' => $postId,
                    'error'   => $errMsg,
                ]);
                return [
                    'error'   => 'bcc_unavailable',
                    'message' => 'Could not save your changes. Try again.',
                ];
            }
        }

        // ── Sidecar meta writes ──────────────────────────────────────
        if ($category !== null) {
            update_post_meta($postId, '_bcc_blog_category', $category->value);
        }
        if ($tagsClean !== null) {
            if ($tagsClean === []) {
                delete_post_meta($postId, '_bcc_blog_tags');
            } else {
                $tagsJson = wp_json_encode(array_values($tagsClean));
                if (is_string($tagsJson)) {
                    update_post_meta($postId, '_bcc_blog_tags', $tagsJson);
                }
            }
        }
        if ($disclosureAction === 'clear') {
            delete_post_meta($postId, '_bcc_blog_disclosure');
        } elseif ($disclosureAction === 'replace' && $disclosureClean !== null) {
            $discJson = wp_json_encode($disclosureClean);
            if (is_string($discJson)) {
                update_post_meta($postId, '_bcc_blog_disclosure', $discJson);
            }
        }
        if ($sourcesAction === 'clear') {
            delete_post_meta($postId, '_bcc_blog_sources');
        } elseif ($sourcesAction === 'replace') {
            $srcJson = wp_json_encode(array_values($sourcesClean));
            if (is_string($srcJson)) {
                update_post_meta($postId, '_bcc_blog_sources', $srcJson);
            }
        }

        // ── Cover image pin / un-pin ─────────────────────────────────
        if ($coverAction === 'set' && $coverImageId !== null && $coverImageId > 0) {
            set_post_thumbnail($postId, $coverImageId);
        } elseif ($coverAction === 'clear') {
            delete_post_thumbnail($postId);
        }

        // ── Chain-tag join replace (cache invalidation inside) ──────
        if ($chainIdsClean !== null) {
            $this->chainTagRepo()->replace($postId, $chainIdsClean);
        }

        // Resolve the post-update status. If status wasn't changed,
        // report whatever post_status the row currently carries.
        $finalStatus = $statusClean ?? (string) $post->post_status;

        return [
            'ok'      => true,
            'post_id' => $postId,
            'status'  => $finalStatus,
        ];
    }

    /**
     * Normalize an inbound tags array to lowercase alnum-dash form.
     *
     * Returns the normalized list on success, OR `null` when any tag
     * contains characters outside [a-z0-9-]. Caller surfaces `null`
     * as a `bcc_invalid_request`.
     *
     * Length cap per tag is enforced here; total-count cap is
     * enforced by the caller (after this method returns) so the cap
     * applies to the deduped set rather than the raw input.
     *
     * @param array<int|string, mixed> $tags
     * @return list<string>|null
     */
    private static function normalizeBlogTags(array $tags): ?array
    {
        /** @var list<string> $out */
        $out = [];
        foreach ($tags as $raw) {
            if (!is_string($raw)) {
                // Non-string entries are silently skipped — a
                // misbehaving frontend should not be able to crash
                // the validator. The shape mismatch is logged by
                // PHP's strict type checks upstream.
                continue;
            }
            $tag = strtolower(trim($raw));
            if ($tag === '') {
                continue;
            }
            // Reject tags with non-alnum-dash chars. Composer's UI
            // normalizes on input, but server-side gate stops a raw
            // API caller from sneaking in #-hashtags / spaces / unicode.
            if (preg_match('/^[a-z0-9-]+$/', $tag) !== 1) {
                return null;
            }
            if (mb_strlen($tag) > self::BLOG_TAG_LEN_MAX) {
                return null;
            }
            if (!in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }
        return $out;
    }

    /**
     * Normalize an inbound chain-ids array — dedupe + cast.
     *
     * Validation against the chain registry happens at the call site
     * (one FK lookup per id); this just shapes the list.
     *
     * @param array<int|string, mixed> $chainIds
     * @return list<int>
     */
    private static function normalizeChainIds(array $chainIds): array
    {
        /** @var list<int> $out */
        $out = [];
        foreach ($chainIds as $raw) {
            $cid = (int) $raw;
            if ($cid > 0 && !in_array($cid, $out, true)) {
                $out[] = $cid;
            }
        }
        return $out;
    }

    /**
     * Validate + normalize a disclosure block.
     *
     * Shape: `{tickers: string[], note: string}` — both optional but
     * one of the two must be present (empty disclosure is meaningless;
     * caller passes `null` instead).
     *
     * Returns the normalized block on success OR a human-readable
     * error string the caller surfaces in the envelope.
     *
     * @param array<string, mixed> $disclosure
     * @return array{tickers: list<string>, note: string}|string
     */
    private static function normalizeDisclosure(array $disclosure): array|string
    {
        $tickersRaw = $disclosure['tickers'] ?? [];
        $noteRaw    = $disclosure['note']    ?? '';

        if (!is_array($tickersRaw)) {
            return 'Disclosure tickers must be an array.';
        }
        if (!is_string($noteRaw)) {
            return 'Disclosure note must be a string.';
        }

        /** @var list<string> $tickers */
        $tickers = [];
        foreach ($tickersRaw as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $t = strtoupper(trim($raw));
            if ($t === '') {
                continue;
            }
            // Alnum only — DEX pair separators (slash, dot) would
            // double the validation surface for marginal benefit.
            // Tickers carrying suffixes ("WBTC.b") get the suffix
            // stripped at the composer; here we enforce the canonical
            // shape.
            if (preg_match('/^[A-Z0-9]+$/', $t) !== 1) {
                return 'Tickers may only contain letters and digits.';
            }
            if (mb_strlen($t) > self::BLOG_DISCLOSURE_TICKER_LEN_MAX) {
                return sprintf(
                    'Tickers cap at %d characters.',
                    self::BLOG_DISCLOSURE_TICKER_LEN_MAX
                );
            }
            if (!in_array($t, $tickers, true)) {
                $tickers[] = $t;
            }
        }
        if (count($tickers) > self::BLOG_DISCLOSURE_TICKERS_MAX) {
            return sprintf(
                'Disclosure caps at %d tickers.',
                self::BLOG_DISCLOSURE_TICKERS_MAX
            );
        }

        $note = trim($noteRaw);
        if (mb_strlen($note) > self::BLOG_DISCLOSURE_NOTE_MAX) {
            return sprintf(
                'Disclosure note caps at %d characters.',
                self::BLOG_DISCLOSURE_NOTE_MAX
            );
        }

        // Empty disclosure (no tickers AND no note) is meaningless —
        // caller should have passed null instead. Surface as a clear
        // contract error rather than silently writing an empty
        // post_meta blob.
        if ($tickers === [] && $note === '') {
            return 'Disclosure block must include at least one ticker or a note.';
        }

        return ['tickers' => $tickers, 'note' => $note];
    }

    /**
     * Normalize the §D6 sources list into a clean `list<string>`.
     *
     * Returns the cleaned array on success or a string error message
     * on validation failure (caller wraps with bcc_invalid_request).
     *
     * Rules:
     *  - Each entry is a free string (URL or short citation).
     *  - Trim, then drop empties.
     *  - Cap each at BLOG_SOURCE_LEN_MAX (280 chars; long URL + trailing
     *    label).
     *  - Cap total count at BLOG_SOURCES_MAX (20).
     *  - Dedupe by exact match (case-sensitive — URLs ARE case-sensitive
     *    in the path component).
     *
     * Empty input list = "no sources" = valid (we just write no
     * post_meta). Distinct from the disclosure block's "empty struct
     * is invalid" rule because sources don't carry a clear-the-existing
     * semantic — the create path defaults to no sources and the update
     * path treats an explicit empty list as "clear" (handled at the
     * updateBlog call site).
     *
     * @param array<int|string, mixed> $sources
     * @return list<string>|string
     */
    private static function normalizeSources(array $sources): array|string
    {
        if ($sources === []) {
            return [];
        }
        /** @var list<string> $clean */
        $clean = [];
        foreach ($sources as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $s = trim($raw);
            if ($s === '') {
                continue;
            }
            if (mb_strlen($s) > self::BLOG_SOURCE_LEN_MAX) {
                return sprintf(
                    'Each source caps at %d characters.',
                    self::BLOG_SOURCE_LEN_MAX
                );
            }
            if (!in_array($s, $clean, true)) {
                $clean[] = $s;
            }
        }
        if (count($clean) > self::BLOG_SOURCES_MAX) {
            return sprintf(
                'Sources cap at %d entries.',
                self::BLOG_SOURCES_MAX
            );
        }
        return $clean;
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
    public function createPhotoPost(
        int $authorId,
        array $file,
        string $caption,
        int $groupId = 0,
        string $visibility = self::VISIBILITY_DEFAULT
    ): array {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        // Defense in depth — clamp to the allowed set (see createStatus).
        $visibility = self::normalizeVisibility($visibility);

        // Group-wall validation runs BEFORE caption / throttle / file
        // gates — same ordering as createStatus.
        if ($groupId > 0) {
            $gateError = self::gateGroupPost($authorId, $groupId, $visibility);
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

        $result = PeepSoPhotoWriter::createSelfPhotoPost($authorId, $file, $captionTrimmed, $groupId, $visibility);
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
    public function createGifPost(
        int $authorId,
        string $url,
        string $caption,
        int $groupId = 0,
        string $visibility = self::VISIBILITY_DEFAULT
    ): array {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        // Defense in depth — clamp to the allowed set (see createStatus).
        $visibility = self::normalizeVisibility($visibility);

        // Group-wall validation runs BEFORE caption / throttle / URL
        // gates — same ordering as createStatus / createPhotoPost.
        if ($groupId > 0) {
            $gateError = self::gateGroupPost($authorId, $groupId, $visibility);
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

        $result = PeepSoGifWriter::createSelfGifPost($authorId, $url, $captionTrimmed, $groupId, $visibility);
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
     * `$visibility` is the already-normalized per-post visibility. When it
     * is `public_all` the author must additionally be authorized to
     * syndicate to the global feed — a rank-and-file member of a
     * closed/secret group is REJECTED (not silently down-clamped) so the
     * boundary is unambiguous and a direct REST call cannot bypass it.
     * See GroupsService::canUsePublicAll / {@see PublicAllPolicy}.
     *
     * @return array{error: string, message: string}|null
     */
    private static function gateGroupPost(int $authorId, int $groupId, string $visibility): ?array
    {
        $groupsService = Plugin::instance()->groupsService();

        $access = $groupsService->resolveGroupAccess($authorId, $groupId);
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

        // Active membership admits `member_readonly` (a muted member may READ
        // the group), but read-only means "read but not contribute" — gate
        // authoring on the posting-capable set. (Free: the membership status
        // was just memoized by resolveGroupAccess above.)
        if (!$groupsService->viewerCanAuthorInGroup($authorId, $groupId)) {
            $message = (string) apply_filters(
                'bcc_group_post_readonly',
                'Your access to this group is read-only.',
                $authorId,
                $groupId
            );
            return [
                'error'   => 'bcc_permission_denied',
                'message' => $message,
            ];
        }

        // public_all syndicates to everyone — gate WHO may choose it.
        if ($visibility === self::VISIBILITY_PUBLIC_ALL
            && !$groupsService->canUsePublicAll($authorId, $groupId)
        ) {
            $message = (string) apply_filters(
                'bcc_group_public_all_denied',
                'You do not have permission to post this publicly in this group.',
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
