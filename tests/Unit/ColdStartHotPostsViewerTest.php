<?php
/**
 * F-276: a signed-in viewer's cold-start hot posts are read and hydrated
 * FOR THEM, not as a stranger.
 *
 * The cold-start panel renders its "What's been happening" posts as full
 * feed cards. They used to come from the shared hot page, which is
 * hydrated anonymously because one copy is cached for everyone, so a
 * signed-in viewer saw their own reaction as unset, no vouch state, and no
 * Report or Delete menu.
 *
 * What these pin is WHICH ARGUMENTS reach bcc-core and the hydration
 * pipeline, through the real FeedRankingService and the real
 * FeedColdStartService::composeHotPosts(). The gate itself is SQL in
 * bcc-core (see FeedPostVisibilityWiringTest's header for why a return
 * value cannot show it).
 *
 * ⭐ The anonymous cases are CONTROLS: a change that personalised
 * everything, or nothing, would fail one side.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Feed\FeedRankingService;
use BCC\Trust\Core\Services\FeedColdStartService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ColdStartHotPostsViewerTest extends TestCase
{
    private const VIEWER = 7;
    private const AUTHOR = 42;
    private const FRIEND = 99;

    /** FeedColdStartService::HOT_POSTS_LIMIT: the panel shows two posts. */
    private const SHOWN = 2;

    protected function setUp(): void
    {
        parent::setUp();
        // ⛔ Never alongside profile-access-stubs.php or
        // follower-wiring-stubs.php (see the stub's header).
        require_once __DIR__ . '/../Stubs/feed-f02-wiring-stubs.php';
        $GLOBALS['__bcc_f02feed'] = [
            'is_admin'         => false,
            'friend_ids'       => [],
            'calls'            => [],
            'group_rule'       => true,
            'group_rule_calls' => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_f02feed']);
        parent::tearDown();
    }

    private function ranking(): FeedRankingService
    {
        return new FeedRankingService(
            new \BCC\Core\Feed\ActivityFeedService(),
            new \BCC\Trust\Core\Repositories\ReputationRepository(),
            new \BCC\Trust\Core\Repositories\HiddenActivityRepository(),
            new \BCC\Trust\Core\Services\GroupContextResolver(),
            new \BCC\Trust\Core\Services\Feed\FeedHydrationPipeline(),
            new \BCC\Trust\Core\Services\FeedItemReportVisibility()
        );
    }

    /**
     * The real composeHotPosts(), over the real FeedRankingService. Only
     * its one collaborator is set; the rest of the cold-start payload is
     * not on this path.
     *
     * @return list<array<string, mixed>>
     */
    private function hotPosts(int $viewerId): array
    {
        $ref = new \ReflectionClass(FeedColdStartService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('feedRankingService')->setValue($svc, $this->ranking());

        /** @var list<array<string, mixed>> */
        return (new \ReflectionMethod(FeedColdStartService::class, 'composeHotPosts'))->invoke($svc, $viewerId);
    }

    /** @return array<string, mixed> the one getFeed() call, asserted to be the only one */
    private static function onlyRead(): array
    {
        $args = $GLOBALS['__bcc_f02feed']['getfeed_args'] ?? [];
        self::assertIsArray($args);
        self::assertCount(1, $args, 'expected exactly one candidate read');

        return $args[0];
    }

    // ── signed in: read and hydrated for the viewer ────────────────────

    public function testASignedInViewersHotPostsAreHydratedForThem(): void
    {
        $this->hotPosts(self::VIEWER);

        self::assertSame(
            [self::VIEWER],
            $GLOBALS['__bcc_f02feed']['hydrated_for'] ?? null,
            'viewer_reaction, vouch state and can_report/can_delete follow the hydrated viewer'
        );

        $read = self::onlyRead();
        self::assertSame(self::VIEWER, $read['viewerId'], 'core hydrates is_followed_by_viewer for this viewer too');
        self::assertSame(self::SHOWN, $read['limit'], 'an uncached read asks only for the posts the panel shows');
        self::assertNull($read['hashtag'], 'the hot feed, not a tag');
        self::assertNull($read['cursor']);
        self::assertNull($read['onlyForGroupId'], 'the global feed, with its public_all gate');
        self::assertSame('for_you', $read['scope']);
    }

    /**
     * ⚠ The per-viewer page must never reach the cache every reader
     * shares, in either direction: reading it would bring back the
     * anonymous hydration, and writing it would serve one viewer's page
     * to everyone.
     */
    public function testASignedInViewerNeverTouchesTheSharedHotCache(): void
    {
        $this->hotPosts(self::VIEWER);

        self::assertSame([], $GLOBALS['__bcc_f02feed']['cache_reads'] ?? [], 'no read of the shared hot page');
        self::assertSame([], $GLOBALS['__bcc_f02feed']['cache_writes'] ?? [], 'no write into it');
    }

    /**
     * §F-02, the hot feed's posture: enforced unconditionally, with no
     * admin exemption, as on /feed/hot and the tag feed.
     */
    public function testTheViewersHotPageIsGatedEvenForAnAdmin(): void
    {
        $GLOBALS['__bcc_f02feed']['is_admin']   = true;
        $GLOBALS['__bcc_f02feed']['friend_ids'] = [self::FRIEND];

        $this->hotPosts(self::VIEWER);

        $read = self::onlyRead();
        self::assertTrue($read['enforcePostVisibility'], 'an admin reads the hot feed gated, like everyone');
        self::assertSame([self::FRIEND], $read['viewerFriendIds']);
    }

    /** F-297: blocks either way are excluded in SQL, so the two shown stay full. */
    public function testTheViewersBlocksAreExcludedInSql(): void
    {
        $GLOBALS['__bcc_f02feed']['blocked']  = [501];
        $GLOBALS['__bcc_f02feed']['blockers'] = [502];

        $this->hotPosts(self::VIEWER);

        $excluded = self::onlyRead()['excludedAuthorIds'];
        self::assertIsArray($excluded);
        self::assertContains(501, $excluded, 'someone the viewer blocked');
        self::assertContains(502, $excluded, 'someone who blocked the viewer');
    }

    /**
     * F-269: the viewer is not shadow-limited from their own posts. Other
     * flagged authors still are. This is the one widening relative to the
     * anonymous page, the same as on the tag feed.
     */
    public function testAFlaggedViewerSeesTheirOwnPostsButNotOtherFlaggedAuthors(): void
    {
        $GLOBALS['__bcc_f02feed']['caution'] = [self::VIEWER, self::AUTHOR];

        $this->hotPosts(self::VIEWER);

        $excluded = self::onlyRead()['excludedAuthorIds'];
        self::assertIsArray($excluded);
        self::assertNotContains(self::VIEWER, $excluded);
        self::assertContains(self::AUTHOR, $excluded);
    }

    // ── anonymous: the shared page, unchanged ──────────────────────────

    public function testAnAnonymousViewerKeepsTheSharedHotPage(): void
    {
        $GLOBALS['__bcc_f02feed']['caution'] = [self::VIEWER, self::AUTHOR];

        $this->hotPosts(0);

        self::assertSame([0], $GLOBALS['__bcc_f02feed']['hydrated_for'] ?? null, 'hydrated anonymously, once, for the cache');

        $read = self::onlyRead();
        self::assertSame(0, $read['viewerId']);
        self::assertSame(FeedRankingService::HOT_WARM_LIMIT, $read['limit'], 'the warm-cron page size, so the warmed key is the one read');
        self::assertSame([self::VIEWER, self::AUTHOR], $read['excludedAuthorIds'], 'nobody is exempt from the caution list');

        $reads = $GLOBALS['__bcc_f02feed']['cache_reads'] ?? [];
        self::assertIsArray($reads);
        self::assertCount(1, $reads, 'the shared hot page is read through its cache');
        self::assertStringContainsString('hot:v1:' . FeedRankingService::HOT_WARM_LIMIT . ':', (string) $reads[0]);
    }

    /**
     * getHotFeedForViewer() with no viewer answers from the shared page,
     * so an anonymous caller can never build an uncached copy.
     */
    public function testTheViewerHotFeedSendsAnonymousToTheSharedPage(): void
    {
        $this->ranking()->getHotFeedForViewer(0, self::SHOWN);

        self::assertSame([0], $GLOBALS['__bcc_f02feed']['hydrated_for'] ?? null);
        self::assertSame(0, self::onlyRead()['viewerId']);
        self::assertNotSame([], $GLOBALS['__bcc_f02feed']['cache_reads'] ?? [], 'through the shared cache');
    }
}
