<?php

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for fraud risk classification.
 *
 * All admin views, materialized tables, and API responses MUST use these
 * methods instead of embedding CASE logic in SQL or ad-hoc if/else chains.
 *
 * Thresholds come from includes/config/fraud-detection.php:
 *   BCC_TRUST_FRAUD_CRITICAL (80), BCC_TRUST_FRAUD_HIGH (60),
 *   BCC_TRUST_FRAUD_MEDIUM (40), BCC_TRUST_FRAUD_LOW (20).
 */
final class FraudClassification
{
    /**
     * Canonical risk label for a given fraud score (Title Case).
     *
     * 5-tier scheme: Critical / High / Medium / Low / Minimal.
     * Used for admin dashboard display badges and materialized tables.
     */
    public static function label(int $fraudScore): string
    {
        if ($fraudScore >= BCC_TRUST_FRAUD_CRITICAL) {
            return 'Critical';
        }
        if ($fraudScore >= BCC_TRUST_FRAUD_HIGH) {
            return 'High';
        }
        if ($fraudScore >= BCC_TRUST_FRAUD_MEDIUM) {
            return 'Medium';
        }
        if ($fraudScore >= BCC_TRUST_FRAUD_LOW) {
            return 'Low';
        }
        return 'Minimal';
    }

    /**
     * Canonical risk level for a given fraud score (lowercase).
     *
     * Same tiers as label() but lowercase for DB risk_level columns
     * and internal logic (FraudDetector, BehavioralAnalyzer, audit logs).
     */
    public static function level(int $fraudScore): string
    {
        return strtolower(self::label($fraudScore));
    }

    /**
     * Presentation-only: risk color for a given fraud score.
     *
     * Matches the 5-tier label scheme. Used for materialized table
     * colors and admin dashboard badges. Do not use colors for
     * branching logic — use label() or the threshold constants instead.
     *
     * NOTE: Formatting::riskColor() is a DIFFERENT mapping — it colors
     * per-analysis risk_level strings, not aggregate fraud_score tiers.
     */
    public static function color(int $fraudScore): string
    {
        if ($fraudScore >= BCC_TRUST_FRAUD_CRITICAL) {
            return '#9c27b0'; // purple — critical
        }
        if ($fraudScore >= BCC_TRUST_FRAUD_HIGH) {
            return '#dc3545'; // red — high
        }
        if ($fraudScore >= BCC_TRUST_FRAUD_MEDIUM) {
            return '#fd7e14'; // orange — medium
        }
        if ($fraudScore >= BCC_TRUST_FRAUD_LOW) {
            return '#ffc107'; // yellow — low
        }
        return '#28a745'; // green — minimal
    }
}
