<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Rank\Repositories\RankPendingRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-71 — a VOIDED Apprentice confirmation can be re-armed, against real SQL
 * on both engines.
 *
 * Before the fix `start()` (INSERT IGNORE on PK user_id) was the only write
 * path, so a voided row turned every later qualifying contribution into a
 * silent no-op: the member could never reach Apprentice. These cases pin the
 * guarded UPDATE's whole truth table:
 *
 *   voided  + NEW contribution  → pending, new source, fresh due_at, reason cleared
 *   voided  + SAME contribution → unchanged (a voided post cannot re-arm itself)
 *   pending + new contribution  → unchanged (R1: nothing moves a live clock)
 *   confirmed + new contribution → unchanged (never reopened)
 *
 * The suite owns its table (created by the integration bootstrap in the
 * throwaway test database) and deletes only the user ids it used.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(RankPendingRepository::class)]
final class RankPendingRestartIntegrationTest extends TestCase
{
    private const USER = 910001;

    private RankPendingRepository $repo;

    protected function setUp(): void
    {
        $this->deleteOurRow();
        $this->repo = new RankPendingRepository();
    }

    protected function tearDown(): void
    {
        $this->deleteOurRow();
    }

    public function testVoidedRowRestartsOnANewContribution(): void
    {
        self::assertTrue($this->repo->start(self::USER, 'post', 101, 555, 86400));
        self::assertTrue($this->repo->resolve(self::USER, 'voided', 'report_upheld'));

        // The pre-fix path: start() is a no-op on the voided row.
        $this->repo->start(self::USER, 'post', 202, 666, 86400);
        self::assertSame('voided', $this->row()['status'], 'INSERT IGNORE alone never re-arms');

        $before = time();
        self::assertTrue($this->repo->restartIfVoided(self::USER, 'comment', 202, 666, 86400));

        $row = $this->row();
        self::assertSame('pending', $row['status']);
        self::assertSame('comment', $row['source_type']);
        self::assertSame('202', (string) $row['source_id']);
        self::assertSame('666', (string) $row['content_act_id']);
        self::assertNull($row['voided_reason']);
        self::assertNull($row['resolved_at']);
        $due = strtotime($row['due_at'] . ' UTC');
        self::assertGreaterThanOrEqual($before + 86400 - 5, $due);
        self::assertLessThanOrEqual(time() + 86400 + 5, $due);

        // The re-armed row is now a live clock the sweep will resolve.
        self::assertTrue($this->repo->resolve(self::USER, 'confirmed'));
        self::assertSame('confirmed', $this->row()['status']);
    }

    public function testTheVoidedContributionCannotReArmItself(): void
    {
        $this->repo->start(self::USER, 'post', 101, 555, 86400);
        $this->repo->resolve(self::USER, 'voided', 'content_unpublished');

        self::assertFalse($this->repo->restartIfVoided(self::USER, 'post', 101, 555, 86400));
        self::assertSame('voided', $this->row()['status']);
    }

    public function testAPendingClockIsNeverResetOrExtended(): void
    {
        $this->repo->start(self::USER, 'post', 101, 555, 3600);
        $dueBefore = $this->row()['due_at'];

        self::assertFalse($this->repo->restartIfVoided(self::USER, 'post', 202, 666, 86400));
        $row = $this->row();
        self::assertSame('pending', $row['status']);
        self::assertSame('101', (string) $row['source_id']);
        self::assertSame($dueBefore, $row['due_at']);
    }

    public function testAConfirmedRowIsNeverReopened(): void
    {
        $this->repo->start(self::USER, 'post', 101, 555, 86400);
        $this->repo->resolve(self::USER, 'confirmed');

        self::assertFalse($this->repo->restartIfVoided(self::USER, 'post', 202, 666, 86400));
        self::assertSame('confirmed', $this->row()['status']);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT status, source_type, source_id, content_act_id, due_at, voided_reason, resolved_at
               FROM ' . $wpdb->prefix . 'bcc_trust_rank_pending WHERE user_id = %d',
            self::USER
        ));
        // ⚠ ARRAY_A is undefined in the integration harness — read the
        // object and cast.
        self::assertIsObject($row, 'row exists');
        return (array) $row;
    }

    private function deleteOurRow(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->prefix . 'bcc_trust_rank_pending WHERE user_id = %d',
            self::USER
        ));
    }
}
