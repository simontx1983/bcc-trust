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

if (!defined('ABSPATH')) {
    exit;
}

class RankCredibilityGate
{
    /**
     * §9.2 credible Helpful-mark recognizer: Apprentice+ (a rank_state
     * row exists — New Members excluded) AND Trust tier Neutral+ AND not
     * suspended AND fraud-clear. The first failing gate short-circuits;
     * order is not load-bearing (all four must hold).
     */
    public function isCredibleRecognizer(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (!$this->notSuspended($userId)) {
            return false;
        }
        // Missing rank_state row = New Member; a present row is always at
        // least Apprentice, so this single check covers both cases.
        if (!$this->hasRankState($userId)) {
            return false;
        }
        if (!$this->tierAtLeastNeutral($userId)) {
            return false;
        }
        return $this->fraudClear($userId);
    }

    /**
     * Stewardship owner responsibility: not suspended AND fraud-clear AND
     * not in Rank recovery (§14.2). An owner is Apprentice+ by
     * construction (the §21.2 acquisition gate), so rank/tier are not
     * re-checked here.
     */
    public function isResponsibleOwner(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (!$this->notSuspended($userId)) {
            return false;
        }
        if ($this->inRecovery($userId)) {
            return false;
        }
        return $this->fraudClear($userId);
    }

    // ── collaborator hooks (overridden by RankCredibilityGateTest) ──────

    protected function notSuspended(int $userId): bool
    {
        return \BCC\Core\Permissions\Permissions::is_not_suspended($userId, false);
    }

    /** Apprentice+ — a rank_state row exists (New Members have none). */
    protected function hasRankState(int $userId): bool
    {
        return \BCC\Trust\Core\Plugin::instance()
            ->rankStateRepository()
            ->getForUser($userId) !== null;
    }

    /** §14.2 recovery pause — rank_state.recovery_status === 'grace'. */
    protected function inRecovery(int $userId): bool
    {
        $row = \BCC\Trust\Core\Plugin::instance()
            ->rankStateRepository()
            ->getForUser($userId);
        return $row !== null && (string) $row->recovery_status === 'grace';
    }

    /** Trust tier ≥ Neutral, via the canonical reputation read + config ordinals. */
    protected function tierAtLeastNeutral(int $userId): bool
    {
        $plugin = \BCC\Trust\Core\Plugin::instance();
        $config = $plugin->rankScoringConfig();
        $tier   = $plugin->reputationRepository()->getTier($userId);

        return $config->tierOrdFor($tier) >= $config->tierOrdFor('neutral');
    }

    /**
     * fraud_score below the HIGH block threshold. Mirrors
     * AttestationService::assertFraudClear (single fraud rule, §11): a
     * missing user_info row is fraud-clear, and the automated-test bypass
     * lets fresh test accounts whose score isn't computed yet through.
     */
    protected function fraudClear(int $userId): bool
    {
        if (defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE) {
            return true;
        }
        $info = \BCC\Trust\Core\Plugin::instance()->userInfoRepository()->getByUserId($userId);
        if ($info === null) {
            return true;
        }
        $fraudScore = isset($info->fraud_score) ? (int) $info->fraud_score : 0;
        return !(defined('BCC_TRUST_FRAUD_HIGH') && $fraudScore >= (int) \BCC_TRUST_FRAUD_HIGH);
    }
}
