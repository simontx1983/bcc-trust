<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Services\WatchingService;
use BCC\Trust\Core\Support\PageAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * SECRET pages in lists shown to ONE viewer, over the REAL bcc-core rule
 * (PeepSoPagePrivacy), the real PageAccess, PageDiscoveryRepository and
 * WatchingRepository, and a `$wpdb` that answers the privacy reads
 * (tests/Stubs/secret-page-list-stubs.php).
 *
 *  - PageAccess::visibleAmong(): the single-page verdict for every page in a
 *    list, with ONE batched read; only secret pages pay a member check.
 *  - The watchlist resolves a followed user's page as the viewer may see it.
 *    A non-member following the owner of a secret page gets a row identical
 *    to the row of a user with no page: no page id, slug, kind or link.
 *
 * The watchers list, the watchlist's cards and feed page labels are covered
 * where their worlds already live: CardWatchersProfileGateTest,
 * WatchingProfileGateTest and FeedPageContextHydrationTest.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecretPageListsTest extends TestCase
{
    private const VIEWER = 5;
    private const OWNER  = 70;
    private const OPEN   = 10;
    private const SECRET = 20;
    private const OTHER  = 30;

    private \BccSecretPageListWpdb $db;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-access-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/secret-page-list-stubs.php';
        \bcc_spl_load_core();

        $GLOBALS['__bcc_profile_access'] = ['viewer_id' => self::VIEWER, 'is_admin' => false];
        $GLOBALS['__bcc_spl'] = [
            'secret' => [self::SECRET => true],
            'posts'  => [
                self::OPEN   => \bcc_spl_post(self::OPEN, self::OWNER),
                self::SECRET => \bcc_spl_post(self::SECRET, self::OWNER),
                self::OTHER  => \bcc_spl_post(self::OTHER, self::OWNER),
            ],
        ];

        $this->db         = new \BccSecretPageListWpdb();
        $GLOBALS['wpdb']  = $this->db;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['__bcc_spl'], $GLOBALS['__bcc_profile_access']);
    }

    // ── PageAccess::visibleAmong ────────────────────────────────────────

    public function testWithNoSecretPageEveryPageIsKeptForOneRead(): void
    {
        $GLOBALS['__bcc_spl']['secret'] = [];

        $out = PageAccess::visibleAmong([self::OTHER, self::OPEN, self::OTHER, 0, self::SECRET], self::VIEWER);

        self::assertSame([self::OTHER, self::OPEN, self::SECRET], $out, 'input order, each id once, no non-positive id');
        self::assertCount(1, $this->db->ofKind('batch'));
        self::assertSame([], $this->db->ofKind('single'), 'no page pays a single-page read');
        self::assertSame([], $this->db->ofKind('member'));
    }

    public function testTheBatchedReadCarriesTheSharedRuleOverTheWholeList(): void
    {
        PageAccess::visibleAmong([self::OPEN, self::SECRET, self::OTHER], self::VIEWER);

        $batch = $this->db->ofKind('batch')[0] ?? '';
        self::assertSame([self::OPEN, self::SECRET, self::OTHER], \BccSecretPageListWpdb::inList($batch));
        self::assertStringContainsString(
            'NOT (' . \BCC\Core\Repositories\PeepSoPagePrivacy::notSecretSql('p.ID') . ')',
            $batch,
            "bcc-core's rule, negated: the secret ones"
        );
        self::assertStringContainsString('LIMIT 3', $batch);
    }

    /** @return iterable<string, array{0: list<string>, 1: bool}> */
    public static function membership(): iterable
    {
        yield 'no row (a stranger)'         => [[], false];
        yield 'member'                      => [['member'], true];
        yield 'owner row'                   => [['member_owner'], true];
        yield 'invited'                     => [['pending_user'], true];
        yield 'asked to join'               => [['pending_admin'], false];
        yield 'banned'                      => [['banned'], false];
        yield 'banned and member, any order' => [['member', 'banned'], false];
    }

    /** @param list<string> $statuses */
    #[DataProvider('membership')]
    public function testASecretPageIsKeptOnlyForThoseThePageAdmits(array $statuses, bool $kept): void
    {
        $GLOBALS['__bcc_spl']['statuses'][self::SECRET][self::VIEWER] = $statuses;

        $out = PageAccess::visibleAmong([self::OPEN, self::SECRET, self::OTHER], self::VIEWER);

        self::assertSame($kept ? [self::OPEN, self::SECRET, self::OTHER] : [self::OPEN, self::OTHER], $out);
        self::assertCount(1, $this->db->ofKind('member'), 'only the secret page pays a member check');

        $onePageAtATime = array_values(array_filter(
            [self::OPEN, self::SECRET, self::OTHER],
            static fn (int $id): bool => PageAccess::viewerMaySeeId($id, self::VIEWER)
        ));
        self::assertSame($onePageAtATime, $out, 'the same verdict as viewerMaySeeId(), page by page');
    }

    public function testTheAuthorOfASecretPageKeepsItWithoutAMemberRead(): void
    {
        $GLOBALS['__bcc_spl']['posts'][self::SECRET] = \bcc_spl_post(self::SECRET, self::VIEWER);

        self::assertSame([self::SECRET], PageAccess::visibleAmong([self::SECRET], self::VIEWER));
        self::assertSame([], $this->db->ofKind('member'));
    }

    public function testAnAnonymousViewerNeverKeepsASecretPage(): void
    {
        // An authorless page: 0 must never match an anonymous viewer's 0.
        $GLOBALS['__bcc_spl']['posts'][self::SECRET] = \bcc_spl_post(self::SECRET, 0);

        self::assertSame([self::OPEN], PageAccess::visibleAmong([self::OPEN, self::SECRET], 0));
        self::assertSame([], $this->db->ofKind('member'));
    }

    public function testAdminsKeepEveryPageWithoutARead(): void
    {
        $GLOBALS['__bcc_profile_access']['is_admin'] = true;

        self::assertSame([self::OPEN, self::SECRET], PageAccess::visibleAmong([self::OPEN, self::SECRET], self::VIEWER));
        self::assertSame([], $this->db->queries);
    }

    public function testAnEmptyListReadsNothing(): void
    {
        self::assertSame([], PageAccess::visibleAmong([], self::VIEWER));
        self::assertSame([], $this->db->queries);
    }

    public function testAFailedBatchedReadSendsEveryPageToTheSingleCheck(): void
    {
        $GLOBALS['__bcc_spl']['fail_batch'] = true;

        $out = PageAccess::visibleAmong([self::OPEN, self::SECRET], self::VIEWER);

        self::assertSame([self::OPEN], $out, 'unknown is treated as secret, then each page is decided on its own');
        self::assertCount(2, $this->db->ofKind('single'));
    }

    public function testWhenEveryReadFailsNoPageIsKept(): void
    {
        $GLOBALS['__bcc_spl']['fail_batch']  = true;
        $GLOBALS['__bcc_spl']['fail_single'] = true;

        self::assertSame([], PageAccess::visibleAmong([self::OPEN, self::SECRET], self::VIEWER));
    }

    // ── the watchlist: a followed user's page, as the viewer sees it ────

    public function testAnOwnerWhoseOnlyPageIsSecretReadsExactlyLikeAUserWithNoPage(): void
    {
        $this->candidates([self::OWNER => [[self::SECRET, 'validator']]]);
        $hidden = $this->watchRow();

        $this->candidates([]);
        $noPage = $this->watchRow();

        self::assertSame($noPage, $hidden);
        self::assertSame('member', $hidden['card_kind']);
        self::assertNull($hidden['page_id']);
        self::assertNull($hidden['card_slug']);
        self::assertFalse($hidden['is_resolved']);
    }

    public function testAMemberOfTheSecretPageSeesItResolved(): void
    {
        $GLOBALS['__bcc_spl']['statuses'][self::SECRET][self::VIEWER] = ['member'];
        $this->candidates([self::OWNER => [[self::SECRET, 'validator']]]);

        $row = $this->watchRow();

        self::assertSame('validator', $row['card_kind']);
        self::assertSame(self::SECRET, $row['page_id']);
        self::assertSame('page-' . self::SECRET, $row['card_slug']);
    }

    public function testTheOwnersNextPageTakesTheSecretPagesPlace(): void
    {
        // Best first: the secret validator page, then an open project page.
        $this->candidates([self::OWNER => [[self::SECRET, 'validator'], [self::OTHER, 'builder']]]);

        $row = $this->watchRow();

        self::assertSame('project', $row['card_kind']);
        self::assertSame(self::OTHER, $row['page_id']);

        $GLOBALS['__bcc_spl']['statuses'][self::SECRET][self::VIEWER] = ['member'];
        $this->db->queries = [];
        self::assertSame(self::SECRET, $this->watchRow()['page_id'], 'a member gets the best page, as before');
    }

    public function testAWholePageOfFollowsCostsOnePrivacyRead(): void
    {
        $this->candidates([
            70 => [[self::OPEN, 'validator']],
            71 => [[self::OTHER, 'builder']],
            72 => [[40, 'nft'], [41, 'validator']],
        ]);

        $map = $this->pageInfoVisibleTo([70, 71, 72]);

        self::assertSame([70 => self::OPEN, 71 => self::OTHER, 72 => 40], array_map(static fn (object $r): int => (int) $r->page_id, $map));
        self::assertCount(1, $this->db->ofKind('batch'));
        self::assertSame([self::OPEN, self::OTHER, 40, 41], \BccSecretPageListWpdb::inList($this->db->ofKind('batch')[0]));
        self::assertSame([], $this->db->ofKind('single'));
        self::assertSame([], $this->db->ofKind('member'));
    }

    public function testNoCandidatesMeansNoPrivacyRead(): void
    {
        $this->candidates([]);

        self::assertSame([], $this->pageInfoVisibleTo([70, 71]));
        self::assertSame([], $this->db->ofKind('batch'));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * Answer WatchingRepository::findPageCandidatesByUserIds() with these
     * pages, best first per user, in the repository's row shape.
     *
     * @param array<int, list<array{0: int, 1: string}>> $byUser user => [page_id, _bcc_page_type]
     */
    private function candidates(array $byUser): void
    {
        $rows = [];
        foreach ($byUser as $userId => $pages) {
            foreach ($pages as [$pageId, $type]) {
                $rows[] = (object) [
                    'user_id'   => (string) $userId,
                    'page_id'   => (string) $pageId,
                    'page_slug' => 'page-' . $pageId,
                    'page_type' => $type,
                ];
            }
        }
        $this->db->other = static fn (string $sql): array => str_contains($sql, "'_bcc_page_type'") ? $rows : [];
    }

    /**
     * @param list<int> $userIds
     * @return array<int, object>
     */
    private function pageInfoVisibleTo(array $userIds): array
    {
        $service = (new \ReflectionClass(WatchingService::class))->newInstanceWithoutConstructor();
        \Closure::bind(function (): void {
            $this->watchingRepo = new WatchingRepository();
        }, $service, WatchingService::class)();

        /** @var array<int, object> $map */
        $map = (new \ReflectionMethod(WatchingService::class, 'pageInfoVisibleTo'))
            ->invoke($service, $userIds, self::VIEWER);

        return $map;
    }

    /**
     * The watch row the viewer gets for following OWNER, built the way
     * getWatching() builds it.
     *
     * @return array<string, mixed>
     */
    private function watchRow(): array
    {
        $map = $this->pageInfoVisibleTo([self::OWNER]);

        $follow = (object) [
            'follow_id'     => '901',
            'card_user_id'  => (string) self::OWNER,
            'card_handle'   => 'owner70',
            'tier_at_watch' => null,
            'batch_id'      => null,
            'watched_at'    => '2026-11-01 10:00:00',
        ];

        /** @var array<string, mixed> $row */
        $row = (new \ReflectionMethod(WatchingService::class, 'buildItem'))
            ->invoke(null, $follow, $map[self::OWNER] ?? null);

        return $row;
    }
}
