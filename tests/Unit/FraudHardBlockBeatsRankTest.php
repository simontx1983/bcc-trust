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
    private static function signals(bool $automated, float $trustRank, float $behavior = 0.0): array
    {
        return [
            'automation'     => ['is_automated' => $automated, 'confidence' => $automated ? 100 : 0],
            'behavior_score' => $behavior,
            'trust_rank'     => $trustRank,
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
        // Not flagged as a bot, but worst-case behaviour (0.50) + a maxed
        // fraud score on record (0.50) reach the block before any relief.
        $r = FraudDiscountCalculator::compute(self::signals(false, 1.0, 100.0), 100);

        self::assertGreaterThanOrEqual(
            BCC_TRUST_FRAUD_BLOCK_THRESHOLD,
            array_sum(array_filter($r['penalties'], static fn (float $p): bool => $p > 0)),
            'precondition: the pre-relief penalty reaches the block'
        );
        self::assertSame(0.0, $r['discount']);
    }

    public function testRankReliefStillSoftensAPenaltyBelowTheBlock(): void
    {
        $noRank   = FraudDiscountCalculator::compute(self::signals(false, 0.0, 60.0), 0);
        $withRank = FraudDiscountCalculator::compute(self::signals(false, 1.0, 60.0), 0);

        self::assertGreaterThan(0.0, $noRank['discount'], 'control: a suspicious member is not blocked');
        self::assertGreaterThan($noRank['discount'], $withRank['discount'], 'a good rank still earns relief below the block');
    }

    public function testSharedDeviceSignalsNoLongerReduceVoteWeight(): void
    {
        // F-184 (user decision): a household sharing one computer votes at
        // full weight. Old callers' keys are ignored, not penalised.
        $r = FraudDiscountCalculator::compute(self::signals(false, 0.0) + [
            'multi_account_risk'       => true,
            'device_fraud_probability' => 1.0,
        ], 0);

        self::assertSame(1.0, $r['discount']);
        self::assertSame([], array_filter($r['penalties'], static fn (float $p): bool => $p > 0));
    }
}
