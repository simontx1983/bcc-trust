<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\FraudDiscountCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * F-82 (user decision): a confirmed bot's hard block ALWAYS beats rank.
 *
 * FraudDiscountCalculator::compute() summed the trust-rank boost (a
 * NEGATIVE penalty, up to BCC_TRUST_FRAUD_RANK_BOOST_CAP) into the total
 * before checking the hard-block threshold. A confirmed bot carries exactly
 * the block-threshold penalty (automation 0.90), so a bot with a high
 * trust_rank — the very thing a reputation-farming bot would have — landed
 * below the threshold and kept a non-zero vote weight. The block is now
 * decided before any rank relief; relief still softens penalties BELOW the
 * block for genuine members.
 */
#[CoversClass(FraudDiscountCalculator::class)]
final class FraudHardBlockBeatsRankTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function signals(bool $automated, float $trustRank, bool $multiAccount = false, float $deviceFraud = 0.0): array
    {
        return [
            'automation'               => ['is_automated' => $automated, 'confidence' => $automated ? 100 : 0],
            'multi_account_risk'       => $multiAccount,
            'device_fraud_probability' => $deviceFraud,
            'behavior_score'           => 0.0,
            'trust_rank'               => $trustRank,
        ];
    }

    public function testAConfirmedBotWithTopRankIsStillHardBlocked(): void
    {
        $r = FraudDiscountCalculator::compute(self::signals(true, 1.0), 0);

        self::assertArrayHasKey('trust_rank_boost', $r['penalties'], 'precondition: the rank boost is present');
        self::assertSame(0.0, $r['discount'], 'a confirmed bot keeps zero weight whatever its rank');
    }

    public function testAPreReliefPenaltyAtTheThresholdIsBlockedWhateverTheRank(): void
    {
        // Not flagged as a bot, but multi-account + heavy device fraud reach
        // the block threshold before any relief.
        $r = FraudDiscountCalculator::compute(self::signals(false, 1.0, true, 1.0), 0);

        self::assertGreaterThanOrEqual(
            BCC_TRUST_FRAUD_BLOCK_THRESHOLD,
            array_sum(array_filter($r['penalties'], static fn (float $p): bool => $p > 0)),
            'precondition: the pre-relief penalty reaches the block'
        );
        self::assertSame(0.0, $r['discount']);
    }

    public function testRankReliefStillSoftensAPenaltyBelowTheBlock(): void
    {
        $noRank   = FraudDiscountCalculator::compute(self::signals(false, 0.0, true), 0);
        $withRank = FraudDiscountCalculator::compute(self::signals(false, 1.0, true), 0);

        self::assertGreaterThan(0.0, $noRank['discount'], 'control: a suspicious member is not blocked');
        self::assertGreaterThan($noRank['discount'], $withRank['discount'], 'a good rank still earns relief below the block');
    }
}
