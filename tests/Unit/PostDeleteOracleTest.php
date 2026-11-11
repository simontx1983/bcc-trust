<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Core\Services\PostsService;
use BCC\Trust\Tests\Stubs\CommentDeleteFakes;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-47: `DELETE /posts/:feed_id` must not confirm that someone else's hidden
 * post exists.
 *
 * Before, a non-owner got 403 "You can only delete your own posts." for any
 * existing activity, but 404 for a missing one, so a 403 confirmed the id was
 * a post, including one in a secret group. Now a post the caller cannot see
 * at all ({@see FeedItemReportVisibility::canSee()}) gets the missing-id 404.
 * A post they can see keeps the 403. Nothing is trashed in either case.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PostDeleteOracleTest extends TestCase
{
    private const OWNER   = 9;
    private const VIEWER  = 10;
    private const ACT     = 123;
    private const MISSING = 999;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/post-delete-stubs.php';
        CommentDeleteFakes::reset();
        $GLOBALS['__bcc_trashed'] = [];

        CommentDeleteFakes::$activities[self::ACT] = (object) [
            'act_id'          => self::ACT,
            'act_user_id'     => self::OWNER,
            'act_external_id' => 555,
            'act_module_id'   => 1,
            'act_status'      => 'publish',
        ];
    }

    private static function service(bool $visible): PostsService
    {
        $visibility = new class ($visible) extends FeedItemReportVisibility {
            public function __construct(private bool $visible)
            {
            }

            public function canSee(int $viewerId, object $activity): bool
            {
                return $this->visible;
            }
        };

        $class   = new ReflectionClass(PostsService::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('visibility')->setValue($service, $visibility);

        return $service;
    }

    public function testSomeoneElsesHiddenPostDeletesExactlyLikeAMissingOne(): void
    {
        $service = self::service(false);

        $missing = $service->deletePost('feed_' . self::MISSING, self::VIEWER);
        $hidden  = $service->deletePost('feed_' . self::ACT, self::VIEWER);

        self::assertSame(['error' => 'bcc_not_found', 'message' => 'Post not found.'], $missing);
        self::assertSame($missing, $hidden, 'a hidden post must be indistinguishable from a missing one');
        self::assertSame([], $GLOBALS['__bcc_trashed']);
    }

    public function testSomeoneElsesVisiblePostKeepsThe403(): void
    {
        $result = self::service(true)->deletePost('feed_' . self::ACT, self::VIEWER);

        self::assertSame(['error' => 'bcc_forbidden', 'message' => 'You can only delete your own posts.'], $result);
        self::assertSame([], $GLOBALS['__bcc_trashed']);
    }

    /** The owner is never sent through the visibility check. */
    public function testTheOwnerReachesTheDelete(): void
    {
        self::service(false)->deletePost('feed_' . self::ACT, self::OWNER);

        self::assertSame([555], $GLOBALS['__bcc_trashed']);
    }
}
