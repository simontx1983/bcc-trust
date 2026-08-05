<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Rank\Services\StewardshipSweepService;
use PHPUnit\Framework\TestCase;

/**
 * Rank helping emitters — the weekly stewardship sweep.
 *
 * Pins: an active User-kind community credits its owner ONCE per ISO
 * period with a stable composite source id (so a re-run within the week
 * mints the identical eventUid → ledger UNIQUE dedupes → no double
 * credit); a below-bar community credits nothing; a suspended / in-
 * recovery / fraud-blocked owner is skipped; and the per-period source
 * id is injective per (group, period).
 *
 * The activity read (getActivityHeat), the candidate enumeration, and
 * the responsibility verdict (RankCredibilityGateTest) are scripted via
 * protected hooks so the sweep logic is pinned without WordPress.
 */
final class StewardshipSweepServiceTest extends TestCase
{
    private const PERIOD = 202631; // ISO 2026-W31

    private const GROUP_A = 10;
    private const OWNER_A = 100;
    private const GROUP_B = 20;
    private const OWNER_B = 200;

    // ── source-id scheme (pure) ─────────────────────────────────────────

    public function testPeriodSourceIdIsStableAndInjective(): void
    {
        $a = StewardshipSweepService::periodSourceId(self::GROUP_A, self::PERIOD);
        self::assertSame(10_000_000 + self::PERIOD, $a);

        // Same (group, period) → same id (idempotent per period).
        self::assertSame($a, StewardshipSweepService::periodSourceId(self::GROUP_A, self::PERIOD));

        // Distinct group OR distinct period → distinct id.
        self::assertNotSame($a, StewardshipSweepService::periodSourceId(self::GROUP_B, self::PERIOD));
        self::assertNotSame($a, StewardshipSweepService::periodSourceId(self::GROUP_A, self::PERIOD + 1));
    }

    // ── sweep behaviour ─────────────────────────────────────────────────

    public function testActiveCommunityCreditsOwnerOncePerPeriod(): void
    {
        $svc = $this->sweep(
            candidates: [[self::GROUP_A, self::OWNER_A]],
            actives:    [self::GROUP_A => 5],
        );
        $svc->run();

        self::assertSame(
            [[self::OWNER_A, StewardshipSweepService::periodSourceId(self::GROUP_A, self::PERIOD)]],
            $svc->ingestCalls
        );
    }

    public function testIdempotentReRunMintsIdenticalSourceIdNoDoubleCredit(): void
    {
        // Two runs in the same period emit the SAME (owner, sourceId) — the
        // ledger's UNIQUE(event_uid, category) collapses them (no double).
        $svc = $this->sweep(
            candidates: [[self::GROUP_A, self::OWNER_A]],
            actives:    [self::GROUP_A => 9],
        );
        $svc->run();
        $svc->run();

        $expected = [self::OWNER_A, StewardshipSweepService::periodSourceId(self::GROUP_A, self::PERIOD)];
        self::assertSame([$expected, $expected], $svc->ingestCalls);
    }

    public function testInactiveCommunityCreditsNothing(): void
    {
        $svc = $this->sweep(
            candidates: [[self::GROUP_A, self::OWNER_A]],
            actives:    [self::GROUP_A => 4], // below the 5-poster bar
        );
        $svc->run();

        self::assertSame([], $svc->ingestCalls);
    }

    public function testCommunityWithNoHeatRowCreditsNothing(): void
    {
        $svc = $this->sweep(
            candidates: [[self::GROUP_A, self::OWNER_A]],
            actives:    [], // absent = zero active posters
        );
        $svc->run();

        self::assertSame([], $svc->ingestCalls);
    }

    public function testIrresponsibleOwnerIsSkipped(): void
    {
        $svc = $this->sweep(
            candidates:   [[self::GROUP_A, self::OWNER_A]],
            actives:      [self::GROUP_A => 8],
            responsible:  [self::OWNER_A => false], // suspended / recovery / fraud
        );
        $svc->run();

        self::assertSame([], $svc->ingestCalls);
    }

    public function testMixedBatchCreditsOnlyQualifyingCommunities(): void
    {
        $svc = $this->sweep(
            candidates:  [[self::GROUP_A, self::OWNER_A], [self::GROUP_B, self::OWNER_B]],
            actives:     [self::GROUP_A => 6, self::GROUP_B => 2],
        );
        $svc->run();

        self::assertSame(
            [[self::OWNER_A, StewardshipSweepService::periodSourceId(self::GROUP_A, self::PERIOD)]],
            $svc->ingestCalls
        );
    }

    public function testIngestFailureIsRecordedAndDoesNotAbortTheBatch(): void
    {
        $svc = $this->sweep(
            candidates: [[self::GROUP_A, self::OWNER_A], [self::GROUP_B, self::OWNER_B]],
            actives:    [self::GROUP_A => 6, self::GROUP_B => 6],
            failOwners: [self::OWNER_A => true],
        );
        $svc->run();

        // Owner A threw — recorded — but the sweep still credited owner B.
        self::assertSame([[self::OWNER_A, self::GROUP_A]], $svc->failures);
        self::assertSame(
            [[self::OWNER_B, StewardshipSweepService::periodSourceId(self::GROUP_B, self::PERIOD)]],
            $svc->ingestCalls
        );
    }

    // ── wrap-around cursor (no community starved) ───────────────────────

    public function testCursorPagesDisjointSetsAcrossRunsThenWrapsAtEnd(): void
    {
        // 150 active User-kind communities — one and a half BATCH_LIMIT (100)
        // pages. group id g, owner 1000+g, all above the 5-poster bar.
        $candidates = [];
        $actives    = [];
        for ($g = 1; $g <= 150; $g++) {
            $candidates[] = [$g, 1000 + $g];
            $actives[$g]  = 5;
        }

        $svc = new RecordingStewardshipSweep($candidates, $actives, [], [], self::PERIOD);

        // Run 1: lowest 100 by group id; full batch → cursor advances, no wrap.
        $svc->run();
        $firstCalls  = $svc->ingestCalls;
        $firstGroups = $this->creditedGroups($firstCalls);
        self::assertSame(range(1, 100), $firstGroups);
        self::assertSame(100, $svc->cursor, 'full batch → cursor = highest processed group id');
        $svc->ingestCalls = [];

        // Run 2: the remaining 50; short batch → cursor wraps to 0.
        $svc->run();
        $secondGroups = $this->creditedGroups($svc->ingestCalls);
        self::assertSame(range(101, 150), $secondGroups);
        self::assertSame(0, $svc->cursor, 'short batch (reached the end) → cursor resets to 0');
        $svc->ingestCalls = [];

        // The two runs covered DISJOINT sets whose union is EVERY community —
        // nothing beyond the first batch is starved.
        self::assertSame([], array_values(array_intersect($firstGroups, $secondGroups)));
        self::assertSame(
            range(1, 150),
            array_values(array_unique(array_merge($firstGroups, $secondGroups)))
        );

        // Run 3 (post-wrap): back to the lowest 100 — SAME period ⇒ byte-
        // identical (owner, sourceId) pairs as run 1, so the ledger's
        // UNIQUE(event_uid, category) dedupes the replay (idempotency holds
        // across the wrap; re-processing mints no double credit).
        $svc->run();
        self::assertSame($firstCalls, $svc->ingestCalls, 'wrap replays the first page identically');
    }

    public function testFewerThanBatchLimitWrapsTheCursorEveryRun(): void
    {
        // Below BATCH_LIMIT the run consumes the whole set and resets the
        // cursor to 0 — identical to the pre-cursor behavior.
        $svc = $this->sweep(
            candidates: [[self::GROUP_A, self::OWNER_A], [self::GROUP_B, self::OWNER_B]],
            actives:    [self::GROUP_A => 6, self::GROUP_B => 7],
        );
        $svc->run();

        self::assertSame(0, $svc->cursor);
        self::assertSame([0], $svc->cursorWrites);
        self::assertSame(
            [
                [self::OWNER_A, StewardshipSweepService::periodSourceId(self::GROUP_A, self::PERIOD)],
                [self::OWNER_B, StewardshipSweepService::periodSourceId(self::GROUP_B, self::PERIOD)],
            ],
            $svc->ingestCalls
        );
    }

    /**
     * Derive the credited group ids (ascending) from recorded ingest
     * calls — sourceId = groupId * 1_000_000 + period (periodSourceId).
     *
     * @param list<array{int, int}> $ingestCalls
     * @return list<int>
     */
    private function creditedGroups(array $ingestCalls): array
    {
        $groups = [];
        foreach ($ingestCalls as [, $sourceId]) {
            $groups[] = intdiv($sourceId - self::PERIOD, 1_000_000);
        }
        sort($groups);
        return $groups;
    }

    /**
     * @param list<array{int, int}> $candidates [groupId, ownerId]
     * @param array<int, int> $actives groupId => active posters
     * @param array<int, bool> $responsible ownerId => responsible
     * @param array<int, bool> $failOwners ownerId => ingest throws
     */
    private function sweep(
        array $candidates,
        array $actives,
        array $responsible = [],
        array $failOwners = []
    ): RecordingStewardshipSweep {
        return new RecordingStewardshipSweep($candidates, $actives, $responsible, $failOwners, self::PERIOD);
    }
}

/**
 * Recording double — scripts candidates, activity heat, responsibility,
 * ingest, and the wrap-around cursor so the suite pins the sweep without
 * WordPress or a DB.
 */
final class RecordingStewardshipSweep extends StewardshipSweepService
{
    /** @var list<array{int, int}> [ownerId, sourceId] */
    public array $ingestCalls = [];
    /** @var list<array{int, int}> [ownerId, groupId] */
    public array $failures = [];

    /** In-memory stand-in for the bcc_rank_stewardship_cursor wp_option. */
    public int $cursor;

    /** @var list<int> Cursor values persisted, in order. */
    public array $cursorWrites = [];

    /**
     * @param list<array{int, int}> $candidateRows
     * @param array<int, int> $actives
     * @param array<int, bool> $responsible
     * @param array<int, bool> $failOwners
     */
    public function __construct(
        private readonly array $candidateRows,
        private readonly array $actives,
        private readonly array $responsible,
        private readonly array $failOwners,
        private readonly int $fixedPeriod,
        int $initialCursor = 0
    ) {
        $this->cursor = $initialCursor;
    }

    protected function candidates(int $limit, int $afterGroupId): array
    {
        // Mirror the repository: gm_group_id > $afterGroupId, ORDER BY
        // gm_group_id ASC, LIMIT $limit.
        $rows = $this->candidateRows;
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $out = [];
        foreach ($rows as [$groupId, $ownerId]) {
            if ($groupId <= $afterGroupId) {
                continue;
            }
            $out[] = (object) ['group_id' => $groupId, 'owner_id' => $ownerId];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    protected function readCursor(): int
    {
        return $this->cursor;
    }

    protected function writeCursor(int $groupId): void
    {
        $this->cursor         = $groupId;
        $this->cursorWrites[] = $groupId;
    }

    protected function onCursorAdvance(int $from, int $to, bool $wrapped): void
    {
        // No-op in tests — keeps the double WP/Logger-free.
    }

    protected function activityHeat(array $groupIds, int $sinceSeconds): array
    {
        $out = [];
        foreach ($groupIds as $groupId) {
            if (isset($this->actives[$groupId])) {
                $out[$groupId] = $this->actives[$groupId];
            }
        }
        return $out;
    }

    protected function isResponsibleOwner(int $ownerUserId): bool
    {
        return $this->responsible[$ownerUserId] ?? true;
    }

    protected function ingest(int $ownerUserId, int $sourceId): void
    {
        if ($this->failOwners[$ownerUserId] ?? false) {
            throw new \RuntimeException('scripted ingest failure');
        }
        $this->ingestCalls[] = [$ownerUserId, $sourceId];
    }

    protected function onIngestFailure(\Throwable $e, int $ownerUserId, int $groupId): void
    {
        $this->failures[] = [$ownerUserId, $groupId];
    }

    protected function isoPeriod(): int
    {
        return $this->fixedPeriod;
    }

    protected function now(): int
    {
        return 1_000; // fixed clock — well inside the budget for the small batches here
    }
}
