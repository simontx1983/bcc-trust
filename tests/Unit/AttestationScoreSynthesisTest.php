<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\AttestationScoreSynthesis;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Slice-E attestation→score synthesis math (the pure computeBonus):
 * decay, the §J.4 40% Elite-tier source cap, the 1.3× diversity multiplier
 * (gated on distinct-source breadth), the entity velocity cap, and the ceiling.
 *
 * A regression that lets a vouch-farming ring cross the ceiling, or that drops
 * the elite-source cap / decay, must turn this suite red — these are the
 * anti-cartel guarantees the trust model relies on.
 */
final class AttestationScoreSynthesisTest extends TestCase
{
    private const CEILING       = 20.0;
    private const ELITE_CAP_PCT = 0.40;
    private const DIV_MULT       = 1.30;
    private const DIV_MIN        = 3;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-26 00:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @param ?string $reaffirmed
     * @return object{attestor_user_id: int, weight_at_time: float, created_at: string, reaffirmed_at: ?string}
     */
    private function row(int $attestor, float $weight, string $createdAt, ?string $reaffirmed = null): object
    {
        return (object) [
            'attestor_user_id' => $attestor,
            'weight_at_time'   => $weight,
            'created_at'       => $createdAt,
            'reaffirmed_at'    => $reaffirmed,
        ];
    }

    /** @param list<object> $rows @param list<int> $elite */
    private function compute(array $rows, array $elite = [], ?float $velocityCap = null): float
    {
        return AttestationScoreSynthesis::computeBonus(
            $rows,
            $elite,
            $this->now,
            self::CEILING,
            self::ELITE_CAP_PCT,
            self::DIV_MULT,
            self::DIV_MIN,
            $velocityCap
        );
    }

    public function testEmptyRowsYieldZero(): void
    {
        self::assertSame(0.0, $this->compute([]));
    }

    public function testSingleFreshVouchIsItsWeight(): void
    {
        // age 0 → decay factor 1.0; one source → no diversity multiplier.
        self::assertSame(1.0, $this->compute([$this->row(10, 1.0, '2026-06-26 00:00:00')]));
    }

    public function testDecayAtOneYearAnchor(): void
    {
        // 365 days old → DecayResolver anchor factor 0.70.
        self::assertSame(0.7, $this->compute([$this->row(10, 1.0, '2025-06-26 00:00:00')]));
    }

    public function testReaffirmedAtResetsTheDecayBaseline(): void
    {
        // created a year ago but reaffirmed today → fresh (factor 1.0), not 0.70.
        self::assertSame(
            1.0,
            $this->compute([$this->row(10, 1.0, '2025-06-26 00:00:00', '2026-06-26 00:00:00')])
        );
    }

    public function testDiversityMultiplierGate(): void
    {
        $two   = [$this->row(1, 1.0, '2026-06-26 00:00:00'), $this->row(2, 1.0, '2026-06-26 00:00:00')];
        $three = array_merge($two, [$this->row(3, 1.0, '2026-06-26 00:00:00')]);
        // 2 distinct sources → no multiplier (2.0); 3 → ×1.3 (3.9).
        self::assertSame(2.0, $this->compute($two), '2 sources below the diversity threshold');
        self::assertSame(3.9, $this->compute($three), '3 sources earn the 1.3x multiplier');
    }

    public function testEliteSourceCapStripsOverCapEliteWeight(): void
    {
        // Elite A (w=10) + non-elite B (w=1): total 11, elite share 90% > 40%.
        // eliteCapAbsolute = 0.4*11 = 4.4; strip (10-4.4)=5.6 → total 5.4.
        // 2 distinct sources → no diversity multiplier.
        $rows = [$this->row(1, 10.0, '2026-06-26 00:00:00'), $this->row(2, 1.0, '2026-06-26 00:00:00')];
        self::assertSame(5.4, $this->compute($rows, [1]));
    }

    public function testEliteWithinCapIsUntouched(): void
    {
        // Elite A (w=1) among non-elites totalling 4 (total 5, elite share 20% < 40%).
        // No strip; 5 distinct sources → ×1.3 → 6.5.
        $rows = [
            $this->row(1, 1.0, '2026-06-26 00:00:00'),
            $this->row(2, 1.0, '2026-06-26 00:00:00'),
            $this->row(3, 1.0, '2026-06-26 00:00:00'),
            $this->row(4, 1.0, '2026-06-26 00:00:00'),
            $this->row(5, 1.0, '2026-06-26 00:00:00'),
        ];
        self::assertSame(6.5, $this->compute($rows, [1]));
    }

    public function testCeilingClamps(): void
    {
        // 30 distinct fresh sources × 1.0 = 30, ×1.3 = 39 → clamped to ceiling 20.
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = $this->row($i, 1.0, '2026-06-26 00:00:00');
        }
        self::assertSame(self::CEILING, $this->compute($rows));
    }

    public function testVelocityCapAppliesBeforeCeiling(): void
    {
        // Sum 8 fresh × 1.0 = 8, ×1.3 = 10.4; velocity cap 5 clamps to 5.
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = $this->row($i, 1.0, '2026-06-26 00:00:00');
        }
        self::assertSame(5.0, $this->compute($rows, [], 5.0));
    }

    public function testZeroAndNegativeWeightsAreSkipped(): void
    {
        $rows = [
            $this->row(1, 0.0, '2026-06-26 00:00:00'),
            $this->row(2, -3.0, '2026-06-26 00:00:00'),
            $this->row(3, 2.0, '2026-06-26 00:00:00'),
        ];
        // Only the w=2 row counts; 1 effective source → no multiplier.
        self::assertSame(2.0, $this->compute($rows));
    }

    public function testMalformedDatesAreSkipped(): void
    {
        $rows = [
            $this->row(1, 5.0, '0000-00-00 00:00:00'),
            $this->row(2, 5.0, ''),
            $this->row(3, 1.0, '2026-06-26 00:00:00'),
        ];
        self::assertSame(1.0, $this->compute($rows));
    }

    // ══ HOW RECIPROCAL SOURCES INTERACT WITH THE DIVERSITY BONUS ══════
    //
    // ⚠ CHARACTERISATION, NOT A CHANGE. The reciprocity baseline-weight fix
    // restores x1.0 at cast time; it deliberately does NOT alter synthesis.
    // These cases pin what synthesis does with reciprocal sources TODAY, so
    // that:
    //   (a) the baseline-weight fix is shown not to disturb it, and
    //   (b) if the diversity half is ever taken up as its own decision,
    //       there is a recorded before-state to argue against.
    //
    // The old specification (trust-attestation-risk-assessment.md, cartel
    // mitigation #3) asked for reciprocal pairs to get "baseline weight only
    // (no diversity bonus)" so they "don't compound". Synthesis has never
    // implemented the second half, and these cases demonstrate that.

    public function testTheDiversityGateCountsDISTINCTSOURCESONLY(): void
    {
        // The gate is count($sources) >= DIV_MIN. Nothing about the
        // relationship between those sources is an input — so synthesis
        // cannot tell a reciprocal source from an independent one, and the
        // cast-time multipliers it already baked in are not re-read.
        $independent = [
            $this->row(1, 1.0, '2026-06-26 00:00:00'),
            $this->row(2, 1.0, '2026-06-26 00:00:00'),
            $this->row(3, 1.0, '2026-06-26 00:00:00'),
        ];

        self::assertSame(3.9, $this->compute($independent), '3 sources x 1.0, x1.3 diversity');
    }

    public function testReciprocalSourcesStillEARNTheDiversityBonus(): void
    {
        // ⚠ THIS IS THE DIVERGENCE FROM THE OLD SPEC, pinned.
        //
        // Three mutually-backing tradespeople. Post-fix each cast carries
        // baseline weight 1.0 (pre-fix it would have been 0.50). Either way
        // synthesis applies the full x1.3 diversity multiplier, because it
        // only ever counted sources. The spec asked for exactly this NOT to
        // happen; it does.
        $reciprocal = [
            $this->row(1, 1.0, '2026-06-26 00:00:00'),
            $this->row(2, 1.0, '2026-06-26 00:00:00'),
            $this->row(3, 1.0, '2026-06-26 00:00:00'),
        ];

        self::assertSame(
            3.9,
            $this->compute($reciprocal),
            'reciprocal sources compound exactly as independent ones do'
        );
    }

    public function testTheBaselineFixRAISESAReciprocalTargetsBonusAndThatIsTheIntent(): void
    {
        // The measurable effect of the cast-time fix, expressed at synthesis.
        // Pre-fix: three reciprocal casts at 0.50 → 1.5, x1.3 → 1.95.
        // Post-fix: the same three casts at 1.00 → 3.0, x1.3 → 3.9.
        $preFix = [
            $this->row(1, 0.50, '2026-06-26 00:00:00'),
            $this->row(2, 0.50, '2026-06-26 00:00:00'),
            $this->row(3, 0.50, '2026-06-26 00:00:00'),
        ];
        $postFix = [
            $this->row(1, 1.00, '2026-06-26 00:00:00'),
            $this->row(2, 1.00, '2026-06-26 00:00:00'),
            $this->row(3, 1.00, '2026-06-26 00:00:00'),
        ];

        self::assertSame(1.95, $this->compute($preFix), 'what a mutual trio used to synthesise to');
        self::assertSame(3.9, $this->compute($postFix), 'what it synthesises to now');
    }

    public function testHISTORICALROWSAREUNAFFECTEDBYTHEFIX(): void
    {
        // ⚠ The fix is FORWARD-ONLY by construction. weight_at_time is an
        // immutable cast-time snapshot and synthesis reads it verbatim, so a
        // row written at 0.50 keeps synthesising at 0.50 after the fix. No
        // migration is implied, and none is proposed.
        $mixed = [
            $this->row(1, 0.50, '2026-06-26 00:00:00'), // cast before the fix
            $this->row(2, 1.00, '2026-06-26 00:00:00'), // cast after
            $this->row(3, 1.00, '2026-06-26 00:00:00'), // cast after
        ];

        // 0.5 + 1.0 + 1.0 = 2.5, three sources → x1.3 → 3.25
        self::assertSame(3.25, $this->compute($mixed), 'old and new rows coexist, verbatim');
    }

    public function testBelowTheDiversityMinimumAReciprocalPairGetsNoBonusEitherWay(): void
    {
        // A mutual PAIR is 2 sources, under DIV_MIN of 3, so the diversity
        // question never arises for the commonest reciprocal shape at all.
        $pair = [
            $this->row(1, 1.0, '2026-06-26 00:00:00'),
            $this->row(2, 1.0, '2026-06-26 00:00:00'),
        ];

        self::assertSame(2.0, $this->compute($pair), 'no multiplier at 2 sources');
    }

    public function testSynthesisDoesNotReapplyAnyCastTimeMultiplier(): void
    {
        // Anti-double-penalty guarantee, pinned: the only thing synthesis
        // knows about a cast's wallet-age, reciprocity or cohort-overlap
        // multipliers is the single number they produced. Two rows with the
        // same weight are indistinguishable regardless of how they got it.
        $viaReciprocity = [$this->row(1, 0.50, '2026-06-26 00:00:00')];
        $viaSomethingElse = [$this->row(9, 0.50, '2026-06-26 00:00:00')];

        self::assertSame($this->compute($viaReciprocity), $this->compute($viaSomethingElse));
        self::assertSame(0.5, $this->compute($viaReciprocity));
    }
}
