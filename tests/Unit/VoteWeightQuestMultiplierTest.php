<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Vote\VoteWeightCalculator;
use BCC\Trust\Core\ValueObjects\VoteWeight;
use PHPUnit\Framework\TestCase;

// Real scoring constants (pure defines; require_once is safe and idempotent).
require_once __DIR__ . '/../../includes/config/trust-weights.php';
require_once __DIR__ . '/../../includes/config/fraud-detection.php';

// Age thresholds/multipliers live in scoring.php, which ALSO (re)defines
// BCC_RANK_CLAIM_VERIFIED_BONUS — already set by the shared bootstrap, so
// requiring the whole file would warn. Mirror just the age constants inline
// (pattern: AttestationOutcomeClassifierTest mirrors its config defaults).
if (!defined('BCC_TRUST_AGE_NEW'))                    define('BCC_TRUST_AGE_NEW', 30);
if (!defined('BCC_TRUST_AGE_ESTABLISHED'))            define('BCC_TRUST_AGE_ESTABLISHED', 90);
if (!defined('BCC_TRUST_AGE_VERIFIED'))               define('BCC_TRUST_AGE_VERIFIED', 1095);
if (!defined('BCC_TRUST_AGE_NEW_MULTIPLIER'))         define('BCC_TRUST_AGE_NEW_MULTIPLIER', 0.70);
if (!defined('BCC_TRUST_AGE_ESTABLISHED_MULTIPLIER')) define('BCC_TRUST_AGE_ESTABLISHED_MULTIPLIER', 0.85);
if (!defined('BCC_TRUST_AGE_VERIFIED_MULTIPLIER'))    define('BCC_TRUST_AGE_VERIFIED_MULTIPLIER', 1.15);

// Quest ceiling (quest-rewards.php) — mirrored to exercise the clamp bound.
if (!defined('BCC_QUEST_MULTIPLIER_MAX')) define('BCC_QUEST_MULTIPLIER_MAX', 1.30);

/**
 * Locks the quest-multiplier stage of vote-weight calculation:
 *
 *     effective = base × fraud_discount × quest_multiplier, clamped to MAX.
 *
 * Pure — no DB, no WordPress. Clean fraud signals give discount = 1.0, and a
 * mid-tier, mid-age, fully-vested voter keeps base stable and unclamped so the
 * multiplier's effect is isolated. This is the reward the quest UI has always
 * promised ("boosts your vote weight") but which previously reached nothing.
 */
final class VoteWeightQuestMultiplierTest extends TestCase
{
    /** @return array<string, mixed> */
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

    /**
     * A voter with a clean record and an age (200 days) that sits between the
     * "established" (90) and "veteran" (1095) thresholds, so no age multiplier
     * fires and base weight is exactly the tier weight.
     *
     * @return array<string, mixed>
     */
    private function voter(string $tier = 'neutral'): array
    {
        return [
            'tier'         => $tier,
            'is_verified'  => false,
            'account_days' => 200,
            'fraud_score'  => 0,
        ];
    }

    private function calc(float $questMultiplier, string $tier = 'neutral'): VoteWeight
    {
        return (new VoteWeightCalculator())->calculate(
            $this->voter($tier),
            123,
            $this->cleanSignals(),
            false,                                          // not a new voter
            BCC_TRUST_AGE_VERIFIED,                         // days_since_first → fully vested (×1.0)
            new \DateTimeImmutable('2026-01-01 00:00:00'),  // fixed clock
            $questMultiplier
        );
    }

    public function testDefaultMultiplierLeavesWeightUnchanged(): void
    {
        $w = $this->calc(1.0);

        self::assertEqualsWithDelta(0.15, $w->base, 0.0001, 'neutral tier base weight');
        self::assertEqualsWithDelta(1.0, $w->fraudDiscount, 0.0001, 'clean signals → no fraud discount');
        self::assertEqualsWithDelta(0.15, $w->effective, 0.0001, 'no quest bonus → effective == base');
        self::assertSame(1.0, $w->questMultiplier);
    }

    public function testMultiplierScalesEffectiveAndVestedWeight(): void
    {
        $w = $this->calc(1.20);

        // 0.15 × 1.0 (discount) × 1.20 (quest) = 0.18
        self::assertEqualsWithDelta(0.18, $w->effective, 0.0001);
        // Fully vested (stage 4 → ×1.0) so vested tracks effective.
        self::assertEqualsWithDelta(0.18, $w->vested, 0.0001);
        self::assertSame(4, $w->vestingStage);
        self::assertSame(1.20, $w->questMultiplier);
    }

    public function testSubUnitMultiplierIsFlooredToNoBonus(): void
    {
        // A value below 1.0 must never PENALISE a vote — it floors to a no-op.
        $w = $this->calc(0.5);

        self::assertEqualsWithDelta(0.15, $w->effective, 0.0001);
        self::assertSame(1.0, $w->questMultiplier);
    }

    public function testEffectiveWeightStaysWithinPlatformCeiling(): void
    {
        // Elite base 0.30 × 1.30 = 0.39 — under the 0.6 ceiling, applied in full.
        $w = $this->calc(BCC_QUEST_MULTIPLIER_MAX, 'elite');

        self::assertEqualsWithDelta(0.39, $w->effective, 0.0001);
        self::assertLessThanOrEqual(BCC_TRUST_MAX_VOTE_WEIGHT, $w->effective);
    }

    public function testOversizedMultiplierCannotBreachTheCeiling(): void
    {
        // Even a wildly out-of-range multiplier is bounded by MAX_VOTE_WEIGHT,
        // so a bug or hostile caller can never mint unlimited vote weight.
        $w = $this->calc(99.0, 'elite');

        self::assertEqualsWithDelta(BCC_TRUST_MAX_VOTE_WEIGHT, $w->effective, 0.0001);
    }
}
