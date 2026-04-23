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

    public function __construct(ReputationRepository $reputationRepo, ?UserInfoRepository $userInfoRepo = null) {
        $this->reputationRepo = $reputationRepo;
        $this->userInfoRepo   = $userInfoRepo ?? new UserInfoRepository();
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

    /**
     * Recalculate reputation for a single user based on their voting history.
     *
     * Fetches aggregate voting stats from the repository, computes a 0-100
     * reputation score from the positive/negative weight ratio, and persists
     * the result back through the repository.
     */
    public function recalculateUserReputation(int $userId): void {
        $stats = $this->reputationRepo->getVotingStatsForOwner($userId);

        if (!$stats) {
            return;
        }

        $positiveWeight = (float) $stats->positive_weight;
        $negativeWeight = abs((float) $stats->negative_weight);
        $totalWeight    = $positiveWeight + $negativeWeight;

        // Simple reputation score: base NEUTRAL, adjusted by vote ratio
        $neutral = (float) BCC_TRUST_NEUTRAL_SCORE;
        $score   = $neutral;
        if ($totalWeight > 0) {
            $ratio = ($positiveWeight - $negativeWeight) / $totalWeight;
            $score = $neutral + ($ratio * $neutral);
        }

        $score = max(0.0, min(100.0, $score));

        $this->reputationRepo->createOrUpdate($userId, [
            'reputation_score'     => $score,
            'total_votes_received' => (int) $stats->unique_voters,
        ]);
    }
}
