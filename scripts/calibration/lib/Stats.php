<?php
/**
 * CalibStats — descriptive statistics for calibration outputs.
 * Harness-only helper (no production twin exists — §11 scan 2026-08-04).
 */

declare(strict_types=1);

final class CalibStats
{
    /** @param list<float|int> $values */
    public static function median(array $values): ?float
    {
        return self::percentile($values, 50.0);
    }

    /** @param list<float|int> $values Nearest-rank percentile (deterministic). */
    public static function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }
        $sorted = array_map('floatval', $values);
        sort($sorted);
        $n = count($sorted);
        $rank = (int) ceil(($p / 100.0) * $n);
        $rank = max(1, min($n, $rank));
        return $sorted[$rank - 1];
    }

    /**
     * Gini coefficient via the sorted-index formula:
     *   G = (2 Σ i·x_(i)) / (n Σ x) − (n + 1) / n
     *
     * @param list<float|int> $values
     */
    public static function gini(array $values): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        $sorted = array_map('floatval', $values);
        sort($sorted);
        $sum = array_sum($sorted);
        if ($sum <= 0.0) {
            return 0.0;
        }
        $weighted = 0.0;
        foreach ($sorted as $i => $x) {
            $weighted += ($i + 1) * $x;
        }
        return round((2.0 * $weighted) / ($n * $sum) - ($n + 1) / $n, 4);
    }

    /**
     * Fixed-width histogram. Returns label => count.
     *
     * @param list<float|int> $values
     * @return array<string, int>
     */
    public static function histogram(array $values, float $binWidth, float $min, float $max): array
    {
        $bins = [];
        for ($lo = $min; $lo < $max - 1e-9; $lo += $binWidth) {
            $bins[sprintf('%.2f-%.2f', $lo, $lo + $binWidth)] = 0;
        }
        foreach ($values as $v) {
            $v = (float) $v;
            $idx = (int) floor(($v - $min) / $binWidth);
            $idx = max(0, min(count($bins) - 1, $idx));
            $label = array_keys($bins)[$idx];
            $bins[$label]++;
        }
        return $bins;
    }

    /**
     * Five-number summary of a day distribution.
     *
     * @param list<int> $days
     * @return array{n: int, min: int|null, p25: float|null, median: float|null, p75: float|null, max: int|null}
     */
    public static function daySummary(array $days): array
    {
        return [
            'n'      => count($days),
            'min'    => $days === [] ? null : min($days),
            'p25'    => self::percentile($days, 25.0),
            'median' => self::median($days),
            'p75'    => self::percentile($days, 75.0),
            'max'    => $days === [] ? null : max($days),
        ];
    }
}
