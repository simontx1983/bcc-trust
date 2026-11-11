<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\FraudSignalWorld as W;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Services\AttestationScoreSynthesis;
use BCC\Trust\Core\Services\CohortOverlapDampener;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Cohort density is observed, not penalised.
 *
 * `CohortOverlapDampener` had no test coverage at all before this.
 *
 * The rule: overlap = |{targets I have backed} ∩ {people backing THIS
 * target}| / |{targets I have backed}|. At >= 0.50 it applied x0.80 to the
 * cast's weight_at_time.
 *
 * ⚠ The fixtures below are the argument. Every ordinary close-knit shape a
 * trade community produces was dampened, a ring escaped by padding its
 * cohort, and an honest member's SECOND-EVER vouch was dampened hardest.
 *
 * ⚠ The THRESHOLD IS DELIBERATELY UNCHANGED, and no graduated curve or
 * minimum-cohort floor is introduced — each would replace one arbitrary
 * number with another and would still be measuring density.
 *
 * ⚠ The attestation graph is a FIXTURE; the ratio and the threshold are
 * production code. The repository doubles derive their counts from the
 * graph with the same set semantics as the production SQL, so these cases
 * exercise the real arithmetic rather than a hand-fed answer.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CohortOverlapIsObservationalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    private function dampener(): CohortOverlapDampener
    {
        return new CohortOverlapDampener(new AttestationRepository());
    }

    /** @param array<int, list<int>> $graph attestor => targets backed */
    private function graph(array $graph): void
    {
        W::reset();
        W::$attestationGraph = $graph;
    }

    /** A 6-person hall where everyone has used everyone else. */
    private function hall(): array
    {
        $g = [];
        foreach ([1, 2, 3, 4, 5, 6] as $m) {
            $g[$m] = array_values(array_diff([1, 2, 3, 4, 5, 6], [$m]));
        }
        return $g;
    }

    // ══ ordinary close-knit communities keep BASELINE weight ══════════

    public function testASixPersonHallKeepsBaselineWeight(): void
    {
        $this->graph($this->hall());
        $d = $this->dampener();

        // The overlap is REAL and still measured: 4 of my 5 prior targets
        // also back member 6.
        self::assertEqualsWithDelta(0.80, $d->overlapRatio(1, 'user_profile', 6), 0.0001);
        self::assertTrue(CohortOverlapDampener::isHighOverlap($d->overlapRatio(1, 'user_profile', 6)));

        // ...and it no longer costs anything.
        self::assertSame(1.00, $d->weightFor(1, 'user_profile', 6), 'was 0.80');
    }

    public function testAFamilyBusinessKeepsBaselineWeight(): void
    {
        // 1 has backed 2 and 3; both of them back the new member 4.
        $this->graph([1 => [2, 3], 2 => [4], 3 => [4]]);

        self::assertEqualsWithDelta(1.00, $this->dampener()->overlapRatio(1, 'user_profile', 4), 0.0001);
        self::assertSame(1.00, $this->dampener()->weightFor(1, 'user_profile', 4), 'was 0.80');
    }

    public function testATradeCrewBackingTheGafferKeepsBaselineWeight(): void
    {
        $this->graph([1 => [2, 3, 4], 2 => [9], 3 => [9], 4 => [9]]);

        self::assertEqualsWithDelta(1.00, $this->dampener()->overlapRatio(1, 'user_profile', 9), 0.0001);
        self::assertSame(1.00, $this->dampener()->weightFor(1, 'user_profile', 9));
    }

    public function testASpecialistWithinOneDisciplineKeepsBaselineWeight(): void
    {
        $this->graph([1 => [2, 3, 4, 5], 2 => [7], 3 => [7], 4 => [7], 5 => [7]]);

        self::assertEqualsWithDelta(1.00, $this->dampener()->overlapRatio(1, 'user_profile', 7), 0.0001);
        self::assertSame(1.00, $this->dampener()->weightFor(1, 'user_profile', 7));
    }

    // ══ ⚠ the small-denominator behaviour, pinned ═════════════════════

    public function testASecondEverVouchWasTheHARDESTHitAndIsNowBaseline(): void
    {
        // ⚠ Cohort of 1. One prior target, who happens to back the new
        // person. Ratio 1.00 — the maximum — on a member's SECOND EVER
        // vouch. This was the single least defensible case.
        $this->graph([1 => [2], 2 => [3]]);

        self::assertEqualsWithDelta(1.00, $this->dampener()->overlapRatio(1, 'user_profile', 3), 0.0001);
        self::assertSame(1.00, $this->dampener()->weightFor(1, 'user_profile', 3), 'was 0.80');
    }

    public function testAThirdEverVouchTrippedAtExactlyTheThreshold(): void
    {
        // Cohort of 2, one overlapping => ratio exactly 0.50, and the
        // comparison is >=, so it tripped.
        $this->graph([1 => [2, 4], 2 => [3]]);

        self::assertEqualsWithDelta(0.50, $this->dampener()->overlapRatio(1, 'user_profile', 3), 0.0001);
        self::assertTrue(CohortOverlapDampener::isHighOverlap(0.50), 'the boundary is inclusive');
        self::assertSame(1.00, $this->dampener()->weightFor(1, 'user_profile', 3));
    }

    public function testAProlificIndependentAttestorWasNeverDampened(): void
    {
        // 20 prior targets, 2 overlapping => 0.10. Below the threshold both
        // before and after. The rule was most lenient toward the most
        // active attestor.
        $this->graph([1 => range(2, 21), 2 => [50], 3 => [50]]);

        self::assertEqualsWithDelta(0.10, $this->dampener()->overlapRatio(1, 'user_profile', 50), 0.0001);
        self::assertFalse(CohortOverlapDampener::isHighOverlap($this->dampener()->overlapRatio(1, 'user_profile', 50)));
    }

    // ══ manipulation: detected the same, and evadable the same ════════

    public function testFiveSockPuppetsScoreTheSameAsTheHall(): void
    {
        // ⚠ Stated explicitly so this suite cannot be read as a detection
        // improvement. It is not one. Both are ratio 0.80.
        $ring = [];
        foreach ([1, 2, 3, 4, 5] as $m) {
            $ring[$m] = array_merge(array_values(array_diff([1, 2, 3, 4, 5], [$m])), [99]);
        }
        $this->graph($ring);
        $ringRatio = $this->dampener()->overlapRatio(1, 'user_profile', 99);

        $this->graph($this->hall());
        $hallRatio = $this->dampener()->overlapRatio(1, 'user_profile', 6);

        self::assertEqualsWithDelta($hallRatio, $ringRatio, 0.0001, 'indistinguishable');
    }

    public function testARingEVADESTheRuleByPaddingItsCohort(): void
    {
        // ⚠ 3 overlapping targets out of 24 prior = 0.13. Not dampened,
        // before or after. Padding the cohort with unrelated targets
        // dilutes the denominator away — which is why raising or lowering
        // the threshold would not fix this rule.
        $this->graph([1 => array_merge([2, 3, 4], range(30, 50)), 2 => [99], 3 => [99], 4 => [99]]);

        $ratio = $this->dampener()->overlapRatio(1, 'user_profile', 99);

        self::assertLessThan(CohortOverlapDampener::OVERLAP_THRESHOLD, $ratio);
        self::assertFalse(CohortOverlapDampener::isHighOverlap($ratio), 'a padded ring was always exempt');
    }

    // ══ the threshold and the detection are UNCHANGED ═════════════════

    public function testTheThresholdConstantIsUnchanged(): void
    {
        self::assertSame(0.50, CohortOverlapDampener::OVERLAP_THRESHOLD);
    }

    public function testTheMultiplierConstantsAreBaseline(): void
    {
        self::assertSame(1.00, CohortOverlapDampener::DAMPENED_MULTIPLIER);
        self::assertSame(1.00, CohortOverlapDampener::NO_DAMPENING);
    }

    public function testNullRatioMeansNOTAPPLICABLEAndIsNotAMeasuredZero(): void
    {
        $this->graph([1 => [2], 2 => [3]]);
        $d = $this->dampener();

        self::assertNull($d->overlapRatio(1, 'validator_card', 3), 'cards have no attestor cohort');
        self::assertNull($d->overlapRatio(1, 'user_profile', 1), 'self');
        self::assertNull($d->overlapRatio(0, 'user_profile', 3), 'non-positive attestor');

        W::reset();
        W::$attestationGraph = [2 => [3]];
        self::assertNull($d->overlapRatio(1, 'user_profile', 3), 'first-ever cast: empty cohort');

        // ⚠ and a genuine 0.0 is distinct from null.
        W::reset();
        W::$attestationGraph = [1 => [2], 5 => [3]];
        self::assertEqualsWithDelta(0.0, $d->overlapRatio(1, 'user_profile', 3), 0.0001);
        self::assertFalse(CohortOverlapDampener::isHighOverlap(0.0));
        self::assertFalse(CohortOverlapDampener::isHighOverlap(null));
    }

    public function testCardTargetsStillKeepBaselineWeight(): void
    {
        $this->graph($this->hall());

        foreach (['validator_card', 'project_card', 'creator_card'] as $kind) {
            self::assertSame(1.00, $this->dampener()->weightFor(1, $kind, 6), $kind);
        }
    }

    // ══ ⚠ the 0.80 neutralized NOTHING where it hit hardest ═══════════

    public function testOnALowSourceTargetTheOldPenaltyHadNoBonusToOffset(): void
    {
        // The 0.80 was documented as neutralizing the 1.3x diversity bonus.
        // That bonus is gated on the TARGET having >= 3 distinct sources.
        // On a two-source target there is no bonus, so the old 0.80 was a
        // flat 20% loss with nothing to offset.
        $now = new \DateTimeImmutable('2026-06-26 00:00:00', new \DateTimeZone('UTC'));
        $row = static fn (int $a, float $w): object => (object) [
            'attestor_user_id' => $a,
            'weight_at_time'   => $w,
            'created_at'       => '2026-06-26 00:00:00',
            'reaffirmed_at'    => null,
        ];
        $synth = static fn (array $rows): float => AttestationScoreSynthesis::computeBonus(
            $rows, [], $now, 20.0, 0.40, 1.30, 3, null
        );

        // two sources => no 1.3x either way
        self::assertSame(1.6, $synth([$row(1, 0.80), $row(2, 0.80)]), 'old: 20% loss, no bonus neutralized');
        self::assertSame(2.0, $synth([$row(1, 1.00), $row(2, 1.00)]), 'new: baseline');
    }

    public function testHistoricalRowsAreUnaffected(): void
    {
        // ⚠ Forward-only. weight_at_time is an immutable cast-time snapshot
        // and synthesis reads it verbatim, so a row written at 0.80 keeps
        // synthesising at 0.80. No migration is implied and none is proposed.
        $now = new \DateTimeImmutable('2026-06-26 00:00:00', new \DateTimeZone('UTC'));
        $row = static fn (int $a, float $w): object => (object) [
            'attestor_user_id' => $a,
            'weight_at_time'   => $w,
            'created_at'       => '2026-06-26 00:00:00',
            'reaffirmed_at'    => null,
        ];
        $synth = static fn (array $rows): float => AttestationScoreSynthesis::computeBonus(
            $rows, [], $now, 20.0, 0.40, 1.30, 3, null
        );

        // one old 0.80 row + two new 1.00 rows, three sources => x1.3
        self::assertSame(3.64, $synth([$row(1, 0.80), $row(2, 1.00), $row(3, 1.00)]));
    }

    // ══ anti-vacuity ═════════════════════════════════════════════════

    public function testTheFixtureGraphActuallyDrivesTheRatio(): void
    {
        // Without this, every assertion above would also pass if the
        // repository doubles returned constants.
        $this->graph([1 => [2], 2 => [3]]);
        self::assertEqualsWithDelta(1.00, $this->dampener()->overlapRatio(1, 'user_profile', 3), 0.0001);

        $this->graph([1 => [2, 4, 5, 6], 2 => [3]]);
        self::assertEqualsWithDelta(0.25, $this->dampener()->overlapRatio(1, 'user_profile', 3), 0.0001);
    }

    public function testTheRepositoryDoublesMatchTheProductionSetSemantics(): void
    {
        // cohort = COUNT(DISTINCT target_id) by attestor;
        // overlap = |my targets ∩ target's backers|.
        $this->graph([1 => [2, 2, 3], 2 => [9], 3 => [9], 4 => [9]]);
        $repo = new AttestationRepository();

        self::assertSame(2, $repo->countDistinctTargetsByAttestor(1, 'user_profile'), 'DISTINCT dedupes');
        self::assertSame(2, $repo->countOverlapBetweenAttestorAndTarget(1, 9), 'only MY targets count, not all backers');
    }
}
