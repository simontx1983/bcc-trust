<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Feed\ActivityFeedService;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use BCC\Trust\Core\Services\GroupContextResolver;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A SECRET page's announcement, on every feed that reads activity rows.
 *
 * §4.32.8 projects a published announcement as ONE activity (module 205),
 * written as the operator's own post. Nothing on the row names the page, so
 * the secret-page rule never reached it: a secret page's notice was on every
 * feed, the operator's wall, /feed/hot and the permalink, for anyone.
 *
 * Now a viewer the page does not admit (PageAccess: its author, then admins,
 * then members and invitees) never gets the notice:
 *   - the list feeds, the tag feed and the wall drop it, and a page that
 *     drop empties is read past (F-297) — through EVERY page filter;
 *   - /feed/hot drops it AFTER the shared anonymous cache, per request, so
 *     the cache keeps it, members see it, and a page made secret is hidden on
 *     the next request;
 *   - the permalink answers the missing post's null (the endpoint's 404).
 *
 * REAL: FeedRankingService, AnnouncementPageAccess,
 * AnnouncementRepository::pageIdsFor(), PageAccess, PageDiscoveryRepository,
 * ProfileAccess, bcc-core's PeepSoPagePrivacy. Doubles:
 * tests/Stubs/secret-page-list-stubs.php (the privacy `$wpdb`) and
 * tests/Stubs/secret-page-feed-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecretPageFeedProjectionTest extends TestCase
{
    private const STRANGER = 5;
    private const MEMBER   = 6;
    private const OPERATOR = 70;
    private const POSTER   = 42;

    private const SECRET = 900;
    private const OPEN   = 901;

    private const ANN_SECRET = 5001;
    private const ANN_OPEN   = 5002;

    /** @var mixed */
    private $priorWpdb;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/secret-page-list-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/secret-page-feed-stubs.php';
        \bcc_spl_load_core();

        $GLOBALS['__bcc_spl'] = [
            'secret'   => [self::SECRET => true],
            'statuses' => [self::SECRET => [self::MEMBER => ['member']]],
            'posts'    => [
                self::SECRET => \bcc_spl_post(self::SECRET, self::OPERATOR),
                self::OPEN   => \bcc_spl_post(self::OPEN, self::OPERATOR),
            ],
        ];
        $GLOBALS['__bcc_spf'] = [
            'pages'   => [],
            'by_id'   => [],
            'blocked' => [],
            'parents' => [self::ANN_SECRET => self::SECRET, self::ANN_OPEN => self::OPEN],
            'reads'   => [],
            'cache'   => [],
        ];

        $this->priorWpdb  = $GLOBALS['wpdb'] ?? null;
        $wpdb             = new \BccSecretPageListWpdb();
        $wpdb->other      = \Closure::fromCallable('bcc_spf_parents_sql');
        $GLOBALS['wpdb']  = $wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_spl'], $GLOBALS['__bcc_spf']);
        $GLOBALS['wpdb'] = $this->priorWpdb;
    }

    // ── the list feeds ──────────────────────────────────────────────────

    public function testForYouDropsASecretPagesNoticeForAStrangerAndKeepsEverythingElseInOrder(): void
    {
        $this->firstPage([self::post(1), self::secretNotice(2), self::openNotice(3), self::post(4)]);

        self::assertSame(['feed_1', 'feed_3', 'feed_4'], self::ids($this->service()->getFeed(self::STRANGER, 'for_you')));
    }

    public function testTheMemberAndThePagesAuthorSeeTheNotice(): void
    {
        $this->firstPage([self::post(1), self::secretNotice(2)]);

        self::assertSame(['feed_1', 'feed_2'], self::ids($this->service()->getFeed(self::MEMBER, 'for_you')), 'a member');
        self::assertSame(['feed_1', 'feed_2'], self::ids($this->service()->getFeed(self::OPERATOR, 'following')), "the page's author");
    }

    public function testAnAdminSeesTheNotice(): void
    {
        $GLOBALS['__bcc_spf']['is_admin'] = true;
        $GLOBALS['__bcc_spf']['current']  = self::STRANGER; // the admin is the request's user
        $this->firstPage([self::secretNotice(2)]);

        self::assertSame(['feed_2'], self::ids($this->service()->getFeed(self::STRANGER, 'for_you')));
    }

    public function testTheTagFeedDropsItToo(): void
    {
        $this->firstPage([self::secretNotice(2), self::post(1)]);

        self::assertSame(['feed_1'], self::ids($this->service()->getTagFeed(self::STRANGER, 'validators')));
    }

    public function testAPageTheNoticesEmptyIsReadPast(): void
    {
        $GLOBALS['__bcc_spf']['pages'] = [
            ''   => \bcc_spf_page([self::secretNotice(2)], 'c2'),
            'c2' => \bcc_spf_page([self::post(1)]),
        ];

        $payload = $this->service()->getFeed(self::STRANGER, 'for_you');

        self::assertSame(['feed_1'], self::ids($payload));
        self::assertSame(['getFeed:', 'getFeed:c2'], $GLOBALS['__bcc_spf']['reads']);
    }

    /**
     * ⚠⚠ A REFILLED PAGE PASSES EVERY FILTER. Page one is emptied by the
     * secret-page filter; the page read to refill it holds a blocked author's
     * post AND another secret notice, and both filters must take them off.
     */
    public function testARefilledPageGoesThroughEveryFilter(): void
    {
        $GLOBALS['__bcc_spf']['blocked'] = [self::POSTER];
        $GLOBALS['__bcc_spf']['pages']   = [
            ''   => \bcc_spf_page([self::secretNotice(2)], 'c2'),
            'c2' => \bcc_spf_page([self::post(10, self::POSTER), self::secretNotice(11), self::post(12, 43)]),
        ];

        self::assertSame(['feed_12'], self::ids($this->service()->getFeed(self::STRANGER, 'for_you')));
    }

    public function testAnAnonymousViewerIsFilteredToo(): void
    {
        $this->firstPage([self::secretNotice(2), self::openNotice(3)]);

        self::assertSame(['feed_3'], self::ids($this->service()->getTagFeed(0, 'validators')));
    }

    // ── failure and shape ───────────────────────────────────────────────

    public function testAFailedPageLookupDropsOnlyTheNotices(): void
    {
        $GLOBALS['__bcc_spf']['fail_parents'] = true;
        $this->firstPage([self::post(1), self::secretNotice(2), self::openNotice(3)]);

        self::assertSame(['feed_1'], self::ids($this->service()->getFeed(self::MEMBER, 'for_you')));
    }

    public function testANoticeWhosePageCannotBeFoundIsDropped(): void
    {
        unset($GLOBALS['__bcc_spf']['parents'][self::ANN_OPEN]);
        $this->firstPage([self::openNotice(3), self::post(1)]);

        self::assertSame(['feed_1'], self::ids($this->service()->getFeed(self::MEMBER, 'for_you')));
    }

    public function testAPageWithNoNoticeCostsNoExtraRead(): void
    {
        $this->firstPage([self::post(1), self::post(4)]);

        $this->service()->getFeed(self::STRANGER, 'for_you');

        self::assertSame([], $GLOBALS['__bcc_spf']['parent_reads'] ?? [], 'no post_parent read');
        self::assertSame([], $GLOBALS['wpdb']->ofKind('batch'), 'no privacy read');
    }

    /** /feed/hot's items come back through the object cache, whose backend may stringify ids. */
    public function testAStringifiedNoticeIdStillMatchesItsPage(): void
    {
        $secret = self::secretNotice(2);
        $open   = self::openNotice(3);
        $secret['external_id'] = (string) self::ANN_SECRET;
        $open['external_id']   = (string) self::ANN_OPEN;
        $this->firstPage([$secret, $open]);

        self::assertSame(['feed_3'], self::ids($this->service()->getFeed(self::STRANGER, 'for_you')));
    }

    // ── /feed/hot ───────────────────────────────────────────────────────

    public function testTheHotFeedHidesItPerRequestWhileTheSharedCacheKeepsIt(): void
    {
        $this->firstPage([self::post(1), self::secretNotice(2)]);
        $service = $this->service();

        self::assertSame(['feed_1'], self::ids($service->getHotFeed(null, 20, 0)), 'anonymous');
        self::assertSame(['feed_1', 'feed_2'], self::ids($service->getHotFeed(null, 20, self::MEMBER)), 'a member');
        self::assertSame(['feed_1'], self::ids($service->getHotFeed(null, 20, self::STRANGER)), 'a stranger, after the member');

        self::assertSame(['getFeed:'], $GLOBALS['__bcc_spf']['reads'], 'one build, then the cache');
        $cached = array_values(array_filter($GLOBALS['__bcc_spf']['cache'], 'is_array'));
        self::assertCount(1, $cached);
        self::assertSame(['feed_1', 'feed_2'], self::ids($cached[0]), 'the shared page is never filtered');
    }

    public function testAPageMadeSecretIsHiddenOnTheNextHotRequestNotAfterTheTtl(): void
    {
        $GLOBALS['__bcc_spl']['secret'] = [];
        $this->firstPage([self::secretNotice(2)]);
        $service = $this->service();

        self::assertSame(['feed_2'], self::ids($service->getHotFeed(null, 20, 0)), 'open: shown');

        $GLOBALS['__bcc_spl']['secret'] = [self::SECRET => true];
        self::assertSame([], self::ids($service->getHotFeed(null, 20, 0)), 'made secret: hidden at once');
        self::assertSame(['getFeed:'], $GLOBALS['__bcc_spf']['reads'], 'from the same cached page');
    }

    // ── the operator's wall, the page feed, the permalink ───────────────

    public function testTheOperatorsWallDropsTheNoticeAndReadsPastAnEmptiedPage(): void
    {
        $GLOBALS['__bcc_spf']['pages'] = [
            ''   => \bcc_spf_page([self::secretNotice(2)], 'c2'),
            'c2' => \bcc_spf_page([self::openNotice(3)]),
        ];

        $payload = $this->service()->getActivityForAuthor(self::OPERATOR, self::STRANGER);

        self::assertSame(['feed_3'], self::ids($payload));
        self::assertSame(['getActivityForAuthor:', 'getActivityForAuthor:c2'], $GLOBALS['__bcc_spf']['reads']);
    }

    public function testAMemberSeesTheNoticeOnTheWall(): void
    {
        $this->firstPage([self::secretNotice(2)]);

        self::assertSame(['feed_2'], self::ids($this->service()->getActivityForAuthor(self::OPERATOR, self::MEMBER)));
    }

    public function testThePageFeedRunsTheSameFilters(): void
    {
        $this->firstPage([self::secretNotice(2), self::post(1)]);

        self::assertSame(['feed_1'], self::ids($this->service()->getActivityForPage(self::OPEN, self::STRANGER)));
    }

    public function testThePermalinkOfAHiddenNoticeIsTheMissingPostsNull(): void
    {
        $GLOBALS['__bcc_spf']['by_id'][2] = self::secretNotice(2);
        $service = $this->service();

        self::assertNull($service->getActivityById(999, self::STRANGER), 'a missing post');
        self::assertNull($service->getActivityById(2, self::STRANGER), 'a hidden notice: the same null, so the same 404');
        self::assertNull($service->getActivityById(2, 0), 'anonymous');
        self::assertSame('feed_2', $service->getActivityById(2, self::MEMBER)['id'] ?? null, 'a member');
    }

    /**
     * canView() asks about a MENTIONEE during the AUTHOR's request (#767), so
     * the page's admin exemption must be the mentionee's own. An admin author
     * must not exempt a stranger from a secret page, and an admin mentionee
     * is exempt whoever is asking.
     */
    public function testCanViewJudgesAMentioneeByTheirOwnExemptionNotTheRequestUsers(): void
    {
        $GLOBALS['__bcc_spf']['by_id'][2] = self::secretNotice(2);
        $GLOBALS['__bcc_spf']['current']  = 99;   // the author, signed in …
        $GLOBALS['__bcc_spf']['is_admin'] = true; // … and an admin
        $service = $this->service();

        self::assertFalse($service->canView(2, self::STRANGER), 'an admin author does not exempt a stranger');
        self::assertTrue($service->canView(2, self::MEMBER), 'a member mentionee');

        $GLOBALS['__bcc_spf']['admins'] = [self::STRANGER];
        self::assertTrue($service->canView(2, self::STRANGER), 'an admin mentionee');
    }

    // ── the seam itself ─────────────────────────────────────────────────

    /** A filter that REWRITES an item keeps it: no refill, order kept. */
    public function testARewrittenItemCountsAsKept(): void
    {
        $rewrite = static fn (array $items, int $viewer): array => array_map(
            static fn (array $item): array => ['rewritten' => true] + $item,
            $items
        );
        $pages = ['' => \bcc_spf_page([self::post(1), self::post(2)], 'c2'), 'c2' => \bcc_spf_page([self::post(3)])];

        [$payload, $reads] = self::refill($pages, [$rewrite]);

        self::assertSame(['feed_1', 'feed_2'], self::ids($payload));
        self::assertTrue($payload['items'][0]['rewritten'] ?? false);
        self::assertSame([null], $reads, 'a rewritten page is not an emptied one');
    }

    /** Every filter on every page read, in either order. */
    public function testEveryFilterRunsOnEveryRefilledPageInEitherOrder(): void
    {
        $dropA = static fn (array $items, int $viewer): array => array_values(array_filter($items, static fn (array $i): bool => ($i['tag'] ?? '') !== 'a'));
        $dropB = static fn (array $items, int $viewer): array => array_values(array_filter($items, static fn (array $i): bool => ($i['tag'] ?? '') !== 'b'));
        $pages = [
            ''   => \bcc_spf_page([['id' => 'feed_1', 'tag' => 'a']], 'c2'),
            'c2' => \bcc_spf_page([['id' => 'feed_2', 'tag' => 'b'], ['id' => 'feed_3', 'tag' => 'a'], ['id' => 'feed_4']]),
        ];

        self::assertSame(['feed_4'], self::ids(self::refill($pages, [$dropA, $dropB])[0]));
        self::assertSame(['feed_4'], self::ids(self::refill($pages, [$dropB, $dropA])[0]));
    }

    public function testAFilterThatFailsClosedEmptiesThePageAndStopsReading(): void
    {
        $fail  = static fn (array $items, int $viewer): ?array => null;
        $pages = ['' => \bcc_spf_page([self::post(1)], 'c2'), 'c2' => \bcc_spf_page([self::post(3)])];

        [$payload, $reads] = self::refill($pages, [$fail]);

        self::assertSame([], $payload['items']);
        self::assertSame([null], $reads);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function service(): FeedRankingService
    {
        return new FeedRankingService(
            new ActivityFeedService(),
            new ReputationRepository(),
            new HiddenActivityRepository(),
            new GroupContextResolver(),
            new FeedHydrationPipeline()
        );
    }

    /** @param list<array<string, mixed>> $items */
    private function firstPage(array $items): void
    {
        $GLOBALS['__bcc_spf']['pages'] = ['' => \bcc_spf_page($items)];
    }

    /**
     * @param array<string, array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}> $pages
     * @param list<callable> $filters
     * @return array{0: array{items: list<array<string, mixed>>, pagination: array<string, mixed>}, 1: list<?string>}
     */
    private static function refill(array $pages, array $filters): array
    {
        $reads = [];
        $fetch = static function (?string $cursor) use ($pages, &$reads): array {
            $reads[] = $cursor;

            return $pages[$cursor ?? ''];
        };
        $m = new ReflectionMethod(FeedRankingService::class, 'fetchPastFilteredPages');
        /** @var array{items: list<array<string, mixed>>, pagination: array<string, mixed>} $payload */
        $payload = $m->invoke(null, $fetch, null, self::STRANGER, $filters);

        return [$payload, $reads];
    }

    /** @return array<string, mixed> */
    private static function post(int $actId, int $author = self::POSTER): array
    {
        return \bcc_spf_item($actId, 'status', 100 + $actId, $author);
    }

    /** @return array<string, mixed> */
    private static function secretNotice(int $actId): array
    {
        return \bcc_spf_item($actId, 'announcement', self::ANN_SECRET, self::OPERATOR);
    }

    /** @return array<string, mixed> */
    private static function openNotice(int $actId): array
    {
        return \bcc_spf_item($actId, 'announcement', self::ANN_OPEN, self::OPERATOR);
    }

    /**
     * @param array{items: list<array<string, mixed>>} $payload
     * @return list<string>
     */
    private static function ids(array $payload): array
    {
        return array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), $payload['items']);
    }
}
