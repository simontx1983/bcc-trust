<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Services\CommentService;
use BCC\Trust\Tests\Stubs\CommentDeleteFakes;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the id plumbing the comment-delete Rank-evidence reversal rides
 * on: `CommentService::deleteComment` must emit `bcc_comment_deleted`
 * with the comment's wp_post ID as the 4th arg — the SAME id the
 * `bcc_comment_created` subscriber ingests as the ledger sourceId for
 * sourceType 'comment' ($newCommentPostId), and therefore the only id
 * Plugin.php's reversal subscriber can reverse by. Without this pin, a
 * refactor that swaps the 4th arg to the act_id would silently turn
 * every reversal into a zero-row no-op and reopen the
 * publish→earn→delete→repeat farming loop.
 *
 * SEAM NOTE — why the Plugin.php `add_action` wiring itself is not
 * under test: Plugin.php is the WP bootstrap (requires live WordPress +
 * bcc-core, `add_action`/hook machinery, and the full container); the
 * unit harness deliberately loads none of that (tests/bootstrap.php).
 * The equivalent post-delete reversal wiring is untested at that seam
 * for the same reason. What CAN be pinned without WP is (a) this event
 * shape and (b) the ingestor's reverse() behavior (RankScoreCalculator
 * suite) — the subscriber between them is four lines of arg-forwarding.
 *
 * ## Isolation
 * Subprocess-only; setUp() pulls in tests/Stubs/comment-delete-stubs.php
 * which fakes the namespaced `do_action`, bcc-core's static
 * PeepSoCommentWriter, and the injected CommentRepository at their FQNs.
 * The service is built constructor-free with the fake repo
 * reflection-injected (same recipe as FeedCommentCountGatingTest).
 * No DB, no WP.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CommentDeleteEvidenceEventTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/comment-delete-stubs.php';
        CommentDeleteFakes::reset();
    }

    private static function service(CommentRepository $repo): CommentService
    {
        $service = (new ReflectionClass(CommentService::class))->newInstanceWithoutConstructor();
        $prop = (new ReflectionClass(CommentService::class))->getProperty('commentRepo');
        $prop->setValue($service, $repo);
        return $service;
    }

    private static function metaRow(int $actId, int $commentPostId, int $parentPostId, int $authorId): object
    {
        return (object) [
            'act_id'          => $actId,
            'comment_post_id' => $commentPostId,
            'parent_post_id'  => $parentPostId,
            'author_id'       => $authorId,
        ];
    }

    public function testDeleteEmitsEventCarryingTheLedgerSourceId(): void
    {
        $repo = new CommentRepository();
        $repo->metaByActId[456] = self::metaRow(456, 777, 555, 9);

        $result = self::service($repo)->deleteComment('feed_123', 'comment_456', 9);

        self::assertSame(['ok' => true, 'comment_id' => 'comment_456'], $result);
        self::assertSame([777], CommentDeleteFakes::$writerCalls);
        self::assertSame(
            [['bcc_comment_deleted', [9, 123, 456, 777]]],
            CommentDeleteFakes::$actions,
            '4th arg MUST be the comment wp_post id (ledger sourceId), not the act_id'
        );
    }

    public function testNoEventWhenWriterFails(): void
    {
        $repo = new CommentRepository();
        $repo->metaByActId[456] = self::metaRow(456, 777, 555, 9);
        CommentDeleteFakes::$writerResult = false;

        $result = self::service($repo)->deleteComment('feed_123', 'comment_456', 9);

        self::assertSame('bcc_internal_error', $result['error'] ?? null);
        self::assertSame([], CommentDeleteFakes::$actions, 'a failed delete must not signal a reversal');
    }

    public function testNoEventForCrossAuthorAttempt(): void
    {
        $repo = new CommentRepository();
        $repo->metaByActId[456] = self::metaRow(456, 777, 555, 9);

        $result = self::service($repo)->deleteComment('feed_123', 'comment_456', 10);

        self::assertSame('bcc_forbidden', $result['error'] ?? null);
        self::assertSame([], CommentDeleteFakes::$writerCalls);
        self::assertSame([], CommentDeleteFakes::$actions);
    }
}
