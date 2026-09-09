<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\HallOwnershipRepairRepository as Repo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The repair's SQL, against a real MySQL.
 *
 * ── WHY THIS EXISTS SEPARATELY FROM THE UNIT SUITE ──────────────────────
 * The unit tests fake this repository, so they can prove the SERVICE reacts
 * correctly to what the repository returns — but they cannot prove the
 * repository returns the right thing. Three properties live only in the SQL
 * and a mutation control against the unit suite alone survives all three:
 *
 *   1. `repointOwnerRow()`'s WHERE clause is fully qualified. Dropping the
 *      `gm_user_id` or `gm_user_status` predicate still passes every unit
 *      test, because the fake matches on gm_id anyway. Here it does not.
 *   2. `computePeepSoMemberCount()` mirrors PeepSo's own LEFT JOIN against
 *      `peepso_users` with the `usr_role NOT IN (…)` filter. A naive
 *      `COUNT(*)` returns a different number for exactly the rows this
 *      repair touches — user 0 has no `peepso_users` row at all.
 *   3. The locking reads refuse to run outside a transaction.
 */
#[Group('integration')]
#[CoversClass(Repo::class)]
final class HallOwnershipRepairRepositoryIntegrationTest extends TestCase
{
    private const GROUP = 6703;

    private function members(): string
    {
        return $GLOBALS['wpdb']->prefix . 'peepso_group_members';
    }

    private function peepsoUsers(): string
    {
        return $GLOBALS['wpdb']->prefix . 'peepso_users';
    }

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $wpdb->query(
            'CREATE TABLE IF NOT EXISTS `' . $this->members() . '` (
                gm_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                gm_user_id BIGINT UNSIGNED NOT NULL,
                gm_group_id BIGINT UNSIGNED NOT NULL,
                gm_user_status VARCHAR(32) NOT NULL,
                PRIMARY KEY (gm_id),
                KEY gm_user_id (gm_user_id),
                KEY gm_group_id (gm_group_id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $wpdb->query(
            'CREATE TABLE IF NOT EXISTS `' . $this->peepsoUsers() . '` (
                usr_id BIGINT UNSIGNED NOT NULL,
                usr_role VARCHAR(32) NOT NULL,
                PRIMARY KEY (usr_id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $wpdb->query('TRUNCATE TABLE `' . $this->members() . '`');
        $wpdb->query('TRUNCATE TABLE `' . $this->peepsoUsers() . '`');
    }

    private function insertRow(int $gmId, int $userId, string $status, int $groupId = self::GROUP): void
    {
        $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
            'INSERT INTO `' . $this->members() . '` (gm_id, gm_user_id, gm_group_id, gm_user_status)
             VALUES (%d, %d, %d, %s)',
            $gmId,
            $userId,
            $groupId,
            $status
        ));
    }

    private function insertPeepSoUser(int $userId, string $role): void
    {
        $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
            'INSERT INTO `' . $this->peepsoUsers() . '` (usr_id, usr_role) VALUES (%d, %s)',
            $userId,
            $role
        ));
    }

    /** @return list<string> "gm_id:user:status" */
    private function snapshot(int $groupId = self::GROUP): array
    {
        $rows = $GLOBALS['wpdb']->get_results($GLOBALS['wpdb']->prepare(
            'SELECT gm_id, gm_user_id, gm_user_status FROM `' . $this->members() . '`
              WHERE gm_group_id = %d ORDER BY gm_id',
            $groupId
        ));

        return array_map(
            static fn(object $r): string => $r->gm_id . ':' . $r->gm_user_id . ':' . $r->gm_user_status,
            $rows ?: []
        );
    }

    /** Run a callback as if inside TransactionManager::run(). */
    private function inTransaction(callable $fn): mixed
    {
        return \BCC\Trust\Core\Security\TransactionManager::run($fn);
    }

    // ── The guarded UPDATE ───────────────────────────────────────────────

    public function testRepointMovesExactlyTheOrphanOwnerRow(): void
    {
        $this->insertRow(100, 0, 'member_owner');
        $this->insertRow(101, 49, 'member');

        $n = $this->inTransaction(fn(): int => Repo::repointOwnerRow(100, self::GROUP, 0, 1, 'member_owner'));

        self::assertSame(1, $n);
        self::assertSame(['100:1:member_owner', '101:49:member'], $this->snapshot());
    }

    /**
     * ⚠ THE WHERE CLAUSE IS THE SAFETY DEVICE.
     *
     * If the row no longer holds the from-user the caller verified, the
     * UPDATE must match NOTHING — that is what turns a concurrent change or
     * a stale plan into a refusal the service rolls back on, rather than a
     * silent overwrite.
     */
    public function testRepointMatchesNothingWhenTheFromUserDiffers(): void
    {
        $this->insertRow(100, 2, 'member_owner');   // already a real owner
        $before = $this->snapshot();

        $n = $this->inTransaction(fn(): int => Repo::repointOwnerRow(100, self::GROUP, 0, 1, 'member_owner'));

        self::assertSame(0, $n, 'the from-user predicate must stop the update');
        self::assertSame($before, $this->snapshot());
    }

    public function testRepointMatchesNothingWhenTheStatusDiffers(): void
    {
        $this->insertRow(100, 0, 'member');   // orphan, but not the owner row
        $before = $this->snapshot();

        $n = $this->inTransaction(fn(): int => Repo::repointOwnerRow(100, self::GROUP, 0, 1, 'member_owner'));

        self::assertSame(0, $n, 'the status predicate must stop the update');
        self::assertSame($before, $this->snapshot());
    }

    public function testRepointMatchesNothingWhenTheGroupDiffers(): void
    {
        $this->insertRow(100, 0, 'member_owner', 9999);
        $before = $this->snapshot(9999);

        $n = $this->inTransaction(fn(): int => Repo::repointOwnerRow(100, self::GROUP, 0, 1, 'member_owner'));

        self::assertSame(0, $n);
        self::assertSame($before, $this->snapshot(9999));
    }

    /** Two Halls, one repair: the other Hall's identical orphan is untouched. */
    public function testRepointNeverTouchesAnotherGroupsOrphanRow(): void
    {
        $this->insertRow(100, 0, 'member_owner');
        $this->insertRow(200, 0, 'member_owner', 7777);

        $this->inTransaction(fn(): int => Repo::repointOwnerRow(100, self::GROUP, 0, 1, 'member_owner'));

        self::assertSame(['100:1:member_owner'], $this->snapshot());
        self::assertSame(['200:0:member_owner'], $this->snapshot(7777), 'the other group is byte-identical');
    }

    /**
     * The second link in the equivalence chain the unit suite documents: a
     * refused plan carries `row_id => 0`, and a non-positive row id can
     * never reach the database.
     */
    public function testANonPositiveRowIdNeverReachesTheDatabase(): void
    {
        $this->insertRow(100, 0, 'member_owner');
        $before = $this->snapshot();

        foreach ([0, -1] as $rowId) {
            $n = $this->inTransaction(fn(): int => Repo::repointOwnerRow($rowId, self::GROUP, 0, 1, 'member_owner'));
            self::assertSame(0, $n, 'row id ' . $rowId . ' must be refused before the UPDATE');
        }

        self::assertSame($before, $this->snapshot());
    }

    // ── PeepSo's own member count ────────────────────────────────────────

    /**
     * The whole reason every broken Hall reads `members_count = 0`: user 0
     * has no `peepso_users` row, the LEFT JOIN yields NULL, and
     * `NULL NOT IN (…)` is NULL — so the row is excluded.
     */
    public function testTheOrphanOwnerIsNotCountedByPeepSosRule(): void
    {
        $this->insertRow(100, 0, 'member_owner');
        // Deliberately NO peepso_users row for user 0.

        self::assertSame(0, Repo::computePeepSoMemberCount(self::GROUP));
    }

    public function testARealOwnerIsCounted(): void
    {
        $this->insertRow(100, 1, 'member_owner');
        $this->insertPeepSoUser(1, 'admin');

        self::assertSame(1, Repo::computePeepSoMemberCount(self::GROUP));
    }

    public function testTheCountRisesWhenTheOrphanBecomesARealOwner(): void
    {
        $this->insertRow(100, 0, 'member_owner');
        $this->insertRow(101, 49, 'member');
        $this->insertPeepSoUser(1, 'admin');
        $this->insertPeepSoUser(49, 'member');

        self::assertSame(1, Repo::computePeepSoMemberCount(self::GROUP), 'only the real member counts');

        $this->inTransaction(fn(): int => Repo::repointOwnerRow(100, self::GROUP, 0, 1, 'member_owner'));

        self::assertSame(2, Repo::computePeepSoMemberCount(self::GROUP));
    }

    /** PeepSo excludes these three roles; so must we, or the counts diverge. */
    public function testExcludedPeepSoRolesAreNotCounted(): void
    {
        $this->insertRow(100, 1, 'member_owner');
        $this->insertRow(101, 2, 'member');
        $this->insertRow(102, 3, 'member');
        $this->insertRow(103, 4, 'member');
        $this->insertPeepSoUser(1, 'admin');
        $this->insertPeepSoUser(2, 'register');
        $this->insertPeepSoUser(3, 'ban');
        $this->insertPeepSoUser(4, 'verified');

        self::assertSame(1, Repo::computePeepSoMemberCount(self::GROUP), 'only the admin is countable');
    }

    public function testNonMemberStatusesAreNotCounted(): void
    {
        $this->insertRow(100, 1, 'member_owner');
        $this->insertRow(101, 2, 'pending_user');
        $this->insertRow(102, 3, 'banned');
        $this->insertPeepSoUser(1, 'admin');
        $this->insertPeepSoUser(2, 'member');
        $this->insertPeepSoUser(3, 'member');

        self::assertSame(1, Repo::computePeepSoMemberCount(self::GROUP));
    }

    // ── The lock assertion ───────────────────────────────────────────────

    /**
     * `SELECT … FOR UPDATE` outside a transaction takes NO lock and silently
     * succeeds, so a caller that forgot the transaction would get rows it
     * believes are locked. Failing loudly is the only safe answer.
     */
    public function testLockingReadsRefuseToRunOutsideATransaction(): void
    {
        $this->insertRow(100, 0, 'member_owner');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must run inside TransactionManager::run/');

        Repo::lockMembershipRows(self::GROUP);
    }

    public function testTheGuardedUpdateAlsoRefusesOutsideATransaction(): void
    {
        $this->insertRow(100, 0, 'member_owner');

        $this->expectException(\RuntimeException::class);

        Repo::repointOwnerRow(100, self::GROUP, 0, 1, 'member_owner');
    }

    // ── Unlocked reads ───────────────────────────────────────────────────

    public function testReadMembershipRowsReturnsEveryRowInIdOrder(): void
    {
        $this->insertRow(102, 49, 'member');
        $this->insertRow(100, 0, 'member_owner');
        $this->insertRow(101, 2, 'member');

        $rows = Repo::readMembershipRows(self::GROUP);

        self::assertSame(
            ['100:0:member_owner', '101:2:member', '102:49:member'],
            array_map(
                static fn(object $r): string => $r->gm_id . ':' . $r->gm_user_id . ':' . $r->gm_user_status,
                $rows
            )
        );
    }

    public function testIsPeepSoCountableUserMatchesTheCountRule(): void
    {
        $this->insertPeepSoUser(1, 'admin');
        $this->insertPeepSoUser(2, 'ban');

        self::assertTrue(Repo::isPeepSoCountableUser(1));
        self::assertFalse(Repo::isPeepSoCountableUser(2));
        self::assertFalse(Repo::isPeepSoCountableUser(999), 'no peepso_users row at all');
        self::assertFalse(Repo::isPeepSoCountableUser(0));
    }
}
