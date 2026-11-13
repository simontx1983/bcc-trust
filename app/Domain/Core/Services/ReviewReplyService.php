<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\ReviewReplyRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\Feed\ActivityStreamWriter;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * F-345 — review replies: ONE public response to a review, by the reviewed
 * party. User decisions (2026-11-12):
 *
 *   - Only the reviewed party may reply: the member, for a review of their
 *     own profile; the verified operator, for a claimed validator page; the
 *     page owner otherwise. Never a third party, never the reviewer.
 *   - One reply per review, no thread, at most BODY_MAX_LENGTH characters.
 *   - The reply's author may edit it (edited_at is shown) and delete it. The
 *     reviewer cannot remove it but can report it; moderators hide it with
 *     the existing act-level hide.
 *   - Shown nested under its review on the Reviews tabs, never as a feed
 *     item. A reply shows only while its review is visible AND the reply
 *     itself is not hidden.
 *   - The reviewer gets a bell (bcc_review_reply_published → the
 *     notification dispatcher).
 *
 * Storage and moderation: see ReviewReplyRepository and
 * ActivityStreamWriter::publishReviewReply().
 */
final class ReviewReplyService
{
    public const BODY_MAX_LENGTH = 1000;

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
     * A read failure degrades to "no reply shown, can't reply" for the page
     * (logged): a review list must still render, and a can_reply that
     * guessed "no reply yet" could offer a duplicate.
     *
     * @param list<array{id: int, page_id: int, voter_user_id: int}> $reviews
     * @return array<int, array{reply: array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string}|null, can_reply: array{allowed: bool, unlock_hint: string|null, reason_code: string|null}}>
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
            $rows = $this->replies->findByVoteIds($voteIds);
            $readOk = true;
        } catch (RepositoryReadFailure $e) {
            $rows   = [];
            $readOk = false;
            Logger::error('[ReviewReplyService] reply read failed', ['error' => $e->getMessage()]);
        }

        $out = [];
        foreach ($reviews as $r) {
            $row = $rows[$r['id']] ?? null;
            $out[$r['id']] = [
                'reply'     => $row !== null && !$row['hidden'] ? self::shapeReply($row) : null,
                'can_reply' => $readOk
                    ? $this->canReplyFor($viewerId, $r['page_id'], $r['voter_user_id'], $row !== null)
                    : self::deny('unavailable'),
            ];
        }
        return $out;
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
     * @return array{reply: array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string}}|array{error: string, message: string}
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
            if ($review === null) {
                return self::error('bcc_not_found', 'Review not found.');
            }
            $pageId  = (int) $review->page_id;
            $voterId = (int) $review->voter_user_id;
            if ($voterId === $viewerId || !$this->answersFor($pageId, $viewerId)) {
                return self::error('bcc_forbidden', 'Only the reviewed member or page can reply to this review.');
            }
            if ($this->replies->findForVote($voteId) !== null) {
                return self::error('bcc_conflict', 'This review already has a reply.');
            }
        } catch (RepositoryReadFailure $e) {
            return self::unavailable();
        }

        if (!self::withinRateLimit($viewerId)) {
            return self::error('bcc_rate_limited', 'You are replying too fast. Try again in a few minutes.');
        }

        $written = $this->writer->publishReviewReply($viewerId, $voteId, $clean);
        if ($written === null) {
            return self::unavailable();
        }

        do_action('bcc_review_reply_published', $viewerId, $voterId, $pageId, $voteId, $written['act_id']);

        try {
            $row = $this->replies->findForVote($voteId);
        } catch (RepositoryReadFailure $e) {
            $row = null;
        }

        return ['reply' => $row !== null
            ? self::shapeReply($row)
            : [
                'id'        => $written['post_id'],
                'body'      => $clean,
                'author_id' => $viewerId,
                'posted_at' => gmdate('c'),
                'edited_at' => null,
                'feed_id'   => 'feed_' . $written['act_id'],
            ]];
    }

    /**
     * @return array{reply: array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string}}|array{error: string, message: string}
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
            if ($this->votes->findReviewForReply($voteId) === null) {
                return self::error('bcc_not_found', 'Review not found.');
            }
            $reply = $this->replies->findForVote($voteId);
        } catch (RepositoryReadFailure $e) {
            return self::unavailable();
        }
        if ($reply === null) {
            return self::error('bcc_not_found', 'Reply not found.');
        }
        if ($reply['author_id'] !== $viewerId) {
            return self::error('bcc_forbidden', 'Only the reply\'s author can edit it.');
        }
        if (!self::withinRateLimit($viewerId)) {
            return self::error('bcc_rate_limited', 'You are replying too fast. Try again in a few minutes.');
        }

        $updated = wp_update_post(['ID' => $reply['post_id'], 'post_content' => $clean], true);
        if (is_wp_error($updated) || (int) $updated <= 0) {
            return self::unavailable();
        }
        update_post_meta($reply['post_id'], ReviewReplyRepository::META_EDITED_AT, gmdate('Y-m-d H:i:s'));

        try {
            $row = $this->replies->findForVote($voteId);
        } catch (RepositoryReadFailure $e) {
            $row = null;
        }
        if ($row === null) {
            return self::unavailable();
        }

        return ['reply' => self::shapeReply($row)];
    }

    /**
     * The author removes their reply. Not gated on suspension: removing
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
            $reply = $this->replies->findForVote($voteId);
        } catch (RepositoryReadFailure $e) {
            return self::unavailable();
        }
        if ($reply === null) {
            return self::error('bcc_not_found', 'Reply not found.');
        }
        if ($reply['author_id'] !== $viewerId) {
            return self::error('bcc_forbidden', 'Only the reply\'s author can delete it.');
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
     * @param array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string} $reply
     * @param array<string, mixed>|null $prefetched a MemberSummaryPrefetcher bundle on list paths
     * @return array{id: int, body: string, author: array<string, mixed>, posted_at: string, edited_at: string|null, feed_id: string}|null
     */
    public function withAuthor(array $reply, int $viewerId, ?array $prefetched): ?array
    {
        $author = \BCC\Trust\Core\Plugin::instance()->userViewService()->getSummary($reply['author_id'], $viewerId, $prefetched);
        if ($author === null) {
            return null;
        }

        return [
            'id'        => $reply['id'],
            'body'      => $reply['body'],
            'author'    => $author,
            'posted_at' => $reply['posted_at'],
            'edited_at' => $reply['edited_at'],
            'feed_id'   => $reply['feed_id'],
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private function canReplyFor(int $viewerId, int $pageId, int $voterId, bool $hasReply): array
    {
        // Anonymous viewers are never the reviewed party, so this is
        // not_applicable rather than a sign-in prompt shown to everyone.
        if ($viewerId <= 0 || $viewerId === $voterId || !$this->answersFor($pageId, $viewerId)) {
            return self::deny('not_applicable');
        }
        if ($hasReply) {
            return self::deny('already_replied');
        }
        if (self::restricted($viewerId) !== null) {
            return self::deny('sender_restricted');
        }
        return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /**
     * @param array{vote_id: int, post_id: int, act_id: int, author_id: int, body: string, posted_at: string, edited_at: string|null, hidden: bool} $row
     * @return array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string}
     */
    private static function shapeReply(array $row): array
    {
        return [
            'id'        => $row['post_id'],
            'body'      => $row['body'],
            'author_id' => $row['author_id'],
            'posted_at' => self::iso($row['posted_at']),
            'edited_at' => $row['edited_at'] !== null ? self::iso($row['edited_at']) : null,
            // The report target, in the FeedItem.id form the report control
            // already parses (`feed_<act_id>`) → POST /me/reports feed_item.
            'feed_id'   => 'feed_' . $row['act_id'],
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
