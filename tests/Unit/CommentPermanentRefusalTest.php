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
 * F-274: a comment PeepSo will never accept is refused as what it is.
 *
 * add_comment() returns FALSE when the post has comments turned off or when
 * PeepSo::check_permissions(owner, PERM_COMMENT, author) refuses (the
 * owner's block list, comment privacy). CommentService mapped every FALSE to
 * 503 "Could not post comment. Try again.", and each retry spent a slot of
 * the burst throttle, so the user ended at 429.
 *
 * Now both are checked with PeepSo's own rules before the throttle: 403 with
 * its own message, no throttle spent, no write. The throttle double DENIES,
 * so a request that gets past the check visibly stops there (control).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CommentPermanentRefusalTest extends TestCase
{
    private const AUTHOR      = 10;
    private const OWNER       = 9;
    private const PARENT_ACT  = 123;
    private const PARENT_POST = 555;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/comment-delete-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/comment-permission-stubs.php';
        CommentDeleteFakes::reset();
        $GLOBALS['__bcc_cpr'] = ['perm' => true, 'perm_calls' => [], 'throttle_calls' => []];

        // An open (non-group) post owned by OWNER.
        CommentDeleteFakes::$activities[self::PARENT_ACT] = (object) [
            'act_id'          => self::PARENT_ACT,
            'act_external_id' => self::PARENT_POST,
            'act_owner_id'    => self::OWNER,
            'act_module_id'   => 1,
            'act_status'      => 'publish',
        ];
    }

    public function testCommentsTurnedOffIsA403WithItsOwnMessage(): void
    {
        CommentDeleteFakes::$postMeta[self::PARENT_POST]['peepso_disable_comments'] = '1';

        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => 'Comments are turned off for this post.'],
            $this->create()
        );
        self::assertSame([], $GLOBALS['__bcc_cpr']['throttle_calls'], 'a permanent no spends no throttle slot');
    }

    public function testAPermissionRefusalIsA403ThatDoesNotSayWhy(): void
    {
        $GLOBALS['__bcc_cpr']['perm'] = false; // e.g. the owner blocked the author

        self::assertSame(
            ['error' => 'bcc_forbidden', 'message' => "You can't comment on this post."],
            $this->create()
        );
        self::assertSame([[self::OWNER, 'comment', self::AUTHOR]], $GLOBALS['__bcc_cpr']['perm_calls'], "PeepSo's own rule, owner first");
        self::assertSame([], $GLOBALS['__bcc_cpr']['throttle_calls']);
    }

    public function testRetryingARefusalNeverReachesTheThrottle(): void
    {
        $GLOBALS['__bcc_cpr']['perm'] = false;

        for ($i = 0; $i < 5; $i++) {
            self::assertSame('bcc_forbidden', $this->create()['error']);
        }
        self::assertSame([], $GLOBALS['__bcc_cpr']['throttle_calls']);
    }

    /** Control: an allowed comment passes the check and reaches the throttle (denied by the double). */
    public function testAnAllowedCommentGetsPastTheCheck(): void
    {
        self::assertSame('bcc_rate_limited', $this->create()['error']);
        self::assertCount(1, $GLOBALS['__bcc_cpr']['throttle_calls']);
    }

    /** @return array<string, mixed> */
    private function create(): array
    {
        return $this->service()->createComment('feed_' . self::PARENT_ACT, self::AUTHOR, 'hello there');
    }

    private function service(): CommentService
    {
        $visibility = new class () extends FeedItemReportVisibility {
            public function __construct()
            {
            }

            public function canSee(int $viewerId, object $activity): bool
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
}
