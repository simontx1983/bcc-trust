<?php
/**
 * X (Twitter) Score Service
 *
 * Calculates a flat trust boost for X account verification.
 * Since we only store email and username, the scoring is simpler
 * than GitHub — a fixed boost for having a verified X account.
 *
 * @package BCC_Trust_Engine
 * @subpackage X
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Services\x;

if (!defined('ABSPATH')) {
    exit;
}

class XScoreService {

    private float $trustBoost;
    private int   $fraudReduction;

    public function __construct() {
        $this->trustBoost     = defined('BCC_TRUST_X_TRUST_BOOST') ? (float) BCC_TRUST_X_TRUST_BOOST : 10.0;
        $this->fraudReduction = defined('BCC_TRUST_X_FRAUD_REDUCTION') ? (int) BCC_TRUST_X_FRAUD_REDUCTION : 5;
    }

    /**
     * Calculate trust boost for a verified X account.
     *
     * @param array<string, mixed> $xData X user data (username, email)
     * @return float Trust boost value
     */
    public function calculateTrustBoost(array $xData): float {

        $boost = $this->trustBoost;

        // Small bonus if email is present (not all X accounts expose email)
        if (!empty($xData['email'])) {
            $boost += 2.0;
        }

        if (defined('WP_DEBUG') && \WP_DEBUG) {
            \BCC\Core\Log\Logger::error('BCC Trust X: Trust boost calculation - ' . json_encode([
                'base'      => $this->trustBoost,
                'has_email' => !empty($xData['email']),
                'final'     => $boost,
            ]));
        }

        return round($boost, 1);
    }

    /**
     * Calculate fraud score reduction for a verified X account.
     *
     * @param array<string, mixed> $xData X user data
     * @param float $trustBoost Calculated trust boost
     * @return int Fraud reduction value
     */
    public function calculateFraudReduction(array $xData, float $trustBoost): int {

        $reduction = $this->fraudReduction;

        // Small bonus if email is present
        if (!empty($xData['email'])) {
            $reduction += 2;
        }

        return $reduction;
    }
}