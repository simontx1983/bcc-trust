<?php
/**
 * RankCredibilityGate — the §9.2 / §10.1 "who counts as evidence"
 * predicate for the Rank helping emitters, extracted so both the
 * helpful_mark and stewardship paths share ONE credibility rule instead
 * of growing parallel ones (§11).
 *
 * Two side-effect-free predicates:
 *
 *   isCredibleRecognizer($userId) — §9.2: a Helpful mark is evidence
 *     ONLY from a credible member — Apprentice+ rank AND Trust tier
 *     Neutral+ AND not suspended AND not fraud-blocked. This is the
 *     SAME check the recognition/attestation cast path already applies
 *     (AttestationService::checkCastEligibility → rank_state existence +
 *     Neutral+ tier + assertFraudClear), lifted to a boolean and
 *     hardened with the suspension gate §9.2 additionally names. A
 *     non-credible marker's mark is still recorded (cosmetic) but mints
 *     no Rank evidence.
 *
 *   isResponsibleOwner($userId) — the stewardship owner gate: not
 *     suspended AND not fraud-blocked AND not in Rank recovery (§14.2).
 *     Rank/tier are not re-checked — a community owner is Apprentice+ by
 *     construction of the §21.2 acquisition gate.
 *
 * Collaborators are reached through protected hooks (same subclass
 * pattern as CapabilityResolver / CommunityCustodyService) so
 * RankCredibilityGateTest can exercise the real predicate logic without
 * WordPress or a DB.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign (helping emitters)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Core\Services\Capability\CredibilityAtoms;

if (!defined('ABSPATH')) {
    exit;
}

class RankCredibilityGate
{
    use CredibilityAtoms;

    /**
     * Parameterized credibility composite — the single predicate every
     * "credible enough?" boolean gate composes (§11). Each requirement is
     * a flag so a caller expresses its exact rule without a parallel body:
     * the recognizer wants rank + tier + suspension + fraud; the
     * stewardship owner wants suspension + recovery + fraud only.
     * `$userId` must be positive; every enabled gate must hold (all ANDed,
     * order not load-bearing).
     */
    public function isCredible(
        int $userId,
        bool $requireRankState = true,
        bool $requireTierNeutral = true,
        bool $requireNotSuspended = true,
        bool $requireNotInRecovery = false,
        bool $requireFraudClear = true
    ): bool {
        if ($userId <= 0) {
            return false;
        }
        if ($requireNotSuspended && !$this->notSuspended($userId)) {
            return false;
        }
        // Missing rank_state row = New Member; a present row is always at
        // least Apprentice, so this single check covers both cases.
        if ($requireRankState && !$this->hasRankState($userId)) {
            return false;
        }
        if ($requireTierNeutral && !$this->tierAtLeastNeutral($userId)) {
            return false;
        }
        if ($requireNotInRecovery && $this->inRecovery($userId)) {
            return false;
        }
        if ($requireFraudClear && !$this->fraudClear($userId)) {
            return false;
        }
        return true;
    }

    /**
     * §9.2 credible Helpful-mark recognizer: Apprentice+ (a rank_state
     * row exists — New Members excluded) AND Trust tier Neutral+ AND not
     * suspended AND fraud-clear. All four required.
     */
    public function isCredibleRecognizer(int $userId): bool
    {
        return $this->isCredible($userId);
    }

    /**
     * Stewardship owner responsibility: not suspended AND fraud-clear AND
     * not in Rank recovery (§14.2). An owner is Apprentice+ by
     * construction (the §21.2 acquisition gate), so rank/tier are not
     * re-checked here.
     */
    public function isResponsibleOwner(int $userId): bool
    {
        return $this->isCredible(
            $userId,
            requireRankState: false,
            requireTierNeutral: false,
            requireNotInRecovery: true
        );
    }

    // ── collaborator hooks ──────────────────────────────────────────────
    // notSuspended / hasRankState / inRecovery / fraudClear (and the
    // parameterized tierAtLeast) now come from the shared CredibilityAtoms
    // trait — one definition, §11. RankCredibilityGateTest overrides them
    // on its subclass exactly as before. Only the Neutral-specific tier
    // hook is kept locally, as a thin wrapper over the shared tierAtLeast,
    // so the test's `tierAtLeastNeutral` override seam is preserved.

    /** Trust tier ≥ Neutral — thin wrapper over the shared tierAtLeast. */
    protected function tierAtLeastNeutral(int $userId): bool
    {
        return $this->tierAtLeast($userId, 'neutral');
    }
}
