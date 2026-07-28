<?php
/**
 * Reputation Calculator Service
 *
 * Static-only helper after the Architecture A reputation cutover. It now
 * exposes a single pure method — blendContribution() — that documents and
 * enforces the Rule-2 contribution ceiling. The instance methods
 * (calculateRecommendedVoteWeight + recalculateUserReputation) and their
 * injected repositories were removed once the cutover orphaned them: a
 * member's trust now lives on the self-page bcc_trust_page_scores row,
 * recomputed inline by ScoreRepository via the canonical TrustScoreService
 * formula, not via a per-user vote-ratio recalc.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) exit;

class ReputationCalculatorService {

    /**
     * Blend the genuine (vote/dispute) reputation with the capped
     * contribution+consistency bonus under the Rule-2 ceiling.
     *
     * Pure — unit-testable. The ceiling means contribution alone can lift a
     * user toward Neutral but never into Trusted/Elite: it only applies
     * while the *genuine* score is below Trusted, so a user who has
     * independently earned Trusted keeps their full score.
     *
     * Called statically from ContributionRecoveryEvaluator.
     */
    public static function blendContribution(float $genuine, float $contributionBonus): float
    {
        $bonus     = max(0.0, $contributionBonus);
        $effective = $genuine + $bonus;

        if ($genuine < (float) BCC_TRUST_TIER_TRUSTED) {
            $effective = min($effective, (float) BCC_CONTRIB_CEILING);
        }

        return max(0.0, min(100.0, $effective));
    }
}
