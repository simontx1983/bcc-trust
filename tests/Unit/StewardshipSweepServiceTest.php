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
 * and ingest so the suite pins the sweep without WordPress or a DB.
 */
final class RecordingStewardshipSweep extends StewardshipSweepService
{
    /** @var list<array{int, int}> [ownerId, sourceId] */
    public array $ingestCalls = [];
    /** @var list<array{int, int}> [ownerId, groupId] */
    public array $failures = [];

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
        private readonly int $fixedPeriod
    ) {
    }

    protected function candidates(int $limit): array
    {
        $out = [];
        foreach ($this->candidateRows as [$groupId, $ownerId]) {
            $out[] = (object) ['group_id' => $groupId, 'owner_id' => $ownerId];
        }
        return $out;
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
