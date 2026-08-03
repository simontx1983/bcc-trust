<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Vote\VoteWeightCalculator;
use BCC\Trust\Rank\Support\RankScoringConfig;
use PHPUnit\Framework\TestCase;

/**
 * Locks the §16.6 meaningful-vote formula (Rank redesign Phase 6):
 *
 *   effective = maturity × rank_multiplier × trust_multiplier
 *               × fraud_discount, clamped to [0, 1.75]
 *
 * Runs against the REAL shipped RankScoringConfig (Phase 0 doctrine) so a
 * config edit is exercised by the same tests that guard the invariants:
 *   - §16.3 maturity boundary suite (d = 0 / 45 / 89 / 90; master-plan C3)
 *   - invariant 15: maturity is a property of the apprentice epoch —
 *     promotion never completes vesting early
 *   - invariant 17: the max ordinary stack is exactly the 1.75 ceiling
 *   - invariant 18: no input combination exceeds the ceiling
 *
 * Pure: the calculator takes every input by argument; no DB, no WP.
 */
final class VoteWeightFormulaTest extends TestCase
{
    private VoteWeightCalculator $calc;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->calc = new VoteWeightCalculator(RankScoringConfig::fromDefaultFile());
        $this->now  = new \DateTimeImmutable('2026-08-03 12:00:00', new \DateTimeZone('UTC'));
    }

    /** MySQL-format epoch exactly $days before $this->now. */
    private function epochDaysAgo(int $days): string
    {
        return $this->now->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    /** Signals that trip no fraud penalty (discount 1.0). */
    private function cleanSignals(): array
    {
        return [
            'automation'               => ['is_automated' => false, 'confidence' => 0],
            'multi_account_risk'       => false,
            'device_fraud_probability' => 0.0,
            'behavior_score'           => 0,
            'trust_rank'               => 0.0,
        ];
    }

    // ── §16.3 maturity boundaries (C3 suite) ─────────────────────────────

    public function testMaturityBoundaryDays(): void
    {
        // floor 0.075, span 90: floor + d/span × (1 − floor)
        self::assertSame(0.075, $this->calc->maturity($this->epochDaysAgo(0), $this->now));
        self::assertSame(0.5375, $this->calc->maturity($this->epochDaysAgo(45), $this->now));
        self::assertSame(0.9897, $this->calc->maturity($this->epochDaysAgo(89), $this->now));
        self::assertSame(1.0, $this->calc->maturity($this->epochDaysAgo(90), $this->now));
        // Beyond the span it saturates — never over-unity.
        self::assertSame(1.0, $this->calc->maturity($this->epochDaysAgo(400), $this->now));
    }

    public function testMaturityIsMonotonicNonDecreasing(): void
    {
        $prev = 0.0;
        for ($d = 0; $d <= 90; $d++) {
            $m = $this->calc->maturity($this->epochDaysAgo($d), $this->now);
            self::assertGreaterThanOrEqual($prev, $m, "maturity regressed at day {$d}");
            $prev = $m;
        }
    }

    public function testMissingOrUnparseableEpochVestsNothing(): void
    {
        // Null / empty / garbage epoch → the floor, never negative or over-unity.
        self::assertSame(0.075, $this->calc->maturity(null, $this->now));
        self::assertSame(0.075, $this->calc->maturity('', $this->now));
        self::assertSame(0.075, $this->calc->maturity('not-a-date', $this->now));
        // A FUTURE epoch (clock skew) clamps to day 0, not negative.
        $future = $this->now->modify('+10 days')->format('Y-m-d H:i:s');
        self::assertSame(0.075, $this->calc->maturity($future, $this->now));
    }

    // ── §16.5 trust multiplier anchors ───────────────────────────────────

    public function testTrustMultiplierAnchorsAndClamps(): void
    {
        self::assertSame(0.75, $this->calc->trustMultiplier(45.0));
        self::assertSame(1.25, $this->calc->trustMultiplier(100.0));
        // Midpoint of the score span maps to the value midpoint.
        self::assertSame(1.0, $this->calc->trustMultiplier(72.5));
        // Below/above the anchors clamps — never extrapolates.
        self::assertSame(0.75, $this->calc->trustMultiplier(0.0));
        self::assertSame(1.25, $this->calc->trustMultiplier(150.0));
    }

    // ── Invariant 17: the max ordinary stack IS the ceiling, exactly ─────

    public function testMaxStackHitsCeilingExactly(): void
    {
        $w = $this->calc->calculate(
            'veteran',
            $this->epochDaysAgo(90),
            100.0,
            'elite',
            $this->cleanSignals(),
            0,
            $this->now
        );

        // 1.0 × 1.4 × 1.25 = 1.75 — the §16.7 ceiling, exactly.
        self::assertSame(1.75, $w->base);
        self::assertSame(1.75, $w->effective);
        self::assertSame($w->effective, $w->vested);
    }

    // ── Invariant 18: no combination exceeds the ceiling ─────────────────

    public function testNoInputCombinationExceedsCeiling(): void
    {
        foreach (['apprentice', 'journeyman', 'veteran'] as $rank) {
            foreach ([0, 45, 89, 90, 400] as $d) {
                foreach ([0.0, 45.0, 72.5, 100.0, 150.0] as $trust) {
                    $w = $this->calc->calculate(
                        $rank, $this->epochDaysAgo($d), $trust, 'neutral',
                        $this->cleanSignals(), 0, $this->now
                    );
                    self::assertLessThanOrEqual(
                        1.75,
                        $w->effective,
                        "ceiling exceeded: {$rank} d={$d} trust={$trust}"
                    );
                    self::assertGreaterThanOrEqual(0.0, $w->effective);
                }
            }
        }
    }

    // ── Invariant 15: promotion never completes vesting early ────────────

    public function testMaturityDependsOnEpochNotRank(): void
    {
        $epoch = $this->epochDaysAgo(45);

        // Trust 72.5 → multiplier exactly 1.0, isolating maturity × rank.
        $apprentice = $this->calc->calculate(
            'apprentice', $epoch, 72.5, 'neutral', $this->cleanSignals(), 0, $this->now
        );
        $veteran = $this->calc->calculate(
            'veteran', $epoch, 72.5, 'neutral', $this->cleanSignals(), 0, $this->now
        );

        // Same epoch → same maturity term; the rungs differ ONLY by the
        // rank multiplier (1.0 vs 1.4). A promotion at day 45 does not
        // fast-forward the ramp.
        self::assertSame(0.5375, $apprentice->base);
        self::assertSame(round(0.5375 * 1.4, 4), $veteran->base);
    }

    // ── Fail-closed rank handling (§5.3) ─────────────────────────────────

    public function testNewMemberAndUnknownRankProduceZeroWeight(): void
    {
        foreach ([null, 'master', 'not-a-rank'] as $rankSlug) {
            $w = $this->calc->calculate(
                $rankSlug, $this->epochDaysAgo(90), 100.0, 'elite',
                $this->cleanSignals(), 0, $this->now
            );
            $label = $rankSlug ?? 'null';
            self::assertSame(0.0, $w->base, "base not zero for rank {$label}");
            self::assertSame(0.0, $w->effective, "effective not zero for rank {$label}");
        }
    }

    // ── Fraud discount: the only per-ballot integrity multiplier ─────────

    public function testConfirmedAutomationHardBlocks(): void
    {
        $signals = $this->cleanSignals();
        $signals['automation'] = ['is_automated' => true, 'confidence' => 100];

        $w = $this->calc->calculate(
            'veteran', $this->epochDaysAgo(90), 100.0, 'elite', $signals, 0, $this->now
        );

        // Penalty 0.90 hits the malicious tier → discount 0, zero influence —
        // regardless of a maxed maturity/rank/trust stack.
        self::assertSame(1.75, $w->base);
        self::assertSame(0.0, $w->fraudDiscount);
        self::assertSame(0.0, $w->effective);
        self::assertTrue($w->isFraudBlocked());
    }

    public function testModerateFraudDiscountsProportionally(): void
    {
        // fraud_score 60 → penalty (60/100 × score-weight), suspicious tier:
        // effective = round(base × discount, 4), strictly between 0 and base.
        $w = $this->calc->calculate(
            'journeyman', $this->epochDaysAgo(90), 72.5, 'neutral',
            $this->cleanSignals(), 60, $this->now
        );

        self::assertSame(1.2, $w->base);
        self::assertGreaterThan(0.0, $w->fraudDiscount);
        self::assertLessThan(1.0, $w->fraudDiscount);
        self::assertSame(round(1.2 * $w->fraudDiscount, 4), $w->effective);
    }
}
