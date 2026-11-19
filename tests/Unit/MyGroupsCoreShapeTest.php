<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Contracts\ActivityReadFailedException;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-416 deploy order: bcc-core first. Until it deploys, "My Groups" must
 * answer the retryable 503, not a fatal (security review, F-416).
 *
 * getMyGroupsFeed() calls core API that only F-416 adds
 * (getActiveMemberGroupIdsOrFail(), MEMBER_GROUPS_CAP, getFeed()'s new
 * parameters). Against an older core that was an uncaught Error: a 500 on
 * `scope=groups` plus WordPress's recovery-mode email. Now it checks the core
 * first and throws ActivityReadFailedException, which every feed route
 * already answers with FeedUnavailable's 503 `bcc_unavailable`
 * (FeedReadFailureWiringTest pins the catch; MyGroupsFeedIntegrationTest's
 * failed-membership case runs the same exception through the real endpoint).
 *
 * Each test runs in its own process with bcc-core in one shape
 * (tests/Stubs/my-groups-core-shape-stubs.php). The F-416 shape is the
 * control: without it, a check that refused every core would pass the first
 * test too.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MyGroupsCoreShapeTest extends TestCase
{
    private const VIEWER = 42;

    /** @return array<string, mixed> */
    private function myGroups(bool $coreHasF416): array
    {
        $GLOBALS['__bcc_mgcore'] = ['f416' => $coreHasF416, 'asked' => []];
        require_once __DIR__ . '/../Stubs/my-groups-core-shape-stubs.php';
        require_once CoreSibling::require('src/Contracts/ActivityReadFailedException.php');

        // Nothing before the membership read touches a collaborator.
        $service = (new \ReflectionClass(FeedRankingService::class))->newInstanceWithoutConstructor();

        return $service->getFeed(self::VIEWER, 'groups', null, 20);
    }

    public function testAnOlderCoreAnswersTheRetryableReadFailureNotAFatal(): void
    {
        try {
            $this->myGroups(false);
            self::fail('an older core must refuse the scope with ActivityReadFailedException');
        } catch (ActivityReadFailedException $e) {
            self::assertStringContainsString('predates', $e->getMessage());
        }
        self::assertSame([], $GLOBALS['__bcc_mgcore']['asked'], 'no call into API the core does not have');
    }

    public function testACoreWithF416IsAskedForTheMembership(): void
    {
        $page = $this->myGroups(true);

        self::assertSame([[self::VIEWER, 200]], $GLOBALS['__bcc_mgcore']['asked']);
        self::assertSame(
            ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]],
            $page,
            'a member of no group gets the empty page, not the read failure'
        );
    }
}
