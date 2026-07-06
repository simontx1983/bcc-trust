<?php
/**
 * Vote Weight Value Object
 *
 * Immutable carrier for weight data produced by VoteWeightCalculator.
 * Three-stage model: base → fraud discount → effective → vested.
 *
 * @package BCC\Trust\Core\ValueObjects
 * @version 2.0.0
 */

namespace BCC\Trust\Core\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class VoteWeight {

    /**
     * @param float       $base               Weight from tier + verification + account age.
     * @param float       $fraudDiscount      Combined fraud discount factor (0.00–1.00).
     * @param array<string, float> $penaltiesBreakdown Per-signal penalty values for observability.
     * @param float       $effective          base × fraudDiscount, clamped to [0, MAX].
     * @param float       $vested             Weight applied to score this cycle (effective × vesting).
     * @param int         $vestingStage       0=10%, 1=20%, 2=50%, 3=75%, 4=100%.
     * @param string      $voterTier          Reputation tier at calculation time.
     * @param string|null $vestingStartedAt   MySQL datetime when vesting clock began (new voters only).
     * @param string|null $fullyVestedAt      MySQL datetime when voter reached stage 2, or null.
     * @param float       $questMultiplier    Earned quest reward applied to effective weight (1.00–1.30).
     */
    public function __construct(
        public readonly float   $base,
        public readonly float   $fraudDiscount,
        public readonly array   $penaltiesBreakdown,
        public readonly float   $effective,
        public readonly float   $vested,
        public readonly int     $vestingStage,
        public readonly string  $voterTier,
        public readonly ?string $vestingStartedAt,
        public readonly ?string $fullyVestedAt,
        public readonly float   $questMultiplier = 1.0,
    ) {}

    /**
     * Whether fraud signals caused a meaningful weight reduction (>10%).
     */
    public function isFraudReduced(): bool {
        return $this->fraudDiscount < 0.9;
    }

    /**
     * Whether the user was hard-blocked by severe fraud signals.
     */
    public function isFraudBlocked(): bool {
        return $this->fraudDiscount === 0.0;
    }

    /**
     * Fraud reduction as a human-readable percentage string, e.g. "-34%".
     */
    public function fraudReductionLabel(): string {
        if ($this->fraudDiscount === 0.0) {
            return '-100% (blocked)';
        }
        $pct = (int) round((1 - $this->fraudDiscount) * 100);
        return ($pct > 0 ? '-' : '') . $pct . '%';
    }
}
