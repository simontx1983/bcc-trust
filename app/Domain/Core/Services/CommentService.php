<?php
/**
 * CommentService — orchestrates list / create / delete for the
 * v1.5 hybrid PeepSo-proxy comments slice.
 *
 * Responsibilities (in scope):
 *   - Resolve feed_id → parent activity row.
 *   - Apply the holder-groups visibility gate (per-parent-post).
 *   - Enforce the per-user create-comment throttle.
 *   - Delegate writes to bcc-core's PeepSoCommentWriter.
 *   - Hydrate listed comments into the contract view-model.
 *   - Emit §A3 events (`bcc_comment_created`, `bcc_comment_deleted`)
 *     for downstream subscribers (notification dispatcher, future
 *     analytics).
 *
 * Out of scope (deferred):
 *   - Threading. PeepSo storage is flat at the (act_comment_object_id)
 *     index level even when its UI shows replies-to-replies — replies
 *     point at the root post via `act_comment_object_id`, with thread
 *     context conveyed by @-mentions in body. Surfacing that context
 *     in the BCC UI is V1.5+ work.
 *   - Edit. Delete + recreate is the V1 model. PeepSo's editcomment
 *     path exists but isn't wired through to BCC.
 *   - Per-comment reactions. Comments stay un-reactable in V1; the
 *     parent post's reaction rail is the only reaction surface.
 *
 * @package BCC\Trust\Core\Services
 * @since v1.5 (2026-05, hybrid PeepSo-proxy comments)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Core\PeepSo\PeepSoCommentWriter;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Core\Security\Throttle;
use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\StokeRepository;
use BCC\Trust\Core\Services\AuthorBadgeResolver;
use BCC\Trust\Core\Services\Mentions\MentionExtractor;
use BCC\Trust\Core\Services\Mentions\MentionOverlayService;
use BCC\Trust\Core\Services\Mentions\MentionPolicy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type CommentRow     from CommentRepository
 * @phpstan-import-type CommentMetaRow from CommentRepository
 */
final class CommentService
{
    /** §D2-style cap mirroring status posts; PeepSo's own cap is 4000
     *  via site_status_limit but we tighten in BCC for the warmer
     *  social grammar — long essays belong in blog posts. */
    public const COMMENT_MAX_LENGTH = 2000;

    /** §3.3.12 — same per-post mention cap as PostsService. */
    public const MENTIONS_PER_COMMENT_MAX = 10;

    /**
     * Group-membership statuses that allow a viewer to read content
     * (posts and their comments) on a gated post. Mirrors the
     * semantics PeepSo's own activity stream applies (see
     * PeepSoGroupRepository::getMembershipStatus for the full enum
     * list).
     *
     * Public so cross-class consumers inside bcc-trust (notably
     * FeedRankingService::hydrateCommentCounts, which gates the
     * comment-count batch on viewer membership as defense-in-depth)
     * share one source of truth for the allowed-status set.
     *
     * @var list<string>
     */
    public const READ_ALLOWED_STATUSES = [
        'member',
        'member_owner',
        'member_manager',
        'member_moderator',
        'member_readonly',
    ];

    /**
     * Subset of READ_ALLOWED_STATUSES that can also write comments.
     * `member_readonly` is excluded — read but not contribute, per
     * PeepSo's semantics for muted/quiet members.
     *
     * @var list<string>
     */
    private const WRITE_ALLOWED_STATUSES = [
        'member',
        'member_owner',
        'member_manager',
        'member_moderator',
    ];

    public function __construct(
        private readonly CommentRepository $commentRepo,
        private readonly MentionOverlayService $mentionOverlay,
        private readonly AuthorBadgeResolver $authorBadgeResolver,
        private readonly AttestationService $attestationService,
        private readonly StokeRepository $stokeRepo
    ) {
    }

    /**
     * List comments on the given feed item with cursor pagination.
     *
     * Auth posture: anonymous viewers can list comments on
     * non-gated posts. Gated posts require the viewer to be a
     * member of the parent post's group; non-members get
     * `bcc_forbidden` so the drawer can surface a "Join group to
     * read comments" hint.
     *
     * @return array{
     *   ok: true,
     *   items: list<array<string, mixed>>,
     *   next_cursor: string|null
     * }|array{error: string, message: string}
     */
    public function listByFeedId(
        string $feedId,
        int $viewerId,
        string $sort,
        ?string $cursor,
        int $limit
    ): array {
        $sort  = self::normalizeSort($sort);
        $actId = self::parseFeedId($feedId);
        if ($actId === null) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid feed_id.'];
        }

        $parent = PeepSoActivityRepository::getById($actId);
        if ($parent === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Post not found.'];
        }
        $parentPostId = (int) $parent->act_external_id;

        $gate = $this->gateForParent($viewerId, $parentPostId);
        if (!$gate['can_read']) {
            return ['error' => 'bcc_forbidden', 'message' => 'You do not have access to this discussion.'];
        }

        [$cursorKey, $cursorActId] = self::decodeCursor($cursor ?? '', $sort);

        $rows = $this->commentRepo->listByParentPostId(
            $parentPostId,
            $sort,
            $cursorKey,
            $cursorActId,
            $limit
        );

        // Batch-resolve author badge fields (card_tier, tier_label,
        // rank_label, reputation_tier) for every author on the page in
        // ONE query — avoids N+1 inside shapeCommentRow when a page
        // has up to PER_PAGE_MAX=50 comments. The map is empty when
        // there are no rows; shapeCommentRow falls through with absent
        // fields per its contract.
        $authorIds = [];
        foreach ($rows as $row) {
            $aid = (int) $row->author_id;
            if ($aid > 0) {
                $authorIds[$aid] = true;
            }
        }
        $badgeMap = $authorIds === []
            ? []
            : $this->authorBadgeResolver->resolveForUsers(array_keys($authorIds));

        // Batch the viewer's per-author vouch state + can-vouch permission for
        // the byline Vouch toggle on commenter names — ONE bounded IN-list read
        // across the page's distinct authors (anon → empty map, fields omitted).
        $vouchMap = $authorIds === []
            ? []
            : $this->attestationService->getViewerVouchStateForAuthors($viewerId, array_keys($authorIds));

        // Per-comment stoke state. The public count now rides inline on
        // each row (`stoke_total`, computed in the list query so top/
        // relevant can sort on it), so only the viewer's own stoked set
        // needs a separate bounded IN-list read (authed only).
        $commentActIds = [];
        foreach ($rows as $row) {
            $cid = (int) $row->act_id;
            if ($cid > 0) {
                $commentActIds[] = $cid;
            }
        }
        $viewerStoked = ($commentActIds === [] || $viewerId <= 0)
            ? []
            : $this->stokeRepo->viewerStokedActIds($viewerId, $commentActIds);

        // Batch-load attached media (§3.5) for the page's comment wp_posts
        // in ONE IN-list read, keyed by comment_post_id (= act_external_id).
        // Absent from the map → the row carries no media.
        $commentPostIds = [];
        foreach ($rows as $row) {
            $pid = (int) $row->comment_post_id;
            if ($pid > 0) {
                $commentPostIds[] = $pid;
            }
        }
        $mediaMap = $commentPostIds === []
            ? []
            : $this->commentRepo->mediaByCommentPostIds($commentPostIds);

        $items   = [];
        $lastRow = null;
        foreach ($rows as $row) {
            $aid   = (int) $row->author_id;
            $cid   = (int) $row->act_id;
            $badge = $badgeMap[$aid] ?? null;
            $vouch = $vouchMap[$aid] ?? null;
            $stoke = [
                'stoke_count'       => (int) $row->stoke_total,
                'viewer_has_stoked' => $viewerStoked[$cid] ?? false,
            ];
            $media = $mediaMap[(int) $row->comment_post_id] ?? null;
            $items[] = $this->shapeCommentRow($row, $viewerId, $feedId, $badge, $vouch, $stoke, $media);
            $lastRow = $row;
        }

        $nextCursor = null;
        if (count($rows) === $limit && $lastRow !== null) {
            $nextCursor = self::encodeCursor($sort, $lastRow);
        }

        return [
            'ok'          => true,
            'items'       => $items,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Create a comment on the given feed item.
     *
     * Optional media (§3.5 `media` block) rides as ONE attachment — a
     * photo XOR a gif, never both. `$attachmentId` is an uploaded WP
     * attachment (via the shared `POST /blog/cover-image` route) the
     * author must own; `$gifUrl` is a remote Giphy CDN URL. Photo wins
     * if both are somehow supplied. The media is layered as a bcc
     * post-meta sidecar on the comment's own wp_post (single-graph rule;
     * PeepSo's write path is untouched — it's text-native).
     *
     * @return array{
     *   ok: true,
     *   comment: array<string, mixed>
     * }|array{error: string, message: string, data?: array<string, mixed>}
     */
    public function createComment(
        string $feedId,
        int $authorId,
        string $content,
        ?int $attachmentId = null,
        ?string $gifUrl = null
    ): array {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            return ['error' => 'bcc_invalid_request', 'message' => 'Comment cannot be empty.'];
        }
        if (mb_strlen($trimmed) > self::COMMENT_MAX_LENGTH) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf('Comments cap at %d characters.', self::COMMENT_MAX_LENGTH),
            ];
        }

        // §3.3.12 — same write-time mention validation as PostsService.
        // Strict reject hidden/blocked/banned/private targets; cap
        // mention fanout at MENTIONS_PER_COMMENT_MAX before PeepSo's
        // Tags::after_save_comment dispatcher fires.
        $mentionError = self::validateMentions($authorId, $trimmed);
        if ($mentionError !== null) {
            return $mentionError;
        }

        $actId = self::parseFeedId($feedId);
        if ($actId === null) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid feed_id.'];
        }

        $parent = PeepSoActivityRepository::getById($actId);
        if ($parent === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Post not found.'];
        }
        $parentPostId   = (int) $parent->act_external_id;
        // PeepSo stores act_module_id as SMALLINT. PeepSo's
        // get_activity_data($post_id, $module_id) looks up the parent
        // by the (post_id, module_id) pair; passing the wrong module
        // (e.g. defaulting to status=1 against a photo post=4)
        // returns null and add_comment refuses with FALSE.
        $parentModuleId = (int) $parent->act_module_id;

        $gate = $this->gateForParent($authorId, $parentPostId);
        if (!$gate['can_create']) {
            return ['error' => 'bcc_forbidden', 'message' => 'You do not have permission to comment here.'];
        }

        // Burst seatbelt — same rationale as PostsService::createStatus.
        // Tight enough to clip accidental flood / double-submit / scripted
        // burst; loose enough that humans never hit it. NOT a primary
        // defense — fires-in-logs is the signal to layer in §K1 abuse
        // gates. Constants live in includes/config/limits.php.
        $burstKey = "comment:{$authorId}:burst";
        if (!Throttle::allow(
            $burstKey,
            BCC_TRUST_RATE_LIMIT_COMMENT,
            BCC_TRUST_RATE_WINDOW_COMMENT
        )) {
            Logger::info('[CommentService] comment burst seatbelt fired', [
                'user_id' => $authorId,
                'limit'   => BCC_TRUST_RATE_LIMIT_COMMENT,
                'window'  => BCC_TRUST_RATE_WINDOW_COMMENT,
            ]);
            return ['error' => 'bcc_rate_limited', 'message' => 'Too fast. Wait a moment before commenting again.'];
        }

        // Resolve + validate the optional attachment BEFORE the PeepSo
        // write so a bad attachment / non-Giphy URL is rejected without
        // leaving an orphan comment behind. `$media` is the stored blob
        // (null when no attachment was sent).
        $mediaResult = $this->resolveMedia($authorId, $attachmentId, $gifUrl);
        if (isset($mediaResult['error'])) {
            return $mediaResult;
        }
        $media = $mediaResult['media'];

        $newCommentPostId = PeepSoCommentWriter::addComment($parentPostId, $authorId, $trimmed, $parentModuleId);
        if ($newCommentPostId <= 0) {
            // PeepSo refused the write — could be:
            //   - parent's `peepso_disable_comments` meta is set
            //   - parent owner blocked the commenter
            //   - content stripped to empty by PeepSo's sanitizer
            // All three surface to the user as "can't comment here";
            // the distinction is observability (Logger), not UX.
            Logger::info('[CommentService] PeepSo refused comment write', [
                'parent_act_id'  => $actId,
                'parent_post_id' => $parentPostId,
                'author_id'      => $authorId,
            ]);
            return ['error' => 'bcc_unavailable', 'message' => 'Could not post comment. Try again.'];
        }

        // Resolve the freshly-written comment back to its canonical
        // CommentRow shape so the response matches the §3.5 contract
        // (id = "comment_<act_id>", parent feed_id echoed). PeepSo's
        // add_comment returned only the wp_post.ID — we look the
        // activity row up by act_external_id to get its act_id.
        $newRow = $this->commentRepo->getCommentRowByPostId($newCommentPostId);
        if ($newRow === null) {
            // Defensive: row should exist immediately — add_comment
            // wrote both wp_post and peepso_activities synchronously.
            // If it's somehow missing the response still must be
            // contract-shaped; surface bcc_unavailable so the client
            // refetches the list rather than caching a malformed row.
            Logger::error('[CommentService] new comment row not found post-write', [
                'comment_post_id' => $newCommentPostId,
                'author_id'       => $authorId,
            ]);
            return ['error' => 'bcc_unavailable', 'message' => 'Comment was saved but could not be confirmed. Refresh to see it.'];
        }

        // Stamp the attachment sidecar on the comment's own wp_post
        // (single-graph rule). Written after the PeepSo write returns the
        // comment's wp_post.ID; the batch read in listByFeedId picks it up
        // on subsequent loads, and we echo it on this create response.
        if ($media !== null) {
            update_post_meta(
                $newCommentPostId,
                CommentRepository::MEDIA_META_KEY,
                wp_json_encode($media)
            );
        }

        // Resolve the new author's badge fields directly — single-row
        // path, so the resolver's singleton helper is the right shape
        // (vs. an array detour that batchers don't need).
        $badge  = $this->authorBadgeResolver->resolveForUser($authorId);
        // A just-created comment has no stokes yet — emit the zero/false
        // baseline so the response carries the same shape as list rows.
        $shaped = $this->shapeCommentRow(
            $newRow,
            $authorId,
            $feedId,
            $badge,
            null,
            ['stoke_count' => 0, 'viewer_has_stoked' => false],
            $media
        );

        // §A3 event — single emission per state change. Subscribers
        // (NotificationDispatcher, future analytics) attach independently.
        $newActId = (int) $newRow->act_id;
        do_action('bcc_comment_created', $authorId, $actId, $newActId, $newCommentPostId);

        return [
            'ok'      => true,
            'comment' => $shaped,
        ];
    }

    /**
     * Delete the viewer's own comment.
     *
     * Authorization: viewer MUST be the comment's author. Cross-author
     * deletes (post owner removing a heckler, admin moderation) flow
     * through PeepSo's existing UI in V1; a future BCC moderation
     * endpoint can extend this service with a separate method.
     *
     * @return array{ok: true, comment_id: string}|array{error: string, message: string}
     */
    public function deleteComment(string $feedId, string $commentId, int $viewerId): array
    {
        if ($viewerId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }
        $parentActId = self::parseFeedId($feedId);
        if ($parentActId === null) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid feed_id.'];
        }
        $commentActId = self::parseCommentId($commentId);
        if ($commentActId === null) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Invalid comment_id.'];
        }

        $meta = $this->commentRepo->getCommentMeta($commentActId);
        if ($meta === null) {
            return ['error' => 'bcc_not_found', 'message' => 'Comment not found.'];
        }

        if ((int) $meta->author_id !== $viewerId) {
            return ['error' => 'bcc_forbidden', 'message' => 'You can only delete your own comments.'];
        }

        $ok = PeepSoCommentWriter::deleteComment((int) $meta->comment_post_id);
        if (!$ok) {
            return ['error' => 'bcc_internal_error', 'message' => 'Could not delete comment.'];
        }

        do_action('bcc_comment_deleted', $viewerId, $parentActId, $commentActId);

        return ['ok' => true, 'comment_id' => $commentId];
    }

    // ──────────────────────────────────────────────────────────────────
    // Holder-groups gate
    // ──────────────────────────────────────────────────────────────────

    /**
     * Decide whether the viewer can read / create comments on the
     * given parent post.
     *
     * Non-gated posts (no `peepso_group_id` meta or zero) → fully open
     * for read; create still goes through PeepSo's own permission
     * check inside add_comment.
     *
     * Gated posts → viewer must be a member. `member_readonly` can
     * read but not create; banned / pending / non-member cannot read.
     *
     * @return array{can_read: bool, can_create: bool}
     */
    private function gateForParent(int $viewerId, int $parentPostId): array
    {
        if ($parentPostId <= 0) {
            return ['can_read' => false, 'can_create' => false];
        }

        $groupId = (int) get_post_meta($parentPostId, 'peepso_group_id', true);
        if ($groupId <= 0) {
            // Open post — anyone can read; create still validates
            // identity (PeepSo permissions handle blocked-by-author).
            return [
                'can_read'   => true,
                'can_create' => $viewerId > 0,
            ];
        }

        // Gated — read membership status. Anonymous viewers always
        // fail the gate on gated content.
        if ($viewerId <= 0) {
            return ['can_read' => false, 'can_create' => false];
        }

        $status = PeepSoGroupRepository::getMembershipStatus($viewerId, $groupId);
        if ($status === null) {
            return ['can_read' => false, 'can_create' => false];
        }

        return [
            'can_read'   => in_array($status, self::READ_ALLOWED_STATUSES, true),
            'can_create' => in_array($status, self::WRITE_ALLOWED_STATUSES, true),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Shape helpers — Comment row → contract view-model
    // ──────────────────────────────────────────────────────────────────

    /**
     * Translate a CommentRepository row into the §3.x Comment
     * view-model. Per §A2 (no business logic on frontend), every
     * field on the response is server-resolved.
     *
     * `$badge` carries the pre-resolved rank-chip fields per author
     * (reputation_tier, card_tier, tier_label, rank_label). Sprint 1
     * cohesion: comment rows now light the same AuthorBadge chip the
     * feed surfaces show. The fields are emitted on `author` when
     * `$badge` is non-null; missing-badge rows (resolver lookup
     * miss — should not happen on a normal page since we pre-fetch
     * the whole page in one query) gracefully degrade to the
     * pre-Sprint-1 shape, and the frontend's AuthorBadge suppresses
     * the chip line when rank_label is absent.
     *
     * @phpstan-param CommentRow $row Repository row matching the CommentRow shape.
     * @param string $parentFeedId  The parent post's `feed_<act_id>` string;
     *                              echoed back per §3.5 so the frontend can
     *                              re-resolve the parent without an extra
     *                              round-trip.
     * @param array{
     *   reputation_tier: string,
     *   reputation_tier_label: string,
     *   card_tier: string|null,
     *   tier_label: string|null,
     *   rank_label: string,
     * }|null $badge  Pre-resolved badge fields (see AuthorBadgeResolver).
     * @param array{viewer_attestation: array<string, mixed>, can_vouch: array{allowed: bool, unlock_hint: string|null}}|null $vouch
     *               Pre-resolved per-author vouch state + can-vouch permission
     *               (see AttestationService::getViewerVouchStateForAuthors).
     * @param array{stoke_count: int, viewer_has_stoked: bool}|null $stoke
     *               Pre-resolved per-comment stoke state (public count +
     *               the viewer's own toggle). Null → omit both fields, so a
     *               stale frontend keeps rendering the row without a rail.
     * @param array<string, mixed>|null $media  Stored media sidecar blob
     *               (`{kind, url, ...}`) or null. Shaped to the §3.5 wire
     *               `media` block via self::shapeMedia; absent → no media.
     * @return array<string, mixed>
     */
    private function shapeCommentRow(object $row, int $viewerId, string $parentFeedId, ?array $badge = null, ?array $vouch = null, ?array $stoke = null, ?array $media = null): array
    {
        $authorId = (int) $row->author_id;
        $authorHandle = (string) $row->author_login;
        $displayName  = (string) $row->author_display_name;
        $body         = (string) $row->body;

        $author = [
            'id'           => $authorId,
            'handle'       => $authorHandle,
            'display_name' => $displayName !== '' ? $displayName : $authorHandle,
            'avatar_url'   => self::resolveAvatarUrl($authorId),
        ];
        if ($badge !== null) {
            // Server-resolved per §A2; the frontend never derives
            // card_tier from reputation_tier client-side.
            $author['reputation_tier']       = $badge['reputation_tier'];
            $author['reputation_tier_label'] = $badge['reputation_tier_label'];
            $author['card_tier']             = $badge['card_tier'];
            $author['tier_label']            = $badge['tier_label'];
            // rank_label is `''` (empty string) when RankCatalog
            // doesn't resolve a label — the FE AuthorBadge treats
            // empty as "no chip"; keep as nullable-string equivalent
            // on the wire by emitting empty string (matches the
            // existing rank_label contract on UserViewService::getSummary).
            $author['rank_label']      = $badge['rank_label'];
        }
        if ($vouch !== null) {
            // Per-author vouch state + permission behind the byline Vouch
            // toggle (vouch is author credibility, not a comment reaction).
            // Authed-only — anon omits both, keeping the shape stable.
            $author['viewer_attestation'] = $vouch['viewer_attestation'];
            $author['can_vouch']          = $vouch['can_vouch'];
        }

        $shaped = [
            'id'          => 'comment_' . (int) $row->act_id,
            'comment_id'  => 'comment_' . (int) $row->act_id,
            'feed_id'     => $parentFeedId,
            'author'      => $author,
            'body'        => $body,
            // §3.3.12 — overlay extracted from raw body. Always present
            // (`[]` when no tokens) for shape stability.
            'mentions'    => $this->mentionOverlay->buildOverlay($body),
            'posted_at'   => self::toIso8601((string) $row->posted_at),
            'permissions' => [
                // Author can always delete own; cross-author + admin
                // moderation deletes are V2.
                'can_delete' => [
                    'allowed'     => $viewerId > 0 && $viewerId === $authorId,
                    'unlock_hint' => null,
                ],
            ],
        ];

        // Stoke on comments — a plain X-"like" toggle (no heat_stage; a
        // comment isn't a velocity rail). Additive per §3.5: absent when
        // $stoke is null so a stale frontend degrades to no rail.
        if ($stoke !== null) {
            $shaped['stoke_count']       = (int) $stoke['stoke_count'];
            $shaped['viewer_has_stoked'] = (bool) $stoke['viewer_has_stoked'];
        }

        // Attached media (§3.5) — one photo XOR gif. Additive: absent when
        // the comment has no attachment (or the stored blob is malformed).
        if ($media !== null) {
            $wireMedia = self::shapeMedia($media);
            if ($wireMedia !== null) {
                $shaped['media'] = $wireMedia;
            }
        }

        return $shaped;
    }

    // ──────────────────────────────────────────────────────────────────
    // Media sidecar — validate on write, shape on read
    // ──────────────────────────────────────────────────────────────────

    /**
     * Validate + resolve the optional attachment into the storable media
     * blob. One attachment per comment: a photo (uploaded WP attachment
     * the author owns) XOR a gif (remote Giphy URL). Photo wins if both
     * are supplied. Returns `{ok, media}` (media null → no attachment) or
     * an envelope-ready `{error, message}` on a bad/foreign attachment.
     *
     * @return array{ok: true, media: array<string, mixed>|null}|array{error: string, message: string}
     */
    private function resolveMedia(int $authorId, ?int $attachmentId, ?string $gifUrl): array
    {
        if ($attachmentId !== null && $attachmentId > 0) {
            return self::resolvePhotoMedia($authorId, $attachmentId);
        }
        if ($gifUrl !== null && trim($gifUrl) !== '') {
            return self::resolveGifMedia(trim($gifUrl));
        }
        return ['ok' => true, 'media' => null];
    }

    /**
     * Resolve an uploaded attachment into a photo media blob, gated on
     * author ownership — the same posture as PostsService::createBlog's
     * cover-image check (`post_author === authorId`). The attachment is
     * created via the shared `POST /blog/cover-image` route, which stamps
     * `post_author` to the uploader.
     *
     * @return array{ok: true, media: array<string, mixed>}|array{error: string, message: string}
     */
    private static function resolvePhotoMedia(int $authorId, int $attachmentId): array
    {
        $attachment = get_post($attachmentId);
        if (!($attachment instanceof \WP_Post) || $attachment->post_type !== 'attachment') {
            return ['error' => 'bcc_invalid_request', 'message' => 'Attached image not found.'];
        }
        if ((int) $attachment->post_author !== $authorId) {
            return ['error' => 'bcc_forbidden', 'message' => 'You do not own that image.'];
        }
        // Must be an image — the upload route only mints images, but an
        // owned non-image attachment id (PDF, etc.) would otherwise be
        // accepted and render as a broken <img>. Mirror the cover-image
        // writer's image-only allowlist.
        if (!str_starts_with((string) get_post_mime_type($attachmentId), 'image/')) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Attachment must be an image.'];
        }

        $url = wp_get_attachment_url($attachmentId);
        if (!is_string($url) || $url === '') {
            return ['error' => 'bcc_invalid_request', 'message' => 'Attached image is unavailable.'];
        }

        $width  = 0;
        $height = 0;
        $meta   = wp_get_attachment_metadata($attachmentId);
        if (is_array($meta)) {
            if (isset($meta['width']) && is_int($meta['width'])) {
                $width = $meta['width'];
            }
            if (isset($meta['height']) && is_int($meta['height'])) {
                $height = $meta['height'];
            }
        }

        return [
            'ok'    => true,
            'media' => [
                'kind'          => 'photo',
                'attachment_id' => $attachmentId,
                'url'           => $url,
                'width'         => $width,
                'height'        => $height,
            ],
        ];
    }

    /**
     * Resolve a remote GIF URL into a gif media blob. Mirrors
     * bcc-core PeepSoGifWriter's posture: the URL must be an http(s) URL
     * that contains "giphy.com" (no file staging — the GIF stays on
     * Giphy's CDN). Any other host is rejected.
     *
     * @return array{ok: true, media: array<string, mixed>}|array{error: string, message: string}
     */
    private static function resolveGifMedia(string $url): array
    {
        // Host-based check (NOT a substring): a substring test would accept
        // https://evil.com/x?y=giphy.com and render it as an <img> in every
        // viewer's browser. Require the host to be giphy.com or a subdomain.
        $isUrl  = filter_var($url, FILTER_VALIDATE_URL) !== false;
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host   = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $isGiphyHost = $host === 'giphy.com' || str_ends_with($host, '.giphy.com');
        if (!$isUrl || ($scheme !== 'https' && $scheme !== 'http') || !$isGiphyHost) {
            return ['error' => 'bcc_invalid_request', 'message' => 'GIF must be a Giphy URL.'];
        }

        return [
            'ok'    => true,
            'media' => [
                'kind' => 'gif',
                'url'  => $url,
            ],
        ];
    }

    /**
     * Shape a stored media blob into the §3.5 wire `media` block. Keeps
     * only the fields the frontend renders (kind + url, plus photo
     * dimensions); the internal `attachment_id` is dropped from the wire.
     * Returns null for a malformed/unknown blob so the row degrades to
     * no-media rather than emitting a broken block.
     *
     * @param array<string, mixed> $media
     * @return array<string, mixed>|null
     */
    private static function shapeMedia(array $media): ?array
    {
        $kind = isset($media['kind']) && is_string($media['kind']) ? $media['kind'] : '';
        $url  = isset($media['url']) && is_string($media['url']) ? $media['url'] : '';
        if ($url === '' || !in_array($kind, ['photo', 'gif'], true)) {
            return null;
        }

        $out = ['kind' => $kind, 'url' => $url];
        if ($kind === 'photo') {
            $out['width']  = isset($media['width']) ? (int) $media['width'] : 0;
            $out['height'] = isset($media['height']) ? (int) $media['height'] : 0;
        }
        return $out;
    }

    /**
     * Avatar URL for a user. Reuses WP's get_avatar_url (which is
     * filterable by other plugins, so PeepSo's avatar override
     * applies automatically). Returns empty string when unresolvable.
     */
    private static function resolveAvatarUrl(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $url = get_avatar_url($userId, ['size' => 96]);
        return is_string($url) ? $url : '';
    }

    private static function toIso8601(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';
    }

    // ──────────────────────────────────────────────────────────────────
    // Cursor helpers — mirror ActivityFeedService for cross-endpoint
    // consistency. The frontend's lib/api/client cursor handling stays
    // unchanged.
    // ──────────────────────────────────────────────────────────────────

    /** Whitelist the sort param; anything unrecognized falls back to the default (relevant). */
    private static function normalizeSort(string $sort): string
    {
        $sort = strtolower(trim($sort));
        return in_array($sort, [
            CommentRepository::SORT_NEW,
            CommentRepository::SORT_TOP,
            CommentRepository::SORT_RELEVANT,
        ], true) ? $sort : CommentRepository::SORT_RELEVANT;
    }

    /**
     * Decode a cursor into [key, act_id] for the active sort. The key is
     * the sort's ordering value: an ISO-8601 timestamp for `new` (→ MySQL
     * UTC datetime), a numeric string for `top`/`relevant` (cast in the
     * repository predicate).
     *
     * @return array{0: ?string, 1: ?int}
     */
    private static function decodeCursor(string $cursor, string $sort): array
    {
        if ($cursor === '') {
            return [null, null];
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            return [null, null];
        }
        $data = json_decode($decoded, true);
        if (!is_array($data) || !isset($data['id'])) {
            return [null, null];
        }
        // Sort key rides as `k`; legacy chronological cursors used `t`,
        // still accepted so pages open across the upgrade don't break.
        $keyRaw = $data['k'] ?? $data['t'] ?? null;
        if ($keyRaw === null) {
            return [null, null];
        }
        $id = (int) $data['id'];

        if ($sort === CommentRepository::SORT_NEW) {
            $ts = strtotime((string) $keyRaw);
            if ($ts === false) {
                return [null, null];
            }
            return [gmdate('Y-m-d H:i:s', $ts), $id];
        }

        // top / relevant keys are numeric; a malformed/tampered key would
        // otherwise cast to 0 and silently return a wrong/empty page, so
        // reject it → first-page reset instead.
        if (!is_numeric($keyRaw)) {
            return [null, null];
        }
        return [(string) $keyRaw, $id];
    }

    /**
     * Encode the next-page cursor from the last row of the current page,
     * keyed by the active sort's ordering value.
     *
     * @param CommentRow $lastRow The page's final row. `relevance_score`
     *               is only SELECTed on the `relevant` sort, which is the
     *               only branch that reads it — the other sorts never
     *               touch the property, so the shared row type is safe.
     */
    private static function encodeCursor(string $sort, object $lastRow): string
    {
        switch ($sort) {
            case CommentRepository::SORT_TOP:
                $key = (string) (int) $lastRow->stoke_total;
                break;
            case CommentRepository::SORT_RELEVANT:
                // Uppercase %F is locale-independent (no comma decimals).
                // Precision MUST match CommentRepository::RELEVANCE_PRECISION
                // (the SQL ROUND) so the keyset `score = %f` tiebreak
                // compares the same value the query ordered on.
                $key = sprintf('%.6F', (float) $lastRow->relevance_score);
                break;
            case CommentRepository::SORT_NEW:
            default:
                $key = self::toIso8601((string) $lastRow->posted_at);
                break;
        }

        $payload = json_encode(['k' => $key, 'id' => (int) $lastRow->act_id], JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
    }

    // ──────────────────────────────────────────────────────────────────
    // ID parsers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Parse "feed_<n>" → numeric act_id. Mirrors ReactionsEndpoint's
     * parser; clients always round-trip the exact id the server
     * emitted. Returns null on any deviation from the expected shape.
     */
    private static function parseFeedId(string $feedId): ?int
    {
        if (!str_starts_with($feedId, 'feed_')) {
            return null;
        }
        $rest = substr($feedId, 5);
        if ($rest === '' || !ctype_digit($rest)) {
            return null;
        }
        $value = (int) $rest;
        return $value > 0 ? $value : null;
    }

    /**
     * Parse "comment_<n>" → numeric comment act_id. The pseudo-id
     * `comment_post_<n>` form (used in create-response only) is
     * intentionally NOT accepted here — once the comment is on the
     * server it gets a stable act_id that the frontend re-fetches.
     */
    private static function parseCommentId(string $commentId): ?int
    {
        if (!str_starts_with($commentId, 'comment_')) {
            return null;
        }
        $rest = substr($commentId, 8);
        // Reject the pseudo-id form `post_<n>`.
        if (!ctype_digit($rest)) {
            return null;
        }
        $value = (int) $rest;
        return $value > 0 ? $value : null;
    }

    /**
     * §3.3.12 — write-time mention validation. Mirrors
     * PostsService::validateMentions exactly; duplicated here rather
     * than reaching into PostsService because comments are an
     * independent service surface and the policy decision needs to
     * be local. (If a third write-side ever needs the same check,
     * extract a shared `MentionValidator::validate($viewerId, $body,
     * $cap): ?array` helper — for two callers, the duplication is
     * cheaper than the indirection.)
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

        if (count($candidateIds) > self::MENTIONS_PER_COMMENT_MAX) {
            return [
                'error'   => 'bcc_too_many_mentions',
                'message' => sprintf(
                    'You can mention up to %d people per comment.',
                    self::MENTIONS_PER_COMMENT_MAX
                ),
                'data' => ['max' => self::MENTIONS_PER_COMMENT_MAX],
            ];
        }

        $allowed = MentionPolicy::filterMentionable($authorId, $candidateIds);
        $allowedSet = array_fill_keys($allowed, true);
        foreach ($candidateIds as $cid) {
            if (!isset($allowedSet[$cid])) {
                return [
                    'error'   => 'bcc_invalid_mention_target',
                    'message' => 'Could not mention that user.',
                    'data' => ['user_id' => $cid],
                ];
            }
        }
        return null;
    }
}
