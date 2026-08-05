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
 *     (fail closed; New Members cannot vote, §5.3). During §14.1
 *     recovery grace the APPRENTICE multiplier applies regardless of
 *     the earned rung (§31.4 recovery pause — Rank Phase 8); see
 *     rankMultiplier(). Ballot weight snapshots are immutable once
 *     cast (invariant 21): a ballot cast BEFORE recovery keeps its
 *     pre-recovery weight verbatim — recasting is what re-snapshots.
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
     * @param string|null $tenureEpoch          MySQL UTC datetime — the
     *        §16.3 maturity epoch: the voter's signup date
     *        (wp_users.user_registered). Null vests the floor.
     * @param float $trustScore                 Current trust score 0–100.
     * @param string $voterTier                 Reputation tier at
     *        calculation time (informational — carried on the VO for
     *        audit; the formula uses the SCORE, not the tier).
     * @param array<string, mixed> $signals     Fraud signals (unchanged
     *        FraudDiscountCalculator contract).
     * @param int $fraudScore                   Current fraud score 0–100.
     * @param bool $inRecovery                  §14.1 recovery-grace flag
     *        (rank_state.recovery_status === 'grace') — pauses the
     *        earned rank multiplier down to apprentice (§31.4).
     */
    public function calculate(
        ?string             $rankSlug,
        ?string             $tenureEpoch,
        float               $trustScore,
        string              $voterTier,
        array               $signals,
        int                 $fraudScore,
        ?\DateTimeImmutable $now = null,
        bool                $inRecovery = false
    ): VoteWeight {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $maturity  = $this->maturity($tenureEpoch, $now);
        $rankMult  = $this->rankMultiplier($rankSlug, $inRecovery);
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
     * §16.4 rank multiplier, with the §31.4 recovery pause (Rank
     * Phase 8): during §14.1 recovery grace the APPRENTICE multiplier
     * applies — never the earned one. Null/unknown slugs stay 0 (fail
     * closed) even in recovery: a New Member cannot vote at all.
     *
     * Public and shared by BOTH weight paths (calculate() above and
     * VoteService::assembleBallotWeightSnapshot's rank_multiplier
     * field) — the same seam pattern as maturity()/trustMultiplier(),
     * so page-vote weights and ballot snapshots can never drift.
     */
    public function rankMultiplier(?string $rankSlug, bool $inRecovery): float
    {
        if ($rankSlug === null || !isset($this->config->rankMultipliers[$rankSlug])) {
            return 0.0;
        }
        if ($inRecovery) {
            return $this->config->rankMultipliers['apprentice'];
        }
        return $this->config->rankMultipliers[$rankSlug];
    }

    /**
     * §16.3: floor + (d / span × (1 − floor)), d clamped to [0, span]
     * from the tenure epoch — the voter's signup date. Anchoring on
     * account tenure (not the apprentice-award moment) means onboarding
     * time is not a vesting penalty; the gate still blocks New Members
     * (rank multiplier 0) until confirmed. Missing epoch ⇒ the floor.
     */
    public function maturity(?string $tenureEpoch, \DateTimeImmutable $now): float
    {
        if ($tenureEpoch === null || $tenureEpoch === '') {
            return $this->config->maturityFloor;
        }

        $epoch = strtotime($tenureEpoch . ' UTC');
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
