<?php
/**
 * §F-02, posts half — the profile BLOG TAB opts in to bcc-core's
 * per-post visibility gate, with the SESSION viewer.
 *
 * Why this surface gets its own file: `BlogService::getUserBlog()` reads
 * post content straight from `PeepSoActivityRepository::getActivities()`
 * and never passes through `FeedRankingService`. Wiring the seven feed
 * call sites therefore leaves it untouched — and it is the one surface
 * built specifically to LIST an author's long-form posts, so missing it
 * would leave the gate looking complete while the most content-rich
 * per-author read stayed open.
 *
 * ⚠ The viewer must be the SESSION id, never the `$viewerId` argument.
 * The service already logs-and-ignores a mismatching argument (its own
 * comment explains why), and §F-02 inherits that: the gate decides whose
 * friend list applies and whose own posts are exempt, so honouring a
 * caller-supplied viewer would let a caller pick the identity the gate
 * runs as.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\BlogService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlogTabPostVisibilityWiringTest extends TestCase
{
    private const AUTHOR  = 42;
    private const SESSION = 7;
    private const FRIEND  = 99;

    protected function setUp(): void
    {
        parent::setUp();
        // ⛔ Never alongside feed-f02-wiring-stubs.php or
        // profile-access-stubs.php. See the stub's header.
        require_once __DIR__ . '/../Stubs/blog-f02-wiring-stubs.php';
        $GLOBALS['__bcc_f02blog'] = [
            'is_admin'   => false,
            'friends_by' => [],
            'session'    => self::SESSION,
            'calls'      => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_f02blog']);
        parent::tearDown();
    }

    private function service(): BlogService
    {
        return new BlogService(new \BCC\Trust\Core\Repositories\HiddenActivityRepository());
    }

    private function onlyCall(): array
    {
        $calls = $GLOBALS['__bcc_f02blog']['calls'] ?? [];
        self::assertIsArray($calls);
        self::assertCount(1, $calls, 'expected exactly one blog query');

        return $calls[0];
    }

    public function testTheBlogTabEnforcesTheGateForTheSessionViewer(): void
    {
        $GLOBALS['__bcc_f02blog']['friends_by'] = [self::SESSION => [self::FRIEND]];

        $this->service()->getUserBlog(self::AUTHOR, self::SESSION);
        $call = $this->onlyCall();

        self::assertTrue($call['enforce'], 'the §F-02 gate must be switched on');
        self::assertSame(self::SESSION, $call['viewer']);
        self::assertSame([self::FRIEND], $call['friends']);
    }

    /**
     * ⚠⚠ The gate runs as the SESSION, even when the caller names
     * somebody else. A caller-chosen viewer would choose whose friend
     * list and whose own-post exemption apply — i.e. it would let the
     * caller pick how much of a restricted author's wall to unlock.
     *
     * The argument here is deliberately a DIFFERENT user from the
     * session, which is exactly the case the service logs and ignores.
     */
    public function testACallerSuppliedViewerDoesNotBecomeTheGateIdentity(): void
    {
        // ⚠ The two users have DIFFERENT friend lists, and that is what
        // makes the swap observable at all: the recorded `viewer` keeps
        // coming from the session either way, so a gate computed for the
        // caller's id shows up only in the friend list it carries. With
        // one flat list for everybody this test passed while the defect
        // was present — the mutation control caught that, not review.
        $GLOBALS['__bcc_f02blog']['friends_by'] = [
            self::SESSION => [self::FRIEND],
            4242          => [11, 12, 13],
        ];

        $this->service()->getUserBlog(self::AUTHOR, 4242);
        $call = $this->onlyCall();

        self::assertSame(self::SESSION, $call['viewer']);
        self::assertSame(
            [self::FRIEND],
            $call['friends'],
            "the gate must run on the session viewer's friend list, not the caller's"
        );
    }

    /**
     * Control: an admin reads the tab unenforced, same as every other
     * per-viewer surface. Without this, a hard-coded `true` would pass
     * the test above and silently drop the exemption the profile half of
     * F-02 grants.
     */
    public function testAnAdminReadsTheBlogTabUnenforced(): void
    {
        $GLOBALS['__bcc_f02blog']['is_admin'] = true;

        $this->service()->getUserBlog(self::AUTHOR, self::SESSION);

        self::assertFalse($this->onlyCall()['enforce']);
    }

    /**
     * An anonymous reader is a friend of nobody and still enforces.
     */
    public function testAnAnonymousReaderEnforcesWithNoFriendList(): void
    {
        $GLOBALS['__bcc_f02blog']['session'] = 0;

        $this->service()->getUserBlog(self::AUTHOR, 0);
        $call = $this->onlyCall();

        self::assertTrue($call['enforce']);
        self::assertSame(0, $call['viewer']);
        self::assertNull($call['friends']);
    }
}
