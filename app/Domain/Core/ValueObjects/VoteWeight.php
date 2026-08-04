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
     * @param float       $vested             Weight applied to score this cycle.
     *        Equal to $effective under the §16.6 formula (the legacy
     *        vote-time vesting stage is retired; the maturity term
     *        inside `base` is the only vesting — Rank Phase 6). The
     *        field persists because the score-recalc SUM reads the
     *        vested_weight column.
     * @param string      $voterTier          Reputation tier at calculation time (audit).
     * @param int         $vestingStage       Always 4 (terminal) since Phase 6 —
     *        kept while the votes schema carries the column.
     * @param string|null $vestingStartedAt   Always null since Phase 6.
     * @param string|null $fullyVestedAt      Always null since Phase 6.
     */
    public function __construct(
        public readonly float   $base,
        public readonly float   $fraudDiscount,
        public readonly array   $penaltiesBreakdown,
        public readonly float   $effective,
        public readonly float   $vested,
        public readonly string  $voterTier,
        public readonly int     $vestingStage = 4,
        public readonly ?string $vestingStartedAt = null,
        public readonly ?string $fullyVestedAt = null,
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
