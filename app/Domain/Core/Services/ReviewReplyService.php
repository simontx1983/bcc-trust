<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\ReviewReplyRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\Feed\ActivityStreamWriter;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority;
use BCC\Core\PeepSo\PeepSoAddPostGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-345 — review replies: ONE public response to a review, by the reviewed
 * party. User decisions (2026-11-12, hand-over rule 2026-11-17):
 *
 *   - Only the reviewed party may reply: the member, for a review of their
 *     own profile; the verified operator, for a claimed validator page; the
 *     page owner otherwise. Never a third party, never the reviewer.
 *   - One reply per review, no thread, at most BODY_MAX_LENGTH characters.
 *   - The reply's author may edit it (edited_at is shown) and delete it. The
 *     reviewer cannot remove it but can report it; moderators hide it with
 *     the existing act-level hide. A moderation-hidden reply is FROZEN: it
 *     keeps its slot, and its author can neither edit nor delete it (a
 *     delete would free the slot for a fresh, re-belled reply and wipe the
 *     act an open report points at).
 *   - HAND-OVER: the page's response is the reply whose author CURRENTLY
 *     answers for the page (answersFor). When the page changes hands (claim
 *     revoked, operator changed, page reassigned) the old author's reply
 *     stops showing and no longer holds the slot, so the current party can
 *     write their own. The old author may still delete theirs, not edit it,
 *     and still SEES it — as `viewer_stale_reply`, to them alone — so the
 *     delete has a place in the UI (user decision 2026-11-18).
 *   - Shown nested under its review on the Reviews tabs, never as a feed
 *     item, and its feed_id takes no comments or reactions (FeedItemGate).
 *     A reply shows only while its review is visible AND the reply itself is
 *     not hidden.
 *   - The reviewer gets a bell (bcc_review_reply_published → the
 *     notification dispatcher).
 *
 * Storage and moderation: see ReviewReplyRepository and
 * ActivityStreamWriter::publishReviewReply().
 */
final class ReviewReplyService
{
    public const BODY_MAX_LENGTH = 1000;

    /** Seconds a create waits for a concurrent create on the same review. */
    private const CREATE_LOCK_WAIT = 5;

    /** @var array<string, bool> "pageId:viewerId" => answers for the page (per request) */
    private array $answersMemo = [];

    public function __construct(
        private readonly VoteRepository $votes,
        private readonly ReviewReplyRepository $replies,
        private readonly ActivityStreamWriter $writer,
        private readonly PageOwnerResolver $owners,
    ) {
    }

    // ── reads (the Reviews tabs) ─────────────────────────────────────────

    /**
     * The reply and the viewer's can_reply for each review on a page of a
     * Reviews list, keyed by vote id. One batched reply read for the page.
     *
     * The reply shown is the page's response: the one whose author answers
     * for the page now, and only while it is not hidden.
     *
     * `viewer_stale_reply` is the VIEWER's own reply after a hand-over: they
     * no longer answer for the page, so it is not the page's response and
     * nobody else sees it, but its author does, so they can delete it. Null
     * while it is moderation-hidden: that one is frozen (delete answers 404).
     * It can sit beside `reply` when the new party has replied too.
     *
     * A read failure degrades to "no reply shown, can't reply" for the page
     * (logged): a review list must still render, and a can_reply that
     * guessed "no reply yet" could offer a duplicate.
     *
     * @param list<array{id: int, page_id: int, voter_user_id: int}> $reviews
     * @return array<int, array{reply: array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>}|null, viewer_stale_reply: array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>}|null, can_reply: array{allowed: bool, unlock_hint: string|null, reason_code: string|null}}>
     */
    public function forReviews(int $viewerId, array $reviews): array
    {
        $voteIds = [];
        foreach ($reviews as $r) {
            if ($r['id'] > 0) {
                $voteIds[] = $r['id'];
            }
        }
        if ($voteIds === []) {
            return [];
        }

        try {
            $byVote = $this->replies->findByVoteIds($voteIds);
            $readOk = true;
        } catch (RepositoryReadFailure $e) {
            $byVote = [];
            $readOk = false;
            Logger::error('[ReviewReplyService] reply read failed', ['error' => $e->getMessage()]);
        }

        $out = [];
        foreach ($reviews as $r) {
            $all     = $byVote[$r['id']] ?? [];
            $current = $this->currentReply($all, $r['page_id']);
            $own     = self::byAuthor($all, $viewerId);

            $out[$r['id']] = [
                'reply'              => $current !== null && !$current['hidden']
                    ? $this->shapeReply($current, $viewerId)
                    : null,
                'viewer_stale_reply' => $own !== null && !$own['hidden'] && !$this->answersFor($r['page_id'], $viewerId)
                    ? $this->shapeReply($own, $viewerId, true)
                    : null,
                'can_reply'          => $readOk
                    ? $this->canReplyFor($viewerId, $r['page_id'], $r['voter_user_id'], $own !== null)
                    : self::deny('unavailable'),
            ];
        }
        return $out;
    }

    /**
     * The author ids a list path must prefetch for forReviews() output: the
     * shown reply's and the viewer's stale reply's. A summary built without
     * its prefetch rows would show zero followers rather than re-reading.
     *
     * @param array<int, array{reply: array{author_id: int}|null, viewer_stale_reply: array{author_id: int}|null}> $bundles
     * @return list<int>
     */
    public static function replyAuthorIds(array $bundles): array
    {
        $ids = [];
        foreach ($bundles as $bundle) {
            foreach ([$bundle['reply'], $bundle['viewer_stale_reply']] as $reply) {
                if ($reply !== null) {
                    $ids[$reply['author_id']] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * May $viewerId answer for reviews of $pageId? The reviewed party only:
     * a member self-page → that member; a validator page → its verified
     * operator (an ambiguous or unclaimed page has no answerer); any other
     * page → its owner.
     *
     * ⚠ "Any other page" covers claimed project/creator pages WITHOUT a new
     * claim check, by reusing the signal the claim flow already writes: a
     * system-minted placeholder page is UNOWNED (`post_author = 0`,
     * ValidatorPageMinter), and a verified claim is what moves `post_author`
     * to the claimer (PeepSoIntegration::onPageClaimed; F-299 / #645 also
     * makes them the PeepSo owner). So an unclaimed placeholder resolves to
     * owner 0 here and has no answerer — the same outcome as
     * ValidatorPageOperatorAuthority's UNCLAIMED deny.
     */
    public function answersFor(int $pageId, int $viewerId): bool
    {
        if ($pageId <= 0 || $viewerId <= 0) {
            return false;
        }
        $key = $pageId . ':' . $viewerId;
        if (isset($this->answersMemo[$key])) {
            return $this->answersMemo[$key];
        }

        if (MemberSelfPageService::isSelfPage($pageId)) {
            $answers = MemberSelfPageService::ownerOfSelfPage($pageId) === $viewerId;
        } elseif (ValidatorPageOperatorAuthority::isValidatorPage($pageId)) {
            $answers = ValidatorPageOperatorAuthority::isOperator($pageId, $viewerId);
        } else {
            $owner   = $this->owners->getPageOwner($pageId);
            $answers = $owner > 0 && $owner === $viewerId;
        }

        return $this->answersMemo[$key] = $answers;
    }

    // ── writes ───────────────────────────────────────────────────────────

    /**
     * @return array{reply: array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>}}|array{error: string, message: string}
     */
    public function create(int $viewerId, int $voteId, string $body): array
    {
        if ($viewerId <= 0) {
            return self::error('bcc_unauthorized', 'Sign in required.');
        }
        $restricted = self::restricted($viewerId);
        if ($restricted !== null) {
            return $restricted;
        }
        $clean = self::cleanBody($body);
        if (is_array($clean)) {
            return $clean;
        }

        try {
            $review = $this->votes->findReviewForReply($voteId);
        } catch (RepositoryReadFailure $e) {
            return self::unavailable();
        }
        if ($review === null) {
            return self::error('bcc_not_found', 'Review not found.');
        }
        $pageId  = (int) $review->page_id;
        $voterId = (int) $review->voter_user_id;
        if ($voterId === $viewerId || !$this->answersFor($pageId, $viewerId)) {
            return self::error('bcc_forbidden', 'Only the reviewed member or page can reply to this review.');
        }

        // One create per review at a time, so two concurrent requests from
        // the party cannot both pass the slot check and write two replies.
        $lockKey = 'bcc_review_reply_' . $voteId;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lockKey, self::CREATE_LOCK_WAIT)) {
            return self::unavailable();
        }
        $written = null;
        try {
            // The slot: does the current party already have a reply here?
            // Hidden included — a moderation-hidden reply keeps its slot. An
            // OLD author's reply (they no longer answer) does not hold it.
            try {
                if ($this->replies->findForVoteByAuthor($voteId, $viewerId) !== null) {
                    return self::error('bcc_conflict', 'This review already has a reply.');
                }
            } catch (RepositoryReadFailure $e) {
                return self::unavailable();
            }

            if (!self::withinRateLimit($viewerId)) {
                return self::error('bcc_rate_limited', 'You are replying too fast. Try again in a few minutes.');
            }

            $written = $this->writer->publishReviewReply($viewerId, $voteId, $clean);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lockKey);
        }
        if ($written === null) {
            return self::unavailable();
        }

        do_action('bcc_review_reply_published', $viewerId, $voterId, $pageId, $voteId, $written['act_id']);

        try {
            $row = $this->replies->findForVoteByAuthor($voteId, $viewerId);
        } catch (RepositoryReadFailure $e) {
            $row = null;
        }

        return ['reply' => $this->shapeReply($row ?? [
            'vote_id'   => $voteId,
            'post_id'   => $written['post_id'],
            'act_id'    => $written['act_id'],
            'author_id' => $viewerId,
            'body'      => $clean,
            'posted_at' => gmdate('Y-m-d H:i:s'),
            'edited_at' => null,
            'hidden'    => false,
        ], $viewerId)];
    }

    /**
     * The author edits their reply — only while they still answer for the
     * page (hand-over rule) and only while it is not moderation-hidden.
     *
     * @return array{reply: array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>}}|array{error: string, message: string}
     */
    public function update(int $viewerId, int $voteId, string $body): array
    {
        if ($viewerId <= 0) {
            return self::error('bcc_unauthorized', 'Sign in required.');
        }
        $restricted = self::restricted($viewerId);
        if ($restricted !== null) {
            return $restricted;
        }
        $clean = self::cleanBody($body);
        if (is_array($clean)) {
            return $clean;
        }

        try {
            $review = $this->votes->findReviewForReply($voteId);
            if ($review === null) {
                return self::error('bcc_not_found', 'Review not found.');
            }
            $reply = $this->replies->findForVoteByAuthor($voteId, $viewerId);
        } catch (RepositoryReadFailure $e) {
            return self::unavailable();
        }
        // Not theirs, gone, or frozen by a moderator: the same answer.
        if ($reply === null || $reply['hidden']) {
            return self::error('bcc_not_found', 'Reply not found.');
        }
        if (!$this->answersFor((int) $review->page_id, $viewerId)) {
            return self::error('bcc_forbidden', 'You no longer answer for this page, so this reply can no longer be edited.');
        }
        if (!self::withinRateLimit($viewerId)) {
            return self::error('bcc_rate_limited', 'You are replying too fast. Try again in a few minutes.');
        }

        // ⚠ F-361 — pinned. #772 pinned the reply PUBLISH (in
        // ActivityStreamWriter); this is the EDIT, a separate write on the
        // same post from the operator's own request.
        $updated = PeepSoAddPostGuard::pinOnly(
            [], // a reply edit drives nothing through the request
            static fn () => wp_update_post(['ID' => $reply['post_id'], 'post_content' => $clean], true)
        );
        if (is_wp_error($updated) || (int) $updated <= 0) {
            return self::unavailable();
        }
        update_post_meta($reply['post_id'], ReviewReplyRepository::META_EDITED_AT, gmdate('Y-m-d H:i:s'));

        try {
            $row = $this->replies->findForVoteByAuthor($voteId, $viewerId);
        } catch (RepositoryReadFailure $e) {
            $row = null;
        }
        if ($row === null) {
            return self::unavailable();
        }

        return ['reply' => $this->shapeReply($row, $viewerId)];
    }

    /**
     * The author removes their reply — also after the page changed hands (a
     * stale reply), but NOT while it is moderation-hidden: that one is frozen
     * and answers exactly like no reply. Not gated on suspension: removing
     * one's own words is never the abuse a suspension exists to stop.
     *
     * @return array{deleted: true}|array{error: string, message: string}
     */
    public function delete(int $viewerId, int $voteId): array
    {
        if ($viewerId <= 0) {
            return self::error('bcc_unauthorized', 'Sign in required.');
        }
        try {
            $reply = $this->replies->findForVoteByAuthor($voteId, $viewerId);
        } catch (RepositoryReadFailure $e) {
            return self::unavailable();
        }
        if ($reply === null || $reply['hidden']) {
            return self::error('bcc_not_found', 'Reply not found.');
        }
        if (!$this->writer->deleteReviewReply($reply['post_id'], $reply['act_id'])) {
            return self::unavailable();
        }

        return ['deleted' => true];
    }

    /**
     * The wire shape of a reply: `author_id` replaced by `author`, the SAME
     * member-summary shape the review rows carry as their `author` (so a
     * Reviews list renders both with one component). Returns null when the
     * author no longer exists, as a review row whose author vanished is
     * skipped rather than shown faceless.
     *
     * @param array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>} $reply
     * @param array<string, mixed>|null $prefetched a MemberSummaryPrefetcher bundle on list paths
     * @return array{id: int, body: string, author: array<string, mixed>, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>}|null
     */
    public function withAuthor(array $reply, int $viewerId, ?array $prefetched): ?array
    {
        $author = \BCC\Trust\Core\Plugin::instance()->userViewService()->getSummary($reply['author_id'], $viewerId, $prefetched);
        if ($author === null) {
            return null;
        }

        return [
            'id'          => $reply['id'],
            'body'        => $reply['body'],
            'author'      => $author,
            'posted_at'   => $reply['posted_at'],
            'edited_at'   => $reply['edited_at'],
            'feed_id'     => $reply['feed_id'],
            'permissions' => $reply['permissions'],
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The page's response among a review's replies: the one whose author
     * answers for the page now. Each author holds at most one reply per
     * review, and only one party answers at a time, so there is at most one.
     *
     * @param list<array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}> $replies
     * @return array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}|null
     */
    private function currentReply(array $replies, int $pageId): ?array
    {
        foreach ($replies as $row) {
            if ($this->answersFor($pageId, $row['author_id'])) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param list<array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}> $replies
     * @return array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool}|null
     */
    private static function byAuthor(array $replies, int $authorId): ?array
    {
        if ($authorId <= 0) {
            return null;
        }
        foreach ($replies as $row) {
            if ($row['author_id'] === $authorId) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private function canReplyFor(int $viewerId, int $pageId, int $voterId, bool $viewerHasReply): array
    {
        // Anonymous viewers are never the reviewed party, so this is
        // not_applicable rather than a sign-in prompt shown to everyone.
        if ($viewerId <= 0 || $viewerId === $voterId || !$this->answersFor($pageId, $viewerId)) {
            return self::deny('not_applicable');
        }
        if ($viewerHasReply) {
            return self::deny('already_replied');
        }
        if (self::restricted($viewerId) !== null) {
            return self::deny('sender_restricted');
        }
        return self::allow();
    }

    /**
     * What THIS viewer may do with a reply, so the client never derives it
     * (§1.4.5). For the page's response its author answers for the page by
     * construction, so editing needs only authorship and good standing. A
     * $stale reply is the viewer's own after a hand-over: deletable, never
     * editable (update() refuses it with 403).
     *
     * @param array{author_id: int} $row
     * @return array{can_edit: array{allowed: bool, unlock_hint: string|null, reason_code: string|null}, can_delete: array{allowed: bool, unlock_hint: string|null, reason_code: string|null}, can_report: array{allowed: bool, unlock_hint: string|null, reason_code: string|null}}
     */
    private static function permissionsFor(array $row, int $viewerId, bool $stale = false): array
    {
        $isAuthor = $viewerId > 0 && $row['author_id'] === $viewerId;
        $isRestricted = $viewerId > 0 && self::restricted($viewerId) !== null;

        if ($viewerId <= 0) {
            $report = ['allowed' => false, 'unlock_hint' => 'Sign in to report.', 'reason_code' => 'sign_in_required'];
        } elseif ($isAuthor) {
            $report = self::deny('own_reply');
        } elseif ($isRestricted) {
            $report = self::deny('sender_restricted');
        } else {
            $report = self::allow();
        }

        return [
            'can_edit'   => !$isAuthor ? self::deny('not_author') : ($stale ? self::deny('page_changed_hands') : ($isRestricted ? self::deny('sender_restricted') : self::allow())),
            'can_delete' => $isAuthor ? self::allow() : self::deny('not_author'),
            'can_report' => $report,
        ];
    }

    /**
     * @param array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool} $row
     * @return array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>}
     */
    private function shapeReply(array $row, int $viewerId, bool $stale = false): array
    {
        return [
            'id'          => $row['post_id'],
            'body'        => $row['body'],
            'author_id'   => $row['author_id'],
            'posted_at'   => self::iso($row['posted_at']),
            'edited_at'   => $row['edited_at'] !== null ? self::iso($row['edited_at']) : null,
            // The report target, in the FeedItem.id form the report control
            // already parses (`feed_<act_id>`) → POST /me/reports feed_item.
            // It takes no comments or reactions (FeedItemGate).
            'feed_id'     => 'feed_' . $row['act_id'],
            'permissions' => self::permissionsFor($row, $viewerId, $stale),
        ];
    }

    /** @return string|array{error: string, message: string} */
    private static function cleanBody(string $body): string|array
    {
        $clean = trim(sanitize_textarea_field($body));
        if ($clean === '') {
            return self::error('bcc_invalid_request', 'Reply cannot be empty.');
        }
        if (mb_strlen($clean) > self::BODY_MAX_LENGTH) {
            return self::error(
                'bcc_invalid_request',
                sprintf('Replies cap at %d characters.', self::BODY_MAX_LENGTH)
            );
        }
        return $clean;
    }

    /** @return array{error: string, message: string}|null */
    private static function restricted(int $viewerId): ?array
    {
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($viewerId, false)) {
            return self::error('bcc_forbidden', 'Your account is currently restricted.');
        }
        if (class_exists('PeepSoUser')) {
            $role = \PeepSoUser::get_instance($viewerId)->get_user_role();
            if ($role === 'ban') {
                return self::error('bcc_forbidden', 'Your account is currently restricted.');
            }
        }
        return null;
    }

    private static function withinRateLimit(int $viewerId): bool
    {
        return \BCC\Core\Security\Throttle::allow(
            'review_reply',
            (int) BCC_TRUST_RATE_LIMIT_REVIEW_REPLY,
            (int) BCC_TRUST_RATE_WINDOW_REVIEW_REPLY,
            'review_reply:' . $viewerId . ':burst'
        );
    }

    private static function iso(string $mysqlUtc): string
    {
        $ts = strtotime($mysqlUtc . ' UTC');
        return gmdate('c', $ts === false ? 0 : $ts);
    }

    /** @return array{allowed: true, unlock_hint: null, reason_code: null} */
    private static function allow(): array
    {
        return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /** @return array{allowed: false, unlock_hint: null, reason_code: string} */
    private static function deny(string $reasonCode): array
    {
        return ['allowed' => false, 'unlock_hint' => null, 'reason_code' => $reasonCode];
    }

    /** @return array{error: string, message: string} */
    private static function error(string $code, string $message): array
    {
        return ['error' => $code, 'message' => $message];
    }

    /** @return array{error: string, message: string} */
    private static function unavailable(): array
    {
        return self::error('bcc_unavailable', 'Replies are temporarily unavailable. Please try again.');
    }
}
