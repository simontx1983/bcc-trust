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
        // F-361 — deletePost()'s trash is now inside
        // PeepSoAddPostGuard::pinOnly(), so the REAL guard has to be
        // present. The loader THROWS rather than skipping, because a skip
        // would turn the pin assertion below into a false green.
        require_once dirname(__DIR__) . '/Stubs/peepso-add-post-guard-real.php';
        CommentDeleteFakes::reset();
        $GLOBALS['__bcc_trashed']      = [];
        $GLOBALS['__bcc_pin_at_write'] = [];

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

    // ── a COMMENT act id is not a post on this route ────────────────

    /**
     * ⚠⚠ TWO bugs in one, and the module check above cannot see either,
     * because PeepSo stores `act_module_id = 1` on a comment — the same
     * module a status post carries.
     *
     * 1. THE OWNER could trash their own COMMENT through the POST route.
     *    That skips `DELETE /comments/{id}` entirely: no comment-delete
     *    event, so the Rank evidence ledger never reverses the comment.
     * 2. A NON-OWNER got `bcc_forbidden` instead of the 404 this whole file
     *    exists to enforce — because `canSee()` judges a comment on its own
     *    row, and a comment's wp_post carries no `peepso_group_id`, so the
     *    group gate it relies on reads "not group-tagged". 403-vs-404 then
     *    told a stranger that a comment exists inside a group they cannot
     *    see, which is the exact oracle F-47 closed for posts.
     */

    /**
     * ⭐ F-361 BEHAVIOURAL PIN TEST — a member trashing their OWN post.
     *
     * `wp_trash_post()` calls `wp_update_post()` in its own body, which
     * calls `wp_insert_post()`, which fires the `wp_insert_post` action —
     * so moods (moods.php:47), location (location.php:90) and the
     * anonymous-OP marker all read THIS member's request on a delete.
     *
     * ⚠ Asserted from a snapshot taken INSIDE the write. The guard
     * restores the request in a `finally`, so an assertion after the call
     * sees the original whether the pin ran or not — the ordering is the
     * whole property.
     *
     * ⚠ `save_mood`'s "no mood, no touch" guard does not protect a trash:
     * the action passes `$post`, so only the DELETE-meta branch is
     * skipped while `else if ($mood)` still fires.
     */
    public function testTheOwnerDeleteTrashIsPinned(): void
    {
        $_GET = $_POST = $_REQUEST = [
            'mood'     => '7',
            'location' => 'somewhere else',
            'anon_id'  => '41',
            'group_id' => '900',
        ];

        self::service(true)->deletePost('feed_' . self::ACT, self::OWNER);

        self::assertNotSame([], $GLOBALS['__bcc_trashed'], 'precondition: the post was trashed');

        /** @var list<array{fn: string, get: array<string, mixed>, post: array<string, mixed>}> $writes */
        $writes = $GLOBALS['__bcc_pin_at_write'];
        self::assertNotEmpty($writes, 'the write never ran — the fixture is not measuring anything');

        foreach ($writes as $w) {
            self::assertSame([], $w['get'], $w['fn'] . ': $_GET must be empty — PeepSoInput reads it FIRST');
            // ⚠ PINNED, NOT UNSET: PeepSoInput::int() falls back to the RAW
            // request body for an absent key, so unsetting `mood`/`anon_id`
            // would OPEN that leg rather than close it.
            self::assertArrayHasKey('mood', $w['post'], $w['fn'] . ': mood must be pinned, not unset');
            self::assertSame(0, $w['post']['mood'], $w['fn'] . ': mood neutral during the write');
            self::assertSame(0, $w['post']['anon_id'], $w['fn'] . ': anon_id neutral during the write');
            self::assertSame('', $w['post']['location'], $w['fn'] . ': location neutral during the write');
            self::assertSame(0, $w['post']['group_id'], $w['fn'] . ': group_id neutral during the write');
        }
    }

    public function testACommentActIdIsNotDeletableThroughThePostRoute(): void
    {
        CommentDeleteFakes::$commentActs = [self::ACT];

        // The OWNER: refused, and nothing is trashed.
        $owner = self::service(true)->deletePost('feed_' . self::ACT, self::OWNER);
        self::assertSame(['error' => 'bcc_not_found', 'message' => 'Post not found.'], $owner);
        self::assertSame([], $GLOBALS['__bcc_trashed'], 'a comment must not be trashed by the post route');

        // A NON-OWNER who CAN see it: the 404, not the 403 — so the oracle
        // this file closes for posts is closed for comments too.
        $stranger = self::service(true)->deletePost('feed_' . self::ACT, self::VIEWER);
        self::assertSame(['error' => 'bcc_not_found', 'message' => 'Post not found.'], $stranger);
        self::assertSame(
            $stranger,
            self::service(true)->deletePost('feed_' . self::MISSING, self::VIEWER),
            'byte-identical to a missing id'
        );
        self::assertSame([], $GLOBALS['__bcc_trashed']);
    }

    /**
     * ⭐ The control. The SAME act, SAME module, SAME visibility — only the
     * comment row is gone. Without this, the test above would be satisfied
     * by a route that refused everything.
     */
    public function testTheSameActIsStillDeletableWhenItIsNotAComment(): void
    {
        CommentDeleteFakes::$commentActs = [];

        self::service(true)->deletePost('feed_' . self::ACT, self::OWNER);

        // ⚠ Asserted on the TRASH, not on the return, exactly as
        // testTheOwnerReachesTheDelete does: this harness stubs the route
        // only as far as the delete, so deletePost still reports an error
        // from a later step it cannot complete. What matters here is that
        // the act reached the delete at all.
        self::assertSame([555], $GLOBALS['__bcc_trashed'], 'the owner must still reach the delete');
    }

    /** The owner is never sent through the visibility check. */
    public function testTheOwnerReachesTheDelete(): void
    {
        self::service(false)->deletePost('feed_' . self::ACT, self::OWNER);

        self::assertSame([555], $GLOBALS['__bcc_trashed']);
    }
}
