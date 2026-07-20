<?php

declare(strict_types=1);

namespace BCC\Trust\Disputes\Tests\Unit;

use BCC\Trust\Core\Services\NotificationDispatcher;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationDispatcher::resolveCommentRecipients().
 *
 * The pure decision table behind comment-created notification routing
 * (§I1, contract v1.45): who hears about a new comment — the post
 * author ("commented on your post"), the replied-to comment's author
 * ("replied to your comment"), both, or nobody. Pure function over
 * three user ids; no WP, no DB.
 *
 * Actors in the scenarios below: B owns the post, A authored the
 * comment being replied to, C is the commenter.
 */
#[CoversMethod(NotificationDispatcher::class, 'resolveCommentRecipients')]
final class CommentRecipientsTest extends TestCase
{
    private const A = 11; // parent-comment author
    private const B = 22; // post author
    private const C = 33; // commenter

    // ── Top-level comments (no parent comment) ──────────────────────────────

    public function testTopLevelCommentNotifiesPostAuthorOnly(): void
    {
        $r = NotificationDispatcher::resolveCommentRecipients(self::C, self::B, 0);

        self::assertSame(0, $r['reply']);
        self::assertSame(self::B, $r['owner']);
    }

    public function testTopLevelCommentOnOwnPostNotifiesNobody(): void
    {
        $r = NotificationDispatcher::resolveCommentRecipients(self::B, self::B, 0);

        self::assertSame(0, $r['reply']);
        self::assertSame(0, $r['owner'], 'self-skip: author commenting on their own post');
    }

    // ── Replies ─────────────────────────────────────────────────────────────

    public function testReplyByThirdPartyNotifiesBothCommentAuthorAndPostAuthor(): void
    {
        $r = NotificationDispatcher::resolveCommentRecipients(self::C, self::B, self::A);

        self::assertSame(self::A, $r['reply']);
        self::assertSame(self::B, $r['owner']);
    }

    public function testReplyToPostAuthorsOwnCommentSendsOnlyReplyNotification(): void
    {
        // A == B: the post author also wrote the parent comment. They get
        // exactly one notification — the reply one — never both.
        $r = NotificationDispatcher::resolveCommentRecipients(self::C, self::B, self::B);

        self::assertSame(self::B, $r['reply']);
        self::assertSame(0, $r['owner']);
    }

    public function testSelfReplyNotifiesPostAuthorOnly(): void
    {
        // C replies to their own comment on B's post: no reply
        // notification, but B still hears about post activity.
        $r = NotificationDispatcher::resolveCommentRecipients(self::C, self::B, self::C);

        self::assertSame(0, $r['reply']);
        self::assertSame(self::B, $r['owner']);
    }

    public function testSelfReplyOnOwnPostNotifiesNobody(): void
    {
        $r = NotificationDispatcher::resolveCommentRecipients(self::B, self::B, self::B);

        self::assertSame(0, $r['reply']);
        self::assertSame(0, $r['owner']);
    }

    // ── Degenerate ids ──────────────────────────────────────────────────────

    public function testUnresolvableParentCommentDegradesToTopLevel(): void
    {
        // Parent deleted/unpublished between write and dispatch → caller
        // passes 0 → routes exactly like a top-level comment.
        $r = NotificationDispatcher::resolveCommentRecipients(self::C, self::B, 0);

        self::assertSame(0, $r['reply']);
        self::assertSame(self::B, $r['owner']);
    }

    public function testMissingPostAuthorStillNotifiesReplyRecipient(): void
    {
        $r = NotificationDispatcher::resolveCommentRecipients(self::C, 0, self::A);

        self::assertSame(self::A, $r['reply']);
        self::assertSame(0, $r['owner']);
    }
}
