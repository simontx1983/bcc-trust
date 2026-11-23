<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\REST\UsersEndpoint;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Support\PageAccess;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-528: a member's Reviews, Disputes and Vouches tabs, read by anyone but
 * the member, leave out the SECRET pages that viewer may not see, with the
 * totals to match. PageAccess decides for each page: its members and
 * invitees see it, as do site admins and the member themselves.
 *
 * The real REST handlers, services, repositories and SQL run over real rows:
 * pages with privacy meta, PeepSo page members, votes, disputes and vouches.
 * Doubled (profile-groups-integration-stubs.php) are only the session, the
 * user/handle lookup and PeepSo's profile level (every profile is public, so
 * the profile gate admits everyone and these cases are about pages only).
 *
 * The fixture, for the member whose tabs are read:
 *   - OPEN       privacy row '0'
 *   - SECRET     privacy row '2'; PAGE_MEMBER is a member
 *   - TWO_ROWS   privacy rows '0' and '2': secret (any row reading 2 is)
 *   - a review of, and a vouch for, another MEMBER (their self-page has no
 *     post, so it is never secret)
 *
 * Rationale: F-528 (private tracker).
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(UsersEndpoint::class)]
#[CoversClass(PageAccess::class)]
#[CoversClass(DisputeRepository::class)]
final class SecretPagesOnMemberListsIntegrationTest extends TestCase
{
    private const AUTHOR      = 952801;
    private const STRANGER    = 952802;
    private const PAGE_MEMBER = 952803;
    private const SUBJECT     = 952804;
    private const ADMIN       = 952805;

    private const OPEN     = 952901;
    private const SECRET   = 952902;
    private const TWO_ROWS = 952903;

    /** Extra secret pages for the cap cases: 953001 and up. */
    private const BULK_BASE = 953000;

    /** The two secret-page reads, and no other statement. */
    private const REVIEW_SECRET_READ  = '/SELECT DISTINCT v\.page_id\s+FROM\s+`?\S*bcc_trust_votes/';
    private const DISPUTE_SECRET_READ = '/SELECT DISTINCT d\.page_id\s+FROM\s+`?\S*bcc_disputes/';

    private const UNAVAILABLE = 'This list is temporarily unavailable. Please try again.';

    /** @var array<int, int> page id => vote id */
    private array $voteOn = [];

    /** @var array<int, int> page id => dispute id */
    private array $disputeOn = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-groups-integration-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/member-lists-integration-stubs.php';
        require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
        require_once dirname(__DIR__, 2) . '/includes/database/schema-project.php';

        global $wpdb;
        if (!defined('DB_NAME')) {
            define('DB_NAME', (string) $wpdb->get_var('SELECT DATABASE()'));
        }

        $GLOBALS['__bcc_pg'] = ['viewer' => 0, 'users' => [], 'meta' => []];
        foreach ([
            self::AUTHOR      => 'author',
            self::STRANGER    => 'stranger',
            self::PAGE_MEMBER => 'pagemember',
            self::SUBJECT     => 'subject',
            self::ADMIN       => 'admin',
        ] as $id => $name) {
            $GLOBALS['__bcc_pg']['users'][$id] = ['login' => 'f528_' . $name, 'handle' => 'f528' . $name, 'name' => 'F528 ' . $name];
            $GLOBALS['__bcc_pg']['meta'][$id]  = ['bcc_handle' => 'f528' . $name];
        }
        $GLOBALS['__bcc_test_caps']         = [];
        $GLOBALS['__bcc_test_object_cache'] = [];
        $wpdb->clearFaultInjection();

        // As in EndorsementsGivenReadFailureIntegrationTest: the REAL
        // TrustReadService answers bcc-core's suspension check, or the
        // profile gate fails closed and every tab answers 403 before it reads.
        $locatorCache = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $resolved     = (array) $locatorCache->getValue();
        $resolved['bcc.resolve.trust_read_service'] = \BCC\Trust\Core\Plugin::instance()->trustReadService();
        $locatorCache->setValue(null, $resolved);

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

    // ── Reviews ──────────────────────────────────────────────────────────

    /** @return array<string, array{int, list<int>}> viewer => the pages whose reviews it is shown */
    public static function reviewViewers(): array
    {
        return [
            'the member themselves'            => [self::AUTHOR, [self::OPEN, self::SECRET, self::TWO_ROWS, -1]],
            'a stranger'                       => [self::STRANGER, [self::OPEN, -1]],
            'anonymous'                        => [0, [self::OPEN, -1]],
            'a member of one of the secret pages' => [self::PAGE_MEMBER, [self::OPEN, self::SECRET, -1]],
        ];
    }

    /** -1 stands for the reviewed member's self-page. */
    #[DataProvider('reviewViewers')]
    public function testTheReviewsTabShowsEachViewerOnlyThePagesItMaySee(int $viewer, array $pages): void
    {
        $this->viewAs($viewer);

        $data = $this->ok($this->call('reviews'));

        self::assertSame($this->votesOn($pages), self::sortedIds($data['items']));
        self::assertSame(count($pages), $data['pagination']['total'], 'the total counts exactly the rows shown');
        self::assertSame(1, $data['pagination']['total_pages']);
    }

    public function testAnAdminSeesEveryReview(): void
    {
        $this->viewAs(self::ADMIN);
        $GLOBALS['__bcc_test_caps'] = ['manage_options'];

        $data = $this->ok($this->call('reviews'));

        self::assertSame(4, $data['pagination']['total']);
        self::assertCount(4, $data['items']);
    }

    /** The hidden reviews are left out in SQL, so paging walks only the shown ones. */
    public function testPagingAgreesWithTheTotal(): void
    {
        $this->viewAs(self::STRANGER);

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $data = $this->ok($this->call('reviews', ['page' => $page, 'per_page' => 1]));
            self::assertSame(2, $data['pagination']['total']);
            self::assertSame(2, $data['pagination']['total_pages']);
            foreach ($data['items'] as $item) {
                $seen[] = (int) $item['id'];
            }
        }

        sort($seen);
        self::assertSame($this->votesOn([self::OPEN, -1]), $seen, 'two pages of one row, then nothing');
    }

    public function testTheMemberThemselvesNeverPaysTheSecretPageRead(): void
    {
        $this->viewAs(self::AUTHOR);
        $GLOBALS['wpdb']->failQueriesMatching = self::REVIEW_SECRET_READ;

        self::assertSame(4, $this->ok($this->call('reviews'))['pagination']['total']);
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures);
    }

    // ── Disputes ─────────────────────────────────────────────────────────

    /** @return array<string, array{int, list<int>}> viewer => the pages whose disputes it is shown */
    public static function disputeViewers(): array
    {
        return [
            'the member themselves'            => [self::AUTHOR, [self::OPEN, self::SECRET, self::TWO_ROWS]],
            'a stranger'                       => [self::STRANGER, [self::OPEN]],
            'anonymous'                        => [0, [self::OPEN]],
            'a member of one of the secret pages' => [self::PAGE_MEMBER, [self::OPEN, self::SECRET]],
        ];
    }

    #[DataProvider('disputeViewers')]
    public function testTheDisputesTabShowsEachViewerOnlyThePagesItMaySee(int $viewer, array $pages): void
    {
        $this->viewAs($viewer);

        $data = $this->ok($this->call('disputes'));

        self::assertSame($this->disputesOn($pages), self::sortedIds($data['items']));
        self::assertSame(count($pages), $data['pagination']['total']);
    }

    /**
     * ⚠ The dispute reads are cached per member. The exclusion is part of
     * the key, so one viewer's answer is never served to another.
     */
    public function testOneViewersCachedDisputesAreNeverServedToAnother(): void
    {
        $totals = [];
        foreach ([self::STRANGER, self::PAGE_MEMBER, self::AUTHOR, self::STRANGER] as $viewer) {
            $this->viewAs($viewer);
            $data     = $this->ok($this->call('disputes'));
            $totals[] = [$data['pagination']['total'], count($data['items'])];
        }

        self::assertSame([[1, 1], [2, 2], [3, 3], [1, 1]], $totals);
    }

    // ── Vouches ──────────────────────────────────────────────────────────

    /** @return array<string, array{int, list<int>}> viewer => the pages whose vouches it is shown */
    public static function vouchViewers(): array
    {
        return [
            'the member themselves'            => [self::AUTHOR, [self::OPEN, self::SECRET, -1]],
            'a stranger'                       => [self::STRANGER, [self::OPEN, -1]],
            'anonymous'                        => [0, [self::OPEN, -1]],
            'a member of the secret page'      => [self::PAGE_MEMBER, [self::OPEN, self::SECRET, -1]],
        ];
    }

    #[DataProvider('vouchViewers')]
    public function testTheVouchesTabShowsEachViewerOnlyThePagesItMaySee(int $viewer, array $pages): void
    {
        $this->viewAs($viewer);

        $data = $this->ok($this->call('endorsements'));

        $expected = array_map(
            static fn (int $page): int => $page === -1 ? MemberSelfPageService::selfPageId(self::SUBJECT) : $page,
            $pages
        );
        sort($expected);
        $shown = array_map(static fn (array $item): int => (int) $item['page_id'], $data['items']);
        sort($shown);

        self::assertSame($expected, $shown);
        self::assertSame(count($pages), $data['total']);
    }

    // ── Failing closed ───────────────────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function secretReads(): array
    {
        return [
            'reviews'  => ['reviews', self::REVIEW_SECRET_READ],
            'disputes' => ['disputes', self::DISPUTE_SECRET_READ],
        ];
    }

    #[DataProvider('secretReads')]
    public function testAFailedSecretPageReadIsA503NotTheUnfilteredList(string $tab, string $read): void
    {
        $this->viewAs(self::STRANGER);
        $GLOBALS['wpdb']->failQueriesMatching = $read;

        self::assertUnavailable($this->call($tab));
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault really hit the secret-page read');
    }

    /** wpdb's silent failure leaves no last_error and stale rows; runRead() still sees it. */
    #[DataProvider('secretReads')]
    public function testASilentFailedSecretPageReadIsA503Too(string $tab, string $read): void
    {
        $this->viewAs(self::STRANGER);
        $GLOBALS['wpdb']->failSilentlyMatching = $read;

        self::assertUnavailable($this->call($tab));
        self::assertGreaterThan(0, $GLOBALS['wpdb']->silentFailures, 'the fault really hit the secret-page read');
    }

    /** At the cap the list is still checked, page by page. */
    #[DataProvider('secretReads')]
    public function testExactlyTheCapOfSecretPagesIsStillChecked(string $tab, string $read): void
    {
        unset($read);
        // The fixture has two secret pages already.
        $this->seedBulkSecretPages(PageAccess::HIDDEN_LIST_CAP - 2);
        $this->viewAs(self::STRANGER);

        $data = $this->ok($this->call($tab, ['per_page' => 50]));

        self::assertSame($tab === 'reviews' ? 2 : 1, $data['pagination']['total'], 'every secret page left out, every other row kept');
    }

    /**
     * Over the cap the list is not checked page by page: it answers 503
     * rather than show any of them, and says so in the log and the metric.
     */
    #[DataProvider('secretReads')]
    public function testOverTheCapOfSecretPagesTheListFailsClosed(string $tab, string $read): void
    {
        unset($read);
        $this->seedBulkSecretPages(PageAccess::HIDDEN_LIST_CAP - 1);
        $this->viewAs(self::STRANGER);
        \BCC\Core\Log\Logger::reset();
        $before = \BCC\Core\Observability\DegradationMetrics::readEvent('page_privacy', 'member_list_over_cap', time());

        self::assertUnavailable($this->call($tab));

        self::assertSame(
            $before + 1,
            \BCC\Core\Observability\DegradationMetrics::readEvent('page_privacy', 'member_list_over_cap', time()),
            'counted once, so a member stuck over the cap shows on /system/health'
        );
        $refusals = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $line): bool => $line['level'] === 'error'
                && str_contains($line['message'], 'over the secret-page cap')
        ));
        self::assertCount(1, $refusals);
        self::assertSame(self::AUTHOR, $refusals[0]['context']['member_id']);
        self::assertSame(PageAccess::HIDDEN_LIST_CAP, $refusals[0]['context']['cap']);
    }

    /**
     * Site admins see every page, so they never pay the secret-page read:
     * not when it would fail, and not when the member is over the cap.
     */
    #[DataProvider('secretReads')]
    public function testAnAdminNeverPaysTheSecretPageReadNorItsRefusal(string $tab, string $read): void
    {
        $this->seedBulkSecretPages(PageAccess::HIDDEN_LIST_CAP - 1);
        $this->viewAs(self::ADMIN);
        $GLOBALS['__bcc_test_caps']           = ['manage_options'];
        $GLOBALS['wpdb']->failQueriesMatching = $read;

        $data = $this->ok($this->call($tab, ['per_page' => 50]));

        // Every row: the fixture's own plus the bulk secret pages.
        self::assertSame(($tab === 'reviews' ? 4 : 3) + PageAccess::HIDDEN_LIST_CAP - 1, $data['pagination']['total']);
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures, 'the read never ran');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function viewAs(int $viewer): void
    {
        $GLOBALS['__bcc_pg']['viewer'] = $viewer;
    }

    /** @param array<string, int> $query */
    private function call(string $tab, array $query = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request(['handle' => 'f528author'] + $query + ['page' => 1, 'per_page' => 20, 'limit' => 20]);

        return match ($tab) {
            'reviews'      => (new UsersEndpoint())->reviews($request),
            'disputes'     => (new UsersEndpoint())->disputes($request),
            'endorsements' => (new UsersEndpoint())->endorsements($request),
        };
    }

    /** @return array<string, mixed> the 200's data */
    private function ok(\WP_REST_Response $response): array
    {
        self::assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));
        $data = $response->get_data()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /** The retryable refusal: no list, nothing cached. */
    private static function assertUnavailable(\WP_REST_Response $response): void
    {
        self::assertSame(503, $response->get_status(), (string) json_encode($response->get_data()));
        self::assertSame(
            ['code' => 'bcc_unavailable', 'message' => self::UNAVAILABLE, 'status' => 503],
            $response->get_data()['error']
        );
        self::assertArrayNotHasKey('data', $response->get_data(), 'no list, not even an empty one');
        self::assertSame(['Cache-Control' => 'private, no-store'], $response->get_headers());
    }

    /**
     * @param list<int> $pages -1 = the reviewed member's self-page
     * @return list<int>
     */
    private function votesOn(array $pages): array
    {
        $ids = array_map(
            fn (int $page): int => $this->voteOn[$page === -1 ? MemberSelfPageService::selfPageId(self::SUBJECT) : $page],
            $pages
        );
        sort($ids);

        return $ids;
    }

    /**
     * @param list<int> $pages
     * @return list<int>
     */
    private function disputesOn(array $pages): array
    {
        $ids = array_map(fn (int $page): int => $this->disputeOn[$page], $pages);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<int>
     */
    private static function sortedIds(array $items): array
    {
        $ids = array_map(static fn (array $item): int => (int) $item['id'], $items);
        sort($ids);

        return $ids;
    }

    private function ensureTables(): void
    {
        global $wpdb;

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', DisputeRepository::disputes_table())) !== DisputeRepository::disputes_table()) {
            DisputeRepository::install();
        }
        bcc_trust_create_trust_attestations_table();
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

        foreach ([self::OPEN => ['0'], self::SECRET => ['2'], self::TWO_ROWS => ['0', '2']] as $pageId => $privacy) {
            $this->insertPage($pageId, $privacy);
        }
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_page_members', [
            'pm_user_id' => self::PAGE_MEMBER, 'pm_page_id' => self::SECRET, 'pm_user_status' => 'member',
        ]), $wpdb->last_error);

        $selfPage = MemberSelfPageService::selfPageId(self::SUBJECT);
        $minute   = 0;
        foreach ([self::OPEN, self::SECRET, self::TWO_ROWS, $selfPage] as $pageId) {
            $this->voteOn[$pageId] = $this->insertReview($pageId, ++$minute);
        }
        foreach ([self::OPEN, self::SECRET, self::TWO_ROWS] as $pageId) {
            $this->disputeOn[$pageId] = $this->insertDispute($pageId, $this->voteOn[$pageId], ++$minute);
        }
        foreach ([['project_card', self::OPEN], ['project_card', self::SECRET], ['user_profile', self::SUBJECT]] as [$kind, $target]) {
            self::assertNotFalse($wpdb->insert(TableRegistry::trustAttestations(), [
                'attestor_user_id' => self::AUTHOR,
                'target_kind'      => $kind,
                'target_id'        => $target,
                'kind'             => 'vouch',
                'weight_at_time'   => 1.0,
                'created_at'       => gmdate('Y-m-d H:i:s', time() - 60 * ++$minute),
            ]), $wpdb->last_error);
        }
    }

    /** $count more secret pages, each with the member's review and dispute. */
    private function seedBulkSecretPages(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $pageId = self::BULK_BASE + $i;
            $this->insertPage($pageId, ['2']);
            $this->insertDispute($pageId, $this->insertReview($pageId, 100 + $i), 100 + $i);
        }
    }

    /** @param list<string> $privacy */
    private function insertPage(int $pageId, array $privacy): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID'          => $pageId,
            'post_title'  => 'F528 page ' . $pageId,
            'post_name'   => 'f528-page-' . $pageId,
            'post_type'   => 'peepso-page',
            'post_status' => 'publish',
        ]), 'page fixture: ' . $wpdb->last_error);
        foreach ($privacy as $value) {
            self::assertNotFalse($wpdb->insert($wpdb->postmeta, [
                'post_id' => $pageId, 'meta_key' => 'peepso_page_privacy', 'meta_value' => $value,
            ]), 'privacy fixture: ' . $wpdb->last_error);
        }
    }

    private function insertReview(int $pageId, int $minutesAgo): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(TableRegistry::votes(), [
            'voter_user_id' => self::AUTHOR,
            'page_id'       => $pageId,
            'vote_type'     => 1,
            'explanation'   => 'F528 review of ' . $pageId,
            'status'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s', time() - 60 * $minutesAgo),
        ]), 'vote fixture: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function insertDispute(int $pageId, int $voteId, int $minutesAgo): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(DisputeRepository::disputes_table(), [
            'vote_id'     => $voteId,
            'page_id'     => $pageId,
            'reporter_id' => self::AUTHOR,
            'voter_id'    => self::STRANGER,
            'reason'      => 'F528 dispute on ' . $pageId,
            'status'      => 'reviewing',
            'created_at'  => gmdate('Y-m-d H:i:s', time() - 60 * $minutesAgo),
        ]), 'dispute fixture: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    /** Only this suite's rows: its pages (and bulk pages), its member's votes, disputes and vouches. */
    private function purge(): void
    {
        global $wpdb;
        $pages = 'BETWEEN 952901 AND 952999 OR %s BETWEEN 953001 AND 953999';
        foreach ([
            [$wpdb->postmeta, 'post_id'],
            [$wpdb->posts, 'ID'],
            [$wpdb->prefix . 'peepso_page_members', 'pm_page_id'],
        ] as [$table, $column]) {
            $wpdb->query("DELETE FROM `{$table}` WHERE {$column} " . sprintf($pages, $column));
        }
        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::votes() . '` WHERE voter_user_id = %d', self::AUTHOR));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . DisputeRepository::disputes_table() . '` WHERE reporter_id = %d', self::AUTHOR));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::trustAttestations() . '` WHERE attestor_user_id = %d', self::AUTHOR));
    }
}
