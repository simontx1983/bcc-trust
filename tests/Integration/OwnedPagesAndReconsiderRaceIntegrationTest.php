<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Rank\Repositories\FindingsRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-146 — two lost-update races, against the REAL tables.
 *
 * (1) user_info.page_ids_owned / pages_owned. increment/decrementPageCount
 *     read the row through a 5-minute OBJECT CACHE the write never
 *     invalidated, then wrote back count±1 and the re-encoded JSON list. Two
 *     page creations in that window lost a page id and drifted the count;
 *     adding a page twice bumped the count twice.
 *
 * (2) findings.score_penalty. A reduction was guarded only on
 *     status = 'active', with "new < current" checked against a penalty read
 *     before the UPDATE. Two concurrent reductions both landed, and the later
 *     could RAISE the penalty.
 */
#[Group('integration')]
#[Group('mariadb')]
final class OwnedPagesAndReconsiderRaceIntegrationTest extends TestCase
{
    private const USER = 88001;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $wpdb = $GLOBALS['wpdb'];
        $root = dirname(__DIR__, 2);
        foreach ([
            [TableRegistry::userInfo(), $root . '/includes/database/schema-user-info.php', 'bcc_trust_create_user_info_table'],
            [TableRegistry::findings(), $root . '/includes/database/schema-findings.php', 'bcc_trust_create_findings_table'],
        ] as [$table, $file, $installer]) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                require_once $file;
                $installer();
            }
            self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)), "{$table} must exist");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::userInfo() . '` WHERE user_id = %d', self::USER));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . TableRegistry::findings() . '` WHERE subject_user_id = %d', self::USER));
        $wpdb->insert(TableRegistry::userInfo(), [
            'user_id' => self::USER, 'user_login' => 'u88001', 'user_email' => 'u88001@example.test', 'display_name' => 'U',
        ]);
        self::assertSame('', $wpdb->last_error, 'fixture: user_info row');
        wp_cache_delete('user_info_' . self::USER, 'bcc_trust');
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        parent::tearDown();
    }

    /** @return array{0: int, 1: list<int>} */
    private function stored(): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            'SELECT pages_owned, page_ids_owned FROM `' . TableRegistry::userInfo() . '` WHERE user_id = %d',
            self::USER
        ));
        self::assertIsObject($row);
        $ids = $row->page_ids_owned ? json_decode((string) $row->page_ids_owned, true) : [];
        return [(int) $row->pages_owned, array_map('intval', (array) $ids)];
    }

    // ── (1) owned pages ─────────────────────────────────────────────────

    public function testTwoPagesInsideTheCacheWindowAreBothKept(): void
    {
        $repo = new UserInfoRepository();
        $repo->getByUserId(self::USER); // warm the 5-minute cache, as a request would

        $repo->incrementPageCount(self::USER, 501);
        $repo->incrementPageCount(self::USER, 502);

        self::assertSame([2, [501, 502]], $this->stored());
    }

    /**
     * Another worker (its own object cache, or none) added a page after this
     * request cached the row. A write from the stale cached copy would drop
     * that page; the read must come from the table.
     */
    public function testAPageAddedByAnotherWorkerIsNotOverwrittenFromAStaleCache(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $repo = new UserInfoRepository();
        $repo->getByUserId(self::USER); // this request's cached copy: no pages

        $wpdb->update(TableRegistry::userInfo(), ['pages_owned' => 1, 'page_ids_owned' => '[400]'], ['user_id' => self::USER]);

        $repo->incrementPageCount(self::USER, 401);

        self::assertSame([2, [400, 401]], $this->stored());
    }

    public function testAddingThePageTwiceCountsItOnce(): void
    {
        $repo = new UserInfoRepository();
        $repo->incrementPageCount(self::USER, 601);
        $repo->incrementPageCount(self::USER, 601);

        self::assertSame([1, [601]], $this->stored());
    }

    public function testRemovingAnUnlistedPageChangesNothing(): void
    {
        $repo = new UserInfoRepository();
        $repo->incrementPageCount(self::USER, 701);
        $repo->decrementPageCount(self::USER, 999);

        self::assertSame([1, [701]], $this->stored());
    }

    public function testRemovingAListedPageDropsItAndTheCount(): void
    {
        $repo = new UserInfoRepository();
        $repo->incrementPageCount(self::USER, 801);
        $repo->incrementPageCount(self::USER, 802);
        $repo->decrementPageCount(self::USER, 801);

        self::assertSame([1, [802]], $this->stored());
    }

    public function testTheCacheIsInvalidatedAfterTheWrite(): void
    {
        $repo = new UserInfoRepository();
        $repo->getByUserId(self::USER);
        $repo->incrementPageCount(self::USER, 901);

        self::assertSame(1, (int) $repo->getByUserId(self::USER)->pages_owned, 'a read after the write must not be the stale cached row');
    }

    public function testALegacyRowWithACountButNoListStillDecrements(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->update(TableRegistry::userInfo(), ['pages_owned' => 3, 'page_ids_owned' => null], ['user_id' => self::USER]);

        (new UserInfoRepository())->decrementPageCount(self::USER, 123);

        self::assertSame([2, []], $this->stored());
    }

    public function testAFailedReadThrowsAndWritesNothing(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        (new UserInfoRepository())->incrementPageCount(self::USER, 1001);

        $wpdb->failQueriesMatching = '/SELECT pages_owned, page_ids_owned FROM/i';
        try {
            (new UserInfoRepository())->incrementPageCount(self::USER, 1002);
            self::fail('a failed read must not be treated as an empty list');
        } catch (RepositoryException $e) {
            self::assertGreaterThan(0, $wpdb->injectedFailures);
        } finally {
            $wpdb->clearFaultInjection();
        }

        self::assertSame([1, [1001]], $this->stored(), 'the earlier page survives; nothing was overwritten');
    }

    // ── (2) findings reduction ──────────────────────────────────────────

    private function finding(float $penalty): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->insert(TableRegistry::findings(), [
            'subject_user_id' => self::USER, 'finding_type' => 'test', 'severity' => 'minor', 'source' => 'admin',
            'issued_by' => 1, 'created_at' => '2026-11-01 00:00:00', 'effective_at' => '2026-11-01 00:00:00',
            'score_penalty' => $penalty, 'penalty_expires_at' => '2027-11-01 00:00:00',
        ]);
        self::assertSame('', $wpdb->last_error, 'fixture: finding row');
        return (int) $wpdb->insert_id;
    }

    private function penalty(int $id): float
    {
        $wpdb = $GLOBALS['wpdb'];
        return (float) $wpdb->get_var($wpdb->prepare('SELECT score_penalty FROM `' . TableRegistry::findings() . '` WHERE id = %d', $id));
    }

    public function testTwoReductionsCannotRaiseThePenalty(): void
    {
        $id   = $this->finding(10.0);
        $repo = new FindingsRepository();

        // Both admins validated "new < 10" against the same earlier read.
        self::assertTrue($repo->applyReconsideration($id, self::USER, 'reduced', 8.0, false, null));
        self::assertFalse(
            $repo->applyReconsideration($id, self::USER, 'reduced', 9.0, false, null),
            'the second "reduction" would raise 8 → 9; it must lose the claim'
        );
        self::assertSame(8.0, $this->penalty($id));
    }

    public function testASecondGenuineReductionStillApplies(): void
    {
        $id   = $this->finding(10.0);
        $repo = new FindingsRepository();

        self::assertTrue($repo->applyReconsideration($id, self::USER, 'reduced', 8.0, false, null));
        self::assertTrue($repo->applyReconsideration($id, self::USER, 'reduced', 5.0, false, null));
        self::assertSame(5.0, $this->penalty($id));
    }
}
