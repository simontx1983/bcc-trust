<?php
/**
 * Vote Weight Calculator — the §16.6 meaningful-vote formula
 * (Rank redesign Phase 6; replaced the legacy tier/email/age/quest/
 * vesting stack at the voting cutover).
 *
 *   effective = maturity × rank_multiplier × trust_multiplier
 *               × fraud_discount, clamped to [0, vote_ceiling]
 *
 *   - maturity (§16.3): 0.075 → 1.0 linear over 90 days from the
 *     member's APPRENTICE EPOCH (rank_state.apprentice_awarded_at) —
 *     promotion never completes vesting early (owner correction 3).
 *   - rank_multiplier (§16.4): apprentice 1.0 · journeyman 1.2 ·
 *     veteran 1.4 — from RankScoringConfig; unknown/missing rank ⇒ 0
 *     (fail closed; New Members cannot vote, §5.3).
 *   - trust_multiplier (§16.5): linear 45→0.75 … 100→1.25 over the
 *     member's current trust SCORE.
 *   - fraud_discount: FraudDiscountCalculator (single source of truth,
 *     unchanged) — the only per-ballot integrity multiplier.
 *   - ceiling (§16.7): 1.75 — no ordinary member exceeds it through
 *     any stacking. Illustrative max: 1.0 × 1.4 × 1.25 = 1.75 exactly.
 *
 * Pure: zero database calls — every input resolved by the caller. All
 * constants come from the validated RankScoringConfig (C0/A4 — no
 * define() reads).
 *
 * @package BCC\Trust\Core\Services\Vote
 * @version 3.0.0 (Rank redesign Phase 6)
 */

namespace BCC\Trust\Core\Services\Vote;

use BCC\Trust\Core\Services\FraudDiscountCalculator;
use BCC\Trust\Core\ValueObjects\VoteWeight;
use BCC\Trust\Rank\Support\RankScoringConfig;

if (!defined('ABSPATH')) {
    exit;
}

class VoteWeightCalculator {

    public function __construct(
        private readonly RankScoringConfig $config
    ) {}

    /**
     * @param string|null $rankSlug             Voter's earned rank, or null
     *        for a New Member (weight 0 — defensive; the eligibility
     *        checker blocks the vote upstream).
     * @param string|null $apprenticeAwardedAt  MySQL UTC datetime — the
     *        §16.3 maturity epoch. Null (missing state) vests nothing.
     * @param float $trustScore                 Current trust score 0–100.
     * @param string $voterTier                 Reputation tier at
     *        calculation time (informational — carried on the VO for
     *        audit; the formula uses the SCORE, not the tier).
     * @param array<string, mixed> $signals     Fraud signals (unchanged
     *        FraudDiscountCalculator contract).
     * @param int $fraudScore                   Current fraud score 0–100.
     */
    public function calculate(
        ?string             $rankSlug,
        ?string             $apprenticeAwardedAt,
        float               $trustScore,
        string              $voterTier,
        array               $signals,
        int                 $fraudScore,
        ?\DateTimeImmutable $now = null
    ): VoteWeight {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $maturity  = $this->maturity($apprenticeAwardedAt, $now);
        $rankMult  = $rankSlug !== null
            ? ($this->config->rankMultipliers[$rankSlug] ?? 0.0)
            : 0.0;
        $trustMult = $this->trustMultiplier($trustScore);

        $base = round($maturity * $rankMult * $trustMult, 4);

        $fraudResult = FraudDiscountCalculator::compute($signals, $fraudScore);
        $discount    = $fraudResult['discount'];

        $effective = round($base * $discount, 4);
        $effective = max(0.0, min($this->config->voteCeiling, $effective));

        return new VoteWeight(
            base:               $base,
            fraudDiscount:      $discount,
            penaltiesBreakdown: $fraudResult['penalties'],
            effective:          $effective,
            vested:             $effective,
            voterTier:          $voterTier,
        );
    }

    /**
     * §16.3: floor + (d / span × (1 − floor)), d clamped to [0, span]
     * from the apprentice epoch. Missing epoch ⇒ the floor (never a
     * negative or an over-unity value).
     */
    public function maturity(?string $apprenticeAwardedAt, \DateTimeImmutable $now): float
    {
        if ($apprenticeAwardedAt === null || $apprenticeAwardedAt === '') {
            return $this->config->maturityFloor;
        }

        $epoch = strtotime($apprenticeAwardedAt . ' UTC');
        if ($epoch === false) {
            return $this->config->maturityFloor;
        }

        $days = (int) floor(($now->getTimestamp() - $epoch) / 86400);
        $days = max(0, min($this->config->maturitySpanDays, $days));

        return round(
            $this->config->maturityFloor
            + ($days / $this->config->maturitySpanDays) * (1.0 - $this->config->maturityFloor),
            4
        );
    }

    /**
     * §16.5: scores clamp to [min_score, max_score] and map linearly to
     * [min_value, max_value] (45→0.75 … 100→1.25).
     */
    public function trustMultiplier(float $trustScore): float
    {
        $s = max($this->config->trustMinScore, min($this->config->trustMaxScore, $trustScore));

        $span = $this->config->trustMaxScore - $this->config->trustMinScore;

        return round(
            $this->config->trustMinValue
            + (($s - $this->config->trustMinScore) / $span)
              * ($this->config->trustMaxValue - $this->config->trustMinValue),
            4
        );
    }
}
