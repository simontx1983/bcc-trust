<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\ContributionScoreService;
use BCC\Trust\Core\Services\ReputationCalculatorService;
use PHPUnit\Framework\TestCase;

// Load the real scoring constants (pure defines; require_once is safe).
require_once __DIR__ . '/../../includes/config/trust-weights.php';
require_once __DIR__ . '/../../includes/config/tiers.php';
require_once __DIR__ . '/../../includes/config/contribution.php';

/**
 * Locks the "Trust Recovery Through Contribution" math against the four
 * anti-abuse rules. All pure functions — no DB, no WP.
 *
 *   R1 — reactions feed only the capped/ceiling'd bonus (never genuine trust).
 *   R2 — contribution alone can't reach Trusted (the blend ceiling).
 *   R3 — non-overlapping per-window caps reward consistency over spikes.
 *   R4 — engagement is weighted by the engager's tier (new accounts ≈ 0).
 */
final class ContributionRecoveryTest extends TestCase
{
    // ── R4: trust-weighted engagement ────────────────────────────────

    public function testHigherTierEngagementWeighsMoreThanLowTier(): void
    {
        self::assertGreaterThan(
            ContributionScoreService::tierWeight('risky'),
            ContributionScoreService::tierWeight('elite'),
            'elite engagement must outweigh risky engagement'
        );
        self::assertSame(
            ContributionScoreService::tierWeight('neutral'),
            ContributionScoreService::tierWeight('bogus-tier'),
            'unknown tier falls back to neutral weight'
        );
    }

    public function testReactionsAloneCreateNoTrust(): void
    {
        // R1, the strongest guarantee: usefulness is a MULTIPLIER on real
        // contribution, never an additive term. A user with zero qualifying
        // contributions earns zero bonus no matter how many reactions they
        // farm (max usefulness multiplier × 0 base = 0).
        $farmed = ContributionScoreService::composeContribution(0.0, 0.0, 0.0, (float) BCC_CONTRIB_ENGAGEMENT_MAX_MULT, false);
        self::assertSame(0.0, $farmed, 'reactions never create trust without underlying contribution');
    }

    public function testEliteEngagementOutweighsRiskyPerReaction(): void
    {
        // Same COUNT of reactions: elite engagement yields strictly more
        // usefulness than risky/new-account engagement (the weight is what
        // matters, defeating reaction farming from junk accounts).
        $elite = ContributionScoreService::usefulnessFromWeightedEngagement(50 * ContributionScoreService::tierWeight('elite'));
        $risky = ContributionScoreService::usefulnessFromWeightedEngagement(50 * ContributionScoreService::tierWeight('risky'));
        self::assertGreaterThan($risky, $elite);
        // Zero engagement is a neutral 1.0 multiplier (no penalty, no boost).
        self::assertSame(1.0, ContributionScoreService::usefulnessFromWeightedEngagement(0.0));
    }

    public function testUsefulnessIsCappedAtMaxMultiplier(): void
    {
        $huge = ContributionScoreService::usefulnessFromWeightedEngagement(1_000_000.0);
        self::assertSame((float) BCC_CONTRIB_ENGAGEMENT_MAX_MULT, $huge);
    }

    // ── R3: per-window caps reward consistency, not spikes ────────────

    public function testSingleWindowSpikeIsCappedPerWindow(): void
    {
        // A huge spike in one window can contribute at most the per-window cap.
        $spike = ContributionScoreService::composeContribution(9999.0, 0.0, 0.0, 1.0, false);
        self::assertSame((float) BCC_CONTRIB_PER_WINDOW_CAP, $spike);

        // Activity spread across all three windows out-earns the same total in one.
        $spread = ContributionScoreService::composeContribution(
            (float) BCC_CONTRIB_PER_WINDOW_CAP,
            (float) BCC_CONTRIB_PER_WINDOW_CAP,
            (float) BCC_CONTRIB_PER_WINDOW_CAP,
            1.0,
            false
        );
        self::assertGreaterThan($spike, $spread, 'sustained activity beats a single spike');
    }

    public function testContributionTotalIsCappedAtMax(): void
    {
        $maxed = ContributionScoreService::composeContribution(1000.0, 1000.0, 1000.0, 2.0, false);
        self::assertSame((float) BCC_CONTRIB_MAX, $maxed);
    }

    // ── R1 / clean-record: violation dampens, never amplifies ─────────

    public function testRecentViolationDampensContribution(): void
    {
        $clean    = ContributionScoreService::composeContribution(3.0, 0.0, 0.0, 1.0, false);
        $violated = ContributionScoreService::composeContribution(3.0, 0.0, 0.0, 1.0, true);
        self::assertSame($clean * (float) BCC_CONTRIB_VIOLATION_DAMPEN, $violated);
    }

    public function testRecentViolationZeroesConsistency(): void
    {
        self::assertSame(0.0, ContributionScoreService::composeConsistency(9999, 3, true));
        self::assertGreaterThan(0.0, ContributionScoreService::composeConsistency(9999, 3, false));
    }

    public function testConsistencyIsCappedAndMonotonic(): void
    {
        $full = ContributionScoreService::composeConsistency(99999, 3, false);
        self::assertLessThanOrEqual((float) BCC_CONSIST_MAX, $full);
        // More sustained presence + older account ⇒ not less consistency.
        $less = ContributionScoreService::composeConsistency(10, 1, false);
        self::assertGreaterThanOrEqual($less, $full);
    }

    // ── R2: the blend ceiling — contribution can't reach Trusted ──────

    public function testContributionCannotLiftBelowTrustedUserIntoTrusted(): void
    {
        // A caution-ish user (genuine 40) with the maximum possible bonus…
        $maxBonus = (float) BCC_CONTRIB_MAX + (float) BCC_CONSIST_MAX;
        $blended  = ReputationCalculatorService::blendContribution(40.0, $maxBonus);

        self::assertLessThan((float) BCC_TRUST_TIER_TRUSTED, $blended, 'must stay below Trusted');
        self::assertLessThanOrEqual((float) BCC_CONTRIB_CEILING, $blended, 'capped at the contribution ceiling');
    }

    public function testRiskyUserCanRecoverButOnlyGradually(): void
    {
        // Risky (genuine 18) + max bonus climbs out of Risky (≥ caution) but
        // not to Neutral on contribution alone.
        $maxBonus = (float) BCC_CONTRIB_MAX + (float) BCC_CONSIST_MAX;
        $blended  = ReputationCalculatorService::blendContribution(18.0, $maxBonus);
        self::assertGreaterThan(18.0, $blended, 'contribution assists recovery');
        self::assertLessThan((float) BCC_TRUST_TIER_TRUSTED, $blended);
    }

    public function testGenuineTrustedUserKeepsFullScoreNoCeiling(): void
    {
        // A user who independently earned Trusted (genuine 70) is NOT clamped
        // to the contribution ceiling — the ceiling only applies below Trusted.
        $blended = ReputationCalculatorService::blendContribution(70.0, 8.0);
        self::assertSame(78.0, $blended);
    }

    public function testBlendClampsToHundred(): void
    {
        self::assertSame(100.0, ReputationCalculatorService::blendContribution(98.0, 10.0));
    }
}
