<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoPagePrivacy;
use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\PageDiscoveryRepository;
use BCC\Trust\Core\Repositories\ReadModelHealthRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SECRET pages (`peepso_page_privacy = 2`) on the page surfaces, run on a
 * real server.
 *
 * Discovery (`/cards`, which `/onboarding/suggestions` also reads through
 * PageDiscoveryService) and trending hide secret pages from everyone, through
 * bcc-core's shared PeepSoPagePrivacy::notSecretSql(). The single card asks
 * PeepSoPagePrivacy::isVisibleTo(), which lets members and invitees in.
 *
 * ⚠⚠ THE TWO-ROW PAGES ARE THE POINT. Nothing stops a page from carrying two
 * privacy rows. The old LEFT JOIN form let a page with rows 0 and 2 through
 * (the 0 row passed) and listed a page with rows 0 and 0 twice.
 * {@see testTheOldLeftJoinFormLeakedAndDuplicated} runs that old form on the
 * same fixture, so these tests are known to catch it.
 *
 * Only a real server has an opinion about the SQL, so these are integration
 * tests; the unit tests pin the wiring.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(PageDiscoveryRepository::class)]
#[CoversClass(ReadModelHealthRepository::class)]
final class SecretPageVisibilityIntegrationTest extends TestCase
{
    private const OPEN          = 993101;
    private const CLOSED        = 993102;
    private const SECRET        = 993103;
    private const NO_META       = 993104;
    /** Privacy rows '0' and '2': secret, and must be hidden. */
    private const TWO_ROWS_SECRET = 993105;
    /** Privacy rows '0' and '0': open, and must be listed ONCE. */
    private const TWO_ROWS_OPEN   = 993106;

    /** What every list must return: each non-secret fixture page, once. */
    private const LISTED = [self::OPEN, self::CLOSED, self::NO_META, self::TWO_ROWS_OPEN];

    private const MEMBER       = 993201;
    private const OWNER        = 993202;
    private const INVITED      = 993203;
    private const ASKED        = 993204;
    private const BANNED_FIRST = 993205;
    private const BANNED_LAST  = 993206;
    private const STRANGER     = 993207;

    protected function setUp(): void
    {
        $this->ensureTables();

        $GLOBALS['wpdb']->clearFaultInjection();
        \BCC\Core\Log\Logger::reset();

        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        // A failed case must not leave a fault armed for the next one.
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    // ── discovery and trending: hidden from everyone, nothing twice ─────

    public function testDiscoveryFromThePostsTableHidesSecretPagesAndListsEachPageOnce(): void
    {
        $result = PageDiscoveryRepository::queryFromPostsTable([], 'newest', false, '', 0.0, false, 0, 50, 1, '');

        self::assertSame(self::LISTED, self::sortedIds($result['rows']));
        self::assertSame(4, $result['total'], 'the COUNT applies the same rule');
        self::assertSame([], \BCC\Core\Log\Logger::$lines, 'nothing failed');
    }

    public function testDiscoveryFromTheReadModelHidesSecretPagesAndListsEachPageOnce(): void
    {
        $result = PageDiscoveryRepository::queryFromReadModel([], 'newest', false, '', 0.0, false, 0, 50, 1, '');

        self::assertSame(self::LISTED, self::sortedIds($result['rows']));
        self::assertSame(4, $result['total'], 'the COUNT applies the same rule');
        self::assertSame([], \BCC\Core\Log\Logger::$lines, 'nothing failed');
    }

    public function testTrendingHidesSecretPagesAndListsEachPageOnce(): void
    {
        $rows = (new ReadModelHealthRepository())->getTrendingPages(50);

        self::assertSame('', (string) $GLOBALS['wpdb']->last_error);
        self::assertSame(self::LISTED, self::sortedIds($rows));
    }

    /**
     * ⚠ ANTI-VACUITY. The old LEFT JOIN form, on the same fixture, shows the
     * 0+2 page and lists the 0+0 page twice. If it did not, the cases above
     * would pass whether or not the new rule were in place.
     */
    public function testTheOldLeftJoinFormLeakedAndDuplicated(): void
    {
        global $wpdb;

        $ids = array_map('intval', $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
               LEFT JOIN {$wpdb->postmeta} pm_priv
                      ON pm_priv.post_id = p.ID AND pm_priv.meta_key = 'peepso_page_privacy'
              WHERE p.post_type = 'peepso-page' AND p.post_status = 'publish'
                AND (pm_priv.meta_value IS NULL OR CAST(pm_priv.meta_value AS UNSIGNED) <> 2)
              ORDER BY p.ID"
        ));

        self::assertSame('', (string) $wpdb->last_error);
        self::assertContains(self::TWO_ROWS_SECRET, $ids, 'the old form leaked the 0+2 page');
        self::assertSame(2, count(array_keys($ids, self::TWO_ROWS_OPEN, true)), 'and listed the 0+0 page twice');
    }

    // ── one page on its own ─────────────────────────────────────────────

    public function testTheOnePageProbeAgreesWithTheLists(): void
    {
        foreach ([self::SECRET, self::TWO_ROWS_SECRET] as $page) {
            self::assertTrue(PeepSoPagePrivacy::isSecretOrUnknown($page), "page {$page} is secret");
        }
        foreach (self::LISTED as $page) {
            self::assertFalse(PeepSoPagePrivacy::isSecretOrUnknown($page), "page {$page} is not secret");
        }
    }

    public function testASecretPageIsVisibleToItsMembersAndInviteesOnly(): void
    {
        $expected = [
            self::MEMBER       => true,
            self::OWNER        => true,
            self::INVITED      => true,
            self::ASKED        => false,
            self::BANNED_FIRST => false,
            self::BANNED_LAST  => false,
            self::STRANGER     => false,
            0                  => false,
        ];
        foreach ($expected as $viewer => $visible) {
            self::assertSame($visible, PeepSoPagePrivacy::isVisibleTo(self::SECRET, $viewer), "viewer {$viewer}");
        }
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error);
    }

    public function testAnOpenPageIsVisibleToEveryone(): void
    {
        foreach ([0, self::STRANGER, self::BANNED_FIRST] as $viewer) {
            self::assertTrue(PeepSoPagePrivacy::isVisibleTo(self::OPEN, $viewer), "viewer {$viewer}");
            self::assertTrue(PeepSoPagePrivacy::isVisibleTo(self::TWO_ROWS_OPEN, $viewer), "viewer {$viewer}");
        }
    }

    public function testAFailedPrivacyReadHidesEvenAnOpenPage(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/bcc_page_priv/';

        self::assertFalse(PeepSoPagePrivacy::isVisibleTo(self::OPEN, self::STRANGER));
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the probe really failed');
    }

    // ── fixture ─────────────────────────────────────────────────────────

    /**
     * @param array<int, object> $rows
     * @return list<int> fixture page ids, sorted, duplicates kept
     */
    private static function sortedIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
        }
        sort($ids);
        return $ids;
    }

    /**
     * The read model comes from the plugin's own installer (a hand-rolled
     * stand-in would shadow the real schema for every suite sharing this
     * database). wp_posts is widened and usermeta / the PeepSo page members
     * table created idempotently, as the sibling discovery suites do; none of
     * it is dropped in teardown, so a sibling suite keeps what it added.
     */
    private function ensureTables(): void
    {
        global $wpdb;

        $readModel = TableRegistry::pageReadModel();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $readModel)) !== $readModel) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-project.php';
            \bcc_trust_create_page_tables();
        }
        self::assertSame($readModel, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $readModel)));

        $existing = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        $adds = [];
        foreach ([
            'post_author' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_date'   => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'",
        ] as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }
        if ($adds !== []) {
            self::assertNotFalse(
                $wpdb->query("ALTER TABLE {$wpdb->posts} " . implode(', ', $adds)),
                'posts fixture: ' . $wpdb->last_error
            );
        }

        self::assertNotFalse(
            $wpdb->query(
                "CREATE TABLE IF NOT EXISTS {$wpdb->usermeta} (
                    umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    meta_key VARCHAR(255) DEFAULT NULL,
                    meta_value LONGTEXT,
                    PRIMARY KEY (umeta_id),
                    KEY user_id (user_id)
                )"
            ),
            'usermeta fixture: ' . $wpdb->last_error
        );

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

        $privacy = [
            self::OPEN            => ['0'],
            self::CLOSED          => ['1'],
            self::SECRET          => ['2'],
            self::NO_META         => [],
            self::TWO_ROWS_SECRET => ['0', '2'],
            self::TWO_ROWS_OPEN   => ['0', '0'],
        ];
        $score = 40.0;
        foreach ($privacy as $pageId => $values) {
            self::assertNotFalse(
                $wpdb->insert($wpdb->posts, [
                    'ID'          => $pageId,
                    'post_title'  => 'Privacy fixture ' . $pageId,
                    'post_name'   => 'privacy-fixture-' . $pageId,
                    'post_type'   => 'peepso-page',
                    'post_status' => 'publish',
                    'post_date'   => '2026-01-01 00:00:00',
                ]),
                'page fixture: ' . $wpdb->last_error
            );
            foreach ($values as $value) {
                self::assertNotFalse(
                    $wpdb->insert($wpdb->postmeta, [
                        'post_id'    => $pageId,
                        'meta_key'   => 'peepso_page_privacy',
                        'meta_value' => $value,
                    ]),
                    'privacy fixture: ' . $wpdb->last_error
                );
            }
            $score += 5.0;
            self::assertNotFalse(
                $wpdb->insert(TableRegistry::pageReadModel(), [
                    'page_id'     => $pageId,
                    'trust_score' => $score,
                ]),
                'read-model fixture: ' . $wpdb->last_error
            );
        }

        foreach ([
            [self::MEMBER, 'member'],
            [self::OWNER, 'member_owner'],
            [self::INVITED, 'pending_user'],
            [self::ASKED, 'pending_admin'],
            [self::BANNED_FIRST, 'banned'],
            [self::BANNED_FIRST, 'member'],
            [self::BANNED_LAST, 'member'],
            [self::BANNED_LAST, 'banned'],
        ] as [$userId, $status]) {
            self::assertNotFalse(
                $wpdb->insert($wpdb->prefix . 'peepso_page_members', [
                    'pm_user_id' => $userId, 'pm_page_id' => self::SECRET, 'pm_user_status' => $status,
                ]),
                'member fixture: ' . $wpdb->last_error
            );
        }

        // ⚠ Asserted, not assumed: a silently empty fixture would make every
        // "hidden" assertion pass for the wrong reason.
        self::assertSame(
            6,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'peepso-page'"),
            'fixture precondition: the six fixture pages, and no stray from another suite'
        );
    }

    /**
     * wp_posts and wp_postmeta are emptied WHOLESALE: they are shared, and
     * another suite's leftover published page is a real match for these
     * lists and would break the exact assertions.
     */
    private function purge(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
        $wpdb->query('DELETE FROM `' . TableRegistry::pageReadModel() . '` WHERE page_id BETWEEN 993101 AND 993199');
        $wpdb->query("DELETE FROM `{$wpdb->prefix}peepso_page_members` WHERE pm_page_id BETWEEN 993101 AND 993199");
    }
}
