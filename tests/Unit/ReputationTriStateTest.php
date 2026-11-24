<?php
/**
 * F-469: ReputationRepository's member-row reads are tri-state.
 *
 * - getByUserIdOrFail(): the row, null (no row: a new member, neutral), or
 *   false (the read did not run). A failure is never memoised, so the next
 *   read in the request tries again.
 * - getTierOrUnknown() / getScoreOrUnknown(): the gate readers; null is
 *   unknown, and a member with no row is still neutral.
 * - The lenient getTier() / getScore() / getByUserId() keep the neutral
 *   answer for display, and log the failure.
 * - primeByUserIds(): a failed read memoises nothing.
 *
 * Reads go through bcc-core's ProbesReads::trustedRows(), so wpdb's silent
 * pre-flush failure (stale rows, no error) is a failure too.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Tests\Stubs\ReputationRowWpdb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReputationRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReputationTriStateTest extends TestCase
{
    private const CAUTION = 11;
    private const TRUSTED = 12;
    private const NEW_MEMBER = 13;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/reputation-row-stubs.php';
        Logger::$errors = [];
        global $wpdb;
        $wpdb = new ReputationRowWpdb();
        $wpdb->rows = [
            self::CAUTION => ['tier' => 'caution', 'score' => 22.0],
            self::TRUSTED => ['tier' => 'trusted', 'score' => 81.0],
        ];
    }

    /** Main agent's condition: a genuine no-row member still passes as neutral. */
    public function testAMemberWithNoRowIsNeutralNotUnknown(): void
    {
        $repo = new ReputationRepository();

        self::assertNull($repo->getByUserIdOrFail(self::NEW_MEMBER), 'no row: null, never false');
        self::assertSame('neutral', $repo->getTierOrUnknown(self::NEW_MEMBER));
        self::assertSame(50.0, $repo->getScoreOrUnknown(self::NEW_MEMBER));
        self::assertSame([], Logger::$errors, 'no row is not a failure');
    }

    public function testARowIsReadAndTheGateReadersAnswerIt(): void
    {
        $repo = new ReputationRepository();

        self::assertSame('caution', $repo->getTierOrUnknown(self::CAUTION));
        self::assertSame(22.0, $repo->getScoreOrUnknown(self::CAUTION));
    }

    /** The defect: a failed read was memoised as "no row", so the neutral 50 for the rest of the request. */
    public function testAFailedReadIsUnknownAndNeverMemoised(): void
    {
        $repo = new ReputationRepository();
        $this->db()->failNext = 1;

        self::assertFalse($repo->getByUserIdOrFail(self::CAUTION), 'unknown');
        self::assertSame('caution', $repo->getTierOrUnknown(self::CAUTION), 'read again, not served from a memoised failure');
        self::assertSame(2, $this->db()->queries);
    }

    public function testTheGateReadersAnswerNullWhenTheReadDidNotRun(): void
    {
        $repo = new ReputationRepository();

        $this->db()->failNext = 1;
        self::assertNull($repo->getTierOrUnknown(self::CAUTION));
        $this->db()->failNext = 1;
        self::assertNull($repo->getScoreOrUnknown(self::CAUTION));
    }

    /** wpdb's silent failure leaves the PREVIOUS rows in last_result: never read as this member's. */
    public function testASilentFailureIsUnknownNotTheStaleRows(): void
    {
        $repo = new ReputationRepository();
        self::assertSame('trusted', $repo->getTierOrUnknown(self::TRUSTED)); // last_result = the trusted row
        $this->db()->failSilentNext = 1;

        self::assertFalse($repo->getByUserIdOrFail(self::CAUTION), 'not the trusted row left behind');
    }

    /** Display keeps degrading to neutral, but the failure is logged. */
    public function testTheLenientReadersStayNeutralAndLog(): void
    {
        $repo = new ReputationRepository();
        $this->db()->failNext = 1;

        self::assertSame('neutral', $repo->getTier(self::CAUTION));
        self::assertNotSame([], Logger::$errors, 'logged');
        self::assertSame('caution', $repo->getTier(self::CAUTION), 'and the next read tries again');
    }

    public function testAFailedPrimeMemoisesNothing(): void
    {
        $repo = new ReputationRepository();
        $this->db()->failNext = 1;
        $repo->primeByUserIds([self::CAUTION, self::TRUSTED]);

        self::assertSame('caution', $repo->getTierOrUnknown(self::CAUTION), 'read on its own');
        self::assertSame(2, $this->db()->queries);
        self::assertNotSame([], Logger::$errors);
    }

    public function testASuccessfulPrimeServesEveryAskedMemberFromTheMemo(): void
    {
        $repo = new ReputationRepository();
        $repo->primeByUserIds([self::CAUTION, self::TRUSTED, self::NEW_MEMBER]);

        self::assertSame('caution', $repo->getTierOrUnknown(self::CAUTION));
        self::assertSame('trusted', $repo->getTierOrUnknown(self::TRUSTED));
        self::assertNull($repo->getByUserIdOrFail(self::NEW_MEMBER), 'primed as no row');
        self::assertSame(1, $this->db()->queries, 'one IN read, then the memo');
    }

    // ── F-469 batch 3: the WRITERS' readers ─────────────────────────────

    public function testTheBatchedTierReadMapsRowsAndLeavesRowlessMembersOut(): void
    {
        $repo = new ReputationRepository();

        self::assertSame(
            [self::CAUTION => 'caution', self::TRUSTED => 'trusted'],
            $repo->getTiersForUsersOrUnknown([self::CAUTION, self::TRUSTED, self::NEW_MEMBER])
        );
        self::assertSame([], Logger::$errors, 'a member with no row is left out, not a failure');
    }

    public function testTheBatchedTierReadIsUnknownWhenItDidNotRun(): void
    {
        $repo = new ReputationRepository();

        $this->db()->failNext = 1;
        self::assertNull($repo->getTiersForUsersOrUnknown([self::CAUTION, self::TRUSTED]));
        self::assertCount(1, Logger::$errors, 'logged');

        $this->db()->failSilentNext = 1;
        self::assertNull(
            $repo->getTiersForUsersOrUnknown([self::CAUTION, self::TRUSTED]),
            'a silent failure is unknown, not the stale rows'
        );
    }

    public function testAnEmptyBatchIsNotARead(): void
    {
        $repo = new ReputationRepository();

        self::assertSame([], $repo->getTiersForUsersOrUnknown([]));
        self::assertSame([], $repo->getTiersForUsersOrUnknown([0, -3]));
        self::assertSame(0, $this->db()->queries);
    }

    /** The DISPLAY batch reader keeps its answer: rows, and [] (rendered neutral) on a failed read. */
    public function testTheDisplayBatchReaderIsUnchanged(): void
    {
        $repo = new ReputationRepository();

        self::assertSame([self::TRUSTED => 'trusted'], $repo->getTiersForUsers([self::TRUSTED, self::NEW_MEMBER]));

        $this->db()->failNext = 1;
        self::assertSame([], $repo->getTiersForUsers([self::TRUSTED]));
    }

    public function testTheContributionBonusReaderIsTriState(): void
    {
        $this->db()->rows[self::CAUTION]['bonus'] = 4.5;
        $repo = new ReputationRepository();

        self::assertSame(4.5, $repo->getContributionBonusOrUnknown(self::CAUTION));
        self::assertSame(0.0, $repo->getContributionBonusOrUnknown(self::NEW_MEMBER), 'no row: 0.0, never unknown');

        $this->db()->failNext = 1;
        self::assertNull($repo->getContributionBonusOrUnknown(self::TRUSTED), 'the read did not run');
    }

    private function db(): ReputationRowWpdb
    {
        /** @var ReputationRowWpdb $wpdb */
        global $wpdb;

        return $wpdb;
    }
}
