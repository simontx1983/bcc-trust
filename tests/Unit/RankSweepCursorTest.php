<?php

declare(strict_types=1);

namespace BCC\Trust\Rank\Tests\Unit {

    use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
    use BCC\Trust\Rank\Services\RankPromotionEngine;
    use BCC\Trust\Rank\Services\TierSnapshotService;
    use BCC\Trust\Rank\Support\RankScoringConfig;
    use BCC\Trust\Rank\Tests\Fakes\FakeRankState;
    use BCC\Trust\Rank\Tests\Fakes\FakeReputation;
    use BCC\Trust\Rank\Tests\Fakes\FakeTierDays;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-13: the daily rank sweeps cover the WHOLE population instead of
     * restarting at zero.
     *
     * Both sweeps used to start every run at cursor 0 under a wall-clock
     * budget, so the members past one budget's worth were never evaluated
     * (runDailyEvaluate) and never got a tier row (runDailySnapshot) — and a
     * member with no row for a day has a non-qualifying day, so they could
     * never be promoted. Both now persist a cursor and continue the same day;
     * the snapshot cursor is day-scoped. A failed read throws rather than
     * reading as the end of the list.
     */
    #[CoversClass(RankPromotionEngine::class)]
    #[CoversClass(TierSnapshotService::class)]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class RankSweepCursorTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            require_once __DIR__ . '/../Stubs/rank-sweep-cursor-stubs.php';
            \RankSweepWorld::reset();
            TestEngine::$clock = 0;
            TestEngine::$secondsPerUser = 0;
            TestEngine::$cursor = null;
            TestEngine::$continuations = 0;
            TestEngine::$lockHeldElsewhere = false;
            TestEngine::$lockHeld = false;
            TestEngine::$releases = 0;
            TestSnapshot::$clock = 0;
            TestSnapshot::$secondsPerBatch = 0;
            TestSnapshot::$day = '2026-11-11';
            TestSnapshot::$state = null;
            TestSnapshot::$continuations = 0;
        }

        /** @return list<int> */
        private static function users(int $n): array
        {
            $out = [];
            for ($i = 1; $i <= $n; $i++) {
                $out[] = $i * 3;
            }
            return $out;
        }

        private static function engine(): TestEngine
        {
            $engine = (new \ReflectionClass(TestEngine::class))->newInstanceWithoutConstructor();
            \Closure::bind(function (FakeRankState $r): void {
                $this->rankState = $r;
            }, $engine, RankPromotionEngine::class)(new FakeRankState());
            return $engine;
        }

        private static function snapshot(): TestSnapshot
        {
            return new TestSnapshot(new FakeTierDays(), new FakeReputation(), RankScoringConfig::fromDefaultFile());
        }

        // ── daily evaluate ──────────────────────────────────────────────

        public function testEvaluateCoversEveryMemberOnceAcrossBudgetCutRuns(): void
        {
            // 30 s budget at 7 s per member: 5 members a run. 250 members
            // used to mean the same 5 every day, for ever.
            \RankSweepWorld::$userIds = self::users(23);
            TestEngine::$secondsPerUser = 7;

            $runs = 0;
            do {
                self::engine()->runDailyEvaluate();
                $runs++;
            } while (TestEngine::$cursor !== null && $runs < 50);

            self::assertSame(self::users(23), \RankSweepWorld::$evaluated, 'every member once, in order');
            self::assertSame(5, $runs);
            self::assertSame(4, TestEngine::$continuations, 'each budget-cut run scheduled a continuation');
            self::assertNull(TestEngine::$cursor, 'a complete cycle clears its cursor');
        }

        public function testEvaluateResumesFromAPersistedCursor(): void
        {
            \RankSweepWorld::$userIds = self::users(6);
            TestEngine::$cursor = 9;

            self::engine()->runDailyEvaluate();

            self::assertSame([12, 15, 18], \RankSweepWorld::$evaluated);
        }

        public function testAMemberWhoseEvaluateThrowsIsMovedPast(): void
        {
            \RankSweepWorld::$userIds = self::users(4);
            \RankSweepWorld::$poison = [6];

            self::engine()->runDailyEvaluate();

            self::assertSame([3, 9, 12], \RankSweepWorld::$evaluated);
            self::assertContains('error:[bcc-trust] rank evaluate failed', \RankSweepWorld::$logs);
            self::assertNull(TestEngine::$cursor);
        }

        public function testAMemberThatThrowsAtTheDeadlineDoesNotStallTheSweep(): void
        {
            // 30 s budget at 10 s per member: the third member (9) is the
            // last of the run. If its failure left the cursor on 6, every
            // continuation would retry 9 first and never get past it.
            \RankSweepWorld::$userIds = self::users(5);
            \RankSweepWorld::$poison = [9];
            TestEngine::$secondsPerUser = 10;

            $runs = 0;
            do {
                self::engine()->runDailyEvaluate();
                $runs++;
            } while (TestEngine::$cursor !== null && $runs < 10);

            self::assertSame([3, 6, 12, 15], \RankSweepWorld::$evaluated);
            self::assertSame(2, $runs);
        }

        public function testAFailedEvaluateReadKeepsTheCursor(): void
        {
            \RankSweepWorld::$userIds = self::users(6);
            TestEngine::$cursor = 9;
            \RankSweepWorld::$readFailures = 1;

            try {
                self::engine()->runDailyEvaluate();
                self::fail('a failed read must not read as "no more members"');
            } catch (RepositoryReadFailure) {
            }

            self::assertSame(9, TestEngine::$cursor, 'the cycle is kept for the next run');
            self::assertSame([], \RankSweepWorld::$evaluated);
        }

        // ── daily evaluate: single-flight lock (F-13 follow-up) ────────

        public function testAHeldLockSkipsTheTickAndTouchesNothing(): void
        {
            // The daily event firing while a continuation is mid-sweep: both
            // would read the same cursor and evaluate the same members.
            \RankSweepWorld::$userIds = self::users(6);
            TestEngine::$cursor = 9;
            TestEngine::$lockHeldElsewhere = true;

            self::engine()->runDailyEvaluate();

            self::assertSame([], \RankSweepWorld::$evaluated);
            self::assertSame(9, TestEngine::$cursor);
            self::assertSame(0, TestEngine::$continuations);
            self::assertSame(0, TestEngine::$releases, 'a lock this run did not take is not released');
        }

        public function testTheLockIsReleasedAfterACompleteRun(): void
        {
            \RankSweepWorld::$userIds = self::users(4);

            self::engine()->runDailyEvaluate();

            self::assertSame(self::users(4), \RankSweepWorld::$evaluated);
            self::assertSame(1, TestEngine::$releases);
            self::assertFalse(TestEngine::$lockHeld);
        }

        public function testTheLockIsReleasedWhenTheBudgetCutsTheRun(): void
        {
            \RankSweepWorld::$userIds = self::users(10);
            TestEngine::$secondsPerUser = 10;

            self::engine()->runDailyEvaluate();

            self::assertSame(1, TestEngine::$continuations);
            self::assertSame(1, TestEngine::$releases, 'the continuation must be able to take the lock');
            self::assertFalse(TestEngine::$lockHeld);
        }

        public function testTheLockIsReleasedWhenTheReadFails(): void
        {
            \RankSweepWorld::$userIds = self::users(4);
            \RankSweepWorld::$readFailures = 1;

            try {
                self::engine()->runDailyEvaluate();
                self::fail('a failed read must propagate');
            } catch (RepositoryReadFailure) {
            }

            self::assertSame(1, TestEngine::$releases);
            self::assertFalse(TestEngine::$lockHeld);
        }

        // ── daily tier snapshot ─────────────────────────────────────────

        public function testSnapshotGivesEveryUserTodaysRowTheSameDay(): void
        {
            // 20 s budget at 9 s per batch of 200: three batches a run. Past
            // that, users used to get no row today — a non-qualifying day.
            \RankSweepWorld::$userIds = self::users(1300);
            TestSnapshot::$secondsPerBatch = 9;

            $runs = 0;
            do {
                self::snapshot()->runDailySnapshot();
                $runs++;
            } while (!(TestSnapshot::$state['complete'] ?? false) && $runs < 50);

            self::assertSame(self::users(1300), \RankSweepWorld::$recorded['2026-11-11'] ?? [], 'every user, today, once');
            self::assertSame(3, $runs);
            self::assertSame(2, TestSnapshot::$continuations);
            self::assertSame(1, \RankSweepWorld::$purges, 'the retention purge runs once, at completion');
        }

        public function testACompletedDayIsNotRedoneTheSameDay(): void
        {
            \RankSweepWorld::$userIds = self::users(5);

            self::snapshot()->runDailySnapshot();
            self::snapshot()->runDailySnapshot();

            self::assertSame(self::users(5), \RankSweepWorld::$recorded['2026-11-11']);
            self::assertSame(1, \RankSweepWorld::$purges);
        }

        public function testACursorFromAnEarlierDayStartsTodayFromTheBeginning(): void
        {
            \RankSweepWorld::$userIds = self::users(5);
            TestSnapshot::$state = ['day' => '2026-11-10', 'after' => 9, 'complete' => false];

            self::snapshot()->runDailySnapshot();

            self::assertSame(self::users(5), \RankSweepWorld::$recorded['2026-11-11'], 'today starts at the first user, not where yesterday stopped');
            self::assertContains('warning:[bcc-trust] rank tier snapshot: an earlier day was left unfinished; starting today from the beginning', \RankSweepWorld::$logs);
        }

        public function testATodayCursorIsResumed(): void
        {
            \RankSweepWorld::$userIds = self::users(5);
            TestSnapshot::$state = ['day' => '2026-11-11', 'after' => 6, 'complete' => false];

            self::snapshot()->runDailySnapshot();

            self::assertSame([9, 12, 15], \RankSweepWorld::$recorded['2026-11-11']);
        }

        public function testAWriteFailureKeepsTheCursorAndPropagates(): void
        {
            \RankSweepWorld::$userIds = self::users(5);
            TestSnapshot::$state = ['day' => '2026-11-11', 'after' => 6, 'complete' => false];
            \RankSweepWorld::$writeFails = true;

            try {
                self::snapshot()->runDailySnapshot();
                self::fail('a failed write must propagate');
            } catch (\RuntimeException) {
            }

            self::assertSame(['day' => '2026-11-11', 'after' => 6, 'complete' => false], TestSnapshot::$state);
            self::assertSame(0, \RankSweepWorld::$purges);
        }

        public function testAFailedSnapshotReadKeepsTheCursor(): void
        {
            \RankSweepWorld::$userIds = self::users(5);
            TestSnapshot::$state = ['day' => '2026-11-11', 'after' => 6, 'complete' => false];
            \RankSweepWorld::$readFailures = 1;

            try {
                self::snapshot()->runDailySnapshot();
                self::fail('a failed read must not read as "no more users"');
            } catch (RepositoryReadFailure) {
            }

            self::assertSame(6, TestSnapshot::$state['after'] ?? null);
            self::assertFalse(TestSnapshot::$state['complete'] ?? true);
        }
    }

    /** RankPromotionEngine with its sweep collaborators replaced. */
    final class TestEngine extends RankPromotionEngine
    {
        public static int $clock = 0;
        public static int $secondsPerUser = 0;
        public static ?int $cursor = null;
        public static int $continuations = 0;
        public static bool $lockHeldElsewhere = false;
        public static bool $lockHeld = false;
        public static int $releases = 0;

        public function evaluate(int $userId): void
        {
            self::$clock += self::$secondsPerUser; // a failing member still costs time
            if (in_array($userId, \RankSweepWorld::$poison, true)) {
                throw new \RuntimeException('poison member');
            }
            \RankSweepWorld::$evaluated[] = $userId;
        }

        protected function now(): int { return self::$clock; }
        protected function readEvaluateCursor(): int { return self::$cursor ?? 0; }
        protected function writeEvaluateCursor(int $userId): void { self::$cursor = $userId; }
        protected function clearEvaluateCursor(): void { self::$cursor = null; }
        protected function scheduleEvaluateContinuation(): void { self::$continuations++; }

        protected function acquireEvaluateLock(): bool
        {
            if (self::$lockHeldElsewhere || self::$lockHeld) {
                return false;
            }
            self::$lockHeld = true;
            return true;
        }

        protected function releaseEvaluateLock(): void
        {
            self::$releases++;
            self::$lockHeld = false;
        }
    }

    /** TierSnapshotService with its sweep collaborators replaced. */
    final class TestSnapshot extends TierSnapshotService
    {
        public static int $clock = 0;
        public static int $secondsPerBatch = 0;
        public static string $day = '2026-11-11';
        /** @var array{day: string, after: int, complete: bool}|null */
        public static ?array $state = null;
        public static int $continuations = 0;

        protected function now(): int
        {
            // Advance per call: runDailySnapshot reads the clock once at the
            // start and once after each batch.
            $t = self::$clock;
            self::$clock += self::$secondsPerBatch;
            return $t;
        }

        protected function today(): string { return self::$day; }
        protected function readSnapshotCursor(): ?array { return self::$state; }
        protected function writeSnapshotCursor(array $state): void { self::$state = $state; }
        protected function scheduleSnapshotContinuation(): void { self::$continuations++; }
    }
}
