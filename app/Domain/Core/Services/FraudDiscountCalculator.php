<?php
/**
 * Fraud Discount Calculator — SINGLE SOURCE OF TRUTH
 *
 * Unifies fraud-based weight reduction across:
 *   - VoteWeightCalculator (real-time vote submission)
 *   - VoteService::recalculateFromVotes (recalculation)
 *   - VoteRepository SQL (retroactive aggregation — simplified mirror)
 *
 * Additive penalty model: individual fraud signals are summed (not multiplied),
 * preventing the exponential weight collapse of multiplicative chains. A user
 * with multiple moderate signals gets a proportional reduction, not a cliff.
 *
 * Two severity tiers:
 *   - Suspicious (penalty < 0.90): reduced influence, floor at $minWeight
 *   - Malicious  (penalty >= 0.90): zero influence (hard block)
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class FraudDiscountCalculator
{
    /**
     * Compute a fraud discount factor (0.00 to 1.00) from all available signals.
     *
     * Returns ['discount' => float, 'penalties' => array] for observability.
     *
     * @param array<string, mixed> $signals    Fraud signals array with keys:
     *   - automation: ['is_automated' => bool, 'confidence' => int]
     *   - multi_account_risk: bool
     *   - device_fraud_probability: float (0.0–1.0)
     *   - behavior_score: int (0–100)
     *   - trust_rank: float (0.0–1.0)
     * @param int   $fraudScore  User's fraud_score (0–100).
     * @param float $minWeight   Floor for the discount (default: BCC_TRUST_WEIGHT_RISKY = 0.03).
     *
     * @return array{discount: float, penalties: array<string, float>}
     */
    public static function compute(array $signals, int $fraudScore, float $minWeight = 0.0): array
    {
        if ($minWeight <= 0.0) {
            $minWeight = defined('BCC_TRUST_WEIGHT_RISKY') ? BCC_TRUST_WEIGHT_RISKY : 0.03;
        }

        $penalties = [];

        // ── Automation: confirmed = hard block penalty, suspected = proportional ──
        if ($signals['automation']['is_automated'] ?? false) {
            $penalties['automation'] = BCC_TRUST_FRAUD_PENALTY_AUTOMATION;
        } elseif (($signals['automation']['confidence'] ?? 0) > BCC_TRUST_AUTOMATION_MEDIUM) {
            $penalties['automation_suspected'] = ($signals['automation']['confidence'] / 200);
        }

        // ── Multi-account: shared device fingerprint ──
        if ($signals['multi_account_risk'] ?? false) {
            $penalties['multi_account'] = BCC_TRUST_FRAUD_PENALTY_MULTI_ACCOUNT;
        }

        // ── Device fraud probability (0.0–1.0, only penalize above threshold) ──
        $deviceFraud = $signals['device_fraud_probability'] ?? 0.0;
        if ($deviceFraud > BCC_TRUST_FRAUD_DEVICE_THRESHOLD) {
            $penalties['device_fraud'] = $deviceFraud * BCC_TRUST_FRAUD_DEVICE_WEIGHT;
        }

        // ── Behavior score (0-100, only penalize above MEDIUM threshold) ──
        // Comparator is >= to match FraudDetector::getRiskLevel and every
        // other threshold gate (audit HIGH-4).
        $behaviorScore = $signals['behavior_score'] ?? 0.0;
        if ($behaviorScore >= BCC_TRUST_FRAUD_MEDIUM) {
            $penalties['behavior'] = ($behaviorScore / 200);
        }

        // ── Fraud score on record (0-100, only penalize above MEDIUM) ──
        if ($fraudScore >= BCC_TRUST_FRAUD_MEDIUM) {
            $penalties['fraud_score'] = ($fraudScore / 100) * BCC_TRUST_FRAUD_SCORE_WEIGHT;
        }

        // ── Hard block is decided BEFORE any rank relief (F-82) ──
        // User decision: a confirmed bot's hard block ALWAYS beats rank.
        // The trust-rank boost used to be summed in first, so a confirmed
        // bot (automation penalty 0.90 = the block threshold) with a high
        // trust_rank landed at 0.75 and escaped the block — the boost is
        // exactly what a bot that farmed reputation would have.
        $penaltyBeforeRelief = max(0.0, array_sum($penalties));
        $hardBlocked = ($signals['automation']['is_automated'] ?? false)
            || $penaltyBeforeRelief >= BCC_TRUST_FRAUD_BLOCK_THRESHOLD;

        // ── Trust rank boost (good actors get a slight reduction in penalty) ──
        // Relief only — it can soften a penalty below the block, never lift
        // an account out of it.
        $trustRank = $signals['trust_rank'] ?? 0.0;
        if ($trustRank > BCC_TRUST_FRAUD_RANK_BOOST_THRESHOLD) {
            $penalties['trust_rank_boost'] = -min(BCC_TRUST_FRAUD_RANK_BOOST_CAP, ($trustRank - BCC_TRUST_FRAUD_RANK_BOOST_THRESHOLD) * 0.5);
        }

        $rawPenalty = max(0.0, array_sum($penalties));

        // Severity tiers:
        //   hard-blocked (confirmed bot, or the pre-relief penalty reaches the
        //   block threshold) = malicious → discount 0.00, whatever the rank
        //   otherwise = suspicious → reduced influence (floor = $minWeight)
        if ($hardBlocked) {
            $discount = 0.0;
        } else {
            $cappedPenalty = min(1.0 - $minWeight, $rawPenalty);
            $discount = round(1.0 - $cappedPenalty, 4);
        }

        // Defence-in-depth: clamp to [0, 1]. If any future regression lets
        // a negative $cappedPenalty slip through, $discount > 1.0 would
        // AMPLIFY a vote's weight instead of discounting it — the exact
        // inverse of this service's purpose. Never return > 1.
        $discount = max(0.0, min(1.0, $discount));

        return ['discount' => $discount, 'penalties' => $penalties];
    }

    /**
     * Compute the retroactive fraud factor for a single vote or endorsement.
     *
     * Used during score recalculation to discount stored weights when a user's
     * fraud_score has increased since the action was recorded.
     *
     * This is the PHP equivalent of the SQL CASE in VoteRepository::getVoteAggregatesForPage().
     * Both MUST use the same formula:
     *   GREATEST(BCC_TRUST_RETROACTIVE_FRAUD_FLOOR, 1.0 - fraud_score / 100.0).
     *
     * SAFETY CONTRACT: The SQL version uses fraud_score only. The full multi-signal
     * model (compute()) runs at action time. To keep both paths aligned, all fraud
     * signals MUST ultimately update user_info.fraud_score.
     *
     * @param int $currentFraud   User's current fraud_score.
     * @param int $originalFraud  User's fraud_score at the time of the action.
     * @return float  Multiplicative factor (FLOOR–1.0). Multiply stored weight by this.
     */
    public static function retroactiveFactor(int $currentFraud, int $originalFraud): float
    {
        $threshold = BCC_TRUST_FRAUD_MEDIUM;
        $floor     = BCC_TRUST_RETROACTIVE_FRAUD_FLOOR;

        // No discount if current fraud is at or below threshold
        if ($currentFraud <= $threshold) {
            return 1.0;
        }

        // Factor that applies NOW
        $currentFactor = max($floor, 1.0 - ($currentFraud / 100.0));

        // Factor that was already baked into the stored weight
        if ($originalFraud > $threshold) {
            $originalFactor = max($floor, 1.0 - ($originalFraud / 100.0));
        } else {
            $originalFactor = 1.0;
        }

        // Only apply incremental difference (fraud got worse)
        if ($currentFactor >= $originalFactor) {
            return 1.0;
        }

        return $currentFactor / $originalFactor;
    }

    /**
     * Normalize disparate fraud signal formats into the standard shape
     * expected by compute().
     *
     * EndorsementService passes fraud data as separate arrays; this bridges
     * to the unified format without changing the caller's interface.
     *
     * @param array<string, mixed> $fraudAnalysis   ['score' => int, ...]
     * @param array<string, mixed> $automationData  ['is_automated' => bool, 'confidence' => int]
     * @param bool                 $multiAccountRisk
     * @param array<string, mixed> $behavior        ['behavior_score' => int, ...]
     * @param float                $trustRank       0.0–1.0
     * @return array<string, mixed>  Normalized signals array.
     */
    public static function normalizeSignals(
        array $fraudAnalysis,
        array $automationData,
        bool $multiAccountRisk,
        array $behavior,
        float $trustRank
    ): array {
        // Validate automation shape — if malformed, treat as no automation data
        // rather than silently defaulting to "not automated" with high confidence.
        if (!isset($automationData['is_automated']) || !isset($automationData['confidence'])) {
            $automationData = ['is_automated' => false, 'confidence' => 0];
        }

        // Clamp trust_rank to valid range — out-of-range values would produce
        // incorrect boost/penalty in compute().
        $trustRank = max(0.0, min(1.0, $trustRank));

        return [
            'automation'               => $automationData,
            'multi_account_risk'       => $multiAccountRisk,
            'device_fraud_probability' => (float) ($fraudAnalysis['device_fraud_probability'] ?? 0.0),
            'behavior_score'           => (int) ($behavior['behavior_score'] ?? 0),
            'trust_rank'               => $trustRank,
        ];
    }
}
