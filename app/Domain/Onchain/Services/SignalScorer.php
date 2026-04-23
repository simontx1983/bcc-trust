<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts raw on-chain signal data into a trust score contribution.
 *
 * Score breakdown (max 20 per wallet):
 *
 *  Wallet age (max 8 pts)
 *  Transaction depth (max 7 pts)
 *  Contract deployments (max 5 pts)
 */
class SignalScorer
{
    /** @param array<string, mixed> $signals */
    public static function score(array $signals): float
    {
        return self::breakdown($signals)['total'];
    }

    /**
     * Maximum plausible values for on-chain signal data.
     *
     * These bounds catch MITM-injected or corrupted API responses.
     * Ethereum mainnet launched 2015-07-30 (~4000 days as of 2026).
     * Solana launched 2020-03-16 (~2200 days). We use generous upper
     * bounds that cover all supported chains.
     */
    private const PLAUSIBILITY_BOUNDS = [
        'wallet_age_days'    => 5000,    // ~13.7 years — no blockchain is older
        'tx_count'           => 5000000, // 5M transactions — implausible for a single wallet
        'contract_count'     => 50000,   // 50k contracts — implausible for a single deployer
        'contract_age_days'  => 5000,    // same as wallet age
    ];

    /**
     * @param array<string, mixed> $signals
     * @return array{age_days: int, tx_count: int, contract_count: int, contract_age_days: int|null, age_score: float, depth_score: float, contract_score: float, total: float, max_possible: float|int}
     */
    public static function breakdown(array $signals): array
    {
        $age_days          = (int)   ($signals['wallet_age_days']    ?? 0);
        $tx_count          = (int)   ($signals['tx_count']           ?? 0);
        $contract_count    = (int)   ($signals['contract_count']     ?? 0);
        $contract_age_days = isset($signals['contract_age_days'])
                             ? (int) $signals['contract_age_days']
                             : null;

        // Check if this chain doesn't support contract detection (e.g., Solana).
        // The flag is stored in raw_data by the fetcher. When set, contract_count
        // is excluded from scoring so chains without detection aren't penalized.
        $rawData = $signals['raw_data'] ?? [];
        if (is_string($rawData)) {
            $rawData = json_decode($rawData, true) ?: [];
        }
        $contractUnsupported = !empty($rawData['contract_count_unsupported']);

        // ── Plausibility enforcement ─────────────────────────────────
        // Clamp to reasonable maximums. Any value above these bounds
        // indicates corrupted data, API spoofing, or a bug. Log and
        // cap rather than reject — the scorer should be tolerant of
        // edge cases but not pass through impossible values.
        $anomaly = false;
        if ($age_days < 0 || $age_days > self::PLAUSIBILITY_BOUNDS['wallet_age_days']) {
            $age_days = min(max(0, $age_days), self::PLAUSIBILITY_BOUNDS['wallet_age_days']);
            $anomaly = true;
        }
        if ($tx_count < 0 || $tx_count > self::PLAUSIBILITY_BOUNDS['tx_count']) {
            $tx_count = min(max(0, $tx_count), self::PLAUSIBILITY_BOUNDS['tx_count']);
            $anomaly = true;
        }
        if ($contract_count < 0 || $contract_count > self::PLAUSIBILITY_BOUNDS['contract_count']) {
            $contract_count = min(max(0, $contract_count), self::PLAUSIBILITY_BOUNDS['contract_count']);
            $anomaly = true;
        }
        if ($contract_age_days !== null && ($contract_age_days < 0 || $contract_age_days > self::PLAUSIBILITY_BOUNDS['contract_age_days'])) {
            $contract_age_days = min(max(0, $contract_age_days), self::PLAUSIBILITY_BOUNDS['contract_age_days']);
            $anomaly = true;
        }

        if ($anomaly) {
            \BCC\Core\Log\Logger::error('[SignalScorer] Implausible signal data clamped', [
                'original' => $signals,
                'clamped'  => compact('age_days', 'tx_count', 'contract_count', 'contract_age_days'),
            ]);
        }

        $age_score      = self::ageScore($age_days);
        $depth_score    = self::depthScore($tx_count);
        // Exclude contract score for chains that don't support detection (e.g., Solana).
        $contract_score = $contractUnsupported ? 0.0 : self::contractScore($contract_count, $contract_age_days);
        $contract_max   = $contractUnsupported ? 0 : BCC_ONCHAIN_MAX_CONTRACT_SCORE;

        return [
            'age_days'          => $age_days,
            'tx_count'          => $tx_count,
            'contract_count'    => $contract_count,
            'contract_age_days' => $contract_age_days,
            'age_score'         => $age_score,
            'depth_score'       => $depth_score,
            'contract_score'    => $contract_score,
            'total'             => min((float) BCC_ONCHAIN_MAX_TOTAL_BONUS, $age_score + $depth_score + $contract_score),
            'max_possible'      => BCC_ONCHAIN_MAX_AGE_SCORE + BCC_ONCHAIN_MAX_DEPTH_SCORE + $contract_max,
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function ageScore(int $days): float
    {
        return match (true) {
            $days >= (365 * 5) => 8.0,
            $days >= (365 * 3) => 6.0,
            $days >= (365 * 2) => 3.0,
            $days >= 365       => 2.0,
            $days >= 180       => 0.5,
            default            => 0.2,
        };
    }

    /**
     * Depth score tiers — aligned with the API fetch cap of 1000 transactions.
     * The top tier triggers at 1000 (the max observable count from Etherscan/Solana).
     */
    private static function depthScore(int $count): float
    {
        return match (true) {
            $count >= 1000 => 7.0,
            $count >= 500  => 5.0,
            $count >= 100  => 3.0,
            $count >= 20   => 1.0,
            default        => 0.2,
        };
    }

    private static function contractScore(int $count, ?int $contract_age_days): float
    {
        $base = match (true) {
            $count >= 20 => 8.0,
            $count >= 10 => 5.0,
            $count >= 5  => 4.0,
            $count >= 3  => 3.0,
            $count >= 2  => 1.0,
            $count >= 1  => 0.5,
            default      => 0.2,
        };

        $score = min($base, (float) BCC_ONCHAIN_MAX_CONTRACT_SCORE);

        if ($count > 0 && $contract_age_days !== null) {
            $multiplier = match (true) {
                $contract_age_days >= 365 => 0.60,
                $contract_age_days >= 90  => 0.45,
                $contract_age_days >= 30  => 0.30,
                default                   => 0.15,
            };
            $score = round($score * $multiplier, 2);
        }

        // Factory-spam discount: penalise wallets that deployed many contracts
        // relative to their age. A legitimate developer deploying 20 contracts
        // over 2 years is fine; deploying 20 in 1 day is factory spam.
        // Threshold: >5 contracts per 30 days of wallet age (generous).
        if ($count > 5 && $contract_age_days !== null && $contract_age_days > 0) {
            $contractsPerMonth = $count / max(1, $contract_age_days / 30);
            if ($contractsPerMonth > 5) {
                // Scale discount from 50% (borderline) to 90% (extreme spam).
                // Previous cap was 50% — insufficient to deter factory farms.
                $velocityRatio = min($contractsPerMonth / 5, 20); // cap at 20x
                $discount      = min(0.9, 0.3 + 0.6 * min(1.0, ($velocityRatio - 1) / 9));
                $score         = round($score * (1 - $discount), 2);

                \BCC\Core\Log\Logger::info('[SignalScorer] Factory-spam discount applied', [
                    'contract_count'      => $count,
                    'contract_age_days'   => $contract_age_days,
                    'contracts_per_month' => round($contractsPerMonth, 2),
                    'discount_pct'        => round($discount * 100, 1),
                ]);
            }

            // Burst detection: all contracts deployed within a 7-day window
            // is a strong factory-spam signal regardless of wallet age.
            if ($contract_age_days <= 7 && $count >= 10) {
                $score = round($score * 0.1, 2); // 90% discount
                \BCC\Core\Log\Logger::info('[SignalScorer] Contract burst detected', [
                    'contract_count'    => $count,
                    'contract_age_days' => $contract_age_days,
                ]);
            }
        }

        return $score;
    }
}
