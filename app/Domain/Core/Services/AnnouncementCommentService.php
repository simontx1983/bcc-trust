<?php

/**
 * A5b — §4.32.7 announcement comment orchestration and serialisation.
 *
 * ── ⚠⚠ THE ONE GUARANTEE THIS CLASS EXISTS TO HOLD ─────────────────────────
 * A TOMBSTONE CARRIES FOUR KEYS AND NOTHING ELSE.
 *
 * §4.32.7: "The tombstone carries NO `body`, `author`, `avatar_url`,
 * `user_id`, actor type, reason or other private metadata."
 *
 * The row this service reads DOES contain all of that — the body survives
 * removal on purpose, because §4.32.7 also promises an administrator "the
 * private original" and a restore path. So the privacy of a tombstone is not a
 * property of the storage; it is a property of exactly one function,
 * {@see shapeRow()}, and the way it is enforced is that the tombstone branch
 * BUILDS A FRESH ARRAY rather than unsetting keys from the visible one.
 *
 * That choice is deliberate. An `unset()`-based implementation leaks every
 * field someone later adds to the visible shape and forgets to unset; a
 * whitelist cannot. The unit suite asserts the key SET, not just the absence
 * of `body`, for the same reason.
 *
 * ── RELATIONSHIP BEFORE ROLE ──────────────────────────────────────────────
 * Who may remove, and what the tombstone says, is decided by
 * {@see AnnouncementCommentPolicy::removalActorType()} — relationship first,
 * role second, so an operator removing their OWN comment is recorded as
 * `author`. This service does not re-derive that.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver as Caps;
use BCC\Trust\Core\Announcements\AnnouncementCommentPolicy as Policy;
use BCC\Trust\Core\Announcements\AnnouncementViewerStanding as Standing;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as Vis;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\AnnouncementCommentRepository;
use BCC\Trust\Core\Repositories\AnnouncementRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementCommentService
{
    public const ERR_INVALID     = 'bcc_invalid_request';
    public const ERR_FORBIDDEN   = 'bcc_forbidden';
    public const ERR_NOT_FOUND   = 'bcc_not_found';
    public const ERR_CONFLICT    = 'bcc_conflict';
    public const ERR_UNAVAILABLE = 'bcc_unavailable';

    /** §4.32.9 — the view-model id prefix for a comment. */
    public const ID_PREFIX = 'acmt_';

    private AnnouncementCommentRepository $comments;
    private AnnouncementRepository $announcements;

    public function __construct(
        ?AnnouncementCommentRepository $comments = null,
        ?AnnouncementRepository $announcements = null
    ) {
        $this->comments      = $comments ?? new AnnouncementCommentRepository();
        $this->announcements = $announcements ?? new AnnouncementRepository();
    }

    // ── reads ────────────────────────────────────────────────────────────

    /**
     * The thread. §4.32.7: flat, oldest-first, tombstones in position.
     *
     * ⚠⚠ THE ANNOUNCEMENT MUST BE PUBLICLY READABLE, and the refusal is a 404.
     * §4.32.4's read-side rule is that a not-yet-public row is `404` to a
     * non-owner — the row's EXISTENCE is what is hidden, which is a different
     * question from ownership and is why this is not a 403. A draft's comment
     * thread must not confirm that the draft exists.
     *
     * @return array<string,mixed>
     */
    public function listThread(int $announcementId, ?int $limit, ?string $cursor, int $viewerId): array
    {
        $row = $this->publicAnnouncementOrNull($announcementId);
        if ($row === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }

        try {
            $page = $this->comments->listForAnnouncement($announcementId, (int) $limit, $cursor);
        } catch (RepositoryException $e) {
            // ⚠ 503, never a 200 with an empty list: an empty discussion looks
            // entirely normal, so a read failure would be invisible.
            return self::fail(self::ERR_UNAVAILABLE, 'The discussion could not be read.', 503);
        }

        $items = [];
        foreach ($page['rows'] as $commentRow) {
            $items[] = $this->shapeRow($commentRow, $viewerId, $this->isOperatorOf($row, $viewerId));
        }

        return [
            'ok'   => true,
            'data' => [
                'items'      => $items,
                'pagination' => [
                    'next_cursor' => $page['has_more'] ? $this->comments->nextCursor($page['rows']) : null,
                    'has_more'    => $page['has_more'],
                ],
                // §4.32.14 puts `can_comment` on the thread envelope, so a
                // client knows whether to render a composer without a second
                // request. It comes from the SAME producer as every other
                // capability — this service never hand-builds one.
                'can_comment' => $this->canComment($announcementId, $row, $viewerId),
            ],
        ];
    }

    // ── create ───────────────────────────────────────────────────────────

    /**
     * §4.32.7 — signed-in members in good standing, flat, plain text.
     *
     * Refusal order is fixed and load-bearing, because two conditions can hold
     * at once and the client renders distinct copy per reason:
     *
     *   1. announcement not publicly readable   -> 404 (existence hidden)
     *   2. `parent_id` sent                     -> 400 replies_not_supported
     *   3. archived                             -> 403 announcement_archived
     *   4. comments disabled                    -> 403 comments_disabled
     *   5. viewer standing (auth/suspended/...) -> 403, reason from the resolver
     *   6. body unacceptable                    -> 400
     *
     * ⚠ The STANDING check is delegated to the capability resolver rather than
     * re-tested here, so the composer the client renders and the refusal the
     * server returns can never disagree — they are the same decision.
     *
     * @return array<string,mixed>
     */
    public function create(
        int $announcementId,
        int $viewerId,
        mixed $body,
        bool $parentIdWasSent,
        ?string $nowUtc = null
    ): array {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        $row = $this->publicAnnouncementOrNull($announcementId);
        if ($row === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }

        // ⚠ Checked BEFORE standing: a client using a threading model this
        // version does not have should be told that, not told to sign in.
        if (!Policy::isFlatSubmission($parentIdWasSent)) {
            return self::fail(
                self::ERR_INVALID,
                'Replies are not supported; comments are flat in this version.',
                400,
                Policy::REASON_REPLIES_UNSUPPORTED
            );
        }

        $decision = $this->canComment($announcementId, $row, $viewerId);
        if (($decision['allowed'] ?? false) !== true) {
            // ⚠ The reason comes from the resolver verbatim. Its vocabulary is
            // §4.32.11's closed inventory, and the three states §4.32.2 says
            // must be indistinguishable already are, because the resolver
            // reports the generic ownership reason for all of them.
            // ⚠ No `is_string()` guard: the resolver's return shape declares
            // `reason_code` as `?string`, so PHPStan narrows it and the guard
            // read as "always true". Coalesced instead, which is the same
            // behaviour without the dead check.
            $reason = $decision['reason_code'] ?? null;

            return self::fail(self::ERR_FORBIDDEN, 'Not permitted.', 403, $reason);
        }

        $clean = Policy::normalizeBody($body);
        if ($clean === null) {
            return self::fail(
                self::ERR_INVALID,
                'A comment must be plain text of 1 to ' . Policy::MAX_LEN . ' characters.',
                400
            );
        }

        try {
            $commentId = $this->comments->create($announcementId, $viewerId, $clean, $now);
        } catch (RepositoryException $e) {
            return self::fail(self::ERR_UNAVAILABLE, 'The comment could not be stored.', 503);
        }

        if ($commentId <= 0) {
            return self::fail(self::ERR_UNAVAILABLE, 'The comment could not be stored.', 503);
        }

        // ⚠ Read back, so the response describes a row that is demonstrably
        // stored rather than one the insert merely claimed. Same discipline as
        // the A3 write layer.
        try {
            $stored = $this->comments->findOne($commentId);
        } catch (RepositoryException $e) {
            $stored = null;
        }

        if ($stored === null) {
            return self::fail(self::ERR_UNAVAILABLE, 'The comment could not be confirmed.', 503);
        }

        return [
            'ok'   => true,
            'data' => $this->shapeRow($stored, $viewerId, $this->isOperatorOf($row, $viewerId)),
        ];
    }

    // ── soft removal ─────────────────────────────────────────────────────

    /**
     * §4.32.7 soft removal. The comment stays in position as a tombstone.
     *
     * ⚠⚠ `not_comment_author` IS THE PUBLIC REASON FOR EVERY REMOVAL DENIAL,
     * whoever the caller is. A stranger, a signed-out visitor and a member who
     * simply did not write that comment all receive the same reason — so the
     * response cannot be used to work out who DID write it.
     *
     * @return array<string,mixed>
     */
    public function remove(int $commentId, int $viewerId, ?string $nowUtc = null): array
    {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        try {
            $comment = $this->comments->findOne($commentId);
        } catch (RepositoryException $e) {
            return self::fail(self::ERR_UNAVAILABLE, 'The comment could not be read.', 503);
        }

        if ($comment === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }

        $announcementId = (int) ($comment->comment_post_ID ?? 0);
        $row = $this->publicAnnouncementOrNull($announcementId);
        if ($row === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }

        // ⚠ Already a tombstone: refused rather than re-stamped, because
        // moving `removed_at` would change a value a reader may already hold.
        // Same reasoning as A3's one-way archive.
        if (trim((string) ($comment->removed_at ?? '')) !== '') {
            return self::fail(self::ERR_CONFLICT, 'Already removed.', 409);
        }

        $actorType = Policy::removalActorType(
            $viewerId,
            (int) ($comment->user_id ?? 0),
            $this->isOperatorOf($row, $viewerId)
        );

        if ($actorType === null) {
            return self::fail(
                self::ERR_FORBIDDEN,
                'Not permitted.',
                403,
                Policy::REASON_NOT_COMMENT_AUTHOR
            );
        }

        try {
            $this->comments->markRemoved($commentId, $actorType, $now);
        } catch (RepositoryException $e) {
            return self::fail(self::ERR_UNAVAILABLE, 'The comment could not be removed.', 503);
        }

        // ⚠ Read back and CONFIRM the tombstone, before announcing success or
        // writing an audit row for something that may not have stored.
        try {
            $after = $this->comments->findOne($commentId);
        } catch (RepositoryException $e) {
            $after = null;
        }

        if ($after === null || trim((string) ($after->removed_at ?? '')) === '') {
            return self::fail(self::ERR_UNAVAILABLE, 'Removal could not be confirmed.', 503);
        }

        // §4.32.7 — author removal, validator removal and administrator
        // restoration each write a DISTINCT audit action.
        AuditLogger::log(
            (string) Policy::auditActionFor($actorType),
            $commentId,
            [
                'announcement_id' => $announcementId,
                'actor_type'      => $actorType,
            ],
            'announcement_comment',
            $viewerId
        );

        return ['ok' => true, 'data' => $this->shapeRow($after, $viewerId, $this->isOperatorOf($row, $viewerId))];
    }

    // ── administrator surfaces ───────────────────────────────────────────

    /**
     * §4.32.7 — the administrator's view of "the private original".
     *
     * ⚠⚠ THIS IS THE ONLY PATH IN THE FEATURE THAT MAY RETURN A REMOVED BODY.
     * It is reachable only behind the admin capability gate, and the response
     * is deliberately NOT the public shape: it is explicitly labelled, so an
     * admin tool cannot accidentally render it where a public payload is
     * expected and a reviewer can tell the two apart at a glance.
     *
     * @return array<string,mixed>
     */
    public function adminOriginal(int $commentId): array
    {
        try {
            $comment = $this->comments->findOne($commentId);
        } catch (RepositoryException $e) {
            return self::fail(self::ERR_UNAVAILABLE, 'The comment could not be read.', 503);
        }

        if ($comment === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }

        $removedAt = trim((string) ($comment->removed_at ?? ''));

        return [
            'ok'   => true,
            'data' => [
                'id'              => self::ID_PREFIX . (int) $comment->comment_ID,
                'announcement_id' => 'ann_' . (int) $comment->comment_post_ID,
                'is_removed'      => $removedAt !== '',
                'removed_at'      => $removedAt !== '' ? self::iso($removedAt) : null,
                'removed_actor'   => $removedAt !== ''
                    ? (string) ($comment->removed_actor ?? '')
                    : null,
                // ⚠ The private original. Present whether or not the row is a
                // tombstone, because that is the point of this surface.
                'original_body'   => (string) ($comment->comment_content ?? ''),
                'author_user_id'  => (int) ($comment->user_id ?? 0),
                'posted_at'       => self::iso((string) ($comment->comment_date_gmt ?? '')),
            ],
        ];
    }

    /**
     * §4.32.7 — administrator restoration. "Comment authors cannot restore;
     * restoration is administrator-only."
     *
     * @return array<string,mixed>
     */
    public function adminRestore(int $commentId, int $actorId, bool $actorIsAdministrator): array
    {
        // ⚠ Re-checked here even though the route's permission callback
        // already gated it, because the policy owns the rule and a service
        // that trusts its caller cannot be unit-tested for it.
        if (!Policy::mayRestore($actorIsAdministrator)) {
            return self::fail(self::ERR_FORBIDDEN, 'Not permitted.', 403);
        }

        try {
            $comment = $this->comments->findOne($commentId);
        } catch (RepositoryException $e) {
            return self::fail(self::ERR_UNAVAILABLE, 'The comment could not be read.', 503);
        }

        if ($comment === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }

        if (trim((string) ($comment->removed_at ?? '')) === '') {
            return self::fail(self::ERR_CONFLICT, 'Not removed.', 409);
        }

        $this->comments->clearRemoved($commentId);

        try {
            $after = $this->comments->findOne($commentId);
        } catch (RepositoryException $e) {
            $after = null;
        }

        if ($after === null || trim((string) ($after->removed_at ?? '')) !== '') {
            return self::fail(self::ERR_UNAVAILABLE, 'Restoration could not be confirmed.', 503);
        }

        AuditLogger::log(
            Policy::AUDIT_RESTORED_BY_ADMIN,
            $commentId,
            ['announcement_id' => (int) ($after->comment_post_ID ?? 0)],
            'announcement_comment',
            $actorId
        );

        return ['ok' => true, 'data' => ['id' => self::ID_PREFIX . $commentId, 'is_removed' => false]];
    }

    // ── shaping ──────────────────────────────────────────────────────────

    /**
     * ⚠⚠ THE TOMBSTONE PRIVACY BOUNDARY. See the class docblock.
     *
     * The tombstone branch builds a FRESH four-key array. It does not start
     * from the visible shape and remove things, because that would leak every
     * field a later change adds and forgets to unset.
     *
     * @return array<string,mixed>
     */
    private function shapeRow(\stdClass $row, int $viewerId, bool $viewerIsOperator): array
    {
        $id        = (int) ($row->comment_ID ?? 0);
        $removedAt = trim((string) ($row->removed_at ?? ''));

        if ($removedAt !== '') {
            $actorType = (string) ($row->removed_actor ?? '');
            $label     = Policy::labelFor($actorType);

            // ⚠ An unrecognised stored actor yields NO label from the closed
            // set, and the fallback is the author label rather than echoing
            // the stored value — a caption must never come from the database.
            return [
                'id'         => self::ID_PREFIX . $id,
                'is_removed' => true,
                'removed_at' => self::iso($removedAt),
                'label'      => $label ?? Policy::LABEL_AUTHOR,
            ];
        }

        $authorId = (int) ($row->user_id ?? 0);

        return [
            'id'          => self::ID_PREFIX . $id,
            'is_removed'  => false,
            'body'        => (string) ($row->comment_content ?? ''),
            'author'      => $this->author($authorId),
            'posted_at'   => self::iso((string) ($row->comment_date_gmt ?? '')),
            'permissions' => [
                'can_remove' => self::entry(
                    Policy::removalActorType($viewerId, $authorId, $viewerIsOperator) !== null,
                    Policy::REASON_NOT_COMMENT_AUTHOR
                ),
            ],
        ];
    }

    /**
     * The author block, §4.32.14's shape.
     *
     * ⚠ Resolved through `UserViewService`, which owns handle, display name and
     * avatar resolution for every other surface. A hand-built author block here
     * would be a second source of truth for the same four fields.
     *
     * @return array{id: int, handle: ?string, display_name: ?string, avatar_url: ?string}
     */
    private function author(int $userId): array
    {
        if ($userId <= 0) {
            return ['id' => 0, 'handle' => null, 'display_name' => null, 'avatar_url' => null];
        }

        try {
            $summary = \BCC\Trust\Core\Plugin::instance()->userViewService()->getSummary($userId, $userId);
        } catch (\Throwable $e) {
            Logger::warning('[AnnouncementCommentService] author lookup failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            $summary = null;
        }

        return [
            'id'           => $userId,
            'handle'       => is_array($summary) ? ($summary['handle'] ?? null) : null,
            'display_name' => is_array($summary) ? ($summary['display_name'] ?? null) : null,
            'avatar_url'   => is_array($summary) ? ($summary['avatar_url'] ?? null) : null,
        ];
    }

    // ── shared helpers ───────────────────────────────────────────────────

    /**
     * The announcement, only if it is PUBLICLY readable.
     *
     * ⚠ Composed through `AnnouncementRepository::findPublic()`, which applies
     * the canonical §4.32.1 three-predicate SQL. The predicate is not restated
     * here for the same reason A4's projection does not restate it: one copy.
     */
    private function publicAnnouncementOrNull(int $announcementId): ?\stdClass
    {
        if ($announcementId <= 0) {
            return null;
        }

        $post = get_post($announcementId);
        if (!$post instanceof \WP_Post) {
            return null;
        }

        $pageId = (int) $post->post_parent;
        if ($pageId <= 0) {
            return null;
        }

        try {
            $row = $this->announcements->findPublic($pageId, $announcementId);
        } catch (RepositoryException $e) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        // ⚠⚠ STAMPED, BECAUSE THE SELECT DOES NOT CARRY IT.
        // `AnnouncementRepository::columns()` selects `post_author` but NOT
        // `post_parent`, so the row this method returns has no page id of its
        // own. An earlier version of this service read `$row->page_id` with a
        // `?? $row->post_parent ?? 0` fallback — BOTH absent — so it resolved
        // to 0, which would have made `isOperatorOf()` always false and
        // resolved authority against page 0. Found by reading the column list
        // rather than by a failing test, because a silent 0 denies rather than
        // grants and would have looked like correct-but-strict behaviour.
        //
        // The page id is already known here, from the post this method just
        // loaded, so it is attached rather than re-queried.
        $row->page_id = $pageId;

        return $row;
    }

    /** Is this viewer the resolved operator of the announcement's page? */
    private function isOperatorOf(\stdClass $row, int $viewerId): bool
    {
        if ($viewerId <= 0) {
            return false;
        }

        $pageId = (int) ($row->page_id ?? ($row->post_parent ?? 0));
        if ($pageId <= 0) {
            return false;
        }

        try {
            $decision = Authority::decide($pageId, $viewerId);
        } catch (\Throwable $e) {
            return false;
        }

        return ($decision['decision'] ?? '') === 'operator';
    }

    /**
     * `can_comment`, from the ONE capability producer.
     *
     * ⚠⚠ The viewer standing comes from `AnnouncementViewerStanding`, which is
     * the fix for the gap that made this capability unreachable: the previous
     * caller supplied only `authenticated`, so `good_standing` was absent and
     * the resolver — correctly failing closed on an absent key — denied every
     * viewer.
     *
     * @return array<string,mixed>
     */
    private function canComment(int $announcementId, \stdClass $row, int $viewerId): array
    {
        $pageId = (int) ($row->page_id ?? ($row->post_parent ?? 0));

        try {
            $decision = Authority::decide($pageId, $viewerId);
        } catch (\Throwable $e) {
            $decision = ['decision' => 'denied', 'reason' => null, 'operator_user_id' => null];
        }

        $commentsEnabled = true;
        try {
            $commentsEnabled = $this->comments->commentsEnabled($announcementId, Vis::META_COMMENTS_ENABLED);
        } catch (RepositoryException $e) {
            // Fail closed: an unreadable switch is not permission to comment.
            $commentsEnabled = false;
        }

        // ⚠⚠ FOUR parameters, not three. `forRow()` takes an explicit
        // `$featureEnabled` rather than reading the switch itself, so the
        // capability answer is a pure function of its inputs and a caller
        // cannot get a different answer than the handler that gated it.
        // PHPStan caught the three-argument call; PHP would have thrown an
        // ArgumentCountError only when the line actually executed, which for a
        // capability block means on a real request.
        $full = Caps::forRow(
            $decision,
            [
                'status'           => Caps::STATUS_PUBLISHED,
                'is_archived'      => trim((string) ($row->archived_at ?? '')) !== '',
                'comments_enabled' => $commentsEnabled,
            ],
            Standing::forViewer($viewerId),
            \BCC\Trust\Core\Announcements\AnnouncementFeature::isEnabled()
        );

        return $full['can_comment'];
    }

    /** @return array<string,mixed> */
    private static function entry(bool $allowed, string $reasonWhenDenied): array
    {
        return [
            'allowed'      => $allowed,
            'unlock_hint'  => null,
            'reason_code'  => $allowed ? null : $reasonWhenDenied,
        ];
    }

    /** MySQL UTC datetime -> ISO-8601 Z, or null. */
    private static function iso(string $mysqlUtc): ?string
    {
        $t = trim($mysqlUtc);
        if ($t === '' || $t === '0000-00-00 00:00:00') {
            return null;
        }

        return str_replace(' ', 'T', $t) . 'Z';
    }

    /** @return array<string,mixed> */
    private static function fail(string $code, string $message, int $status, ?string $reason = null): array
    {
        $out = ['ok' => false, 'code' => $code, 'message' => $message, 'status' => $status];

        // ⚠ Only when supplied — the renderer keys off presence to decide
        // whether to emit a `reason_code` block at all.
        if ($reason !== null) {
            $out['reason'] = $reason;
        }

        return $out;
    }
}
