<?php
/**
 * Endorsement Weight Calculator
 *
 * Pure calculation logic extracted from EndorsementService (refactor step 5).
 * No persistence, no audit logging, no side effects.
 *
 * @package BCC\Trust\Core\Services
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VerificationRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\PeepSoPageResolver;

if (!defined('ABSPATH')) {
    exit;
}

class EndorsementWeightCalculator {

    private ScoreRepository $scoreRepo;
    private VerificationRepository $verificationRepo;

    public function __construct(
        ScoreRepository $scoreRepo,
        VerificationRepository $verificationRepo
    ) {
        $this->scoreRepo = $scoreRepo;
        $this->verificationRepo = $verificationRepo;
    }

    /**
     * Calculate base endorsement weight using user_info table
     */
    public function calculateBaseEndorserWeight(int $userId): float {
        $weight = 1.0;

        // Get user's own page scores
        $userPages = $this->scoreRepo->getByOwnerId($userId);

        if (!empty($userPages)) {
            $totalScore = 0;
            foreach ($userPages as $page) {
                $totalScore += $page->total_score ?? ($page->score ? $page->score->getTotalScore() : 0);
            }
            $avgScore = $totalScore / count($userPages);

            if ($avgScore >= BCC_TRUST_TIER_ELITE) {
                $weight = BCC_TRUST_ENDORSE_ELITE;
            } elseif ($avgScore >= BCC_TRUST_TIER_TRUSTED) {
                $weight = BCC_TRUST_ENDORSE_TRUSTED;
            } elseif ($avgScore >= BCC_TRUST_TIER_NEUTRAL) {
                $weight = BCC_TRUST_ENDORSE_NEUTRAL;
            } elseif ($avgScore >= BCC_TRUST_TIER_CAUTION) {
                $weight = BCC_TRUST_ENDORSE_CAUTION;
            } else {
                $weight = BCC_TRUST_ENDORSE_RISKY;
            }

            // Page count multiplier
            $pageCount = count($userPages);
            if ($pageCount >= 5) {
                $weight *= 1.2;
            } elseif ($pageCount >= 2) {
                $weight *= 1.1;
            }
        }

        // Check if user is verified using verification repo
        if ($this->verificationRepo->isVerified($userId)) {
            $weight *= 1.2;
        }

        // Time-based multiplier
        $user = get_userdata($userId);
        if ($user) {
            $accountAge = time() - strtotime($user->user_registered);
            $accountYears = $accountAge / (365 * DAY_IN_SECONDS);

            if ($accountYears >= 2) {
                $weight *= BCC_TRUST_ENDORSE_AGE_2YR_MULTIPLIER;
            } elseif ($accountYears >= 1) {
                $weight *= BCC_TRUST_ENDORSE_AGE_1YR_MULTIPLIER;
            }
        }

        return round($weight, 2);
    }

    /**
     * Apply fraud adjustments to endorsement weight.
     *
     * Delegates core fraud discount to FraudDiscountCalculator (single source of
     * truth), then applies endorsement-specific cap/floor logic.
     *
     * @see FraudDiscountCalculator::compute()
     *
     * @param array<string, mixed> $fraudAnalysis
     * @param array<string, mixed> $automationData
     * @param array<string, mixed> $behavior
     */
    public function applyFraudAdjustments(
        float $baseWeight,
        array $fraudAnalysis,
        array $automationData,
        bool $multiAccountRisk,
        array $behavior,
        float $trustRank,
        int $userId
    ): float {
        // Normalize the separate arrays into the unified signal format
        $signals = FraudDiscountCalculator::normalizeSignals(
            $fraudAnalysis,
            $automationData,
            $multiAccountRisk,
            $behavior,
            $trustRank,
        );

        // Use shared additive penalty model — minWeight matches endorsement
        // caution tier (more lenient floor than votes' BCC_TRUST_WEIGHT_RISKY,
        // preserving existing behavior where endorsements had a 0.1 floor)
        $endorseFloor = BCC_TRUST_ENDORSE_CAUTION;
        $result  = FraudDiscountCalculator::compute($signals, (int) ($fraudAnalysis['score'] ?? 0), $endorseFloor);
        $weight  = $baseWeight * $result['discount'];

        // Endorsement-specific cap: diminishing returns above BCC_TRUST_MAX_ENDORSE_WEIGHT
        $cap = BCC_TRUST_MAX_ENDORSE_WEIGHT;
        if ($weight > $cap) {
            $weight = $cap + (($weight - $cap) * 0.3);
        }

        // Final safety cap
        $weight = min($cap + 0.5, $weight);

        return round(max($endorseFloor, $weight), 2);
    }

    /**
     * Calculate diversity factor based on endorsement source concentration.
     *
     * If a single page owner's endorsers overlap heavily (same owner cluster),
     * the endorsement weight is reduced.
     *
     * @param  int $pageId         The page being endorsed.
     * @param  int $endorserUserId The new endorser.
     * @return float Diversity factor (0.2 to 1.0).
     */
    public function calculateDiversityFactor(int $pageId, int $endorserUserId): float {
        $endorseRepo = \BCC\Trust\Core\Plugin::instance()->endorsementRepository();

        // Get page owner
        $pageOwnerId = PeepSoPageResolver::getOwnerId($pageId);

        // Count total active endorsements for this page
        $totalEndorsements = $endorseRepo->countForPage($pageId);

        if ($totalEndorsements < 2) {
            return 1.0; // Not enough data to assess diversity
        }

        // Count how many endorsers of THIS page also endorse OTHER pages
        // owned by the same owner — indicates a cluster
        $clusterCount = $endorseRepo->countClusterEndorsers($pageId, $endorserUserId);

        // Endorsement reciprocity check: detect if the page owner has endorsed
        // any page owned by this endorser.
        $reciprocal = $endorseRepo->countReciprocal($endorserUserId, $pageOwnerId);

        if ($reciprocal > 0) {
            AuditLogger::log('endorsement_reciprocity_detected', $pageId, [
                'endorser_id'  => $endorserUserId,
                'page_owner'   => $pageOwnerId,
                'reciprocal_count' => $reciprocal,
            ], 'page');

            // Reciprocal endorsements get harsh reduction
            return 0.2;
        }

        // Include the new endorser in the total for concentration calc
        // $totalEndorsements is always >= 2 at this point (checked by caller)
        $concentration = $clusterCount / ($totalEndorsements + 1);

        if ($concentration < BCC_TRUST_ENDORSE_DIVERSITY_LOW) {
            return 1.0;
        }
        if ($concentration < BCC_TRUST_ENDORSE_DIVERSITY_MED) {
            return 0.7;
        }
        if ($concentration < BCC_TRUST_ENDORSE_DIVERSITY_HIGH) {
            return 0.4;
        }

        AuditLogger::log('endorsement_low_diversity', $pageId, [
            'endorser_id'   => $endorserUserId,
            'concentration' => round($concentration, 2),
            'cluster_count' => $clusterCount,
            'total'         => $totalEndorsements,
        ], 'page');

        return 0.2;
    }

    /**
     * Calculate the vesting factor for an endorsement based on its age.
     *
     * Uses the unified 5-stage vesting model (shared with votes):
     *   Stage 0:  0–29 days   → 10%
     *   Stage 1: 30–89 days   → 20%
     *   Stage 2: 90–151 days  → 50%
     *   Stage 3: 152–364 days → 75%
     *   Stage 4: 365+ days    → 100%
     *
     * @param  string $createdAt  Endorsement created_at datetime string.
     * @return array{factor: float, stage: int}
     */
    public function calculateEndorsementVesting(string $createdAt): array {
        $ageDays = (int) ((time() - strtotime($createdAt)) / DAY_IN_SECONDS);
        return self::resolveVestingStage($ageDays);
    }

    /**
     * Resolve vesting stage and factor from age in days.
     *
     * Single source of truth for the 5-stage vesting model. Used by:
     *   - EndorsementWeightCalculator (endorsement creation)
     *   - VoteService::recalculateFromVotes() (score recalculation)
     *   - EndorsementVestingProcessor (cron graduation)
     *
     * @param  int $ageDays  Age of the endorsement or vote in days.
     * @return array{factor: float, stage: int}
     */
    public static function resolveVestingStage(int $ageDays): array {
        if ($ageDays >= BCC_TRUST_VESTING_STAGE_4_DAYS) {
            return ['factor' => BCC_TRUST_VESTING_STAGE_4_PCT, 'stage' => 4];
        }
        if ($ageDays >= BCC_TRUST_VESTING_STAGE_3_DAYS) {
            return ['factor' => BCC_TRUST_VESTING_STAGE_3_PCT, 'stage' => 3];
        }
        if ($ageDays >= BCC_TRUST_VESTING_STAGE_2_DAYS) {
            return ['factor' => BCC_TRUST_VESTING_STAGE_2_PCT, 'stage' => 2];
        }
        if ($ageDays >= BCC_TRUST_VESTING_STAGE_1_DAYS) {
            return ['factor' => BCC_TRUST_VESTING_STAGE_1_PCT, 'stage' => 1];
        }
        return ['factor' => BCC_TRUST_VESTING_STAGE_0_PCT, 'stage' => 0];
    }

    /**
     * Page-level endorser diversity factor.
     *
     * SINGLE SOURCE OF TRUTH — DO NOT DIVERGE.
     * Used by BOTH the live apply/remove path AND the cron recalc path.
     *
     * @param int $uniqueEndorsers Number of distinct endorser user IDs.
     * @return float Diversity multiplier (0.50 to 1.00).
     */
    public static function computePageDiversityFactor(int $uniqueEndorsers): float {
        if ($uniqueEndorsers <= 0) {
            return 1.0;
        }
        if ($uniqueEndorsers === 1) {
            return 0.50;
        }
        if ($uniqueEndorsers === 2) {
            return 0.65;
        }
        if ($uniqueEndorsers <= 5) {
            return 0.80;
        }
        if ($uniqueEndorsers <= 10) {
            return 0.90;
        }
        return 1.00;
    }

    /**
     * Compute page endorsement_bonus + count from active endorsement rows.
     *
     * SINGLE SOURCE OF TRUTH — DO NOT DIVERGE.
     * Both the live path (ScoreRepository::deriveAndWriteEndorsementBonus)
     * and the cron recalc path (VoteService::recalculateFromVotes) MUST use
     * this helper. Stored weight values are NEVER consulted; every factor is
     * recomputed dynamically from base_weight, created_at, and current fraud.
     *
     * @param object[] $rows  Rows from EndorsementRepository::getActiveWithFraudScoresForPage()
     *                        Each row must expose: base_weight, created_at,
     *                        endorser_user_id, fraud_score_at_endorsement,
     *                        current_fraud_score.
     * @return array{bonus: float, count: int, unique_endorsers: int}
     */
    public static function computePageEndorsementBonus(array $rows): array {
        $bonus = 0.0;
        $endorsers = [];
        foreach ($rows as $r) {
            $baseW      = (float) ($r->base_weight ?? 0);
            $createdAt  = (string) ($r->created_at ?? '');
            $origFraud  = (int)   ($r->fraud_score_at_endorsement ?? 0);
            $currFraud  = (int)   ($r->current_fraud_score ?? 0);
            $endorserId = (int)   ($r->endorser_user_id ?? 0);

            $ageDays = $createdAt !== ''
                ? (int) ((time() - strtotime($createdAt)) / DAY_IN_SECONDS)
                : 0;
            $vestingFactor = self::resolveVestingStage(max(0, $ageDays))['factor'];

            $retro = FraudDiscountCalculator::retroactiveFactor($currFraud, $origFraud);

            $bonus += $baseW * $vestingFactor * $retro;

            if ($endorserId > 0) {
                $endorsers[$endorserId] = true;
            }
        }

        $uniqueEndorsers = count($endorsers);
        $bonus = round($bonus * self::computePageDiversityFactor($uniqueEndorsers), 2);

        return [
            'bonus'            => $bonus,
            'count'            => count($rows),
            'unique_endorsers' => $uniqueEndorsers,
        ];
    }
}
