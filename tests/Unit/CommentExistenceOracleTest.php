<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Services\CommentService;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Tests\Stubs\CommentDeleteFakes;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-47: the comment routes must not confirm that a post the viewer cannot see
 * exists.
 *
 * Before, a group post the viewer was not a member of answered 403
 * ("You do not have access to this discussion."), while a missing id answered
 * 404. So a 403 confirmed that the id was a post in a group the viewer could
 * not see, including a SECRET one. The same split on DELETE confirmed that
 * someone else's comment existed.
 *
 * Now, once the membership gate refuses:
 *   - a post the viewer cannot see at all
 *     ({@see FeedItemReportVisibility::canSee()}, the rule reports and the
 *     feeds share) gets EXACTLY the missing-id 404;
 *   - a post the viewer CAN see keeps the 403 and its "join the group" hint,
 *     which reveals nothing they don't already know.
 *
 * The real CommentService runs; the repositories, the activity and membership
 * reads and the visibility rule are doubled (tests/Stubs/comment-delete-stubs.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CommentExistenceOracleTest extends TestCase
{
    private const VIEWER       = 10;
    private const AUTHOR       = 9;
    private const PARENT_ACT   = 123;
    private const PARENT_POST  = 555;
    private const GROUP_ID     = 4231;
    private const MISSING_ACT  = 999;

    private const NOT_FOUND_POST    = ['error' => 'bcc_not_found', 'message' => 'Post not found.'];
    private const NOT_FOUND_COMMENT = ['error' => 'bcc_not_found', 'message' => 'Comment not found.'];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/comment-delete-stubs.php';
        CommentDeleteFakes::reset();

        // A group-tagged parent post the viewer is NOT a member of.
        CommentDeleteFakes::$activities[self::PARENT_ACT] = (object) [
            'act_id'          => self::PARENT_ACT,
            'act_external_id' => self::PARENT_POST,
            'act_module_id'   => 1,
            'act_status'      => 'publish',
        ];
        CommentDeleteFakes::$postMeta[self::PARENT_POST]['peepso_group_id'] = (string) self::GROUP_ID;
    }

    /** @param list<int> $visibleActIds */
    private static function service(array $visibleActIds, ?CommentRepository $repo = null): CommentService
    {
        $visibility = new class ($visibleActIds) extends FeedItemReportVisibility {
            /** @param list<int> $visible */
            public function __construct(private array $visible)
            {
            }

            public function canSee(int $viewerId, object $activity): bool
            {
                return in_array((int) $activity->act_id, $this->visible, true);
            }
        };

        $class   = new ReflectionClass(CommentService::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('visibility')->setValue($service, $visibility);
        $class->getProperty('commentRepo')->setValue($service, $repo ?? new CommentRepository());

        return $service;
    }

    // ── GET /posts/:feed_id/comments ─────────────────────────────────

    public function testAPostTheViewerCannotSeeListsExactlyLikeAMissingOne(): void
    {
        $service = self::service([]);

        $missing = $service->listByFeedId('feed_' . self::MISSING_ACT, self::VIEWER, 'oldest', null, 20);
        $hidden  = $service->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertSame(self::NOT_FOUND_POST, $missing);
        self::assertSame($missing, $hidden, 'a hidden post must be indistinguishable from a missing one');
    }

    public function testAnonymousViewerOfAHiddenPostAlsoGetsTheMissingId404(): void
    {
        $hidden = self::service([])->listByFeedId('feed_' . self::PARENT_ACT, 0, 'oldest', null, 20);

        self::assertSame(self::NOT_FOUND_POST, $hidden);
    }

    public function testAVisiblePostKeepsTheJoinTheGroup403(): void
    {
        $result = self::service([self::PARENT_ACT])
            ->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => 'You do not have access to this discussion.'],
            $result
        );
    }

    // ── DELETE /posts/:feed_id/comments/:comment_id ──────────────────

    private static function repoWithComment(): CommentRepository
    {
        $repo = new CommentRepository();
        $repo->metaByActId[456] = (object) [
            'act_id'          => 456,
            'comment_post_id' => 777,
            'parent_post_id'  => self::PARENT_POST,
            'author_id'       => self::AUTHOR,
        ];

        return $repo;
    }

    public function testSomeoneElsesCommentOnAHiddenPostDeletesLikeAMissingOne(): void
    {
        $service = self::service([], self::repoWithComment());

        $missing = $service->deleteComment('feed_' . self::PARENT_ACT, 'comment_404', self::VIEWER);
        $hidden  = $service->deleteComment('feed_' . self::PARENT_ACT, 'comment_456', self::VIEWER);

        self::assertSame(self::NOT_FOUND_COMMENT, $missing);
        self::assertSame($missing, $hidden);
        self::assertSame([], CommentDeleteFakes::$writerCalls);
    }

    public function testAFeedIdThatIsNotTheCommentsParentDeletesLikeAMissingOne(): void
    {
        CommentDeleteFakes::$activities[321] = (object) [
            'act_id'          => 321,
            'act_external_id' => 999999,
            'act_module_id'   => 1,
            'act_status'      => 'publish',
        ];

        $result = self::service([321, self::PARENT_ACT], self::repoWithComment())
            ->deleteComment('feed_321', 'comment_456', self::VIEWER);

        self::assertSame(self::NOT_FOUND_COMMENT, $result);
    }

    public function testSomeoneElsesCommentOnAVisiblePostKeepsThe403(): void
    {
        $result = self::service([self::PARENT_ACT], self::repoWithComment())
            ->deleteComment('feed_' . self::PARENT_ACT, 'comment_456', self::VIEWER);

        self::assertSame(['error' => 'bcc_forbidden', 'message' => 'You can only delete your own comments.'], $result);
        self::assertSame([], CommentDeleteFakes::$writerCalls);
    }
}
