<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Real-SQL proof for the Repair page's "Check Tables & Remove Orphans":
 * what the repositories REPORT deleted equals what is actually gone, on
 * MySQL and MariaDB.
 *
 * Regression 1: ScoreRepository::deleteOrphaned() was `DELETE s … JOIN …
 * LIMIT n` — invalid on both engines (no LIMIT on multi-table DELETE). It
 * deleted nothing, the (int) cast turned `false` into 0, and runDbCheck
 * still reported the pre-count as fixed.
 *
 * Regression 2 (would have followed a naive SQL fix): member self-page
 * rows (page_id = ID_BASE + user_id) never have a wp_posts row, so "no
 * matching post" matched EVERY member's trust row. They must survive.
 */
#[Group('integration')]
#[CoversClass(ScoreRepository::class)]
#[CoversClass(UserInfoRepository::class)]
final class OrphanCleanupIntegrationTest extends TestCase
{
    private const LIVE_PAGE    = 940101;
    private const ORPHAN_A     = 940102;
    private const ORPHAN_B     = 940103;
    private const ORPHAN_C     = 940104;
    private const LIVE_USER    = 940501;
    private const GONE_USER    = 940502;

    private function wpdb(): object
    {
        return $GLOBALS['wpdb'];
    }

    protected function setUp(): void
    {
        $db = $this->wpdb();
        $db->query('DELETE FROM `' . TableRegistry::scores() . '`');
        $db->query('DELETE FROM `' . TableRegistry::userInfo() . '`');
        $db->query('DELETE FROM `wp_posts` WHERE ID BETWEEN 940000 AND 949999');
        $db->query('DELETE FROM `wp_users` WHERE ID BETWEEN 940000 AND 949999');
    }

    private function score(int $pageId, int $category = 0): void
    {
        $this->wpdb()->query(sprintf(
            'INSERT INTO `%s` (page_id, category_id, page_owner_id) VALUES (%d, %d, 1)',
            TableRegistry::scores(),
            $pageId,
            $category
        ));
    }

    /** @return list<int> */
    private function scorePageIds(): array
    {
        $rows = $this->wpdb()->get_col('SELECT page_id FROM `' . TableRegistry::scores() . '` ORDER BY page_id, category_id');
        return array_map('intval', $rows);
    }

    public function testDeletesOnlyOrphanedPageRowsAndReportsTheRealCount(): void
    {
        $member     = MemberSelfPageService::ID_BASE + 77;     // member self-page: never a post
        $goneMember = MemberSelfPageService::ID_BASE + 78;
        $this->wpdb()->query('INSERT INTO `wp_posts` (ID, post_type) VALUES (' . self::LIVE_PAGE . ", 'peepso-page')");

        $this->score(self::LIVE_PAGE);
        $this->score(self::ORPHAN_A, 0);
        $this->score(self::ORPHAN_A, 3);   // second category row of the same orphaned page
        $this->score($member);
        $this->score($goneMember);

        $repo = new ScoreRepository();
        self::assertSame(2, $repo->countOrphaned(), 'member self-page rows are not orphans');

        $deleted = $repo->deleteOrphaned();

        self::assertSame(2, $deleted, 'reported == actually deleted');
        self::assertSame([self::LIVE_PAGE, $member, $goneMember], $this->scorePageIds());
        self::assertSame(0, $repo->countOrphaned());
    }

    public function testLimitBoundsOrphanedPagesAndStillReportsRealRows(): void
    {
        $this->score(self::ORPHAN_A);
        $this->score(self::ORPHAN_B);
        $this->score(self::ORPHAN_C);

        $repo    = new ScoreRepository();
        $before  = count($this->scorePageIds());
        $deleted = $repo->deleteOrphaned(2);
        $after   = count($this->scorePageIds());

        self::assertSame(2, $deleted);
        self::assertSame($before - $deleted, $after);
        self::assertSame(1, $repo->countOrphaned());
    }

    public function testUserInfoOrphansDeletedAndCountMatches(): void
    {
        $db = $this->wpdb();
        $db->query("INSERT INTO `wp_users` (ID, user_login) VALUES (" . self::LIVE_USER . ", 'live')");
        foreach ([self::LIVE_USER, self::GONE_USER] as $uid) {
            $db->query(sprintf(
                "INSERT INTO `%s` (user_id, user_login, user_email, display_name) VALUES (%d, 'u%d', 'u%d@example.test', 'U')",
                TableRegistry::userInfo(),
                $uid,
                $uid,
                $uid
            ));
        }

        $repo = new UserInfoRepository();
        self::assertSame(1, $repo->countOrphaned());
        self::assertSame(1, $repo->deleteOrphaned());

        $left = array_map('intval', $db->get_col('SELECT user_id FROM `' . TableRegistry::userInfo() . '`'));
        self::assertSame([self::LIVE_USER], $left);
    }
}
