<?php
/**
 * F-438 / F-455: a viewer's exclusion list that could not be read is a
 * read-failure (the routes' 503), never a feed or a post served without it.
 *
 * Every per-viewer surface reads three lists before any post or page: the
 * §K1 block lists both ways, the §K1-C moderation-hidden list and the §O4.1
 * caution list (FeedRankingService::viewerExclusions()). Before:
 *   - a failed block read answered [] ("blocks nobody"), the SQL pre-filter
 *     dropped every block, and the exact per-page check then emptied the
 *     page: a list feed said 200 with no items, the permalink said 404;
 *   - a hidden list with no last-good copy threw RepositoryException, which
 *     no route caught: a 500.
 *
 * What this pins, surface by surface:
 *   1. a list that cannot be read throws ActivityReadFailedException, and NO
 *      post or page read runs first: the answer cannot depend on what the id
 *      or the feed holds (no existence oracle);
 *   2. the row-dependent check after the read (F-54's exact block check)
 *      still answers as if there were nothing: an EMPTY page or a 404, never
 *      the page with the filter skipped (security, F-455);
 *   3. a probed context is reused only for its own viewer, and every probe
 *      reads afresh (security: never cached across viewers or requests).
 *
 * The routes' catch (ActivityReadFailedException → FeedUnavailable) is pinned
 * by FeedReadFailureWiringTest, and end to end, on a real database, by
 * PagePostsIntegrationTest's F-438 section. The hot feed's shared cache is
 * HotFeedCacheIsolationTest's.
 *
 * SUBPROCESS-ONLY: the stubs declare real bcc-core and bcc-trust class names.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Contracts\ActivityReadFailedException;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use BCC\Trust\Core\Services\Feed\ViewerExclusions;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(FeedRankingService::class)]
#[CoversClass(ViewerExclusions::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeedExclusionReadFailureTest extends TestCase
{
    private const VIEWER = 7;
    private const OTHER  = 8;
    private const AUTHOR = 42;
    private const GROUP  = 1138;
    private const ACT    = 99;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/feed-f02-wiring-stubs.php';
        require_once __DIR__ . '/../Stubs/permalink-room-membership-stubs.php';
        require_once CoreSibling::require('src/Contracts/ActivityReadFailedException.php');
        $GLOBALS['__bcc_f02feed'] = [
            'is_admin'         => false,
            'friend_ids'       => [],
            'calls'            => [],
            'group_rule'       => true,
            'group_rule_calls' => [],
            'membership_calls' => [],
            'excluded_by_read' => [],
            'block_reads'      => [],
            'my_groups'        => [self::GROUP],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_f02feed']);
        parent::tearDown();
    }

    // ── 1. a list that cannot be read ───────────────────────────────────

    /**
     * Every per-viewer surface. Labels, not closures: a data provider's rows
     * are serialised into each test's subprocess.
     *
     * @return array<string, array{0: string}>
     */
    public static function surfaces(): array
    {
        return [
            'for you'          => ['for_you'],
            'following'        => ['following'],
            'signals'          => ['signals'],
            'My Groups'        => ['groups'],
            'tag feed'         => ['tag'],
            'cold-start hot'   => ['hot_viewer'],
            'author wall'      => ['author'],
            'page stream'      => ['page'],
            'group room'       => ['group'],
            'permalink'        => ['permalink'],
            'mention gate'     => ['can_view'],
        ];
    }

    #[DataProvider('surfaces')]
    public function testAnUnreadableBlockedListThrowsBeforeAnyRead(string $surface): void
    {
        $GLOBALS['__bcc_f02feed']['blocked_fails'] = true;

        $this->assertThrowsBeforeAnyRead($surface);
    }

    #[DataProvider('surfaces')]
    public function testAnUnreadableBlockerListThrowsBeforeAnyRead(string $surface): void
    {
        $GLOBALS['__bcc_f02feed']['blockers_fails'] = true;

        $this->assertThrowsBeforeAnyRead($surface);
    }

    /** It used to escape as RepositoryException, which no route caught: a 500. */
    #[DataProvider('surfaces')]
    public function testAnUnreadableHiddenListThrowsTheReadFailureBeforeAnyRead(string $surface): void
    {
        $GLOBALS['__bcc_f02feed']['hidden_throws'] = true;

        $e = $this->assertThrowsBeforeAnyRead($surface);
        self::assertInstanceOf(RepositoryException::class, $e->getPrevious(), 'the cause is kept for the log');
    }

    /**
     * F-454: the caution list's excluding reader serves its last-good copy
     * (24 h at most) and throws only when it has none; that throw is the
     * same read-failure, before any read.
     */
    #[DataProvider('surfaces')]
    public function testAnUnreadableCautionListThrowsTheReadFailureBeforeAnyRead(string $surface): void
    {
        $GLOBALS['__bcc_f02feed']['caution_throws'] = true;

        $e = $this->assertThrowsBeforeAnyRead($surface);
        self::assertInstanceOf(RepositoryException::class, $e->getPrevious(), 'the cause is kept for the log');
    }

    /** ANTI-VACUITY for the four above: with every list readable, each surface reads. */
    #[DataProvider('surfaces')]
    public function testWithEveryListReadableEachSurfaceReads(string $surface): void
    {
        $this->read($surface, self::VIEWER);

        self::assertNotSame([], $GLOBALS['__bcc_f02feed']['calls'], 'the surface read its posts');
        self::assertContains(['blocked', self::VIEWER], $GLOBALS['__bcc_f02feed']['block_reads']);
        self::assertContains(['blockers', self::VIEWER], $GLOBALS['__bcc_f02feed']['block_reads']);
    }

    /** The anonymous reader blocks no one: no block list is read, so none can fail it. */
    public function testTheAnonymousReaderReadsNoBlockList(): void
    {
        $GLOBALS['__bcc_f02feed']['blocked_fails']  = true;
        $GLOBALS['__bcc_f02feed']['blockers_fails'] = true;

        self::assertNotNull($this->service()->getActivityById(self::ACT, 0));
        $this->service()->getActivityForAuthor(self::AUTHOR, 0);

        self::assertSame([], $GLOBALS['__bcc_f02feed']['block_reads']);
    }

    /**
     * The anonymous hot feed's shared page reads the hidden list too. A
     * failed one throws, and nothing is written to the cache every reader
     * shares.
     */
    public function testTheAnonymousHotFeedThrowsAndCachesNothingWhenTheHiddenListIsUnreadable(): void
    {
        $GLOBALS['__bcc_f02feed']['hidden_throws'] = true;

        try {
            $this->service()->getHotFeed(null, FeedRankingService::HOT_WARM_LIMIT, 0);
            self::fail('the hot feed answered a page without the hidden list');
        } catch (ActivityReadFailedException) {
            // the route's 503
        }
        self::assertSame([], $GLOBALS['__bcc_f02feed']['cache_writes'] ?? [], 'nothing cached');
        self::assertSame([], $GLOBALS['__bcc_f02feed']['calls'], 'no page was read');

        unset($GLOBALS['__bcc_f02feed']['hidden_throws']);
        $this->service()->getHotFeed(null, FeedRankingService::HOT_WARM_LIMIT, 0);
        self::assertCount(1, $GLOBALS['__bcc_f02feed']['cache_writes'] ?? [], 'control: a good build is cached');
    }

    /**
     * F-454: the anonymous hot page excludes the WHOLE caution list. With no
     * usable copy of it, the build throws and nothing reaches the shared
     * cache; a later good build is cached.
     */
    public function testTheAnonymousHotFeedThrowsAndCachesNothingWhenTheCautionListIsUnreadable(): void
    {
        $GLOBALS['__bcc_f02feed']['caution_throws'] = true;

        try {
            $this->service()->getHotFeed(null, FeedRankingService::HOT_WARM_LIMIT, 0);
            self::fail('the hot feed answered a page without the caution list');
        } catch (ActivityReadFailedException) {
            // the route's 503
        }
        self::assertSame([], $GLOBALS['__bcc_f02feed']['cache_writes'] ?? [], 'nothing cached');
        self::assertSame([], $GLOBALS['__bcc_f02feed']['calls'], 'no page was read');

        unset($GLOBALS['__bcc_f02feed']['caution_throws']);
        $GLOBALS['__bcc_f02feed']['caution'] = [self::AUTHOR];
        $this->service()->getHotFeed(null, FeedRankingService::HOT_WARM_LIMIT, 0);
        self::assertSame([self::AUTHOR], $GLOBALS['__bcc_f02feed']['excluded']['getFeed:for_you'] ?? null, 'the list is applied');
        self::assertCount(1, $GLOBALS['__bcc_f02feed']['cache_writes'] ?? [], 'control: a good build is cached');
    }

    // ── 2. the check after the read ─────────────────────────────────────

    /**
     * ⚠ The race: the lists were read, then the exact check failed. The
     * permalink answers the 404 a missing id gets, never a 503 (security,
     * F-438).
     */
    public function testAFailedExactCheckOnThePermalinkIsANotFoundNotAThrow(): void
    {
        $GLOBALS['__bcc_f02feed']['row_enforced'] = self::row();
        $GLOBALS['__bcc_f02feed']['mutual_fails'] = true;

        self::assertNull($this->service()->getActivityById(self::ACT, self::VIEWER));
        self::assertFalse($this->service()->canView(self::ACT, self::VIEWER));

        unset($GLOBALS['__bcc_f02feed']['mutual_fails']);
        self::assertNotNull($this->service()->getActivityById(self::ACT, self::VIEWER), 'control: it opens');
    }

    /**
     * The list feeds, through both helpers: getFeed() reads on with
     * fetchPastBlockedPages(), the wall drops with dropMutuallyBlockedAuthors().
     * EMPTY, never the rows with the filter skipped.
     */
    public function testAFailedExactCheckOnTheListFeedsIsAnEmptyPageNeverTheUnfilteredOne(): void
    {
        $GLOBALS['__bcc_f02feed']['feed_rows']    = [self::row()];
        $GLOBALS['__bcc_f02feed']['mutual_fails'] = true;
        $s = $this->service();

        self::assertSame([], $s->getFeed(self::VIEWER, 'for_you')['items'], 'for you');
        self::assertSame([], $s->getActivityForAuthor(self::AUTHOR, self::VIEWER)['items'], 'author wall');

        unset($GLOBALS['__bcc_f02feed']['mutual_fails']);
        self::assertCount(1, $s->getFeed(self::VIEWER, 'for_you')['items'], 'control: the row is there');
        self::assertCount(1, $s->getActivityForAuthor(self::AUTHOR, self::VIEWER)['items'], 'control: the row is there');
    }

    // ── 3. one viewer, one request ──────────────────────────────────────

    /** A probed context is handed on: the lists are not read twice. */
    public function testAProbedContextIsReusedNotReRead(): void
    {
        $GLOBALS['__bcc_f02feed']['blocked'] = [self::AUTHOR];
        $GLOBALS['__bcc_f02feed']['row_enforced'] = self::row();
        $s = $this->service();

        $exclusions = $s->viewerExclusions(self::VIEWER);
        $GLOBALS['__bcc_f02feed']['block_reads'] = [];

        self::assertNull($s->getActivityById(self::ACT, self::VIEWER, $exclusions), 'its block applies');
        self::assertSame([], $GLOBALS['__bcc_f02feed']['block_reads'], 'and nothing was re-read');
        self::assertContains(self::AUTHOR, $GLOBALS['__bcc_f02feed']['excluded_by_read'][0] ?? []);
    }

    /**
     * ⚠ A context probed for ANOTHER viewer is never applied: this viewer's
     * own lists are read, and their block holds although the other viewer
     * blocks no one.
     */
    public function testAContextProbedForAnotherViewerIsNotApplied(): void
    {
        $GLOBALS['__bcc_f02feed']['blocked_by_viewer'] = [self::OTHER => [], self::VIEWER => [self::AUTHOR]];
        $GLOBALS['__bcc_f02feed']['row_enforced'] = self::row();
        $s = $this->service();

        $theirs = $s->viewerExclusions(self::OTHER);
        $GLOBALS['__bcc_f02feed']['block_reads'] = [];

        self::assertNull($s->getActivityById(self::ACT, self::VIEWER, $theirs), "this viewer's block holds");
        self::assertContains(['blocked', self::VIEWER], $GLOBALS['__bcc_f02feed']['block_reads'], 're-read for this viewer');
        self::assertNotNull($s->getActivityById(self::ACT, self::OTHER, $theirs), 'control: it opens for the other viewer');
    }

    /** Every probe reads afresh, so a block made since the last one applies. */
    public function testEveryProbeReadsAfresh(): void
    {
        $s = $this->service();

        self::assertSame([], $s->viewerExclusions(self::VIEWER)->blockedAuthorIds());
        $GLOBALS['__bcc_f02feed']['blocked'] = [self::AUTHOR];
        self::assertSame([self::AUTHOR], $s->viewerExclusions(self::VIEWER)->blockedAuthorIds());
        self::assertCount(4, $GLOBALS['__bcc_f02feed']['block_reads'], 'two lists, read on each probe');
    }

    /** The parts: the caution list never holds the viewer (F-269); blocks both ways. */
    public function testTheContextKeepsItsPartsApartAndMergesThemOnce(): void
    {
        $GLOBALS['__bcc_f02feed']['caution']  = [self::AUTHOR, self::VIEWER, 50];
        $GLOBALS['__bcc_f02feed']['blocked']  = [50, 60];
        $GLOBALS['__bcc_f02feed']['blockers'] = [70];
        $GLOBALS['__bcc_f02feed']['hidden']   = [900, 901];

        $x = $this->service()->viewerExclusions(self::VIEWER);

        self::assertSame(self::VIEWER, $x->viewerId());
        self::assertSame([self::AUTHOR, 50], $x->cautionedAuthorIds());
        self::assertSame([50, 60, 70], $x->blockedAuthorIds());
        self::assertSame([self::AUTHOR, 50, 60, 70], $x->excludedAuthorIds());
        self::assertSame([900, 901], $x->hiddenActivityIds());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function service(): FeedRankingService
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

    /** One surface, read as $viewerId. */
    private function read(string $surface, int $viewerId): mixed
    {
        $s = $this->service();

        return match ($surface) {
            'for_you', 'following', 'signals', 'groups' => $s->getFeed($viewerId, $surface),
            'tag'        => $s->getTagFeed($viewerId, 'solana'),
            'hot_viewer' => $s->getHotFeedForViewer($viewerId, 2),
            'author'     => $s->getActivityForAuthor(self::AUTHOR, $viewerId),
            'page'       => $s->getActivityForPage(5, $viewerId),
            'group'      => $s->getGroupFeed($viewerId, self::GROUP),
            'permalink'  => $s->getActivityById(self::ACT, $viewerId),
            'can_view'   => $s->canView(self::ACT, $viewerId),
        };
    }

    /** The read-failure, and no post or page read before it. */
    private function assertThrowsBeforeAnyRead(string $surface): ActivityReadFailedException
    {
        try {
            $this->read($surface, self::VIEWER);
        } catch (ActivityReadFailedException $e) {
            self::assertSame([], $GLOBALS['__bcc_f02feed']['calls'], 'no post or page was read first');

            return $e;
        }

        self::fail($surface . ' answered without the list it could not read');
    }

    /** @return array<string, mixed> */
    private static function row(): array
    {
        return [
            'id'          => 'feed_' . self::ACT,
            'external_id' => \BCC_F02FEED_DEFAULT_POST,
            'author'      => ['kind' => 'user', 'id' => self::AUTHOR],
        ];
    }
}
