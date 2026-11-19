<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Who gets the "@x posted in <Hall>" bell, against a real database.
 *
 * NotificationDispatcher::dispatchPrimaryHallPostFor() fans out to
 * PeepSoGroupRepository::findUsersByPrimaryHall() (bcc-core). The
 * bcc_primary_hall_group_id pointer is cleared only by BCC's own
 * leaveHall(), so a member removed through PeepSo (row deleted) or banned
 * (row set to 'banned') used to keep getting the Hall's bells. The read
 * now also requires an active (member%) row and no banned row for the
 * pair; every case here is one way the pointer and the roster can
 * disagree.
 */
#[Group('integration')]
#[Group('mariadb')]
final class PrimaryHallRecipientsIntegrationTest extends TestCase
{
    private const HALL  = 990441;
    private const OTHER = 990442;

    /** First fixture user; every case uses its own id from here up. */
    private const U = 990451;

    private const POINTER = 'bcc_primary_hall_group_id';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $wpdb;
        // As WordPress declares it (other integration tests create the same).
        $ok = $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->usermeta}` (
                umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255) NULL DEFAULT NULL,
                meta_value LONGTEXT NULL,
                PRIMARY KEY (umeta_id),
                KEY user_id (user_id),
                KEY meta_key (meta_key(191))
            )"
        );
        self::assertNotFalse($ok, (string) $wpdb->last_error);
        // PeepSo Groups' roster (only the columns these reads touch). No
        // unique (group, user) key, as in PeepSo.
        $ok = $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_group_members` (
                gm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gm_user_id BIGINT UNSIGNED NOT NULL,
                gm_group_id BIGINT UNSIGNED NOT NULL,
                gm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
                gm_joined DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (gm_id)
            )"
        );
        self::assertNotFalse($ok, (string) $wpdb->last_error);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->clear();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->clear();
        parent::tearDown();
    }

    private function clear(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->usermeta}` WHERE meta_key = %s AND user_id BETWEEN %d AND %d",
            self::POINTER,
            self::U,
            self::U + 99
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->prefix}peepso_group_members` WHERE gm_group_id IN (%d, %d)",
            self::HALL,
            self::OTHER
        ));
    }

    private function pointer(int $userId, int $groupId): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => self::POINTER, 'meta_value' => (string) $groupId]);
        self::assertNotFalse($ok, (string) $wpdb->last_error);
    }

    /** @param list<string> $statuses in insert (gm_id) order */
    private function rows(int $userId, int $groupId, array $statuses): void
    {
        global $wpdb;
        foreach ($statuses as $status) {
            $ok = $wpdb->insert($wpdb->prefix . 'peepso_group_members', [
                'gm_user_id' => $userId, 'gm_group_id' => $groupId, 'gm_user_status' => $status,
            ]);
            self::assertNotFalse($ok, (string) $wpdb->last_error);
        }
    }

    public function testOnlyActiveUnbannedMembersWithThePointerAreRecipients(): void
    {
        $member      = self::U;      // ✔ pointer + an active row
        $removed     = self::U + 1;  // ✘ pointer, row deleted by a PeepSo removal
        $banned      = self::U + 2;  // ✘ pointer, row set to 'banned'
        $banBeside   = self::U + 3;  // ✘ a ban beside a stray active row
        $banFirst    = self::U + 4;  // ✘ the same, other row order, beside an owner row
        $pending     = self::U + 5;  // ✘ a join request is not membership
        $twoActive   = self::U + 6;  // ✔ two active rows: listed once
        $elsewhere   = self::U + 7;  // ✘ pointer here, membership in another group only
        $otherHall   = self::U + 8;  // ✘ a member here whose pointer names another Hall
        $moderator   = self::U + 9;  // ✔ any member% role counts

        $this->pointer($member, self::HALL);
        $this->rows($member, self::HALL, ['member']);

        $this->pointer($removed, self::HALL);

        $this->pointer($banned, self::HALL);
        $this->rows($banned, self::HALL, ['banned']);

        $this->pointer($banBeside, self::HALL);
        $this->rows($banBeside, self::HALL, ['member', 'banned']);

        $this->pointer($banFirst, self::HALL);
        $this->rows($banFirst, self::HALL, ['banned', 'member_owner']);

        $this->pointer($pending, self::HALL);
        $this->rows($pending, self::HALL, ['pending_admin']);

        $this->pointer($twoActive, self::HALL);
        $this->rows($twoActive, self::HALL, ['member', 'member_moderator']);

        $this->pointer($elsewhere, self::HALL);
        $this->rows($elsewhere, self::OTHER, ['member']);

        $this->pointer($otherHall, self::OTHER);
        $this->rows($otherHall, self::HALL, ['member']);

        $this->pointer($moderator, self::HALL);
        $this->rows($moderator, self::HALL, ['member_moderator']);

        self::assertSame(
            [$member, $twoActive, $moderator],
            PeepSoGroupRepository::findUsersByPrimaryHall(self::HALL, 1000)
        );
    }

    public function testTheLimitCountsRecipientsNotRows(): void
    {
        $first  = self::U + 20;
        $second = self::U + 21;
        $this->pointer($first, self::HALL);
        $this->rows($first, self::HALL, ['member', 'member']);
        $this->pointer($second, self::HALL);
        $this->rows($second, self::HALL, ['member']);

        self::assertSame([$first], PeepSoGroupRepository::findUsersByPrimaryHall(self::HALL, 1));
        self::assertSame([$first, $second], PeepSoGroupRepository::findUsersByPrimaryHall(self::HALL, 2));
    }

    /** A read that did not run sends to nobody (wpdb::get_col() answers [] on failure). */
    public function testAFailedReadSendsToNobody(): void
    {
        global $wpdb;
        $this->pointer(self::U + 30, self::HALL);
        $this->rows(self::U + 30, self::HALL, ['member']);
        $wpdb->failQueriesMatching = '/peepso_group_members/i';

        $recipients = PeepSoGroupRepository::findUsersByPrimaryHall(self::HALL, 1000);

        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        self::assertSame([], $recipients);
    }
}
