<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\TrustScoreService;
use BCC\Trust\Core\ValueObjects\PageScore;
use PHPUnit\Framework\TestCase;

/**
 * Locks the contribution_bonus term added to the canonical trust-score
 * formula (Architecture A — a member's tier is their self-page's total_score,
 * which now includes contribution). Pure: no DB, no WP.
 */
final class PageScoreContributionTest extends TestCase
{
    public function testComputeAddsTheContributionTerm(): void
    {
        // Default (entity pages) — contribution defaults 0, formula unchanged.
        self::assertSame(50.0, TrustScoreService::compute(0.0, 0.0, 0.0, 0.0));
        // The new term lifts the score…
        self::assertSame(58.0, TrustScoreService::compute(0.0, 0.0, 0.0, 0.0, 8.0));
        // …alongside votes + the other bonuses…
        self::assertSame(78.0, TrustScoreService::compute(10.0, 0.0, 0.0, 0.0, 8.0));
        // …and is still clamped to [0, 100].
        self::assertSame(100.0, TrustScoreService::compute(0.0, 0.0, 0.0, 0.0, 200.0));
    }

    public function testFormulaSqlIncludesContributionBonus(): void
    {
        self::assertStringContainsString('contribution_bonus', TrustScoreService::formulaSql());
    }

    public function testTierSqlMapsScoreExpressionToTierThresholds(): void
    {
        $sql = TrustScoreService::tierSql(TrustScoreService::formulaSql());
        // Highest-first CASE covering every tier + the risky floor.
        self::assertStringContainsString("THEN 'elite'", $sql);
        self::assertStringContainsString("THEN 'trusted'", $sql);
        self::assertStringContainsString("THEN 'neutral'", $sql);
        self::assertStringContainsString("THEN 'caution'", $sql);
        self::assertStringContainsString("ELSE 'risky'", $sql);
        // The score expression (incl. contribution_bonus) is what's bucketed.
        self::assertStringContainsString('contribution_bonus', $sql);
    }

    public function testPageScoreCarriesContributionAndPassesTheFormulaCheck(): void
    {
        // total 78 = 50 + (10-0)*2 + 0 + 0 + 8(contribution) — must validate.
        $score = new PageScore(
            1_000_000_005, // a self-page id (member 5)
            5,
            78.0,          // total_score
            10.0,          // positive
            0.0,           // negative
            1,             // vote_count
            1,             // unique_voters
            0.0,           // confidence
            'trusted',
            0,             // endorsement_count
            null,          // last_vote_at
            null,          // last_calculated_at
            null,          // fraud_metadata
            0.0,           // endorsement_bonus
            0.0,           // onchain_bonus
            8.0            // contribution_bonus
        );

        self::assertSame(8.0, $score->getContributionBonus());
        self::assertSame(78.0, $score->getTotalScore());
    }

    public function testPageScoreRejectsTotalThatIgnoresContribution(): void
    {
        // total 70 ignores the +8 contribution (expected 78) — beyond tolerance,
        // so the VO's formula check rejects it.
        $this->expectException(\InvalidArgumentException::class);
        new PageScore(
            1_000_000_005, 5, 70.0, 10.0, 0.0, 1, 1, 0.0, 'neutral',
            0, null, null, null, 0.0, 0.0, 8.0
        );
    }
}
