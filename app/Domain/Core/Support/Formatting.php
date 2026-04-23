<?php

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure formatting and display helpers.
 *
 * All methods are static and side-effect free.
 * Migrated from includes/helpers.php.
 */
final class Formatting
{
    public static function scoreColor(float $score): string
    {
        if ($score >= 80) return '#f44336';
        if ($score >= 60) return '#ff9800';
        if ($score >= 40) return '#2196f3';
        if ($score >= 20) return '#4caf50';
        return '#8bc34a';
    }

    /**
     * Presentation-only: color for a per-analysis risk_level string.
     *
     * This maps fraud_analysis.risk_level (lowercase string from a single
     * analysis run). It is NOT the same as FraudClassification::color(),
     * which maps a user's aggregate fraud_score integer to a tier color.
     */
    public static function riskColor(string $riskLevel): string
    {
        $colors = [
            'critical' => '#9c27b0',
            'high'     => '#f44336',
            'medium'   => '#ff9800',
            'low'      => '#2196f3',
            'minimal'  => '#4caf50',
            'unknown'  => '#9e9e9e',
        ];
        return $colors[$riskLevel] ?? $colors['unknown'];
    }

    public static function tierColor(string $tier): string
    {
        $colors = [
            'elite'             => '#ffd700',
            'trusted'           => '#4caf50',
            'neutral'           => '#9e9e9e',
            'caution'           => '#ff9800',
            'risky'             => '#f44336',
            'insufficient_data' => '#9e9e9e',
        ];
        return $colors[$tier] ?? '#9e9e9e';
    }

    /**
     * @return array{color: string, icon: string, label: string}
     */
    public static function tierInfo(string $tier): array
    {
        $tiers = [
            'elite'   => ['color' => '#ffd700', 'icon' => '👑', 'label' => 'Elite'],
            'trusted' => ['color' => '#4caf50', 'icon' => '⭐', 'label' => 'Trusted'],
            'neutral' => ['color' => '#9e9e9e', 'icon' => '○', 'label' => 'Neutral'],
            'caution' => ['color' => '#ff9800', 'icon' => '⚠️', 'label' => 'Caution'],
            'risky'   => ['color' => '#f44336', 'icon' => '🔴', 'label' => 'Risky'],
        ];
        return $tiers[$tier] ?? $tiers['neutral'];
    }

    public static function timeAgo(?string $datetime): string
    {
        if (!$datetime) {
            return 'Never';
        }

        $time = strtotime($datetime) ?: 0;
        $diff = time() - $time;

        if ($diff < 60) {
            return $diff . ' seconds ago';
        } elseif ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 2592000) {
            $days = (int) floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        }

        return date('Y-m-d H:i', $time);
    }

    /**
     * @see trust-header.js scoreToGrade() — must stay in sync.
     */
    public static function scoreToGrade(float $score): string
    {
        $score = round($score);
        if ($score >= 95) return 'A+';
        if ($score >= 90) return 'A';
        if ($score >= 85) return 'A-';
        if ($score >= 80) return 'B+';
        if ($score >= 75) return 'B';
        if ($score >= 70) return 'B-';
        if ($score >= 65) return 'C+';
        if ($score >= 60) return 'C';
        if ($score >= 55) return 'C-';
        if ($score >= 50) return 'D+';
        if ($score >= 45) return 'D';
        if ($score >= 40) return 'D-';
        return 'F';
    }
}
