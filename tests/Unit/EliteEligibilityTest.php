<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\EliteEligibilityService;
use BCC\Trust\Core\Services\TrustScoreService;
use PHPUnit\Framework\TestCase;

/**
 * Locks the §J.12 elite gate: the native-conduct floor, the cross-table
 * eligibility gates, and — most importantly — the PHP↔SQL parity that stops
 * the recalc hole from reopening.
 *
 * All pure: no DB, no WP. EliteEligibilityService::evaluate() and
 * TrustScoreService::tierFor() are static and side-effect free by design so
 * the decision can be tested without a fixture.
 */
final class EliteEligibilityTest extends TestCase
{
    // ── The gate decision ────────────────────────────────────────────────

    public function testAllConditionsMetIsEligible(): void
    {
        $v = EliteEligibilityService::evaluate(5, 90, false, 5, 90);

        self::assertTrue($v['eligible']);
        self::assertSame([], $v['failed']);
    }

    public function testTooFewDistinctAttestorsIsBlocked(): void
    {
        // 4 backers against a minimum of 5 — the anti-ring condition.
        $v = EliteEligibilityService::evaluate(4, 365, false, 5, 90);

        self::assertFalse($v['eligible']);
        self::assertContains('distinct_attestors', $v['failed']);
    }

    public function testInsufficientTenureIsBlocked(): void
    {
        $v = EliteEligibilityService::evaluate(20, 89, false, 5, 90);

        self::assertFalse($v['eligible']);
        self::assertContains('tenure_days', $v['failed']);
    }

    public function testUpheldDisputeIsBlocked(): void
    {
        // Everything else is comfortably clear; the dispute alone blocks.
        $v = EliteEligibilityService::evaluate(50, 3650, true, 5, 90);

        self::assertFalse($v['eligible']);
        self::assertContains('upheld_dispute', $v['failed']);
    }

    public function testBoundariesAreInclusive(): void
    {
        // Exactly at both minimums qualifies — the thresholds are floors,
        // not exclusive bounds.
        self::assertTrue(EliteEligibilityService::evaluate(5, 90, false, 5, 90)['eligible']);
        self::assertFalse(EliteEligibilityService::evaluate(4, 90, false, 5, 90)['eligible']);
        self::assertFalse(EliteEligibilityService::evaluate(5, 89, false, 5, 90)['eligible']);
    }

    public function testEveryFailedConditionIsReported(): void
    {
        // The breakdown exists so "why am I not elite" is answerable without
        // re-running the evaluation — it must not short-circuit on the first
        // failure.
        $v = EliteEligibilityService::evaluate(0, 0, true, 5, 90);

        self::assertFalse($v['eligible']);
        self::assertContains('distinct_attestors', $v['failed']);
        self::assertContains('tenure_days', $v['failed']);
        self::assertContains('upheld_dispute', $v['failed']);
        self::assertCount(3, $v['failed']);
    }

    // ── The native-conduct floor ─────────────────────────────────────────

    public function testComputeNativeExcludesOnchainBonusOnly(): void
    {
        // 50 + (10-0)×0.6 = 56; the 20-point onchain term is dropped.
        self::assertSame(56.0, TrustScoreService::computeNative(10.0, 0.0));
        // Every other term survives: +8 contribution, -5 penalty, +12 attestation.
        self::assertSame(71.0, TrustScoreService::computeNative(10.0, 0.0, 8.0, -5.0, 12.0));
    }

    public function testNativeScoreIsClampedIndependently(): void
    {
        // THE bug this formulation exists to avoid. Both of these clamp to a
        // total_score of 100, so `total_score - onchain_bonus` would report 80
        // for BOTH — identical readings for wildly different conduct.
        //
        //   A: positive 50 → raw 50 + 30 + 20 + 20 = 120
        //   B: positive 20 → raw 50 + 12 + 20 + 20 = 102
        self::assertSame(100.0, TrustScoreService::compute(50.0, 0.0, 20.0, 0.0, 0.0, 20.0));
        self::assertSame(100.0, TrustScoreService::compute(20.0, 0.0, 20.0, 0.0, 0.0, 20.0));

        // Recomputed from the terms instead, they separate correctly.
        self::assertSame(100.0, TrustScoreService::computeNative(50.0, 0.0, 0.0, 0.0, 20.0));
        self::assertSame(82.0, TrustScoreService::computeNative(20.0, 0.0, 0.0, 0.0, 20.0));
    }

    public function testNativeFormulaSqlDropsOnchainAndKeepsEverythingElse(): void
    {
        $native = TrustScoreService::nativeFormulaSql();

        self::assertStringNotContainsString('onchain_bonus', $native);
        self::assertStringContainsString('positive_score', $native);
        self::assertStringContainsString('negative_score', $native);
        self::assertStringContainsString('contribution_bonus', $native);
        self::assertStringContainsString('penalty_adjustment', $native);
        self::assertStringContainsString('attestation_bonus', $native);

        // The full formula still carries the onchain term.
        self::assertStringContainsString('onchain_bonus', TrustScoreService::formulaSql());
    }

    // ── The tier ladder ──────────────────────────────────────────────────

    public function testWalletDepthAloneCannotMintElite(): void
    {
        // The exact attack: onchain 20 + attestation 20 → total_score 90,
        // over the 80 threshold. Native conduct is only 70, under the floor
        // of 71, so the tier resolves to trusted.
        $total  = TrustScoreService::compute(0.0, 0.0, 20.0, 0.0, 0.0, 20.0);
        $native = TrustScoreService::computeNative(0.0, 0.0, 0.0, 0.0, 20.0);

        self::assertSame(90.0, $total);
        self::assertSame(70.0, $native);
        self::assertSame('trusted', TrustScoreService::tierFor($total, $native, true));
    }

    public function testGenuineConductReachesElite(): void
    {
        // Same 90 total, but earned: 50 + (40-0)×0.6 + 16 attestation = 90
        // native. Over both the threshold and the floor.
        $total  = TrustScoreService::compute(40.0, 0.0, 0.0, 0.0, 0.0, 16.0);
        $native = TrustScoreService::computeNative(40.0, 0.0, 0.0, 0.0, 16.0);

        self::assertSame(90.0, $total);
        self::assertSame(90.0, $native);
        self::assertSame('elite', TrustScoreService::tierFor($total, $native, true));
    }

    public function testGateDeniesEliteRegardlessOfScore(): void
    {
        // A perfect 100/100 still does not reach elite while the cross-table
        // gate says no.
        self::assertSame('trusted', TrustScoreService::tierFor(100.0, 100.0, false));
        self::assertSame('elite', TrustScoreService::tierFor(100.0, 100.0, true));
    }

    public function testLowerTiersAreUnaffectedByTheGate(): void
    {
        // The gate touches ONLY the elite arm — a denied page must not fall
        // further than trusted, and the lower bands must not shift at all.
        foreach ([true, false] as $gate) {
            self::assertSame('trusted', TrustScoreService::tierFor(65.0, 65.0, $gate));
            self::assertSame('neutral', TrustScoreService::tierFor(45.0, 45.0, $gate));
            self::assertSame('caution', TrustScoreService::tierFor(30.0, 30.0, $gate));
            self::assertSame('risky', TrustScoreService::tierFor(29.9, 29.9, $gate));
        }
    }

    // ── The grandfather rule ─────────────────────────────────────────────

    public function testNeverEvaluatedRowsAreGrandfathered(): void
    {
        // Shipping the column must not itself be a mass demotion: a row with
        // no evaluation timestamp reads as eligible even though the flag
        // defaults to 0.
        self::assertTrue(TrustScoreService::resolveEliteEligible(0, null));
        self::assertTrue(TrustScoreService::resolveEliteEligible(0, ''));
        self::assertTrue(TrustScoreService::resolveEliteEligible(0, '0000-00-00 00:00:00'));
    }

    public function testEvaluatedRowsHonourTheFlag(): void
    {
        self::assertFalse(TrustScoreService::resolveEliteEligible(0, '2026-07-28 00:00:00'));
        self::assertTrue(TrustScoreService::resolveEliteEligible(1, '2026-07-28 00:00:00'));
        // $wpdb hands back numeric strings.
        self::assertFalse(TrustScoreService::resolveEliteEligible('0', '2026-07-28 00:00:00'));
        self::assertTrue(TrustScoreService::resolveEliteEligible('1', '2026-07-28 00:00:00'));
    }

    // ── PHP ↔ SQL parity ─────────────────────────────────────────────────

    public function testTierSqlEncodesTheSamePredicateAsTierFor(): void
    {
        // THE regression guard. tierFor() runs on the 5-minute recalculation
        // queue and the hourly recalc; tierSql() runs on the eight inline
        // score UPDATEs. If they drift, a gated demotion is silently restored
        // within five minutes and the whole gate becomes a no-op in
        // production while still passing manual testing.
        $sql = TrustScoreService::tierSql(TrustScoreService::formulaSql());

        // The elite arm carries all three conditions.
        self::assertStringContainsString('elite_eligible = 1', $sql);
        self::assertStringContainsString('elite_eligible_at IS NULL', $sql);
        self::assertStringContainsString("THEN 'elite'", $sql);

        // The native expression is bucketed against the floor, and it is the
        // onchain-free one.
        $floor = TrustScoreService::eliteNativeFloor();
        self::assertStringContainsString('>= ' . $floor, $sql);
        self::assertStringContainsString(TrustScoreService::nativeFormulaSql(), $sql);

        // Every band still present, highest-first.
        foreach (['elite', 'trusted', 'neutral', 'caution'] as $tier) {
            self::assertStringContainsString("THEN '{$tier}'", $sql);
        }
        self::assertStringContainsString("ELSE 'risky'", $sql);
    }

    public function testGateConditionsApplyOnlyToTheEliteArm(): void
    {
        $sql = TrustScoreService::tierSql(TrustScoreService::formulaSql());

        // The gate must appear exactly once — if a future edit copies it onto
        // the trusted arm, a denied page would fall two bands instead of one.
        self::assertSame(1, substr_count($sql, 'elite_eligible = 1'));
    }

    public function testNativeFloorSitsAboveThePureAttestationCeiling(): void
    {
        // attestation_bonus is ceiling'd at 20, so backing-with-no-reviews
        // tops out at exactly 70 native. The floor must be strictly above
        // that or the vouch-only path stays open.
        $ceiling = defined('BCC_ATTEST_CEILING') ? (float) BCC_ATTEST_CEILING : 20.00;
        $pureAttestationMax = TrustScoreService::computeNative(0.0, 0.0, 0.0, 0.0, $ceiling);

        self::assertGreaterThan(
            $pureAttestationMax,
            (float) TrustScoreService::eliteNativeFloor(),
            'The native floor must exceed the maximum reachable from attestations alone.'
        );
    }
}
