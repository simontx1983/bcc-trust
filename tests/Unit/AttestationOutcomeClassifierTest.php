<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\AttestationOutcomeClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Slice-2 Operator Reliability math (the pure compute core):
 * the weighted-average normalization (denominator = Σ weights), the
 * per-outcome goodness fed in, the vouch vs stand_behind kind weighting,
 * and the stand_behind early-read multiplier table + first-5 protection.
 *
 * A regression that drops the normalization, flips the kind weighting, or
 * breaks the early-read tiers must turn this suite red — those are the
 * load-bearing reliability guarantees the §J.3.2.1 self-mirror relies on.
 */
final class AttestationOutcomeClassifierTest extends TestCase
{
    // Mirror the includes/config/attestation.php defaults so the pure-core
    // assertions read against the shipped tuning.
    private const KW_VOUCH        = 1.0;
    private const KW_STAND_BEHIND = 1.5;
    private const M1      = 2.5;
    private const M25     = 1.5;
    private const M620    = 1.0;
    private const M21PLUS = 0.5;

    // Goodness values.
    private const G_UPHELD    = 0.0;
    private const G_CLEAN     = 0.5;
    private const G_FURTHER   = 1.0;
    private const G_DISMISSED = 1.0;

    /**
     * @param list<array{goodness: float, kind_weight: float, early_read_mult: float}> $rows
     */
    private function compute(array $rows): float
    {
        return AttestationOutcomeClassifier::computeReliability($rows);
    }

    /** @return array{goodness: float, kind_weight: float, early_read_mult: float} */
    private function row(float $goodness, float $kw = self::KW_VOUCH, float $erm = 1.0): array
    {
        return ['goodness' => $goodness, 'kind_weight' => $kw, 'early_read_mult' => $erm];
    }

    public function testEmptyYieldsZero(): void
    {
        self::assertSame(0.0, $this->compute([]));
    }

    public function testZeroTotalWeightYieldsZero(): void
    {
        // A row whose combined weight is 0 contributes nothing and leaves the
        // denominator at 0 → 0.0, never a divide-by-zero.
        self::assertSame(0.0, $this->compute([$this->row(self::G_FURTHER, self::KW_VOUCH, 0.0)]));
    }

    public function testSingleCleanActiveIsHalf(): void
    {
        self::assertSame(0.5, $this->compute([$this->row(self::G_CLEAN)]));
    }

    public function testAllPositiveIsOne(): void
    {
        $rows = [
            $this->row(self::G_FURTHER),
            $this->row(self::G_DISMISSED),
            $this->row(self::G_FURTHER, self::KW_STAND_BEHIND),
        ];
        self::assertSame(1.0, $this->compute($rows));
    }

    public function testDisputedUpheldDragsTheAverage(): void
    {
        // One upheld (0.0) + one further (1.0), equal weights → 0.5.
        $rows = [
            $this->row(self::G_UPHELD),
            $this->row(self::G_FURTHER),
        ];
        self::assertSame(0.5, $this->compute($rows));

        // Adding a clean (0.5) shifts it: (0 + 1 + 0.5) / 3 = 0.5 still here,
        // so use a sharper case: two upheld + one further → 1/3 ≈ 0.3333.
        $rows2 = [
            $this->row(self::G_UPHELD),
            $this->row(self::G_UPHELD),
            $this->row(self::G_FURTHER),
        ];
        self::assertSame(0.3333, $this->compute($rows2));
    }

    public function testKindWeightingFavoursStandBehind(): void
    {
        // A GOOD stand_behind (1.0) and a BAD vouch (0.0): equal-weight would
        // give 0.5, but stand_behind weighs 1.5 vs vouch 1.0, so the good
        // signal dominates: (1.0*1.5 + 0.0*1.0) / (1.5 + 1.0) = 1.5/2.5 = 0.6.
        $rows = [
            $this->row(self::G_FURTHER, self::KW_STAND_BEHIND),
            $this->row(self::G_UPHELD, self::KW_VOUCH),
        ];
        self::assertSame(0.6, $this->compute($rows));
    }

    public function testWeightedAverageNormalizationMixedRows(): void
    {
        // stand_behind 1st-mover good (1.0, kw 1.5, erm 2.5) + vouch clean
        // (0.5, kw 1.0, erm 1.0):
        //   num = 1.0*1.5*2.5 + 0.5*1.0*1.0 = 3.75 + 0.5 = 4.25
        //   den = 1.5*2.5 + 1.0*1.0       = 3.75 + 1.0 = 4.75
        //   4.25 / 4.75 = 0.894736… → 0.8947
        $rows = [
            $this->row(self::G_FURTHER, self::KW_STAND_BEHIND, self::M1),
            $this->row(self::G_CLEAN, self::KW_VOUCH, self::M620),
        ];
        self::assertSame(0.8947, $this->compute($rows));
    }

    // ── Early-read multiplier table ────────────────────────────────────────

    private function erm(string $kind, int $order, bool $protected): float
    {
        return AttestationOutcomeClassifier::earlyReadMultiplier(
            $kind,
            $order,
            $protected,
            self::M1,
            self::M25,
            self::M620,
            self::M21PLUS
        );
    }

    public function testVouchAlwaysOneRegardlessOfOrder(): void
    {
        self::assertSame(1.0, $this->erm('vouch', 1, false));
        self::assertSame(1.0, $this->erm('vouch', 99, false));
    }

    public function testStandBehindEarlyReadTiers(): void
    {
        self::assertSame(self::M1, $this->erm('stand_behind', 1, false), '1st');
        self::assertSame(self::M25, $this->erm('stand_behind', 2, false), '2nd');
        self::assertSame(self::M25, $this->erm('stand_behind', 5, false), '5th');
        self::assertSame(self::M620, $this->erm('stand_behind', 6, false), '6th');
        self::assertSame(self::M620, $this->erm('stand_behind', 20, false), '20th');
        self::assertSame(self::M21PLUS, $this->erm('stand_behind', 21, false), '21st');
        self::assertSame(self::M21PLUS, $this->erm('stand_behind', 500, false), 'far tail');
    }

    public function testFirstMoverProtectionForcesOne(): void
    {
        // A late stand_behind (order 50 → would be 0.5×) that's within the
        // operator's first-protected window is pinned to 1.0×.
        self::assertSame(1.0, $this->erm('stand_behind', 50, true));
        // Even an order-1 cast under protection is 1.0× (not 2.5×) — protection
        // overrides the tier table entirely.
        self::assertSame(1.0, $this->erm('stand_behind', 1, true));
    }

    // ── Slice 4 Early Read sub-tracks (weightedGoodness) ────────────────────

    /**
     * @param list<array{goodness: float, kind_weight: float, early_read_mult: float}> $rows
     */
    private function subTrack(array $rows): float
    {
        return AttestationOutcomeClassifier::weightedGoodness($rows);
    }

    public function testSubTrackEmptySubsetIsZero(): void
    {
        // An operator with no stand_behind attestations (consensus subset) or
        // no pre-consensus stand_behinds (early-read subset) has no number.
        self::assertSame(0.0, $this->subTrack([]));
    }

    public function testSubTrackIsTheSameWeightedAverageAsReliability(): void
    {
        // The sub-tracks reuse the identical normalization — same rows in,
        // same value out as computeReliability. This is the lockstep guard:
        // if weightedGoodness ever diverges from computeReliability the
        // sub-tracks would silently drift from the headline math.
        $rows = [
            $this->row(self::G_FURTHER, self::KW_STAND_BEHIND, self::M1),
            $this->row(self::G_CLEAN, self::KW_STAND_BEHIND, self::M620),
        ];
        self::assertSame($this->compute($rows), $this->subTrack($rows));
    }

    public function testEarlyReadMultiplierIsReflectedInTheWeighting(): void
    {
        // early_read_accuracy is a weighted average where the 1st-mover
        // multiplier (2.5×) is the point: a GOOD 1st-mover call (1.0, kw 1.5,
        // erm 2.5) outweighs a BAD later call (0.0, kw 1.5, erm 0.5):
        //   num = 1.0*1.5*2.5 + 0.0*1.5*0.5 = 3.75
        //   den = 1.5*2.5 + 1.5*0.5         = 3.75 + 0.75 = 4.5
        //   3.75 / 4.5 = 0.8333…
        $rows = [
            $this->row(self::G_FURTHER, self::KW_STAND_BEHIND, self::M1),
            $this->row(self::G_UPHELD, self::KW_STAND_BEHIND, self::M21PLUS),
        ];
        self::assertSame(0.8333, $this->subTrack($rows));
    }
}
