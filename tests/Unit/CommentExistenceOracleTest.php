<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Services\CommentService;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Tests\Stubs\CommentDeleteFakes;
use BCC\Trust\Tests\Support\PostGroupFactsWpdb;
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
    private const NOTICE_ACT   = 205205;
    private const NOTICE_POST  = 5001;

    private const NOT_FOUND_POST    = ['error' => 'bcc_not_found', 'message' => 'Post not found.'];
    private const NOT_FOUND_COMMENT = ['error' => 'bcc_not_found', 'message' => 'Comment not found.'];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/comment-delete-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/announcement-page-access-stub.php';
        CommentDeleteFakes::reset();
        $GLOBALS['__bcc_apa'] = ['hidden' => [], 'calls' => []];

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
    private static function service(array $visibleActIds, ?CommentRepository $repo = null, bool $live = true): CommentService
    {
        $visibility = new class ($visibleActIds, $live) extends FeedItemReportVisibility {
            /** @param list<int> $visible */
            public function __construct(private array $visible, private bool $live)
            {
            }

            public function canSee(int $viewerId, object $activity): bool
            {
                return in_array((int) $activity->act_id, $this->visible, true);
            }

            // F-265: whether the parent post is still published and not
            // moderation-hidden (the real rule is pinned in
            // RemovedPostLivenessIntegrationTest + FeedItemReportVisibilityTest).
            public function isLive(object $activity): bool
            {
                return $this->live;
            }
        };

        $class   = new ReflectionClass(CommentService::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('visibility')->setValue($service, $visibility);
        $class->getProperty('commentRepo')->setValue($service, $repo ?? new CommentRepository());

        return $service;
    }

    // ── a SECRET page's announcement ─────────────────────────────────

    /**
     * A secret page's notice is gone from a stranger's feeds and permalink,
     * so its thread is too: the missing id's 404, before any other gate.
     */
    public function testAHiddenNoticesThreadListsExactlyLikeAMissingPost(): void
    {
        $this->seedNotice();
        $GLOBALS['__bcc_apa']['hidden'] = [self::NOTICE_ACT];
        $service = self::service([self::NOTICE_ACT]);

        $missing = $service->listByFeedId('feed_' . self::MISSING_ACT, self::VIEWER, 'oldest', null, 20);
        $hidden  = $service->listByFeedId('feed_' . self::NOTICE_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertSame(self::NOT_FOUND_POST, $hidden);
        self::assertSame($missing, $hidden);
        self::assertSame([self::NOTICE_ACT . ':' . self::VIEWER], $GLOBALS['__bcc_apa']['calls']);
    }

    public function testANoticeTheViewerMaySeeGetsPastThePageCheck(): void
    {
        $this->seedNotice();

        $result = self::service([self::NOTICE_ACT])
            ->listByFeedId('feed_' . self::NOTICE_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertNotSame(self::NOT_FOUND_POST, $result);
    }

    public function testCommentingOnAHiddenNoticeIsTheMissingPosts404(): void
    {
        $this->seedNotice();
        $GLOBALS['__bcc_apa']['hidden'] = [self::NOTICE_ACT];
        // Past the page check the post's own audience refuses too, and that
        // refusal is the 403 a VISIBLE post keeps — so a create path that
        // skipped the page check would answer 403 here, not the 404.
        CommentDeleteFakes::$f02Hidden = [self::NOTICE_ACT];

        $result = self::service([self::NOTICE_ACT])->createComment('feed_' . self::NOTICE_ACT, self::VIEWER, 'hello');

        self::assertSame(self::NOT_FOUND_POST, $result);
        self::assertSame([self::NOTICE_ACT . ':' . self::VIEWER], $GLOBALS['__bcc_apa']['calls'], 'asked for the author');
    }

    private function seedNotice(): void
    {
        CommentDeleteFakes::$activities[self::NOTICE_ACT] = (object) [
            'act_id'          => self::NOTICE_ACT,
            'act_external_id' => self::NOTICE_POST,
            'act_module_id'   => 205,
            'act_status'      => 'publish',
        ];
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

    // ── F-265: a deleted or moderation-hidden post has no thread ─────

    public function testARemovedPostListsLikeAMissingOneEvenForAViewerWhoCouldSeeIt(): void
    {
        // A member: the membership gate would let them read.
        CommentDeleteFakes::$memberships[self::VIEWER][self::GROUP_ID] = 'member';

        $removed = self::service([self::PARENT_ACT], live: false)
            ->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertSame(self::NOT_FOUND_POST, $removed);
    }

    public function testARemovedPostListsLikeAMissingOneForAnonymousViewers(): void
    {
        unset(CommentDeleteFakes::$postMeta[self::PARENT_POST]['peepso_group_id']); // an open post

        $removed = self::service([self::PARENT_ACT], live: false)
            ->listByFeedId('feed_' . self::PARENT_ACT, 0, 'oldest', null, 20);

        self::assertSame(self::NOT_FOUND_POST, $removed);
    }

    public function testNobodyCanCommentOnARemovedPost(): void
    {
        $removed = self::service([self::PARENT_ACT], live: false)
            ->createComment('feed_' . self::PARENT_ACT, self::VIEWER, 'still here?');

        self::assertSame(self::NOT_FOUND_POST, $removed);
    }

    /** Control: the same create on a LIVE post gets past the liveness check to the membership gate. */
    public function testALivePostReachesTheMembershipGateOnCreate(): void
    {
        $live = self::service([self::PARENT_ACT])
            ->createComment('feed_' . self::PARENT_ACT, self::VIEWER, 'hello');

        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => 'You do not have permission to comment here.'],
            $live
        );
    }

    // ── F-461: the comment gate reads the group from the database ────
    //
    // Below, get_post_meta() says "no group"
    // while the database says the post is GROUP_ID's. The gate must follow
    // the database.

    private function putThePostInTheGroupInTheDatabase(): void
    {
        unset(CommentDeleteFakes::$postMeta[self::PARENT_POST]);
        CommentDeleteFakes::wpdb()->dbRows[self::PARENT_POST] = [
            PostGroupFactsWpdb::row('peepso_group_id', (string) self::GROUP_ID),
            PostGroupFactsWpdb::row('_bcc_post_visibility', 'members_only'),
        ];
    }

    public function testANonMemberCannotReadAThreadTheDatabasePutsInAGroup(): void
    {
        $this->putThePostInTheGroupInTheDatabase();

        $result = self::service([self::PARENT_ACT])
            ->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertSame(['error' => 'bcc_forbidden', 'message' => 'You do not have access to this discussion.'], $result);
        self::assertSame([], CommentDeleteFakes::$listReads, 'the thread was never read');
    }

    /**
     * What createComment() touches after the gate (comment-permission-stubs:
     * PeepSo's permission check allows, the Throttle records and DENIES), so a
     * create that gets past the gate ends at bcc_rate_limited, before any write.
     */
    private static function loadCreateSeatbelt(): void
    {
        require_once dirname(__DIR__) . '/Stubs/comment-permission-stubs.php';
        $GLOBALS['__bcc_cpr'] = ['perm' => true, 'perm_calls' => [], 'throttle_calls' => []];
    }

    public function testANonMemberCannotCommentWhereTheDatabasePutsTheThreadInAGroup(): void
    {
        self::loadCreateSeatbelt();
        $this->putThePostInTheGroupInTheDatabase();

        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => 'You do not have permission to comment here.'],
            self::service([self::PARENT_ACT])->createComment('feed_' . self::PARENT_ACT, self::VIEWER, 'hello')
        );
        self::assertSame([], $GLOBALS['__bcc_cpr']['throttle_calls'], 'refused by the gate, before the throttle');
    }

    /** ⭐ CONTROL: the same database row with the viewer a member reads the thread. */
    public function testAMemberStillReadsTheThreadTheDatabaseSaysIsTheirGroups(): void
    {
        $this->putThePostInTheGroupInTheDatabase();
        CommentDeleteFakes::$memberships[self::VIEWER][self::GROUP_ID] = 'member';

        $result = self::service([self::PARENT_ACT])
            ->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertTrue($result['ok'] ?? false);
        self::assertSame([self::PARENT_POST], CommentDeleteFakes::$listReads);
    }

    /** Unreadable group facts close the thread, even an ordinary post's (which would otherwise list). */
    public function testUnreadableGroupFactsCloseTheThread(): void
    {
        self::loadCreateSeatbelt();
        unset(CommentDeleteFakes::$postMeta[self::PARENT_POST]); // an ordinary post
        self::assertTrue(
            self::service([self::PARENT_ACT])->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20)['ok'] ?? false,
            'control: an ordinary visible post lists'
        );
        CommentDeleteFakes::$listReads = [];

        CommentDeleteFakes::wpdb()->failRead = true;

        $result = self::service([self::PARENT_ACT])
            ->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20);
        self::assertArrayHasKey('error', $result);
        self::assertSame([], CommentDeleteFakes::$listReads, 'the thread was never read');
        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => 'You do not have permission to comment here.'],
            self::service([self::PARENT_ACT])->createComment('feed_' . self::PARENT_ACT, self::VIEWER, 'hello')
        );
        self::assertSame([], $GLOBALS['__bcc_cpr']['throttle_calls'], 'refused by the gate, before the throttle');
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
