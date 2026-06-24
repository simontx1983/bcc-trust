<?php
/**
 * Reputation Calculator Service
 *
 * Handles reputation computation logic that was previously embedded in
 * ReputationRepository. The repository retains raw data-access methods;
 * this service applies the business rules (tier-based weight branching,
 * fraud-score adjustments, vote-ratio scoring).
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) exit;

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;

class ReputationCalculatorService {

    private const DEFAULT_VOTE_WEIGHT = 1.0;

    private ReputationRepository $reputationRepo;
    private UserInfoRepository $userInfoRepo;

    public function __construct(
        ReputationRepository $reputationRepo,
        ?UserInfoRepository $userInfoRepo = null
    ) {
        $this->reputationRepo = $reputationRepo;
        $this->userInfoRepo   = $userInfoRepo ?? new UserInfoRepository();
        // The ReputationEventRepository dependency was dropped in the
        // Architecture A reputation cutover (Stage D): the only consumer
        // was recalculateUserReputation(), which is gone. The class now
        // only exposes calculateRecommendedVoteWeight() + blendContribution().
    }

    /**
     * Calculate recommended vote weight based on reputation tier and fraud signals.
     *
     * Reads the user's reputation record from the repository, then applies
     * tier-based weight mapping and fraud-score penalties.
     */
    public function calculateRecommendedVoteWeight(int $userId): float {
        $record = $this->reputationRepo->getByUserId($userId);

        if (!$record) {
            return self::DEFAULT_VOTE_WEIGHT;
        }

        $tier       = $record->reputation_tier ?? 'neutral';

        // Use fraud_score from user_info (authoritative source) instead of
        // flag_count from the reputation table, matching the rest of the
        // system (VoteWeightCalculator, RateLimiter).
        $userInfo   = $this->userInfoRepo->getByUserId($userId);
        $fraudScore = $userInfo ? (int) $userInfo->fraud_score : 0;

        $baseWeight = match($tier) {
            'elite'   => BCC_TRUST_WEIGHT_ELITE,
            'trusted' => BCC_TRUST_WEIGHT_TRUSTED,
            'neutral' => BCC_TRUST_WEIGHT_NEUTRAL,
            'caution' => BCC_TRUST_WEIGHT_CAUTION,
            'risky'   => BCC_TRUST_WEIGHT_RISKY,
            default   => self::DEFAULT_VOTE_WEIGHT,
        };

        // Comparator is >= for consistency with FraudDetector::getRiskLevel
        // and all other threshold gates (audit HIGH-4).
        if ($fraudScore >= BCC_TRUST_FRAUD_HIGH) {
            $baseWeight *= 0.5;
        } elseif ($fraudScore >= BCC_TRUST_FRAUD_MEDIUM) {
            $baseWeight *= 0.8;
        }

        return max(BCC_TRUST_MIN_VOTE_WEIGHT, min(BCC_TRUST_MAX_VOTE_WEIGHT, $baseWeight));
    }

    // recalculateUserReputation() REMOVED (Architecture A — reputation
    // cutover Stage D). A member's trust now lives on their self-page
    // bcc_trust_page_scores row, recomputed inline by ScoreRepository
    // (applyContributionBonus / applyPenalty / vote + endorsement paths)
    // via the canonical TrustScoreService formula. The legacy snapshot
    // recompute (vote-ratio → bcc_trust_reputation.reputation_score) and its
    // sole data source getVotingStatsForOwner() are gone. blendContribution()
    // is retained: it is pure, unit-tested, and documents the Rule-2 ceiling.

    /**
     * Blend the genuine (vote/dispute) reputation with the capped
     * contribution+consistency bonus under the Rule-2 ceiling.
     *
     * Pure — unit-testable. The ceiling means contribution alone can lift a
     * user toward Neutral but never into Trusted/Proven: it only applies
     * while the *genuine* score is below Trusted, so a user who has
     * independently earned Trusted keeps their full score.
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
