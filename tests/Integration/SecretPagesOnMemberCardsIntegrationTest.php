<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Support\MemberSummaryPrefetcher;
use BCC\Trust\Core\Support\PageAccess;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-571: member CARDS built in a batch (the directory, group rosters,
 * suggestions, feed cold start, review authors) count each member's reviews
 * written and disputes signed as the VIEWER may see them. The SECRET pages
 * that viewer may not see are left out, as the member's own Reviews and
 * Disputes tabs leave them out (F-528).
 *
 * The real prefetch, repositories, PageAccess and SQL run over real rows:
 * pages with privacy meta, PeepSo page members, votes and disputes. One read
 * per table finds the list's secret pages, and one hiddenAmong() decides them
 * for the viewer. The list:
 *   - AUTHOR reviewed OPEN, SECRET, TWO_ROWS and another member; disputed
 *     OPEN, SECRET and TWO_ROWS;
 *   - SECOND reviewed OPEN and SECRET_B; disputed SECRET_B;
 *   - STRANGER (also a viewer) reviewed SECRET_B.
 * PAGE_MEMBER is a member of SECRET; nobody is a member of SECRET_B.
 *
 * Rationale: F-571 (private tracker).
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(MemberSummaryPrefetcher::class)]
#[CoversClass(PageAccess::class)]
#[CoversClass(DisputeRepository::class)]
final class SecretPagesOnMemberCardsIntegrationTest extends TestCase
{
    private const AUTHOR      = 954801;
    private const SECOND      = 954802;
    private const STRANGER    = 954803;
    private const PAGE_MEMBER = 954804;
    private const SUBJECT     = 954805;
    private const ADMIN       = 954806;

    private const LIST = [self::AUTHOR, self::SECOND, self::STRANGER];

    private const OPEN     = 954901;
    private const SECRET   = 954902;
    private const TWO_ROWS = 954903;
    private const SECRET_B = 954904;

    /** Extra secret pages for the cap cases: 955001 and up. */
    private const BULK_BASE = 955000;

    /** The list's two secret-page reads, and no other statement. */
    private const REVIEW_SECRET_READ  = '/SELECT DISTINCT v\.page_id\s+FROM\s+`?\S*bcc_trust_votes`? v\s+WHERE v\.voter_user_id IN/';
    private const DISPUTE_SECRET_READ = '/SELECT DISTINCT d\.page_id\s+FROM\s+`?\S*bcc_disputes`? d\s+WHERE d\.reporter_id IN/';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-groups-integration-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/member-lists-integration-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/schema-project.php';

        global $wpdb;
        if (!defined('DB_NAME')) {
            define('DB_NAME', (string) $wpdb->get_var('SELECT DATABASE()'));
        }

        $GLOBALS['__bcc_pg'] = ['viewer' => 0, 'users' => [], 'meta' => []];
        foreach ([
            self::AUTHOR      => 'author',
            self::SECOND      => 'second',
            self::STRANGER    => 'stranger',
            self::PAGE_MEMBER => 'pagemember',
            self::SUBJECT     => 'subject',
            self::ADMIN       => 'admin',
        ] as $id => $name) {
            $GLOBALS['__bcc_pg']['users'][$id] = ['login' => 'f571_' . $name, 'handle' => 'f571' . $name, 'name' => 'F571 ' . $name];
            $GLOBALS['__bcc_pg']['meta'][$id]  = ['bcc_handle' => 'f571' . $name];
        }
        $GLOBALS['__bcc_test_caps']         = [];
        $GLOBALS['__bcc_test_object_cache'] = [];
        $wpdb->clearFaultInjection();

        $this->ensureTables();
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        unset($GLOBALS['__bcc_pg'], $GLOBALS['__bcc_test_caps']);
    }

    // ── Per viewer ───────────────────────────────────────────────────────

    /**
     * @return array<string, array{int, array<int, array{int, int}>}>
     *         viewer => member => [reviews_written, disputes_signed]
     */
    public static function viewers(): array
    {
        return [
            'a stranger, whose own card is in the list' => [self::STRANGER, [
                self::AUTHOR => [2, 1], self::SECOND => [1, 0], self::STRANGER => [1, 0],
            ]],
            'anonymous' => [0, [
                self::AUTHOR => [2, 1], self::SECOND => [1, 0], self::STRANGER => [0, 0],
            ]],
            'a member of one of the secret pages' => [self::PAGE_MEMBER, [
                self::AUTHOR => [3, 2], self::SECOND => [1, 0], self::STRANGER => [0, 0],
            ]],
            'a member of the list, on their own card' => [self::AUTHOR, [
                self::AUTHOR => [4, 3], self::SECOND => [1, 0], self::STRANGER => [0, 0],
            ]],
        ];
    }

    /**
     * Each card counts only what its viewer may see, and the viewer's own
     * card counts everything of their own. The cards show exactly the
     * prefetched counts (getSummary's `engagement`).
     *
     * @param array<int, array{int, int}> $expected
     */
    #[DataProvider('viewers')]
    public function testEachCardCountsOnlyThePagesItsViewerMaySee(int $viewer, array $expected): void
    {
        $this->viewAs($viewer);

        self::assertSame($expected, self::countsFor($viewer));

        $prefetched = MemberSummaryPrefetcher::primeFor(self::LIST, $viewer);
        $views      = \BCC\Trust\Core\Plugin::instance()->userViewService();
        foreach ($expected as $member => $counts) {
            $summary = $views->getSummary($member, $viewer, $prefetched);
            self::assertIsArray($summary);
            self::assertSame(
                $counts,
                [$summary['engagement']['reviews_written'], $summary['engagement']['disputes_signed']],
                "the card of {$member}"
            );
        }
    }

    /**
     * A site admin sees every page. The exemption is the VIEWER's: a list
     * the admin's request builds for someone else still leaves out what
     * that viewer may not see.
     */
    public function testAnAdminSeesEveryCountButAListBuiltForSomeoneElseDoesNotBorrowIt(): void
    {
        $this->viewAs(self::ADMIN);
        $GLOBALS['__bcc_test_caps'] = ['manage_options'];

        self::assertSame(
            [self::AUTHOR => [4, 3], self::SECOND => [2, 1], self::STRANGER => [1, 0]],
            self::countsFor(self::ADMIN),
            'the admin, reading as themselves'
        );
        self::assertSame(
            [self::AUTHOR => [2, 1], self::SECOND => [1, 0], self::STRANGER => [1, 0]],
            self::countsFor(self::STRANGER),
            'built for a stranger'
        );
    }

    /** Admins never pay the secret-page reads. */
    public function testAnAdminNeverPaysTheSecretPageReads(): void
    {
        $this->viewAs(self::ADMIN);
        $GLOBALS['__bcc_test_caps']           = ['manage_options'];
        $GLOBALS['wpdb']->failQueriesMatching = '/SELECT DISTINCT [vd]\.page_id/';

        self::assertSame(
            [self::AUTHOR => [4, 3], self::SECOND => [2, 1], self::STRANGER => [1, 0]],
            self::countsFor(self::ADMIN)
        );
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures, 'the reads never ran');
    }

    /** A list of only the viewer's own card has nothing to hide, and reads nothing. */
    public function testAViewerAloneInTheListPaysNoRead(): void
    {
        $this->viewAs(self::STRANGER);
        $GLOBALS['wpdb']->failQueriesMatching = '/SELECT DISTINCT [vd]\.page_id/';

        self::assertSame(
            ['reviews_written_counts' => [self::STRANGER => 1], 'disputes_signed_counts' => []],
            MemberSummaryPrefetcher::contentCountsFor([self::STRANGER], self::STRANGER)
        );
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures);
    }

    /**
     * The two other ways cards are built hand the prefetch their own viewer:
     * the member-card prefetch (the directory, followers, watchers, watching,
     * a profile) and the feed cold start's own copy of the prefetch.
     */
    public function testTheMemberCardAndColdStartPrefetchesCountForTheirViewer(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
        bcc_trust_create_trust_attestations_table();
        $this->viewAs(self::PAGE_MEMBER);
        $expected = [
            'reviews_written_counts' => [self::AUTHOR => 3, self::SECOND => 1],
            'disputes_signed_counts' => [self::AUTHOR => 2],
        ];

        $cards = \BCC\Trust\Core\Support\MemberCardPrefetcher::primeFor(self::LIST, self::PAGE_MEMBER);
        $cold  = (new \ReflectionMethod(\BCC\Trust\Core\Services\FeedColdStartService::class, 'buildOperatorPrefetch'))
            ->invoke(null, self::LIST, self::PAGE_MEMBER);
        self::assertIsArray($cold);

        foreach (['member cards' => $cards, 'cold start' => $cold] as $path => $map) {
            foreach ($expected as $key => $counts) {
                $got = $map[$key];
                self::assertIsArray($got);
                ksort($got);
                self::assertSame($counts, $got, "{$path}: {$key}");
            }
        }
    }

    // ── Failing closed ───────────────────────────────────────────────────

    /** @return array<string, array{string, string, string}> */
    public static function failedReads(): array
    {
        return [
            'reviews, a failed read'         => ['reviews_written_counts', self::REVIEW_SECRET_READ, 'failQueriesMatching'],
            'reviews, a silent failed read'  => ['reviews_written_counts', self::REVIEW_SECRET_READ, 'failSilentlyMatching'],
            'disputes, a failed read'        => ['disputes_signed_counts', self::DISPUTE_SECRET_READ, 'failQueriesMatching'],
            'disputes, a silent failed read' => ['disputes_signed_counts', self::DISPUTE_SECRET_READ, 'failSilentlyMatching'],
        ];
    }

    /**
     * A secret-page read that fails shows that count as 0 on every card but
     * the viewer's own: never a count that includes hidden pages, and never
     * a 503 for the whole list. The other count is unaffected.
     */
    #[DataProvider('failedReads')]
    public function testAFailedSecretPageReadShowsZeroOnEveryCardButTheViewersOwn(string $map, string $read, string $fault): void
    {
        $this->viewAs(self::STRANGER);
        $GLOBALS['wpdb']->{$fault} = $read;
        \BCC\Core\Log\Logger::reset();

        $counts = MemberSummaryPrefetcher::contentCountsFor(self::LIST, self::STRANGER);
        ksort($counts['reviews_written_counts']);
        ksort($counts['disputes_signed_counts']);

        $failed = $map === 'reviews_written_counts'
            ? [self::STRANGER => 1]
            : [];
        $intact = $map === 'reviews_written_counts'
            ? [self::AUTHOR => 1]
            : [self::AUTHOR => 2, self::SECOND => 1, self::STRANGER => 1];
        self::assertSame($failed, $counts[$map], 'only the viewer\'s own card keeps its count');
        self::assertSame($intact, $counts[$map === 'reviews_written_counts' ? 'disputes_signed_counts' : 'reviews_written_counts']);
        self::assertGreaterThan(0, $fault === 'failQueriesMatching' ? $GLOBALS['wpdb']->injectedFailures : $GLOBALS['wpdb']->silentFailures, 'the fault really hit the secret-page read');
        self::assertCount(1, self::errorLines('member cards show this count as 0'));
    }

    /** At the cap, across the list, every secret page is still checked. */
    public function testExactlyTheCapAcrossTheListIsStillChecked(): void
    {
        // The list's other members already cover 3 distinct secret pages in
        // each table. Neither member alone comes near the cap.
        $this->seedBulkSecretPages(PageAccess::HIDDEN_LIST_CAP - 3);
        $this->viewAs(self::STRANGER);

        self::assertSame(
            [self::AUTHOR => [2, 1], self::SECOND => [1, 0], self::STRANGER => [1, 0]],
            self::countsFor(self::STRANGER)
        );
    }

    /**
     * Over the cap, the LIST is refused: its members together cover more
     * secret pages than one list may check. Every card but the viewer's own
     * shows 0, never a 503, and the refusal is logged with the list's
     * members and counted on the existing metric, once per table.
     */
    public function testOverTheCapAcrossTheListTheCountsShowZeroLoggedAndCounted(): void
    {
        $this->seedBulkSecretPages(PageAccess::HIDDEN_LIST_CAP - 2);
        $this->viewAs(self::STRANGER);
        \BCC\Core\Log\Logger::reset();
        $before = \BCC\Core\Observability\DegradationMetrics::readEvent('page_privacy', 'member_list_over_cap', time());

        self::assertSame(
            [self::AUTHOR => [0, 0], self::SECOND => [0, 0], self::STRANGER => [1, 0]],
            self::countsFor(self::STRANGER)
        );

        self::assertSame(
            $before + 2,
            \BCC\Core\Observability\DegradationMetrics::readEvent('page_privacy', 'member_list_over_cap', time()),
            'counted once for each table'
        );
        $refusals = self::errorLines('over the secret-page cap');
        self::assertCount(2, $refusals);
        foreach ($refusals as $refusal) {
            self::assertSame([self::AUTHOR, self::SECOND], $refusal['context']['member_ids']);
            self::assertSame(PageAccess::HIDDEN_LIST_CAP, $refusal['context']['cap']);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function viewAs(int $viewer): void
    {
        $GLOBALS['__bcc_pg']['viewer'] = $viewer;
    }

    /** @return array<int, array{int, int}> member => [reviews_written, disputes_signed], 0 when absent */
    private static function countsFor(int $viewer): array
    {
        $counts = MemberSummaryPrefetcher::contentCountsFor(self::LIST, $viewer);
        $out    = [];
        foreach (self::LIST as $member) {
            $out[$member] = [
                $counts['reviews_written_counts'][$member] ?? 0,
                $counts['disputes_signed_counts'][$member] ?? 0,
            ];
        }

        return $out;
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private static function errorLines(string $containing): array
    {
        return array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $line): bool => $line['level'] === 'error' && str_contains($line['message'], $containing)
        ));
    }

    private function ensureTables(): void
    {
        global $wpdb;

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', DisputeRepository::disputes_table())) !== DisputeRepository::disputes_table()) {
            DisputeRepository::install();
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', TableRegistry::pageReadModel())) !== TableRegistry::pageReadModel()) {
            \bcc_trust_create_page_tables();
        }

        $columns = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        if (!in_array('post_author', $columns, true)) {
            self::assertNotFalse(
                $wpdb->query("ALTER TABLE {$wpdb->posts} ADD COLUMN post_author BIGINT UNSIGNED NOT NULL DEFAULT 0"),
                'posts fixture: ' . $wpdb->last_error
            );
        }

        // PeepSo Pages' membership table (only the columns the read uses).
        self::assertNotFalse(
            $wpdb->query(
                "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_page_members` (
                    pm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    pm_user_id BIGINT UNSIGNED NOT NULL,
                    pm_page_id BIGINT UNSIGNED NOT NULL,
                    pm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
                    PRIMARY KEY (pm_id)
                )"
            ),
            'members fixture: ' . $wpdb->last_error
        );
    }

    private function seed(): void
    {
        global $wpdb;

        foreach ([self::OPEN => ['0'], self::SECRET => ['2'], self::TWO_ROWS => ['0', '2'], self::SECRET_B => ['2']] as $pageId => $privacy) {
            $this->insertPage($pageId, $privacy);
        }
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_page_members', [
            'pm_user_id' => self::PAGE_MEMBER, 'pm_page_id' => self::SECRET, 'pm_user_status' => 'member',
        ]), $wpdb->last_error);

        $minute = 0;
        foreach ([self::OPEN, self::SECRET, self::TWO_ROWS] as $pageId) {
            $this->insertDispute(self::AUTHOR, $pageId, $this->insertReview(self::AUTHOR, $pageId, ++$minute), ++$minute);
        }
        $this->insertReview(self::AUTHOR, MemberSelfPageService::selfPageId(self::SUBJECT), ++$minute);

        $this->insertReview(self::SECOND, self::OPEN, ++$minute);
        $this->insertDispute(self::SECOND, self::SECRET_B, $this->insertReview(self::SECOND, self::SECRET_B, ++$minute), ++$minute);

        $this->insertReview(self::STRANGER, self::SECRET_B, ++$minute);
    }

    /**
     * $count more secret pages, each with one review and one dispute,
     * alternately by AUTHOR and SECOND: the list passes the cap, neither
     * member does.
     */
    private function seedBulkSecretPages(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $pageId = self::BULK_BASE + $i;
            $member = $i % 2 === 0 ? self::AUTHOR : self::SECOND;
            $this->insertPage($pageId, ['2']);
            $this->insertDispute($member, $pageId, $this->insertReview($member, $pageId, 100 + $i), 100 + $i);
        }
    }

    /** @param list<string> $privacy */
    private function insertPage(int $pageId, array $privacy): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID'          => $pageId,
            'post_title'  => 'F571 page ' . $pageId,
            'post_name'   => 'f571-page-' . $pageId,
            'post_type'   => 'peepso-page',
            'post_status' => 'publish',
        ]), 'page fixture: ' . $wpdb->last_error);
        foreach ($privacy as $value) {
            self::assertNotFalse($wpdb->insert($wpdb->postmeta, [
                'post_id' => $pageId, 'meta_key' => 'peepso_page_privacy', 'meta_value' => $value,
            ]), 'privacy fixture: ' . $wpdb->last_error);
        }
    }

    private function insertReview(int $voter, int $pageId, int $minutesAgo): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(TableRegistry::votes(), [
            'voter_user_id' => $voter,
            'page_id'       => $pageId,
            'vote_type'     => 1,
            'explanation'   => 'F571 review of ' . $pageId,
            'status'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s', time() - 60 * $minutesAgo),
        ]), 'vote fixture: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function insertDispute(int $reporter, int $pageId, int $voteId, int $minutesAgo): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(DisputeRepository::disputes_table(), [
            'vote_id'     => $voteId,
            'page_id'     => $pageId,
            'reporter_id' => $reporter,
            'voter_id'    => self::PAGE_MEMBER,
            'reason'      => 'F571 dispute on ' . $pageId,
            'status'      => 'reviewing',
            'created_at'  => gmdate('Y-m-d H:i:s', time() - 60 * $minutesAgo),
        ]), 'dispute fixture: ' . $wpdb->last_error);
    }

    /** Only this suite's rows: its pages (and bulk pages), and its members' votes and disputes. */
    private function purge(): void
    {
        global $wpdb;
        $pages = 'BETWEEN 954901 AND 954999 OR %s BETWEEN 955001 AND 955999';
        foreach ([
            [$wpdb->postmeta, 'post_id'],
            [$wpdb->posts, 'ID'],
            [$wpdb->prefix . 'peepso_page_members', 'pm_page_id'],
        ] as [$table, $column]) {
            $wpdb->query("DELETE FROM `{$table}` WHERE {$column} " . sprintf($pages, $column));
        }
        $members = implode(',', self::LIST);
        $wpdb->query('DELETE FROM `' . TableRegistry::votes() . "` WHERE voter_user_id IN ({$members})");
        $wpdb->query('DELETE FROM `' . DisputeRepository::disputes_table() . "` WHERE reporter_id IN ({$members})");
    }
}
