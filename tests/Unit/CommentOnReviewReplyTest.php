<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Services\CommentService;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Tests\Stubs\CommentDeleteFakes;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-345 review (LOW-2): a review reply's feed_id is NOT a post, so the
 * comment routes answer it exactly like a missing id, for list AND create.
 *
 * This is the path that was the real hole: a reply (module 206) is a live,
 * reportable activity, so the liveness and visibility rules alone admitted
 * it, and anyone could open a thread under it. FeedItemGateTest pins the
 * gate's call sites by source text; this pins the BEHAVIOUR.
 *
 * The feed allowlist is bcc-core's REAL FeedItemNormalizer (loaded from the
 * sibling checkout before the stubs, so the stubs' double is skipped): "206
 * is not a feed module" is bcc-core's own answer here. The control is the
 * same parent as an ordinary status post (module 1): it gets past the
 * module check to the membership gate's 403.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CommentOnReviewReplyTest extends TestCase
{
    private const VIEWER      = 10;
    private const PARENT_ACT  = 123;
    private const PARENT_POST = 555;
    private const GROUP_ID    = 4231;

    private const STATUS_MODULE       = 1;
    private const REVIEW_REPLY_MODULE = 206;

    private const NOT_FOUND_POST = ['error' => 'bcc_not_found', 'message' => 'Post not found.'];

    protected function setUp(): void
    {
        require_once CoreSibling::require('src/Feed/FeedItemNormalizer.php');
        require_once dirname(__DIR__) . '/Stubs/comment-delete-stubs.php';
        CommentDeleteFakes::reset();
    }

    /** A live parent the viewer can see, in a group they are not a member of. */
    private static function parent(int $moduleId, bool $groupTagged = true): void
    {
        CommentDeleteFakes::$activities[self::PARENT_ACT] = (object) [
            'act_id'          => self::PARENT_ACT,
            'act_external_id' => self::PARENT_POST,
            'act_module_id'   => $moduleId,
            'act_status'      => 'publish',
            'act_owner_id'    => 9,
        ];
        if ($groupTagged) {
            CommentDeleteFakes::$postMeta[self::PARENT_POST]['peepso_group_id'] = (string) self::GROUP_ID;
        }
    }

    private static function service(): CommentService
    {
        // Visible and live: only the module rule can refuse.
        $visibility = new class extends FeedItemReportVisibility {
            public function __construct()
            {
            }

            public function canSee(int $viewerId, object $activity): bool
            {
                return true;
            }

            public function isLive(object $activity): bool
            {
                return true;
            }
        };

        $class   = new ReflectionClass(CommentService::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('visibility')->setValue($service, $visibility);
        $class->getProperty('commentRepo')->setValue($service, new CommentRepository());

        return $service;
    }

    public function testTheBundledAllowlistIsBccCoresAndLeavesOutReviewReplies(): void
    {
        self::assertTrue(class_exists(\BCC\Core\Feed\FeedItemNormalizer::class, false));
        self::assertNotContains(self::REVIEW_REPLY_MODULE, \BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds());
        self::assertContains(self::STATUS_MODULE, \BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds());
    }

    // ── GET /posts/:feed_id/comments ─────────────────────────────────

    public function testAStatusPostReachesTheMembershipGateOnList(): void
    {
        self::parent(self::STATUS_MODULE);

        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => 'You do not have access to this discussion.'],
            self::service()->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20)
        );
    }

    public function testAReviewReplyHasNoThreadToList(): void
    {
        self::parent(self::REVIEW_REPLY_MODULE);
        self::assertSame(self::NOT_FOUND_POST, self::service()->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20));
        self::assertSame(self::NOT_FOUND_POST, self::service()->listByFeedId('feed_' . self::PARENT_ACT, 0, 'oldest', null, 20), 'anonymous too');
    }

    public function testAnUngroupedReviewReplyHasNoThreadToListEither(): void
    {
        self::parent(self::REVIEW_REPLY_MODULE, false);

        self::assertSame(self::NOT_FOUND_POST, self::service()->listByFeedId('feed_' . self::PARENT_ACT, self::VIEWER, 'oldest', null, 20));
    }

    // ── POST /posts/:feed_id/comments ────────────────────────────────

    public function testAStatusPostReachesTheMembershipGateOnCreate(): void
    {
        self::parent(self::STATUS_MODULE);

        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => 'You do not have permission to comment here.'],
            self::service()->createComment('feed_' . self::PARENT_ACT, self::VIEWER, 'hello')
        );
    }

    public function testNobodyCanCommentOnAReviewReply(): void
    {
        self::parent(self::REVIEW_REPLY_MODULE);
        self::assertSame(self::NOT_FOUND_POST, self::service()->createComment('feed_' . self::PARENT_ACT, self::VIEWER, 'hello'));

        self::parent(self::REVIEW_REPLY_MODULE, false);
        unset(CommentDeleteFakes::$postMeta[self::PARENT_POST]['peepso_group_id']);
        self::assertSame(self::NOT_FOUND_POST, self::service()->createComment('feed_' . self::PARENT_ACT, self::VIEWER, 'hello'), 'grouped or not');
    }
}
