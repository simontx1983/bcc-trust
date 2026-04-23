<?php
/**
 * Vote Weight Calculator
 *
 * Pure calculation stage of the vote pipeline. Zero database calls, zero side
 * effects — making it trivially unit-testable.
 *
 * THREE-STAGE MODEL:
 *
 *   Stage 1: BASE WEIGHT
 *     Determined by reputation tier, email verification, and account age.
 *     Range: 0.03 (risky, unverified, new) to 0.41 (elite, verified, veteran)
 *
 *   Stage 2: FRAUD DISCOUNT
 *     A single 0–1 penalty derived from all fraud signals (automation, device,
 *     behavior, multi-account, fraud score). Signals are combined additively
 *     and capped, not multiplied — preventing the exponential collapse of the
 *     old 7-stage multiplicative chain.
 *     Range: 0.10 (worst case floor) to 1.00 (clean)
 *
 *   Stage 3: FINAL WEIGHT
 *     base × discount × vesting_factor, clamped to [MIN, MAX].
 *     Vesting (5-stage): 10% → 20% → 50% → 75% → 100%
 *     Thresholds: 0/30/90/152/365 days.
 *
 * Maximum spread: 0.41 / 0.003 ≈ 137x before vesting, ~20x in practice
 * because fraud discount floor is 0.10 and vesting floor is 0.30.
 *
 * @package BCC\Trust\Core\Services\Vote
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Services\Vote;

use BCC\Trust\Core\Services\EndorsementWeightCalculator;
use BCC\Trust\Core\Services\FraudDiscountCalculator;
use BCC\Trust\Core\ValueObjects\VoteWeight;

if (!defined('ABSPATH')) {
    exit;
}

class VoteWeightCalculator {

    // Vesting thresholds now use shared constants from trust-weights.php:
    //   BCC_TRUST_VESTING_STAGE_1_DAYS (30)
    //   BCC_TRUST_VESTING_STAGE_2_DAYS (90)
    //   BCC_TRUST_VESTING_STAGE_3_DAYS (152)
    //   BCC_TRUST_VESTING_STAGE_4_DAYS (365)

    /** @var array<int, float>|null */
    private static ?array $vestingMultipliers = null;

    /**
     * @return array<int, float>
     */
    private static function getVestingMultipliers(): array
    {
        if (self::$vestingMultipliers === null) {
            self::$vestingMultipliers = [
                0 => BCC_TRUST_VESTING_STAGE_0_PCT,   // 0.10 — new (0-29 days)
                1 => BCC_TRUST_VESTING_STAGE_1_PCT,   // 0.20 — early (30-89 days)
                2 => BCC_TRUST_VESTING_STAGE_2_PCT,   // 0.50 — established (90-151 days)
                3 => BCC_TRUST_VESTING_STAGE_3_PCT,   // 0.75 — trusted (152-364 days)
                4 => BCC_TRUST_VESTING_STAGE_4_PCT,   // 1.00 — fully vested (365+ days)
            ];
        }
        return self::$vestingMultipliers;
    }

    /**
     * Calculate vote weight.
     *
     * @param array<string, mixed> $voterProfile {
     *     @type string $tier         Reputation tier: elite|trusted|neutral|caution|risky.
     *     @type bool   $is_verified  Whether email is verified.
     *     @type int    $account_days Account age in days.
     *     @type int    $fraud_score  Current fraud score (0-100).
     * }
     * @param array<string, mixed> $signals {
     *     @type array  $automation               ['is_automated' => bool, 'confidence' => float 0-100]
     *     @type bool   $multi_account_risk       Fingerprint shared by > RING_MIN_SIZE users.
     *     @type float  $device_fraud_probability 0.0–1.0 from DeviceFingerprinter.
     *     @type float  $behavior_score           0–100 from BehavioralAnalyzer.
     *     @type float  $trust_rank               0.0–1.0 from TrustGraph.
     * }
     * @param bool $is_new_voter     True when no previous vote row exists.
     * @param int  $days_since_first Days since voter's first-ever vote (0 when new).
     */
    public function calculate(
        array               $voterProfile,
        int                 $voterId,
        array               $signals,
        bool                $is_new_voter,
        int                 $days_since_first,
        ?\DateTimeImmutable $now = null
    ): VoteWeight {
        $now ??= new \DateTimeImmutable('now');

        // ── Stage 1: Base Weight ────────────────────────────────────────
        $base = $this->computeBase($voterProfile);

        // ── Stage 2: Fraud Discount ─────────────────────────────────────
        $fraudResult = $this->computeFraudDiscount($signals, $voterProfile['fraud_score']);
        $discount    = $fraudResult['discount'];

        // ── Stage 3: Final Weight ───────────────────────────────────────
        $effective = round($base * $discount, 4);
        $effective = max(0.0, min(BCC_TRUST_MAX_VOTE_WEIGHT, $effective));

        [$vested, $stage, $startedAt, $fullyVestedAt] =
            $this->computeVesting($effective, $is_new_voter, $days_since_first, $now);

        return new VoteWeight(
            base:              $base,
            fraudDiscount:     $discount,
            penaltiesBreakdown: $fraudResult['penalties'],
            effective:         $effective,
            vested:            $vested,
            vestingStage:      $stage,
            voterTier:         $voterProfile['tier'],
            vestingStartedAt:  $startedAt,
            fullyVestedAt:     $fullyVestedAt,
        );
    }

    // ── Stage 1: Base Weight ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $profile
     */
    private function computeBase(array $profile): float {
        $tierWeights = [
            'elite'   => BCC_TRUST_WEIGHT_ELITE,    // 0.30
            'trusted' => BCC_TRUST_WEIGHT_TRUSTED,   // 0.25
            'neutral' => BCC_TRUST_WEIGHT_NEUTRAL,   // 0.15
            'caution' => BCC_TRUST_WEIGHT_CAUTION,   // 0.08
            'risky'   => BCC_TRUST_WEIGHT_RISKY,     // 0.03
        ];

        $weight = (float) ($tierWeights[$profile['tier']] ?? BCC_TRUST_WEIGHT_NEUTRAL);

        // Email-verified voters earn a 20% boost
        if ($profile['is_verified']) {
            $weight *= 1.2;
        }

        // Account age factor — multipliers from scoring.php
        if ($profile['account_days'] < BCC_TRUST_AGE_NEW) {
            $weight *= BCC_TRUST_AGE_NEW_MULTIPLIER;
        } elseif ($profile['account_days'] < BCC_TRUST_AGE_ESTABLISHED) {
            $weight *= BCC_TRUST_AGE_ESTABLISHED_MULTIPLIER;
        } elseif ($profile['account_days'] > BCC_TRUST_AGE_VERIFIED) {
            $weight *= BCC_TRUST_AGE_VERIFIED_MULTIPLIER;
        }

        return round($weight, 4);
    }

    // ── Stage 2: Fraud Discount ─────────────────────────────────────────

    /**
     * Delegate to FraudDiscountCalculator — single source of truth.
     *
     * @param array<string, mixed> $signals
     * @return array{discount: float, penalties: array<string, float>}
     * @see FraudDiscountCalculator::compute()
     */
    private function computeFraudDiscount(array $signals, int $fraudScore): array {
        return FraudDiscountCalculator::compute($signals, $fraudScore);
    }

    // ── Stage 3: Vesting ────────────────────────────────────────────────

    /**
     * 5-stage graduated vesting:
     *   Stage 0:  0–29 days   → 10% (new user)
     *   Stage 1: 30–89 days   → 20% (early)
     *   Stage 2: 90–151 days  → 50% (established)
     *   Stage 3: 152–364 days → 75% (trusted)
     *   Stage 4: 365+ days    → 100% (fully vested)
     *
     * @return array{float, int, string|null, string|null}
     */
    private function computeVesting(
        float               $effective,
        bool                $isNewVoter,
        int                 $daysSinceFirst,
        \DateTimeImmutable  $now
    ): array {
        if ($isNewVoter) {
            $vested    = round($effective * self::getVestingMultipliers()[0], 4);
            $startedAt = $now->format('Y-m-d H:i:s');
            return [$vested, 0, $startedAt, null];
        }

        $stage  = EndorsementWeightCalculator::resolveVestingStage($daysSinceFirst)['stage'];
        $vested = round($effective * self::getVestingMultipliers()[$stage], 4);

        $fullyVestedAt = ($stage === 4)
            ? $this->approximateFullVestDate($daysSinceFirst, $now)
            : null;

        return [$vested, $stage, null, $fullyVestedAt];
    }

    private function approximateFullVestDate(int $daysSinceFirst, \DateTimeImmutable $now): string {
        $vestTimestamp = $now->getTimestamp()
                       - ($daysSinceFirst * 86400)
                       + (BCC_TRUST_VESTING_STAGE_4_DAYS * 86400);
        return gmdate('Y-m-d H:i:s', $vestTimestamp);
    }
}
