<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Services\FeedColdStartService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-78: the cold-start panel never surfaces a banned, private or blocked
 * member. Candidate selection is recency only and used to reach the
 * panel unfiltered; it now goes through the members directory's
 * privacy gate (PeepSoUserSearch via MentionPolicy) before the shuffle.
 *
 * ⚠ The doubles (a fake UserSyncRepository and PeepSoUserSearch) are
 * required from setUp(), in this test's own child process. They used to be
 * declared at the top of this file, which PHPUnit's collection runs in the
 * MAIN process. That made them THE classes there for every other test, and
 * a test that needed the real UserSyncRepository failed in CI.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ColdStartVisibilityTest extends TestCase
{
    private const VIEWER = 5101;

    protected function setUp(): void
    {
        // Doubles first: they must exist before the real classes autoload.
        require_once dirname(__DIR__) . '/Stubs/user-sync-recent-stub.php';
        require_once dirname(__DIR__) . '/Stubs/cold-start-visibility-stubs.php';

        UserSyncRepository::$recent = [11, 12, 13, 14];
        \PeepSoUserSearch::$hidden  = [12 => true];          // e.g. banned
        \PeepSoUserSearch::$blocked = [self::VIEWER => [13]]; // blocked either way
        \PeepSoUserSearch::$calls   = [];
    }

    /** @return list<int> */
    private function candidates(int $viewerId): array
    {
        $svc = (new \ReflectionClass(FeedColdStartService::class))->newInstanceWithoutConstructor();
        /** @var list<int> */
        return (new \ReflectionMethod(FeedColdStartService::class, 'visibleOperatorCandidates'))->invoke($svc, $viewerId);
    }

    public function testBannedPrivateAndBlockedMembersAreNeverCandidates(): void
    {
        self::assertSame([11, 14], $this->candidates(self::VIEWER));
        self::assertSame([['include' => [11, 12, 13, 14], 'viewer' => self::VIEWER]], \PeepSoUserSearch::$calls, 'the gate sees every candidate, for this viewer');
    }

    public function testAnAnonymousViewerStillGetsTheHiddenFilter(): void
    {
        self::assertSame([11, 13, 14], $this->candidates(0), 'blocks need a viewer; banned/private never show');
        self::assertNull(\PeepSoUserSearch::$calls[0]['viewer']);
    }

    public function testHotPostsAreComposedForTheViewer(): void
    {
        // The payload hands composeHotPosts() the viewer. What it then
        // reads, and for whom it is hydrated (blocks dropped, F-276
        // viewer chrome), is proven behaviourally in
        // ColdStartHotPostsViewerTest.
        $source = file_get_contents(__DIR__ . '/../../app/Domain/Core/Services/FeedColdStartService.php');
        self::assertIsString($source);
        self::assertStringContainsString("'hot_posts'        => \$this->composeHotPosts(\$viewerIdInt),", $source);
    }
}
